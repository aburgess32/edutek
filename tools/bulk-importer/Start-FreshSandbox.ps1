[CmdletBinding()]
param([string]$InstallDirectory='D:\edutek-system\bulk-loader-fresh-v05')
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath($InstallDirectory)
$compose=Join-Path $root 'compose.yaml'
$envFile=Join-Path $root '.env'
foreach ($file in @($compose,$envFile)) {if (-not (Test-Path -LiteralPath $file)) {throw "Missing installation file: $file"}}
& docker compose --project-directory $root --env-file $envFile -p edutek-bulk-fresh-v05 -f $compose config --quiet
if ($LASTEXITCODE -ne 0) {throw 'Compose configuration validation failed.'}
& docker compose --project-directory $root --env-file $envFile -p edutek-bulk-fresh-v05 -f $compose up -d --no-build --pull never
if ($LASTEXITCODE -ne 0) {throw 'Sandbox services could not be started.'}
$ready=$false
for ($i=0;$i -lt 20;$i++) {
 Start-Sleep -Seconds 2
 $heartbeat=Join-Path $root 'data\heartbeat.txt'
 $active=(Test-Path -LiteralPath $heartbeat)
 if ($active) {$active=([DateTime]::UtcNow-(Get-Item -LiteralPath $heartbeat).LastWriteTimeUtc).TotalSeconds -lt 12}
 try {
  $response=Invoke-WebRequest -Uri 'http://127.0.0.1:8092/bulk-import-guided.php' -UseBasicParsing -TimeoutSec 3
  if ($response.StatusCode -eq 200 -and $response.Content.Contains('Add learning materials') -and $active) {$ready=$true;break}
 } catch {}
}
& docker compose --project-directory $root --env-file $envFile -p edutek-bulk-fresh-v05 -f $compose ps
if (-not $ready) {
 & docker compose --project-directory $root --env-file $envFile -p edutek-bulk-fresh-v05 -f $compose logs --tail 40 web worker
 throw 'Readiness failed. Do not reinstall or delete the installation; share the output, without any key.'
}
Write-Host "`nPASS: Web page responds and worker heartbeat is current." -ForegroundColor Green
