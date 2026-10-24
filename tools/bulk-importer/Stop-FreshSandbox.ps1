[CmdletBinding()]
param([string]$InstallDirectory='D:\edutek-system\bulk-loader-fresh-v05')
$ErrorActionPreference='Stop'
& docker compose --project-directory $InstallDirectory --env-file (Join-Path $InstallDirectory '.env') -p edutek-bulk-fresh-v05 -f (Join-Path $InstallDirectory 'compose.yaml') stop
if ($LASTEXITCODE -ne 0) {throw 'Sandbox stop failed.'}
Write-Host 'Fresh sandbox stopped. Files and test jobs retained. Main Edutek services were not targeted.'
