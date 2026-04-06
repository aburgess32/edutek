# ============================================================
# EduPak Build Script - Create Update Archive
# ============================================================
# Runs on the OPERATOR'S MACHINE (Windows with Git + PowerShell).
# Packages the repo into a zip archive for transfer to devices.
#
# Usage:
#   .\scripts\edupak-build.ps1
#   .\scripts\edupak-build.ps1 -OutputDir "E:\updates"
#   .\scripts\edupak-build.ps1 -SkipPull
# ============================================================

param(
    [string]$OutputDir = "",
    [switch]$SkipPull
)

$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Split-Path -Parent $scriptDir
$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$archiveName = "edupak-update-$timestamp.zip"

if ($OutputDir -eq "") { $OutputDir = Join-Path $projectRoot "dist" }
if (-not (Test-Path $OutputDir)) { New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null }

$archivePath = Join-Path $OutputDir $archiveName

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  EduPak Build Script" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Git pull
if (-not $SkipPull) {
    Write-Host "[1/4] Pulling latest from origin..." -ForegroundColor Yellow
    Set-Location $projectRoot
    git pull origin master 2>&1
    if ($LASTEXITCODE -ne 0) { Write-Warning "Git pull failed - building from current state" }
} else {
    Write-Host "[1/4] Skipping git pull" -ForegroundColor Yellow
}

# Step 2: Gather commit info
Write-Host "[2/4] Gathering build info..." -ForegroundColor Yellow
Set-Location $projectRoot
$commitHash = git rev-parse --short HEAD 2>$null
if (-not $commitHash) { $commitHash = "unknown" }
$commitBranch = git rev-parse --abbrev-ref HEAD 2>$null
if (-not $commitBranch) { $commitBranch = "unknown" }

# Step 3: Stage files
Write-Host "[3/4] Staging files..." -ForegroundColor Yellow
$stagingDir = Join-Path $env:TEMP "edupak-build-$timestamp"
New-Item -ItemType Directory -Path $stagingDir -Force | Out-Null

$appDirs = @("api", "includes", "css", "js", "admin", "assets", "fonts", "vendor", "ajax", "config")
$htdocsSource = Join-Path $projectRoot "htdocs"
$htdocsStaging = Join-Path $stagingDir "htdocs"
New-Item -ItemType Directory -Path $htdocsStaging -Force | Out-Null

foreach ($dir in $appDirs) {
    $src = Join-Path $htdocsSource $dir
    if (Test-Path $src) { Copy-Item $src -Destination (Join-Path $htdocsStaging $dir) -Recurse -Force }
}

# Root PHP files
Get-ChildItem (Join-Path $htdocsSource "*.php") -File -ErrorAction SilentlyContinue | ForEach-Object {
    Copy-Item $_.FullName -Destination $htdocsStaging -Force
}

# .htaccess
$htaccess = Join-Path $htdocsSource ".htaccess"
if (Test-Path $htaccess) { Copy-Item $htaccess -Destination $htdocsStaging -Force }

# Database migrations
$dbSource = Join-Path $projectRoot "db"
if (Test-Path $dbSource) { Copy-Item $dbSource -Destination (Join-Path $stagingDir "db") -Recurse -Force }

# Scripts
$scriptsStaging = Join-Path $stagingDir "scripts"
New-Item -ItemType Directory -Path $scriptsStaging -Force | Out-Null
$scriptFiles = @("migrate.php", "content-indexer.php", "edupak-update.ps1", "edupak-rollback.ps1", "edupak-diagnose.ps1")
foreach ($sf in $scriptFiles) {
    $src = Join-Path $scriptDir $sf
    if (Test-Path $src) { Copy-Item $src -Destination $scriptsStaging -Force }
}

# .env.example
$envExample = Join-Path $projectRoot ".env.example"
if (Test-Path $envExample) { Copy-Item $envExample -Destination $stagingDir -Force }

# Build info file
$buildTs = Get-Date -Format "yyyy-MM-ddTHH:mm:ssZ"
$buildInfoText = "EduPak Build`n============`nArchive    : $archiveName`nBuilt at   : $buildTs`nGit commit : $commitHash`nGit branch : $commitBranch`nBuilder    : $env:USERNAME@$env:COMPUTERNAME"
$buildInfoText | Out-File -FilePath (Join-Path $stagingDir "BUILD_INFO.txt") -Encoding UTF8
Copy-Item (Join-Path $stagingDir "BUILD_INFO.txt") -Destination (Join-Path $htdocsStaging "BUILD_INFO.txt") -Force

# Safety check
$totalSize = (Get-ChildItem $stagingDir -Recurse -File | Measure-Object -Property Length -Sum).Sum
$totalSizeMB = [math]::Round($totalSize / 1MB, 1)
if ($totalSizeMB -gt 100) {
    Write-Error "Staged files are ${totalSizeMB}MB - content directories may have been included. Aborting."
    Remove-Item $stagingDir -Recurse -Force
    exit 1
}

# Step 4: Create archive
Write-Host "[4/4] Creating archive..." -ForegroundColor Yellow
Compress-Archive -Path "$stagingDir\*" -DestinationPath $archivePath -Force
Remove-Item $stagingDir -Recurse -Force

$archiveSize = [math]::Round((Get-Item $archivePath).Length / 1MB, 1)

Write-Host ""
Write-Host "============================================" -ForegroundColor Green
Write-Host "  Build complete!" -ForegroundColor Green
Write-Host "  Archive: $archivePath" -ForegroundColor Green
Write-Host "  Size:    ${archiveSize}MB" -ForegroundColor Green
Write-Host "  Commit:  $commitHash ($commitBranch)" -ForegroundColor Green
Write-Host "============================================" -ForegroundColor Green
Write-Host ""
Write-Host "Next: Copy $archiveName to a USB drive and run edupak-update.ps1 on the device."
