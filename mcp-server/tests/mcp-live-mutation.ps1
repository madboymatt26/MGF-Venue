$ErrorActionPreference = 'Stop'
$server = Join-Path (Split-Path -Parent $PSScriptRoot) 'mgf-venue-mcp.ps1'
$messages = @(
    @{ jsonrpc='2.0'; id=1; method='initialize'; params=@{ protocolVersion='2025-06-18'; capabilities=@{}; clientInfo=@{name='live-mutation';version='1.0'} } },
    @{ jsonrpc='2.0'; method='notifications/initialized'; params=@{} },
    @{ jsonrpc='2.0'; id=2; method='tools/call'; params=@{ name='run_admin_action'; arguments=@{ action='cancel_scout_series'; arguments=@{series_id='INT-MUT'} } } },
    @{ jsonrpc='2.0'; id=3; method='tools/call'; params=@{ name='get_series'; arguments=@{series_ref='INT-MUT'} } }
)
$lines=$messages|ForEach-Object{$_|ConvertTo-Json -Depth 20 -Compress}
$responses=@($lines|& pwsh -NoLogo -NoProfile -File $server|ForEach-Object{$_|ConvertFrom-Json})
$mutation=$responses|Where-Object{$_.id -eq 2}
$read=$responses|Where-Object{$_.id -eq 3}
$mutationText=if($null -eq $mutation){''}else{[string]$mutation.result.content[0].text}
if($null -eq $mutation -or $mutation.result.isError -ne $true -or $mutationText -notmatch '409'){
    $detail=$mutation|ConvertTo-Json -Depth 20 -Compress
    throw "MCP compatibility mutation did not fail closed (response=${detail})."
}
if($null -eq $read -or $read.result.isError -ne $false -or $read.result.structuredContent.series.status -ne 'confirmed'){
    $detail=$read|ConvertTo-Json -Depth 20 -Compress
    throw "MCP compatibility attempt changed the first-class series (response=${detail})."
}
Write-Output 'MCP_LIVE_MUTATION_OK: authenticated MCP->REST->admin compatibility action failed closed and preserved the series.'
