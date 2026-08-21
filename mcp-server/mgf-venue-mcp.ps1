param(
    [switch]$SelfTest
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$InformationPreference = 'SilentlyContinue'

$script:ServerName = 'mgf-venue'
$script:ServerVersion = '0.3.1'
$script:DefaultProtocolVersion = '2025-06-18'

function Get-PropertyValue {
    param(
        [AllowNull()][object]$Object,
        [Parameter(Mandatory = $true)][string]$Name,
        [AllowNull()][object]$Default = $null
    )

    if ($null -eq $Object) { return $Default }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $Default }
    return $property.Value
}

function Write-JsonLine {
    param([Parameter(Mandatory = $true)][object]$Value)

    $json = $Value | ConvertTo-Json -Depth 40 -Compress
    [Console]::Out.WriteLine($json)
    [Console]::Out.Flush()
}

function Write-JsonRpcResult {
    param(
        [AllowNull()][object]$Id,
        [AllowNull()][object]$Result
    )

    Write-JsonLine @{
        jsonrpc = '2.0'
        id = $Id
        result = $Result
    }
}

function Write-JsonRpcError {
    param(
        [AllowNull()][object]$Id,
        [int]$Code,
        [string]$Message,
        [AllowNull()][object]$Data = $null
    )

    $errorObject = @{
        code = $Code
        message = $Message
    }
    if ($null -ne $Data) { $errorObject.data = $Data }

    Write-JsonLine @{
        jsonrpc = '2.0'
        id = $Id
        error = $errorObject
    }
}

function ConvertTo-QueryString {
    param([hashtable]$Values)

    $parts = @()
    foreach ($key in $Values.Keys) {
        $value = $Values[$key]
        if ($null -eq $value) { continue }
        if ($value -is [string] -and [string]::IsNullOrWhiteSpace($value)) { continue }

        if ($value -is [bool]) {
            $value = $value.ToString().ToLowerInvariant()
        }

        $encodedKey = [Uri]::EscapeDataString([string]$key)
        $encodedValue = [Uri]::EscapeDataString([string]$value)
        $parts += "${encodedKey}=${encodedValue}"
    }
    return ($parts -join '&')
}

function Assert-VenueConfiguration {
    $missing = @()
    foreach ($name in @('MGF_VENUE_BASE_URL', 'MGF_VENUE_USERNAME', 'MGF_VENUE_APP_PASSWORD')) {
        if ([string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($name))) {
            $missing += $name
        }
    }

    if ($missing.Count -gt 0) {
        throw "Missing required environment variable(s): $($missing -join ', ')."
    }
}

function Get-WebErrorMessage {
    param([Parameter(Mandatory = $true)][object]$ErrorRecord)

    $message = $ErrorRecord.Exception.Message
    try {
        $response = $ErrorRecord.Exception.Response
        if ($null -ne $response -and $null -ne $response.GetResponseStream()) {
            $reader = New-Object System.IO.StreamReader($response.GetResponseStream())
            $body = $reader.ReadToEnd()
            $reader.Dispose()
            if (-not [string]::IsNullOrWhiteSpace($body)) {
                try {
                    $parsed = $body | ConvertFrom-Json
                    $apiMessage = Get-PropertyValue $parsed 'message' ''
                    $apiCode = Get-PropertyValue $parsed 'code' ''
                    if ($apiMessage) {
                        return $(if ($apiCode) { "${apiCode}: ${apiMessage}" } else { $apiMessage })
                    }
                } catch {
                    return $body
                }
            }
        }
    } catch {
        # Preserve the original exception message if the response body is unavailable.
    }
    return $message
}

function Invoke-VenueApi {
    param(
        [Parameter(Mandatory = $true)][ValidateSet('GET', 'POST')][string]$Method,
        [Parameter(Mandatory = $true)][string]$Path,
        [AllowNull()][object]$Body = $null
    )

    Assert-VenueConfiguration

    $baseUrl = $env:MGF_VENUE_BASE_URL.TrimEnd('/')
    $username = $env:MGF_VENUE_USERNAME
    $appPassword = $env:MGF_VENUE_APP_PASSWORD
    $credentialBytes = [Text.Encoding]::UTF8.GetBytes("${username}:${appPassword}")
    $credential = [Convert]::ToBase64String($credentialBytes)
    $headers = @{
        Authorization = "Basic ${credential}"
        Accept = 'application/json'
        'User-Agent' = "MGF-Venue-MCP/$($script:ServerVersion)"
    }
    $uri = "${baseUrl}/wp-json/mathlin/v1${Path}"

    try {
        if ($Method -eq 'POST') {
            $jsonBody = $Body | ConvertTo-Json -Depth 20 -Compress
            $response = Invoke-RestMethod -Method Post -Uri $uri -Headers $headers -ContentType 'application/json; charset=utf-8' -Body $jsonBody
        } else {
            $response = Invoke-RestMethod -Method Get -Uri $uri -Headers $headers
        }
        if ($response -is [string] -and $response.TrimStart().StartsWith('<')) {
            throw 'Venue API returned HTML instead of JSON; check the WordPress REST URL and permalink configuration.'
        }
        return $response
    } catch {
        throw (Get-WebErrorMessage $_)
    }
}

