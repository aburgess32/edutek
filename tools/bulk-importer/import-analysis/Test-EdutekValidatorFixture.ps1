[CmdletBinding()]
param([string]$ToolsDirectory='D:\edutek-system\bulk-import-tools')
$ErrorActionPreference='Stop'
$analyzer=Join-Path $ToolsDirectory 'Invoke-EdutekImportAnalysis.ps1'
$validator=Join-Path $ToolsDirectory 'Test-EdutekImportPlan.ps1'
foreach ($file in @($analyzer,$validator)) {if (-not (Test-Path -LiteralPath $file)) {throw "Missing tool: $file"}}
$root=Join-Path 'D:\edutek-system' ('bulk-validator-test-'+[guid]::NewGuid().ToString('N'))
$source=Join-Path $root 'source';$library=Join-Path $root 'library';$reports=Join-Path $root 'reports'
foreach ($folder in @("$source\Math\Algebra","$library\Math\Algebra")) {New-Item -ItemType Directory -Path $folder -Force | Out-Null}
[IO.File]::WriteAllText("$source\Math\Algebra\New.pdf",'new dummy content')
[IO.File]::WriteAllText("$source\Math\Algebra\Existing.pdf",'existing dummy content')
[IO.File]::WriteAllText("$library\Math\Algebra\Existing.pdf",'existing dummy content')
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $analyzer -SourceRoot $source -LibraryRoot $library -ReportRoot $reports
if ($LASTEXITCODE -ne 0) {throw 'Fixture analysis failed.'}
$run=Get-ChildItem -LiteralPath $reports -Directory | Select-Object -First 1
$manifest=Join-Path $run.FullName 'manifest.json'
$plan=Join-Path $run.FullName 'import-plan.csv'
function Check([string]$Name,[int]$Expected) {
 Write-Host "`n=== TEST: $Name ===" -ForegroundColor Cyan
 & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $validator -ManifestPath $manifest -PlanPath $plan
 if ($LASTEXITCODE -ne $Expected) {throw "$Name failed: expected exit $Expected, received $LASTEXITCODE"}
 Write-Host "PASS: $Name" -ForegroundColor Green
}
Check 'All excluded' 0
$rows=@(Import-Csv -LiteralPath $plan)
$new=$rows | Where-Object {$_.source_relative_path -eq 'Math/Algebra/New.pdf'}
$new.include='1';$new.action='COPY'
$rows | Export-Csv -LiteralPath $plan -NoTypeInformation -Encoding UTF8
Check 'Select absent destination' 0
$new.target_relative_path='../escape.pdf'
$rows | Export-Csv -LiteralPath $plan -NoTypeInformation -Encoding UTF8
Check 'Reject traversal' 2
$new.target_relative_path='Math/Algebra/Existing.pdf'
$rows | Export-Csv -LiteralPath $plan -NoTypeInformation -Encoding UTF8
Check 'Reject occupied destination' 2
$new.target_relative_path='Math/Algebra/New.pdf'
$rows | Export-Csv -LiteralPath $plan -NoTypeInformation -Encoding UTF8
[IO.File]::AppendAllText("$source\Math\Algebra\New.pdf",' changed')
Check 'Reject changed source' 2
Write-Host "`nALL FIVE TESTS PASSED. No media imported. Fixture: $root" -ForegroundColor Green
