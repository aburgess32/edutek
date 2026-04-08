# ============================================================
# EduPak Update Script (Windows / PowerShell)
# ============================================================
# Runs ON THE DEVICE. Applies an update archive built by
# edupak-build.ps1, preserving content directories and .env.
#
# Usage:
#   .\edupak-update.ps1 -Archive "E:\edupak-update-20260405-134500.zip"
#   .\edupak-update.ps1 -Archive ".\edupak-update.zip" -DryRun
#   .\edupak-update.ps1 -Archive ".\edupak-update.zip" -FirstRun
# ============================================================

param(
    [Parameter(Mandatory=$true)]
    [string]$Archive,
    [switch]$FirstRun,
    [switch]$DryRun,
    [switch]$SkipBackup,
    [switch]$SkipMigrate,
    [switch]$SkipDiagnose
)

$ErrorActionPreference = "Stop"

# â”€â”€ Configuration â”€â”€
$XAMPP_ROOT = if (Test-Path "D:\xampp") { "D:\xampp" } elseif (Test-Path "C:\xampp") { "C:\xampp" } else { throw "XAMPP not found" }
$APP_DIR      = "$XAMPP_ROOT\htdocs\Edutek"
$BACKUP_ROOT  = "D:\edupak-backups"
$TIMESTAMP    = Get-Date -Format "yyyyMMdd-HHmmss"
$MYSQL_BIN    = "$XAMPP_ROOT\mysql\bin"
$APACHE_BIN   = "$XAMPP_ROOT\apache\bin\httpd.exe"
$PHP_BIN      = "$XAMPP_ROOT\php\php.exe"

$PROTECTED_DIRS = @("videos", "khan", "Wiki", "Kiwix", "images")
$envFile = Join-Path $APP_DIR ".env"
if (Test-Path $envFile) {
    $envContent = Get-Content $envFile -Raw
    if ($envContent -match 'CONTENT_DIRS=(.+)') {
        $customDirs = $Matches[1].Trim().Split(',') | ForEach-Object { $_.Trim() }
        if ($customDirs.Count -gt 0) { $PROTECTED_DIRS = $customDirs }
    }
}
$PROTECTED_FILES = @(".env")
$UPDATE_DIRS = @("api", "includes", "css", "js", "admin", "assets", "fonts", "vendor", "ajax", "config")

# â”€â”€ Helpers â”€â”€
function Write-Step($num, $total, $msg) { Write-Host "[$num/$total] $msg" -ForegroundColor Yellow }
function Write-Ok($msg) { Write-Host "  [OK] $msg" -ForegroundColor Green }
function Write-Fail($msg) { Write-Host "  [FAIL] $msg" -ForegroundColor Red }

# â”€â”€ Banner â”€â”€
Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  EduPak Update Script" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  XAMPP:     $XAMPP_ROOT"
Write-Host "  App dir:   $APP_DIR"
Write-Host "  Archive:   $Archive"
$modeStr = if ($FirstRun) { "First-time migration" } else { "Update" }
Write-Host "  Mode:      $modeStr"
Write-Host "  Dry run:   $DryRun"
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

$totalSteps = 8

# Step 0: Verify archive
if (-not (Test-Path $Archive)) { throw "Archive not found: $Archive" }

Add-Type -AssemblyName System.IO.Compression.FileSystem

if ($DryRun) {
    Write-Host "[DRY RUN] Would perform the following:" -ForegroundColor Magenta
    Write-Host "  1. Pre-flight MariaDB check"
    Write-Host "  2. Stop Apache"
    Write-Host "  3. Back up app files to $BACKUP_ROOT\$TIMESTAMP\"
    Write-Host "  4. Extract archive and sync: $($UPDATE_DIRS -join ', ')"
    Write-Host "  5. Preserve: $($PROTECTED_DIRS -join ', '), .env"
    Write-Host "  6. Restart Apache"
    Write-Host "  7. Run database migrations"
    Write-Host "  8. Run diagnostic tests"
    if ($FirstRun) { Write-Host "  [FirstRun] Full BLink backup, remove legacy files, create edupak DB" }
    Write-Host ""
    Write-Host "[DRY RUN] No changes made." -ForegroundColor Magenta
    exit 0
}

# â”€â”€ Step 1: Pre-flight MariaDB repair â”€â”€
Write-Step 1 $totalSteps "Pre-flight: checking MariaDB CLI..."

