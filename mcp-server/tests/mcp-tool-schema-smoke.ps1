$ErrorActionPreference = 'Stop'

$server = Join-Path (Split-Path -Parent $PSScriptRoot) 'mgf-venue-mcp.ps1'
$messages = @(
    @{ jsonrpc = '2.0'; id = 1; method = 'initialize'; params = @{ protocolVersion = '2025-06-18'; capabilities = @{}; clientInfo = @{ name = 'schema-smoke'; version = '1.0' } } },
    @{ jsonrpc = '2.0'; method = 'notifications/initialized'; params = @{} },
    @{ jsonrpc = '2.0'; id = 2; method = 'tools/list'; params = @{} }
)

$lines = $messages | ForEach-Object { $_ | ConvertTo-Json -Depth 12 -Compress }
$powershell = if ( Get-Command powershell.exe -ErrorAction SilentlyContinue ) { 'powershell.exe' } else { 'pwsh' }
$responses = @($lines | & $powershell -NoLogo -NoProfile -ExecutionPolicy RemoteSigned -File $server | ForEach-Object { $_ | ConvertFrom-Json })
$list = $responses | Where-Object { $_.id -eq 2 }
if ( $null -eq $list -or $null -eq $list.result.tools ) { throw 'tools/list did not return tools.' }

$tools = @($list.result.tools)
$healthResource = $tools | Where-Object { $_.name -eq 'get_admin_resource' }
if ($healthResource.inputSchema.properties.resource.enum -notcontains 'health') { throw 'Read-only integration health is not exposed through MCP.' }
foreach ($resource in @('calendar_status','calendar_review')) { if ($healthResource.inputSchema.properties.resource.enum -notcontains $resource) { throw "Missing calendar read $resource." } }
if ( $tools.Count -ne 23 ) { throw "Expected 23 MCP tools; found $($tools.Count)." }

$create = $tools | Where-Object { $_.name -eq 'create_booking' }
if ( $null -eq $create ) { throw 'create_booking tool is missing.' }
foreach ( $required in @('idempotency_key', 'space', 'booking_date', 'purpose') ) {
    if ( $create.inputSchema.required -notcontains $required ) { throw "create_booking is missing required field $required." }
}
if ( $create.annotations.readOnlyHint -ne $false -or $create.annotations.idempotentHint -ne $true ) {
    throw 'create_booking safety annotations are incorrect.'
}
if ( $null -eq $create.inputSchema.properties.pricing_tier ) { throw 'create_booking is missing pricing_tier.' }

$listBookings = $tools | Where-Object { $_.name -eq 'list_bookings' }
if ( $null -eq $listBookings.inputSchema.properties.scout_only ) { throw 'list_bookings is missing scout_only.' }

$setStatus = $tools | Where-Object { $_.name -eq 'set_booking_status' }
if ( $setStatus.inputSchema.required -notcontains 'idempotency_key' ) { throw 'set_booking_status must require idempotency_key.' }

foreach ( $toolName in @('list_series', 'get_series', 'approve_series', 'configure_series_billing', 'update_series_state', 'list_invoices', 'get_invoice', 'record_invoice_payment') ) {
    if ( $null -eq ($tools | Where-Object { $_.name -eq $toolName }) ) { throw "Missing recurring billing tool $toolName." }
}
$listSeries = $tools | Where-Object { $_.name -eq 'list_series' }
if ( $null -eq $listSeries.inputSchema.properties.series_kind ) { throw 'list_series is missing series_kind.' }
foreach ( $kind in @('all', 'external', 'scout') ) {
    if ( $listSeries.inputSchema.properties.series_kind.enum -notcontains $kind ) { throw "list_series series_kind is missing $kind." }
}
foreach ( $toolName in @('approve_series', 'configure_series_billing', 'update_series_state', 'record_invoice_payment') ) {
    $tool = $tools | Where-Object { $_.name -eq $toolName }
    foreach ( $required in @('idempotency_key', 'expected_version') ) {
        if ( $tool.inputSchema.required -notcontains $required ) { throw "$toolName must require $required." }
    }
    if ( $tool.annotations.idempotentHint -ne $true ) { throw "$toolName must be marked idempotent." }
}

$adminResource = $tools | Where-Object { $_.name -eq 'get_admin_resource' }
foreach ( $resource in @('dashboard', 'global_audit') ) {
    if ( $adminResource.inputSchema.properties.resource.enum -notcontains $resource ) {
        throw "get_admin_resource is missing $resource."
    }
}

$chargeWrite = $tools | Where-Object { $_.name -eq 'manage_additional_charge' }
if ($null -eq $chargeWrite -or $chargeWrite.annotations.readOnlyHint -ne $false -or $chargeWrite.annotations.destructiveHint -ne $true) { throw 'Charge writes need explicit write annotations.' }
foreach ($operation in @('create','edit','issue','send','resend','send_credit','set_reminders','discard','credit')) {
    if ($chargeWrite.inputSchema.properties.operation.enum -notcontains $operation) { throw "Missing charge operation $operation." }
}
if ($chargeWrite.inputSchema.required -notcontains 'idempotency_key' -or $chargeWrite.inputSchema.properties.confirm_send.default -ne $false) { throw 'Charge write retry/send safety schema is incomplete.' }
foreach ($toolName in @('list_additional_charges','get_additional_charge','get_additional_charge_document')) {
    $tool = $tools | Where-Object { $_.name -eq $toolName }
    if ($null -eq $tool -or $tool.annotations.readOnlyHint -ne $true) { throw "Charge read tool $toolName is missing or unsafe." }
}
$pdfExport = $tools | Where-Object { $_.name -eq 'export_additional_charge_pdf' }
if ($null -eq $pdfExport -or $pdfExport.inputSchema.required -notcontains 'output_path') { throw 'Charge PDF export must require an explicit path.' }
Write-Output 'MCP_TOOL_SCHEMA_SMOKE_OK: 23 tools'
