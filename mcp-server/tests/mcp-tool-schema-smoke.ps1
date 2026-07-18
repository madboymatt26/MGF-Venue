$ErrorActionPreference = 'Stop'

$server = Join-Path (Split-Path -Parent $PSScriptRoot) 'mgf-venue-mcp.ps1'
$messages = @(
    @{ jsonrpc = '2.0'; id = 1; method = 'initialize'; params = @{ protocolVersion = '2025-06-18'; capabilities = @{}; clientInfo = @{ name = 'schema-smoke'; version = '1.0' } } },
    @{ jsonrpc = '2.0'; method = 'notifications/initialized'; params = @{} },
    @{ jsonrpc = '2.0'; id = 2; method = 'tools/list'; params = @{} }
)

$lines = $messages | ForEach-Object { $_ | ConvertTo-Json -Depth 12 -Compress }
$responses = @($lines | & powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File $server | ForEach-Object { $_ | ConvertFrom-Json })
$list = $responses | Where-Object { $_.id -eq 2 }
if ( $null -eq $list -or $null -eq $list.result.tools ) { throw 'tools/list did not return tools.' }

$tools = @($list.result.tools)
if ( $tools.Count -ne 18 ) { throw "Expected 18 MCP tools; found $($tools.Count)." }

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

Write-Output 'MCP_TOOL_SCHEMA_SMOKE_OK: 18 tools'