$mysqlTest = $null
try { $mysqlTest = cmd /c "`"$MYSQL_BIN\mysql.exe`" -u root -e `"SELECT 1`" 2>&1" } catch { }

if ($mysqlTest -notmatch "1") {
    Write-Warning "MariaDB CLI not responding - attempting privilege table repair..."
    $backupMysql = "$XAMPP_ROOT\mysql\backup\mysql"
    $dataMysql = "$XAMPP_ROOT\mysql\data\mysql"
    if (Test-Path $backupMysql) {
        Get-Process -Name "mysqld" -ErrorAction SilentlyContinue | Stop-Process -Force
        Start-Sleep -Seconds 3
        Copy-Item "$backupMysql\db.frm" -Destination "$dataMysql\db.frm" -Force
        Copy-Item "$backupMysql\db.MAD" -Destination "$dataMysql\db.MAD" -Force
        Copy-Item "$backupMysql\db.MAI" -Destination "$dataMysql\db.MAI" -Force
        Start-Process "$XAMPP_ROOT\mysql\bin\mysqld.exe" -ArgumentList "--defaults-file=`"$XAMPP_ROOT\mysql\bin\my.ini`"","--standalone" -WindowStyle Hidden
        Start-Sleep -Seconds 5
        $mysqlTest2 = cmd /c "`"$MYSQL_BIN\mysql.exe`" -u root -e `"SELECT 1`" 2>&1"
        if ($mysqlTest2 -match "1") { Write-Ok "MariaDB CLI repaired successfully" }
        else { Write-Fail "MariaDB CLI still not responding after repair"; Write-Warning "Continuing - migrations will use HTTP fallback" }
    } else {
        Write-Warning "No backup mysql directory found at $backupMysql"
    }
} else {
    Write-Ok "MariaDB CLI responding"
}

# â”€â”€ Step 2: Stop Apache â”€â”€
Write-Step 2 $totalSteps "Stopping Apache..."
try {
    Get-Process -Name "httpd" -ErrorAction SilentlyContinue | Stop-Process -Force
    Start-Sleep -Seconds 3
    Write-Ok "Apache stopped"
} catch {
    Write-Warning "Could not stop Apache - it may not be running"
}

# â”€â”€ Step 3: Backup â”€â”€
Write-Step 3 $totalSteps "Creating backup..."

if (-not $SkipBackup) {
    $backupDir = Join-Path $BACKUP_ROOT $TIMESTAMP
    New-Item -ItemType Directory -Path $backupDir -Force | Out-Null

    if ($FirstRun) {
        $appBackup = Join-Path $backupDir "app-full"
        New-Item -ItemType Directory -Path $appBackup -Force | Out-Null
        Get-ChildItem $APP_DIR -Force | Where-Object { $_.Name -notin $PROTECTED_DIRS } | ForEach-Object {
            Copy-Item $_.FullName -Destination $appBackup -Recurse -Force
        }
        Write-Ok "Full app backup (excluding content) saved"
    } else {
        $appBackup = Join-Path $backupDir "app"
        New-Item -ItemType Directory -Path $appBackup -Force | Out-Null
        Get-ChildItem $APP_DIR -Force | Where-Object { $_.Name -notin $PROTECTED_DIRS } | ForEach-Object {
            Copy-Item $_.FullName -Destination $appBackup -Recurse -Force
        }
        Write-Ok "App backup saved"
    }

    # Database backup
    $dbDump = Join-Path $backupDir "all-databases.sql"
    try {
        cmd /c "`"$MYSQL_BIN\mysqldump.exe`" -u root --all-databases > `"$dbDump`" 2>&1"
        if ((Test-Path $dbDump) -and (Get-Item $dbDump).Length -gt 0) {
            Write-Ok "Database backup saved to $dbDump"
        } else {
            Write-Warning "mysqldump produced empty output"
        }
    } catch {
        Write-Warning "mysqldump failed: $_"
    }

    Write-Ok "Backup saved to: $backupDir"
} else {
    Write-Warning "Backup skipped"
}

# â”€â”€ Step 4: Extract and sync â”€â”€
Write-Step 4 $totalSteps "Extracting archive and syncing files..."

$tempDir = Join-Path $env:TEMP "edupak-update-$TIMESTAMP"
Expand-Archive -Path $Archive -DestinationPath $tempDir -Force

# 4a: Update app directories (allowlist only)
foreach ($dir in $UPDATE_DIRS) {
    $src = Join-Path $tempDir "htdocs\$dir"
    $dst = Join-Path $APP_DIR $dir
    if (Test-Path $src) {
        if (Test-Path $dst) { Remove-Item $dst -Recurse -Force }
        Copy-Item $src -Destination $dst -Recurse -Force
        Write-Host "    Synced: $dir" -ForegroundColor Gray
    }
}

# 4b: Update root PHP files (except protected)
$phpFiles = Get-ChildItem (Join-Path $tempDir "htdocs\*.php") -File -ErrorAction SilentlyContinue
if ($phpFiles) {
    $phpFiles | Where-Object { $_.Name -notin $PROTECTED_FILES } | ForEach-Object {
        Copy-Item $_.FullName -Destination (Join-Path $APP_DIR $_.Name) -Force
        Write-Host "    Synced: $($_.Name)" -ForegroundColor Gray
    }
}

# 4c: Update .htaccess
$htaccess = Join-Path $tempDir "htdocs\.htaccess"
if (Test-Path $htaccess) {
    Copy-Item $htaccess -Destination (Join-Path $APP_DIR ".htaccess") -Force
    Write-Host "    Synced: .htaccess" -ForegroundColor Gray
}

# 4d: Copy BUILD_INFO.txt
$buildInfo = Join-Path $tempDir "BUILD_INFO.txt"
if (Test-Path $buildInfo) {
    Copy-Item $buildInfo -Destination (Join-Path $APP_DIR "BUILD_INFO.txt") -Force
    Write-Host "    Synced: BUILD_INFO.txt" -ForegroundColor Gray
}
$buildInfoHtdocs = Join-Path $tempDir "htdocs\BUILD_INFO.txt"
if (Test-Path $buildInfoHtdocs) {
    Copy-Item $buildInfoHtdocs -Destination (Join-Path $APP_DIR "BUILD_INFO.txt") -Force
}

# 4e: First-run - remove BLink legacy files
if ($FirstRun) {
    $legacyFiles = @("Data.php", "functions.php")
    foreach ($lf in $legacyFiles) {
        $legacyPath = Join-Path $APP_DIR $lf
        if (Test-Path $legacyPath) {
            Write-Host "    Removed legacy: $lf" -ForegroundColor Gray
            Remove-Item $legacyPath -Force
        }
    }
}

# 4f: Ensure .env exists
$envPath = Join-Path $APP_DIR ".env"
if (-not (Test-Path $envPath)) {
    $envExampleSrc = Join-Path $tempDir ".env.example"
    if (Test-Path $envExampleSrc) {
        Copy-Item $envExampleSrc -Destination $envPath -Force
        Write-Warning ".env was missing - created from .env.example. EDIT IT with device-specific values!"
    }
}

Write-Ok "File sync complete"

# ── Step 4g: Patch Apache DocumentRoot ──
# Point DocumentRoot to Edutek/ so URLs like /logout.php resolve correctly
# without needing /Edutek/ prefix in the URL path.
$httpdConf = "$XAMPP_ROOT\apache\conf\httpd.conf"
if (Test-Path $httpdConf) {
    $confContent = Get-Content $httpdConf -Raw
    if ($confContent -match 'DocumentRoot "D:/xampp/htdocs"') {
        Copy-Item $httpdConf "$httpdConf.pre-edupak" -Force
        $confContent = $confContent -replace 'DocumentRoot "D:/xampp/htdocs"', 'DocumentRoot "D:/xampp/htdocs/Edutek"'
        $confContent = $confContent -replace '<Directory "D:/xampp/htdocs">', '<Directory "D:/xampp/htdocs/Edutek">'
        [System.IO.File]::WriteAllText($httpdConf, $confContent, (New-Object System.Text.UTF8Encoding $false))
        Write-Ok "Apache DocumentRoot set to Edutek/"
    } elseif ($confContent -match 'DocumentRoot "D:/xampp/htdocs/Edutek"') {
        Write-Ok "Apache DocumentRoot already set to Edutek/"
    } else {
        Write-Warning "Unexpected DocumentRoot in httpd.conf - check manually"
    }
}

# â”€â”€ Step 5: Restart Apache â”€â”€
Write-Step 5 $totalSteps "Starting Apache..."
try {
    Start-Process $APACHE_BIN -WindowStyle Hidden
    Start-Sleep -Seconds 5
    # Verify
    try {
        $httpTest = Invoke-WebRequest -Uri "http://localhost/" -TimeoutSec 10 -UseBasicParsing
        Write-Ok "Apache started (HTTP $($httpTest.StatusCode))"
    } catch {
        Write-Warning "Apache may not be responding yet - check XAMPP Control Panel"
    }
} catch {
    Write-Warning "Could not start Apache - try starting from XAMPP Control Panel"
}

# â”€â”€ Step 6: Database migrations â”€â”€
Write-Step 6 $totalSteps "Running database migrations..."

if (-not $SkipMigrate) {
    # First-run: create edupak database
    if ($FirstRun) {
        Write-Host "    Creating edupak database..." -ForegroundColor Gray
        cmd /c "`"$MYSQL_BIN\mysql.exe`" -u root -e `"CREATE DATABASE IF NOT EXISTS edupak DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`" 2>&1"
        Write-Ok "edupak database created"
    }

    # Try HTTP migration endpoint first
    $migrateDone = $false
    $deploySecret = ""
    if (Test-Path $envPath) {
        $envLines = Get-Content $envPath
        foreach ($line in $envLines) {
            if ($line -match '^DEPLOY_SECRET=(.+)$') { $deploySecret = $Matches[1].Trim() }
        }
    }

    if ($deploySecret -ne "" -and $deploySecret -ne "change-me-to-a-random-string") {
        try {
            $migrateUrl = "http://localhost/Edutek/api/migrate-runner.php?action=up&key=$deploySecret"
            $migrateResult = Invoke-WebRequest -Uri $migrateUrl -TimeoutSec 120 -UseBasicParsing
            Write-Host "    $($migrateResult.Content)" -ForegroundColor Gray
            $migrateDone = $true
            Write-Ok "Migrations complete (HTTP)"
        } catch {
            Write-Warning "HTTP migration failed: $_ - trying CLI..."
        }
    }

    # Fallback: CLI migration
    if (-not $migrateDone) {
        $migrateScript = Join-Path (Split-Path $APP_DIR) "..\scripts\migrate.php"
        if (-not (Test-Path $migrateScript)) {
            $migrateScript = Join-Path $tempDir "scripts\migrate.php"
        }
        if ((Test-Path $PHP_BIN) -and (Test-Path $migrateScript)) {
            try {
                $output = & $PHP_BIN $migrateScript up 2>&1
                Write-Host "    $output" -ForegroundColor Gray
                Write-Ok "Migrations complete (CLI)"
            } catch {
                Write-Fail "CLI migration failed: $_"
            }
        } else {
            Write-Warning "Could not find migrate.php or php.exe - skipping migrations"
        }
    }
} else {
    Write-Warning "Migrations skipped"
}

# â”€â”€ Step 7: Diagnostics â”€â”€
Write-Step 7 $totalSteps "Running diagnostics..."

if (-not $SkipDiagnose) {
    $diagnoseScript = Join-Path $PSScriptRoot "edupak-diagnose.ps1"
    if (-not (Test-Path $diagnoseScript)) {
        $diagnoseScript = Join-Path $tempDir "scripts\edupak-diagnose.ps1"
    }
    if (Test-Path $diagnoseScript) {
        & $diagnoseScript
    } else {
        Write-Warning "edupak-diagnose.ps1 not found - skipping diagnostics"
    }
} else {
    Write-Warning "Diagnostics skipped"
}

# â”€â”€ Step 8: Cleanup â”€â”€
Write-Step 8 $totalSteps "Cleaning up..."
Remove-Item $tempDir -Recurse -Force -ErrorAction SilentlyContinue
Write-Ok "Temp files cleaned"

Write-Host ""
Write-Host "============================================" -ForegroundColor Green
Write-Host "  Update complete!" -ForegroundColor Green
if (-not $SkipBackup) {
    Write-Host "  Backup at: $BACKUP_ROOT\$TIMESTAMP" -ForegroundColor Green
}
Write-Host "  To rollback: .\edupak-rollback.ps1" -ForegroundColor Green
Write-Host "============================================" -ForegroundColor Green
