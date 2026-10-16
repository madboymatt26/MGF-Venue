$ErrorActionPreference = 'Stop'
$server = Join-Path (Split-Path -Parent $PSScriptRoot) 'mgf-venue-mcp.ps1'
$parseErrors = $null; $tokens = $null
$ast = [Management.Automation.Language.Parser]::ParseFile($server,[ref]$tokens,[ref]$parseErrors)
if ($parseErrors.Count) { throw 'MCP bridge has parser errors.' }
# Import only function definitions: never start the transport loop or use credentials.
$ast.FindAll({param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst]},$true) | ForEach-Object { Invoke-Expression $_.Extent.Text }
$script:calls = @()
function Invoke-VenueApi {
    param($Method,$Path,$Body)
    $script:calls += @{ method=$Method; path=$Path; body=$Body }
    if ($Path -match 'format=pdf') { return @{pdf_base64=[Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes('%PDF-synthetic'));document_ref='INV-SYNTHETIC';document_id=42} }
    return @{ok=$true}
}
Invoke-MgfVenueTool -Name list_additional_charges -Arguments ([pscustomobject]@{related_ref='MBS-SYNTHETIC';limit=1;offset=2}) | Out-Null
if ($script:calls[-1].method -ne 'GET' -or $script:calls[-1].path -notmatch 'related_ref=MBS-SYNTHETIC') { throw 'List routing failed.' }
Invoke-MgfVenueTool -Name list_additional_charges -Arguments ([pscustomobject]@{archive_view='archived'}) | Out-Null
if ($script:calls[-1].path -notmatch 'archive_view=archived') { throw 'Archive filter routing failed.' }
foreach($op in @('cancel','archive','restore')) {
    Invoke-MgfVenueTool -Name manage_additional_charge -Arguments ([pscustomobject]@{operation=$op;invoice_ref='INV-SYNTHETIC';expected_version=2;reason='Synthetic';idempotency_key=('synthetic-'+$op)}) | Out-Null
    if ($script:calls[-1].body.operation -ne $op) { throw 'Archive/cancellation routing changed request.' }
}
Invoke-MgfVenueTool -Name manage_additional_charge -Arguments ([pscustomobject]@{operation='issue';invoice_ref='INV-SYNTHETIC';expected_version=2;idempotency_key='synthetic-request'}) | Out-Null
if ($script:calls[-1].method -ne 'POST' -or $script:calls[-1].body.operation -ne 'issue') { throw 'Mutation routing changed request.' }
Invoke-MgfVenueTool -Name get_additional_charge_document -Arguments ([pscustomobject]@{invoice_ref='INV-SYNTHETIC';document_ref='CN-SYNTHETIC'}) | Out-Null
if ($script:calls[-1].path -notmatch 'document_ref=CN-SYNTHETIC' -or $script:calls[-1].path -notmatch 'format=html') { throw 'Credit document routing failed.' }
$testDir = Join-Path ([IO.Path]::GetTempPath()) ('mgf-charge-dispatch-' + [Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($testDir) | Out-Null
$pdf = Join-Path $testDir 'synthetic.pdf'
try {
    $result = Invoke-MgfVenueTool -Name export_additional_charge_pdf -Arguments ([pscustomobject]@{invoice_ref='INV-SYNTHETIC';output_path=$pdf})
    if (-not [IO.File]::Exists($pdf) -or $result.bytes -ne 14 -or $result.sha256.Length -ne 64 -or $result.ContainsKey('pdf_base64')) { throw 'PDF export result is unsafe or incomplete.' }
    $before = $script:calls.Count; $blocked = $false
    try { Invoke-MgfVenueTool -Name export_additional_charge_pdf -Arguments ([pscustomobject]@{invoice_ref='INV-SYNTHETIC';output_path=$pdf}) } catch { $blocked = $true }
    if (-not $blocked -or $script:calls.Count -ne $before -or [IO.File]::ReadAllText($pdf) -ne '%PDF-synthetic') { throw 'Existing PDF overwrite was not blocked before transport.' }
} finally {
    if ([IO.File]::Exists($pdf)) { [IO.File]::Delete($pdf) }
    [IO.Directory]::Delete($testDir,$false)
}
Write-Output 'ADDITIONAL_CHARGE_DISPATCH_SMOKE_OK: mocked transport, credit HTML, PDF save and no overwrite'
