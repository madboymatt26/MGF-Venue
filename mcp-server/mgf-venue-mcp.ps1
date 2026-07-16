param(
    [switch]$SelfTest
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$InformationPreference = 'SilentlyContinue'

$script:ServerName = 'mgf-venue'
$script:ServerVersion = '0.1.0'
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
            return Invoke-RestMethod -Method Post -Uri $uri -Headers $headers -ContentType 'application/json; charset=utf-8' -Body $jsonBody
        }
        return Invoke-RestMethod -Method Get -Uri $uri -Headers $headers
    } catch {
        throw (Get-WebErrorMessage $_)
    }
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
            }
            additionalProperties = $false
        }
        annotations = @{ readOnlyHint = $true; destructiveHint = $false; idempotentHint = $true; openWorldHint = $true }
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
                notify_hirer = @{ type = 'boolean'; default = $false }
                reason = @{ type = 'string'; description = 'Cancellation reason included only when notifying the hirer.' }
            }
            required = @('ref', 'status', 'expected_status')
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
            foreach ($key in @('status', 'date_from', 'date_to', 'search', 'orderby', 'order', 'limit', 'offset', 'exclude_archived', 'exclude_scout')) {
                $value = Get-PropertyValue $Arguments $key $null
                if ($null -ne $value) { $queryValues[$key] = $value }
            }
            $query = ConvertTo-QueryString $queryValues
            $path = '/admin/bookings'
            if ($query) { $path += "?${query}" }
            return Invoke-VenueApi -Method GET -Path $path
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
                    instructions = 'Read a booking before changing it. For status writes, pass expected_status from the latest read. Never set notify_hirer=true unless the user explicitly authorized that external email. Treat booking contact details as private and do not copy them outside the booking workflow.'
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