function Invoke-VenueDownload {
    param(
        [Parameter(Mandatory = $true)][string]$Action,
        [AllowNull()][object]$Arguments,
        [Parameter(Mandatory = $true)][string]$OutputPath
    )

    Assert-VenueConfiguration

    $fullPath = [IO.Path]::GetFullPath($OutputPath)
    if ([IO.Path]::GetExtension($fullPath).ToLowerInvariant() -ne '.csv') {
        throw 'Admin exports must be saved with a .csv extension.'
    }
    $parent = Split-Path -Parent $fullPath
    if (-not (Test-Path -LiteralPath $parent)) {
        New-Item -ItemType Directory -Path $parent -Force | Out-Null
    }

    $baseUrl = $env:MGF_VENUE_BASE_URL.TrimEnd('/')
    $credentialBytes = [Text.Encoding]::UTF8.GetBytes("$($env:MGF_VENUE_USERNAME):$($env:MGF_VENUE_APP_PASSWORD)")
    $credential = [Convert]::ToBase64String($credentialBytes)
    $headers = @{
        Authorization = "Basic ${credential}"
        Accept = 'text/csv, application/json'
        'User-Agent' = "MGF-Venue-MCP/$($script:ServerVersion)"
    }
    $uri = "${baseUrl}/wp-json/mathlin/v1/admin/actions/${Action}"
    $jsonBody = $Arguments | ConvertTo-Json -Depth 30 -Compress

    try {
        Invoke-WebRequest -Method Post -Uri $uri -Headers $headers -ContentType 'application/json; charset=utf-8' -Body $jsonBody -OutFile $fullPath
    } catch {
        throw (Get-WebErrorMessage $_)
    }

    $file = Get-Item -LiteralPath $fullPath
    return @{ path = $file.FullName; bytes = $file.Length; action = $Action }
}

$script:Tools = @(
    @{
        name = 'list_bookings'
        title = 'List venue bookings'
        description = 'List and filter venue bookings. Read a booking before any status-changing action.'
        inputSchema = @{
            type = 'object'
            properties = @{
                status = @{ type = 'string'; enum = @('pending', 'confirmed', 'deposit_paid', 'paid', 'cancelled', 'archived') }
                date_from = @{ type = 'string'; description = 'Inclusive start date in YYYY-MM-DD format.' }
                date_to = @{ type = 'string'; description = 'Inclusive end date in YYYY-MM-DD format.' }
                search = @{ type = 'string'; description = 'Search name, organisation, reference, or purpose.' }
                orderby = @{ type = 'string'; enum = @('booking_date', 'created_at', 'name', 'status', 'amount'); default = 'booking_date' }
                order = @{ type = 'string'; enum = @('ASC', 'DESC'); default = 'ASC' }
                limit = @{ type = 'integer'; minimum = 1; maximum = 200; default = 50 }
                offset = @{ type = 'integer'; minimum = 0; default = 0 }
                exclude_archived = @{ type = 'boolean'; default = $true }
                exclude_scout = @{ type = 'boolean'; default = $false }
                scout_only = @{ type = 'boolean'; default = $false; description = 'Return only internal Scout-use bookings.' }
            }
            additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $true; destructiveHint = $false; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'create_booking'
        title = 'Create a venue booking'
        description = 'Create a normal one-off booking or an optional weekly series. Scout use is free. New confirmed bookings use the normal status transition, including Home Assistant notification and automatic paid status for a £0 one-off booking. notify_hirer defaults to false. Reuse the same idempotency_key when retrying an uncertain call.'
        inputSchema = @{
            type = 'object'
            properties = @{
                idempotency_key = @{ type = 'string'; minLength = 8; maxLength = 128; description = 'Stable unique key for this intended booking.' }
                name = @{ type = 'string'; description = 'Contact name. Defaults to the purpose for Scout use.' }
                organisation = @{ type = 'string' }
                email = @{ type = 'string'; description = 'Contact email. Defaults to the current WordPress user/admin email.' }
                phone = @{ type = 'string' }
                address = @{ type = 'string' }
                space = @{ type = 'string' }
                booking_date = @{ type = 'string'; description = 'Start date in YYYY-MM-DD format.' }
                booking_date_end = @{ type = 'string'; description = 'Optional inclusive end date in YYYY-MM-DD format.' }
                repeat_until = @{ type = 'string'; description = 'Optional weekly recurrence end date in YYYY-MM-DD format, up to one calendar year inclusive and 53 dates. Cannot be combined with a multi-day booking_date_end.' }
                start_time = @{ type = 'string'; description = 'Required for timed bookings, HH:MM.' }
                end_time = @{ type = 'string'; description = 'Required for timed bookings, HH:MM.' }
                all_day = @{ type = 'boolean'; default = $false }
                scout_use = @{ type = 'boolean'; default = $false; description = 'Internal Scout use; calculated charge is £0.' }
                pricing_tier = @{ type = 'string'; default = 'standard'; description = 'Configured pricing tier to apply, for example standard, community, or commercial.' }
                kitchen = @{ type = 'boolean'; default = $false }
                attendees = @{ type = 'integer'; minimum = 0; default = 0 }
                purpose = @{ type = 'string' }
                notes = @{ type = 'string' }
                is_public = @{ type = 'boolean'; default = $false }
                status = @{ type = 'string'; enum = @('pending', 'confirmed'); default = 'confirmed' }
                custom_amount = @{ type = 'number'; minimum = 0; description = 'Optional administrator price override.' }
                custom_fields = @{ type = 'object'; additionalProperties = @{ type = 'string' } }
                notify_hirer = @{ type = 'boolean'; default = $false; description = 'Send the normal booking/confirmation email only when explicitly authorised.' }
            }
            required = @('idempotency_key', 'space', 'booking_date', 'purpose')
            additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $false; destructiveHint = $false; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'get_booking'
        title = 'Get a venue booking'
        description = 'Get the current details for one booking reference.'
        inputSchema = @{
            type = 'object'
            properties = @{ ref = @{ type = 'string'; description = 'Booking reference such as MBS-ABC123.' } }
            required = @('ref')
            additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $true; destructiveHint = $false; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'check_availability'
        title = 'Check venue availability'
        description = 'Check blocked dates and booking conflicts for a proposed venue slot.'
        inputSchema = @{
            type = 'object'
            properties = @{
                space = @{ type = 'string' }
                date = @{ type = 'string'; description = 'Date in YYYY-MM-DD format.' }
                start_time = @{ type = 'string'; description = 'Start time in HH:MM format for timed bookings.' }
                end_time = @{ type = 'string'; description = 'End time in HH:MM format for timed bookings.' }
                all_day = @{ type = 'boolean'; default = $false }
                exclude_ref = @{ type = 'string'; description = 'Existing booking reference to ignore when checking an amendment.' }
            }
            required = @('space', 'date')
            additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $true; destructiveHint = $false; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'get_booking_audit'
        title = 'Get booking audit history'
        description = 'Get the action history for one booking. Stored IP addresses are not returned.'
        inputSchema = @{
            type = 'object'
            properties = @{ ref = @{ type = 'string' } }
            required = @('ref')
            additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $true; destructiveHint = $false; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'set_booking_status'
        title = 'Set booking status'
        description = 'Set a booking to pending, confirmed, or cancelled. Read it first and pass expected_status. notify_hirer sends the normal external confirmation/cancellation email and must only be true when the user authorized that communication.'
        inputSchema = @{
            type = 'object'
            properties = @{
                ref = @{ type = 'string' }
                status = @{ type = 'string'; enum = @('pending', 'confirmed', 'cancelled') }
                expected_status = @{ type = 'string'; enum = @('pending', 'confirmed', 'deposit_paid', 'paid', 'cancelled', 'archived') }
                idempotency_key = @{ type = 'string'; minLength = 8; maxLength = 128 }
                notify_hirer = @{ type = 'boolean'; default = $false }
                reason = @{ type = 'string'; description = 'Cancellation reason included only when notifying the hirer.' }
            }
            required = @('ref', 'status', 'expected_status', 'idempotency_key')
            additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $false; destructiveHint = $true; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'update_admin_notes'
        title = 'Update booking administrator notes'
        description = 'Replace the internal administrator note on a booking. This never emails the hirer.'
        inputSchema = @{
            type = 'object'
            properties = @{
                ref = @{ type = 'string' }
                notes = @{ type = 'string' }
            }
            required = @('ref', 'notes')
            additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $false; destructiveHint = $false; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'list_series'
        title = 'List recurring booking series'
        description = 'List recurring series with safe metadata. Filter external customer hires from no-charge Scout series with series_kind.'
        inputSchema = @{
            type = 'object'; properties = @{
                status = @{ type = 'string'; enum = @('pending', 'confirmed', 'paused', 'cancelled_future', 'cancelled') }
                search = @{ type = 'string' }
                series_kind = @{ type = 'string'; enum = @('all', 'external', 'scout'); default = 'all'; description = 'Return all series, external customer hires only, or internal Scout-use series only.' }
                limit = @{ type = 'integer'; minimum = 1; maximum = 500; default = 100 }
            }; additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $true; destructiveHint = $false; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'get_series'
        title = 'Get recurring series'
        description = 'Read a recurring series, occurrences, exceptions, invoice preview, invoices and audit history. Payment tokens and internal hashes are excluded.'
        inputSchema = @{ type = 'object'; properties = @{ series_ref = @{ type = 'string' } }; required = @('series_ref'); additionalProperties = $false }
        annotations = @{ readOnlyHint = $true; destructiveHint = $false; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'approve_series'
        title = 'Approve recurring series'
        description = 'Idempotently approve a pending recurring series. Read it first and pass status/version. notify_hirer defaults to false.'
        inputSchema = @{
            type = 'object'; properties = @{
                series_ref = @{ type = 'string' }; expected_status = @{ type = 'string'; enum = @('pending', 'confirmed') }
                expected_version = @{ type = 'integer'; minimum = 1 }; idempotency_key = @{ type = 'string'; minLength = 8; maxLength = 128 }
                notify_hirer = @{ type = 'boolean'; default = $false }
            }; required = @('series_ref', 'expected_status', 'expected_version', 'idempotency_key'); additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $false; destructiveHint = $true; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'configure_series_billing'
        title = 'Configure recurring series billing'
        description = 'Set monthly, termly, upfront, legacy-per-occurrence or no billing. Legacy adoption requires adopt_legacy=true after preview. Customer email is opt-in.'
        inputSchema = @{
            type = 'object'; properties = @{
                series_ref = @{ type = 'string' }; expected_version = @{ type = 'integer'; minimum = 1 }
                idempotency_key = @{ type = 'string'; minLength = 8; maxLength = 128 }
                billing_mode = @{ type = 'string'; enum = @('monthly', 'termly', 'legacy_per_occurrence', 'upfront', 'none') }
                billing_treatment = @{ type = 'string'; enum = @('manual_consolidated', 'invoice_managed', 'legacy_per_occurrence', 'none') }
                payment_method = @{ type = 'string'; enum = @('online', 'offline_bacs', 'none') }
                invoice_lead_days = @{ type = 'integer'; minimum = 0; maximum = 365; default = 28 }
                payment_terms_days = @{ type = 'integer'; minimum = 0; maximum = 365; default = 14 }
                billing_schedule = @{ type = 'object'; description = 'For termly mode: {terms:[{key,label,start,end}]}.'; additionalProperties = $true }
                adopt_legacy = @{ type = 'boolean'; default = $false }; notify_hirer = @{ type = 'boolean'; default = $false }
            }; required = @('series_ref', 'expected_version', 'idempotency_key', 'billing_mode', 'billing_treatment', 'payment_method'); additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $false; destructiveHint = $true; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'update_series_state'
        title = 'Pause, resume or cancel recurring series'
        description = 'Change series state with optimistic concurrency and a stable idempotency key. Cancellation may be future-only or entire-series; email is opt-in.'
        inputSchema = @{
            type = 'object'; properties = @{
                series_ref = @{ type = 'string' }; operation = @{ type = 'string'; enum = @('pause', 'resume', 'cancel', 'extend') }
                scope = @{ type = 'string'; enum = @('future', 'all'); default = 'future' }; expected_status = @{ type = 'string' }
                repeat_until = @{ type = 'string'; description = 'Required for extend; within one calendar year of the original start.' }
                expected_version = @{ type = 'integer'; minimum = 1 }; idempotency_key = @{ type = 'string'; minLength = 8; maxLength = 128 }
                notify_hirer = @{ type = 'boolean'; default = $false }
            }; required = @('series_ref', 'operation', 'expected_status', 'expected_version', 'idempotency_key'); additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $false; destructiveHint = $true; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'list_invoices'
        title = 'List consolidated invoices'
        description = 'List safe invoice summaries without payment tokens or idempotency hashes.'
        inputSchema = @{ type = 'object'; properties = @{ status = @{ type = 'string' }; series_ref = @{ type = 'string' }; limit = @{ type = 'integer'; minimum = 1; maximum = 500; default = 100 } }; additionalProperties = $false }
        annotations = @{ readOnlyHint = $true; destructiveHint = $false; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'get_invoice'
        title = 'Get consolidated invoice'
        description = 'Read an invoice with line items and safe payment transaction history. Bearer tokens and internal hashes are excluded.'
        inputSchema = @{ type = 'object'; properties = @{ invoice_ref = @{ type = 'string' } }; required = @('invoice_ref'); additionalProperties = $false }
        annotations = @{ readOnlyHint = $true; destructiveHint = $false; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'record_invoice_payment'
        title = 'Record offline invoice payment'
        description = 'Record a capability-protected partial or full offline payment using exact minor units, expected invoice version and an idempotency key.'
        inputSchema = @{
            type = 'object'; properties = @{
                invoice_ref = @{ type = 'string' }; amount_minor = @{ type = 'string'; pattern = '^[0-9]+$' }
                expected_version = @{ type = 'integer'; minimum = 1 }; idempotency_key = @{ type = 'string'; minLength = 8; maxLength = 128 }; note = @{ type = 'string' }
            }; required = @('invoice_ref', 'amount_minor', 'expected_version', 'idempotency_key'); additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $false; destructiveHint = $true; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'get_admin_resource'
        title = 'Read an MGF Venue admin resource'
        description = 'Read the same supporting data shown in the MGF Venue admin pages. Stored secrets are returned only as configured/not-configured flags.'
        inputSchema = @{
            type = 'object'
            properties = @{
                resource = @{ type = 'string'; enum = @('capabilities', 'dashboard', 'blocked_dates', 'series', 'requests', 'global_audit', 'configuration', 'email_configuration', 'custom_fields', 'osm_configuration', 'analytics', 'invoice', 'payment_url') }
                ref = @{ type = 'string'; description = 'Booking reference, required for invoice and payment_url.' }
                series_id = @{ type = 'string'; description = 'Series identifier, required for series.' }
                status = @{ type = 'string'; enum = @('pending', 'all'); default = 'pending' }
                search = @{ type = 'string'; description = 'Search reference, action, details or user for global_audit.' }
                limit = @{ type = 'integer'; minimum = 1; maximum = 1000; default = 100 }
                date_from = @{ type = 'string'; pattern = '^\d{4}-\d{2}-\d{2}$'; description = 'Optional report start date for analytics (YYYY-MM-DD).' }
                date_to = @{ type = 'string'; pattern = '^\d{4}-\d{2}-\d{2}$'; description = 'Optional report end date for analytics (YYYY-MM-DD).' }
            }
            required = @('resource')
            additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $true; destructiveHint = $false; idempotentHint = $true; openWorldHint = $true }
    },
    @{
        name = 'run_admin_action'
        title = 'Run an MGF Venue admin action'
        description = 'Run an allow-listed action using the same handler as the MGF Venue web admin. This can change bookings, payments, settings or series, send external email, trigger Home Assistant, or permanently delete data. Inspect current state and obtain explicit user authorization before calling.'
        inputSchema = @{
            type = 'object'
            properties = @{
                action = @{
                    type = 'string'
                    enum = @(
                        'update_status', 'delete_booking', 'mark_refunded', 'mark_deposit_paid',
                        'undo_deposit', 'restore_booking', 'resend_access', 'send_feedback_request',
                        'create_scout_recurring', 'save_settings', 'test_ha', 'check_update',
                        'archive_past', 'add_blocked', 'delete_blocked', 'clear_expired_blocks',
                        'update_series_status', 'resend_series_confirmation', 'record_invoice_manual_payment',
                        'configure_series_billing', 'pause_series', 'catch_up_series_billing', 'extend_external_series',
                        'cancel_scout_series', 'edit_scout_series',
                        'extend_scout_series', 'reopen_scout_series', 'delete_scout_series',
                        'save_admin_notes', 'chase_payment', 'save_email_settings',
                        'save_custom_fields', 'edit_booking', 'approve_request', 'reject_request',
                        'bulk_action', 'save_osm_settings', 'test_osm_connection', 'osm_get_sections',
                        'osm_discover', 'osm_sync_woopayments', 'osm_retry_event', 'osm_resolve_event'
                    )
                }
                arguments = @{ type = 'object'; description = 'Arguments expected by the matching MGF Venue admin action.'; additionalProperties = $true }
            }
            required = @('action', 'arguments')
            additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $false; destructiveHint = $true; idempotentHint = $false; openWorldHint = $true }
    },
    @{
        name = 'export_admin_data'
        title = 'Export MGF Venue admin data'
        description = 'Run the same CSV or accounting export as the Analytics/Bookings admin pages and save it to an explicit local .csv path.'
        inputSchema = @{
            type = 'object'
            properties = @{
                export = @{ type = 'string'; enum = @('export_csv', 'export_accounting') }
                output_path = @{ type = 'string'; description = 'Absolute local path ending in .csv.' }
                arguments = @{ type = 'object'; description = 'Export filters such as format, date_from and date_to.'; additionalProperties = $true }
            }
            required = @('export', 'output_path', 'arguments')
            additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $false; destructiveHint = $false; idempotentHint = $false; openWorldHint = $true }
    }
)

function Invoke-MgfVenueTool {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [AllowNull()][object]$Arguments
    )

    switch ($Name) {
        'list_bookings' {
            $queryValues = @{}
            foreach ($key in @('status', 'date_from', 'date_to', 'search', 'orderby', 'order', 'limit', 'offset', 'exclude_archived', 'exclude_scout', 'scout_only')) {
                $value = Get-PropertyValue $Arguments $key $null
                if ($null -ne $value) { $queryValues[$key] = $value }
            }
            $query = ConvertTo-QueryString $queryValues
            $path = '/admin/bookings'
            if ($query) { $path += "?${query}" }
            return Invoke-VenueApi -Method GET -Path $path
        }
        'create_booking' {
            return Invoke-VenueApi -Method POST -Path '/admin/bookings/create' -Body $Arguments
        }
        'get_booking' {
            $ref = [Uri]::EscapeDataString(([string](Get-PropertyValue $Arguments 'ref' '')).ToUpperInvariant())
            return Invoke-VenueApi -Method GET -Path "/admin/bookings/${ref}"
        }
        'check_availability' {
            $queryValues = @{}
            foreach ($key in @('space', 'date', 'start_time', 'end_time', 'all_day', 'exclude_ref')) {
                $value = Get-PropertyValue $Arguments $key $null
                if ($null -ne $value) { $queryValues[$key] = $value }
            }
            $query = ConvertTo-QueryString $queryValues
            return Invoke-VenueApi -Method GET -Path "/admin/availability?${query}"
        }
        'get_booking_audit' {
            $ref = [Uri]::EscapeDataString(([string](Get-PropertyValue $Arguments 'ref' '')).ToUpperInvariant())
            return Invoke-VenueApi -Method GET -Path "/admin/bookings/${ref}/audit"
        }
        'set_booking_status' {
            $ref = [Uri]::EscapeDataString(([string](Get-PropertyValue $Arguments 'ref' '')).ToUpperInvariant())
            $body = @{
                status = Get-PropertyValue $Arguments 'status' ''
                expected_status = Get-PropertyValue $Arguments 'expected_status' ''
                idempotency_key = Get-PropertyValue $Arguments 'idempotency_key' ''
                notify_hirer = [bool](Get-PropertyValue $Arguments 'notify_hirer' $false)
                reason = Get-PropertyValue $Arguments 'reason' ''
            }
            return Invoke-VenueApi -Method POST -Path "/admin/bookings/${ref}/status" -Body $body
        }
        'update_admin_notes' {
            $ref = [Uri]::EscapeDataString(([string](Get-PropertyValue $Arguments 'ref' '')).ToUpperInvariant())
            $body = @{ notes = Get-PropertyValue $Arguments 'notes' '' }
            return Invoke-VenueApi -Method POST -Path "/admin/bookings/${ref}/notes" -Body $body
        }
        'list_series' {
            $queryValues = @{}
            foreach ($key in @('status', 'search', 'series_kind', 'limit')) { $value = Get-PropertyValue $Arguments $key $null; if ($null -ne $value) { $queryValues[$key] = $value } }
            $query = ConvertTo-QueryString $queryValues
            $path = '/admin/series'; if ($query) { $path += "?${query}" }
            return Invoke-VenueApi -Method GET -Path $path
        }
        'get_series' {
            $seriesRef = [Uri]::EscapeDataString(([string](Get-PropertyValue $Arguments 'series_ref' '')).ToUpperInvariant())
            return Invoke-VenueApi -Method GET -Path "/admin/series/${seriesRef}"
        }
        'approve_series' {
            $seriesRef = [Uri]::EscapeDataString(([string](Get-PropertyValue $Arguments 'series_ref' '')).ToUpperInvariant())
            return Invoke-VenueApi -Method POST -Path "/admin/series/${seriesRef}/approve" -Body $Arguments
        }
        'configure_series_billing' {
            $seriesRef = [Uri]::EscapeDataString(([string](Get-PropertyValue $Arguments 'series_ref' '')).ToUpperInvariant())
            return Invoke-VenueApi -Method POST -Path "/admin/series/${seriesRef}/billing" -Body $Arguments
        }
        'update_series_state' {
            $seriesRef = [Uri]::EscapeDataString(([string](Get-PropertyValue $Arguments 'series_ref' '')).ToUpperInvariant())
            return Invoke-VenueApi -Method POST -Path "/admin/series/${seriesRef}/state" -Body $Arguments
        }
        'list_invoices' {
            $queryValues = @{}
            foreach ($key in @('status', 'series_ref', 'limit')) { $value = Get-PropertyValue $Arguments $key $null; if ($null -ne $value) { $queryValues[$key] = $value } }
            $query = ConvertTo-QueryString $queryValues
            $path = '/admin/invoices'; if ($query) { $path += "?${query}" }
            return Invoke-VenueApi -Method GET -Path $path
        }
        'get_invoice' {
            $invoiceRef = [Uri]::EscapeDataString(([string](Get-PropertyValue $Arguments 'invoice_ref' '')).ToUpperInvariant())
            return Invoke-VenueApi -Method GET -Path "/admin/invoices/${invoiceRef}"
        }
        'record_invoice_payment' {
            $invoiceRef = [Uri]::EscapeDataString(([string](Get-PropertyValue $Arguments 'invoice_ref' '')).ToUpperInvariant())
            return Invoke-VenueApi -Method POST -Path "/admin/invoices/${invoiceRef}/payments" -Body $Arguments
        }
        'get_admin_resource' {
            $resource = [string](Get-PropertyValue $Arguments 'resource' '')
            switch ($resource) {
                'capabilities' { return Invoke-VenueApi -Method GET -Path '/admin/capabilities' }
                'dashboard' { return Invoke-VenueApi -Method GET -Path '/admin/dashboard' }
                'blocked_dates' { return Invoke-VenueApi -Method GET -Path '/admin/blocked-dates' }
                'series' {
                    $seriesId = [Uri]::EscapeDataString(([string](Get-PropertyValue $Arguments 'series_id' '')).ToUpperInvariant())
                    return Invoke-VenueApi -Method GET -Path "/admin/series/${seriesId}"
                }
                'requests' {
                    $query = ConvertTo-QueryString @{
                        status = Get-PropertyValue $Arguments 'status' 'pending'
                        limit = Get-PropertyValue $Arguments 'limit' 100
                    }
                    return Invoke-VenueApi -Method GET -Path "/admin/requests?${query}"
                }
                'global_audit' {
                    $query = ConvertTo-QueryString @{
                        search = Get-PropertyValue $Arguments 'search' ''
                        limit = Get-PropertyValue $Arguments 'limit' 200
                    }
                    return Invoke-VenueApi -Method GET -Path "/admin/audit?${query}"
                }
                'configuration' { return Invoke-VenueApi -Method GET -Path '/admin/configuration' }
                'email_configuration' { return Invoke-VenueApi -Method GET -Path '/admin/email-configuration' }
                'custom_fields' { return Invoke-VenueApi -Method GET -Path '/admin/custom-fields' }
                'osm_configuration' { return Invoke-VenueApi -Method GET -Path '/admin/osm-configuration' }
                'analytics' {
                    $queryValues = @{}
                    $dateFrom = Get-PropertyValue $Arguments 'date_from' $null
                    $dateTo = Get-PropertyValue $Arguments 'date_to' $null
                    if ($null -ne $dateFrom -and [string]$dateFrom -ne '') { $queryValues['report_from'] = [string]$dateFrom }
                    if ($null -ne $dateTo -and [string]$dateTo -ne '') { $queryValues['report_to'] = [string]$dateTo }
                    $query = ConvertTo-QueryString $queryValues
                    $analyticsPath = '/admin/analytics'
                    if ($query) { $analyticsPath = "/admin/analytics?${query}" }
                    return Invoke-VenueApi -Method GET -Path $analyticsPath
                }
                'invoice' {
                    return Invoke-VenueApi -Method POST -Path '/admin/actions/get_invoice' -Body @{ ref = Get-PropertyValue $Arguments 'ref' '' }
                }
                'payment_url' {
                    $ref = [Uri]::EscapeDataString(([string](Get-PropertyValue $Arguments 'ref' '')).ToUpperInvariant())
                    return Invoke-VenueApi -Method GET -Path "/bookings/${ref}/payment-url"
                }
                default { throw "Unknown admin resource: ${resource}" }
            }
        }
        'run_admin_action' {
            $action = [string](Get-PropertyValue $Arguments 'action' '')
            $actionArguments = Get-PropertyValue $Arguments 'arguments' @{}
            return Invoke-VenueApi -Method POST -Path "/admin/actions/${action}" -Body $actionArguments
        }
        'export_admin_data' {
            $export = [string](Get-PropertyValue $Arguments 'export' '')
            $outputPath = [string](Get-PropertyValue $Arguments 'output_path' '')
            $exportArguments = Get-PropertyValue $Arguments 'arguments' @{}
            return Invoke-VenueDownload -Action $export -Arguments $exportArguments -OutputPath $outputPath
        }
        default {
            throw "Unknown tool: ${Name}"
        }
    }
}

if ($SelfTest) {
    Write-JsonLine @{
        server = $script:ServerName
        version = $script:ServerVersion
        tools = @($script:Tools | ForEach-Object { $_.name })
    }
    exit 0
}

while ($null -ne ($line = [Console]::In.ReadLine())) {
    if ([string]::IsNullOrWhiteSpace($line)) { continue }

    $message = $null
    try {
        $message = $line | ConvertFrom-Json
    } catch {
        Write-JsonRpcError -Id $null -Code -32700 -Message 'Parse error'
        continue
    }

    $id = Get-PropertyValue $message 'id' $null
    $method = [string](Get-PropertyValue $message 'method' '')
    $params = Get-PropertyValue $message 'params' $null

    try {
        switch ($method) {
            'initialize' {
                $requestedProtocol = [string](Get-PropertyValue $params 'protocolVersion' $script:DefaultProtocolVersion)
                $supportedProtocols = @('2024-11-05', '2025-03-26', '2025-06-18')
                if ($supportedProtocols -notcontains $requestedProtocol) { $requestedProtocol = $script:DefaultProtocolVersion }
                Write-JsonRpcResult -Id $id -Result @{
                    protocolVersion = $requestedProtocol
                    capabilities = @{ tools = @{ listChanged = $false } }
                    serverInfo = @{ name = $script:ServerName; version = $script:ServerVersion }
                    instructions = 'Read current state before changing it. Before create_booking, confirm the exact date, time and space, check availability, use a stable idempotency_key, and leave notify_hirer false unless external email was explicitly authorised. For status writes, pass expected_status from the latest read. run_admin_action can send email, trigger Home Assistant, change payments/settings, or permanently delete data, so obtain explicit authorization for the exact action. Treat booking contact details and configuration as private.'
                }
            }
            'notifications/initialized' {
                # Notifications do not receive a JSON-RPC response.
            }
            'ping' {
                Write-JsonRpcResult -Id $id -Result @{}
            }
            'tools/list' {
                Write-JsonRpcResult -Id $id -Result @{ tools = $script:Tools }
            }
            'tools/call' {
                $toolName = [string](Get-PropertyValue $params 'name' '')
                $arguments = Get-PropertyValue $params 'arguments' $null
                try {
                    $toolResult = Invoke-MgfVenueTool -Name $toolName -Arguments $arguments
                    $textResult = $toolResult | ConvertTo-Json -Depth 30
                    Write-JsonRpcResult -Id $id -Result @{
                        content = @(@{ type = 'text'; text = $textResult })
                        structuredContent = $toolResult
                        isError = $false
                    }
                } catch {
                    Write-JsonRpcResult -Id $id -Result @{
                        content = @(@{ type = 'text'; text = $_.Exception.Message })
                        isError = $true
                    }
                }
            }
            default {
                if ($null -ne $id) {
                    Write-JsonRpcError -Id $id -Code -32601 -Message "Method not found: ${method}"
                }
            }
        }
    } catch {
        if ($null -ne $id) {
            Write-JsonRpcError -Id $id -Code -32603 -Message 'Internal error' -Data $_.Exception.Message
        }
    }
}
