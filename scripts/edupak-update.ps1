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
#
# Flags:
#   -FirstRun      First-time migration (BLink -> EduPak). Backs up
#                  entire Edutek folder, removes legacy files.
#   -DryRun        Show what would happen without making changes.
#   -SkipBackup    Skip the backup step (DANGEROUS).
#   -SkipMigrate   Skip database migrations.
#   -SkipDiagnose  Skip post-update diagnostic tests.
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

# ── Configuration ────────────────────────────────────────────
# Auto-detect XAMPP root
$XAMPP_ROOT = if (Test-Path "D:\xampp") { "D:\xampp" }
              elseif (Test-Path "C:\xampp") { "C:\xampp" }
              else { throw "XAMPP installation not found at D:\xampp or C:\xampp" }

$APP_DIR      = "$XAMPP_ROOT\htdocs\Edutek"
$BACKUP_ROOT  = "D:\edupak-backups"
$TIMESTAMP    = Get-Date -Format "yyyyMMdd-HHmmss"
$MYSQL_BIN    = "$XAMPP_ROOT\mysql\bin"
$APACHE_BIN   = "$XAMPP_ROOT\apache\bin\httpd.exe"
$PHP_BIN      = "$XAMPP_ROOT\php\php.exe"

# Protected directories — NEVER touched during updates
# Read from .env CONTENT_DIRS if available, otherwise use defaults
$envFile = Join-Path $APP_DIR ".env"
$PROTECTED_DIRS = @("videos", "khan", "Wiki", "Kiwix", "images")
if (Test-Path $envFile) {
    $envContent = Get-Content $envFile -Raw
    if ($envContent -match 'CONTENT_DIRS=(.+)') {
        $customDirs = $Matches[1].Trim().Split(',') | ForEach-Object { $_.Trim() }
        if ($customDirs.Count -gt 0) { $PROTECTED_DIRS = $customDirs }
    }
}

# Protected files — NEVER overwritten
$PROTECTED_FILES = @(".env")

# App directories to update (allowlist)
$UPDATE_DIRS = @("api", "includes", "css", "js", "admin", "assets", "fonts", "vendor", "ajax", "config")

# ── Helpers ──────────────────────────────────────────────────
function Write-Step($num, $total, $msg) {
    Write-Host "[$num/$total] $msg" -ForegroundColor Yellow
}

function Write-Ok($msg) {
    Write-Host "  [OK] $msg" -ForegroundColor Green
}

function Write-Fail($msg) {
    Write-Host "  [FAIL] $msg" -ForegroundColor Red
}

# ── Pre-flight checks ───────────────────────────────────────
Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  EduPak Update Script" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  XAMPP:     $XAMPP_ROOT"
Write-Host "  App dir:   $APP_DIR"
Write-Host "  Archive:   $Archive"
Write-Host "  Mode:      $(if ($FirstRun) { 'First-time migration' } else { 'Update' })"
Write-Host "  Dry run:   $DryRun"
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

$totalSteps = 8

# Step 0: Verify archive
if (-not (Test-Path $Archive)) {
    throw "Archive not found: $Archive"
}

# Verify BUILD_INFO.txt exists in archive
$archiveItems = [System.IO.Compression.ZipFile]::OpenRead((Resolve-Path $Archive).Path)
$hasBuildInfo = $archiveItems.Entries | Where-Object { $_.Name -eq "BUILD_INFO.txt" }
$archiveItems.Dispose()
if (-not $hasBuildInfo) {
    Write-Warning "Archive does not contain BUILD_INFO.txt — may not be a valid EduPak update"
}

if ($DryRun) {
    Write-Host "[DRY RUN] Would perform the following:" -ForegroundColor Magenta
    Write-Host "  1. Stop Apache"
    Write-Host "  2. Back up app files to $BACKUP_ROOT\$TIMESTAMP\"
    Write-Host "  3. Back up edupak database via mysqldump"
    Write-Host "  4. Extract archive and sync app directories: $($UPDATE_DIRS -join ', ')"
    Write-Host "  5. Preserve: $($PROTECTED_DIRS -join ', '), .env"
    Write-Host "  6. Restart Apache"
    Write-Host "  7. Run database migrations"
    Write-Host "  8. Run diagnostic tests"
    if ($FirstRun) {
        Write-Host "  [FirstRun] Back up entire Edutek folder, remove BLink legacy files"
    }
    Write-Host ""
    Write-Host "[DRY RUN] No changes made." -ForegroundColor Magenta
    exit 0
}

# ── Step 1: Pre-flight MariaDB repair ───────────────────────
Write-Step 1 $totalSteps "Pre-flight: checking MariaDB CLI..."

Add-Type -AssemblyName System.IO.Compression.FileSystem

$mysqlTest = $null
try {
    $mysqlTest = cmd /c "`"$MYSQL_BIN\mysql.exe`" -u root -e `"SELECT 1`" 2>&1"
} catch { }

if ($mysqlTest -notmatch "1") {
    Write-Warning "MariaDB CLI not responding — attempting privilege table repair..."
    $backupMysql = "$XAMPP_ROOT\mysql\backup\mysql"
    $dataMysql = "$XAMPP_ROOT\mysql\data\mysql"
    if (Test-Path $backupMysql) {
        # Stop MariaDB
        Get-Process -Name "mysqld" -ErrorAction SilentlyContinue | Stop-Process -Force
        Start-Sleep -Seconds 3

        Copy-Item "$backupMysql\db.frm" -Destination "$dataMysql\db.frm" -Force
        Copy-Item "$backupMysql\db.MAD" -Destination "$dataMysql\db.MAD" -Force
        Copy-Item "$backupMysql\db.MAI" -Destination "$dataMysql\db.MAI" -Force

        # Restart MariaDB via XAMPP
        Start-Process "$XAMPP_ROOT\mysql\bin\mysqld.exe" -ArgumentList "--defaults-file=`"$XAMPP_ROOT\mysql\bin\my.ini`"" -WindowStyle Hidden
        Start-Sleep -Seconds 5

        # Re-test
        $mysqlTest2 = cmd /c "`"$MYSQL_BIN\mysql.exe`" -u root -e `"SELECT 1`" 2>&1"
        if ($mysqlTest2 -match "1") {
            Write-Ok "MariaDB CLI repaired successfully"
        } else {
            Write-Fail "MariaDB CLI still not responding after repair"
            Write-Warning "Continuing — migrations will use HTTP endpoint as fallback"
        }
    } else {
        Write-Warning "No backup mysql directory found at $backupMysql"
    }
} else {
    Write-Ok "MariaDB CLI responding"
}

# ── Step 2: Stop Apache ─────────────────────────────────────
Write-Step 2 $totalSteps "Stopping Apache..."
try {
    & $APACHE_BIN -k stop 2>$null
    Start-Sleep -Seconds 3
    Write-Ok "Apache stopped"
} catch {
    Write-Warning "Could not stop Apache via httpd.exe — it may not be running"
}

# ── Step 3: Backup ──────────────────────────────────────────
Write-Step 3 $totalSteps "Creating backup..."

if (-not $SkipBackup) {
    $backupDir = Join-Path $BACKUP_ROOT $TIMESTAMP
    New-Item -ItemType Directory -Path $backupDir -Force | Out-Null

    if ($FirstRun) {
        # First run: back up entire Edutek folder (except content dirs for speed)
        $appBackup = Join-Path $backupDir "app-full"
        New-Item -ItemType Directory -Path $appBackup -Force | Out-Null
        Get-ChildItem $APP_DIR -Force | Where-Object {
            $_.Name -notin $PROTECTED_DIRS
        } | ForEach-Object {
            Copy-Item $_.FullName -Destination $appBackup -Recurse -Force
        }
        Write-Ok "Full app backup (excluding content) saved"
    } else {
        # Subsequent update: back up app code dirs only
        $appBackup = Join-Path $backupDir "app"
        New-Item -ItemType Directory -Path $appBackup -Force | Out-Null
        Get-ChildItem $APP_DIR -Force | Where-Object {
            $_.Name -notin $PROTECTED_DIRS
        } | ForEach-Object {
            Copy-Item $_.FullName -Destination $appBackup -Recurse -Force
        }
        Write-Ok "App backup saved"
    }

    # Database backup via mysqldump
    $dbDump = Join-Path $backupDir "edupak-db.sql"
    try {
        cmd /c "`"$MYSQL_BIN\mysqldump.exe`" -u root edupak > `"$dbDump`" 2>&1"
        if (Test-Path $dbDump) {
            Write-Ok "Database backup saved to $dbDump"
        }
    } catch {
        Write-Warning "mysqldump failed — backing up raw data files instead"
        $rawDbBackup = Join-Path $backupDir "db-edupak-raw"
        if (Test-Path "$XAMPP_ROOT\mysql\data\edupak") {
            Copy-Item "$XAMPP_ROOT\mysql\data\edupak" -Destination $rawDbBackup -Recurse -Force
        }
    }

    Write-Ok "Backup saved to: $backupDir"
} else {
    Write-Warning "Backup skipped (--SkipBackup)"
}

# ── Step 4: Extract and sync ────────────────────────────────
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

# 4b: Update root PHP files (except protected files)
Get-ChildItem (Join-Path $tempDir "htdocs\*.php") -File -ErrorAction SilentlyContinue | Where-Object {
    $_.Name -notin $PROTECTED_FILES
} | ForEach-Object {
    Copy-Item $_.FullName -Destination (Join-Path $APP_DIR $_.Name) -Force
    Write-Host "    Synced: $($_.Name)" -ForegroundColor Gray
}

# 4c: Update .htaccess
$htaccess = Join-Path $tempDir "htdocs\.htaccess"
if (Test-Path $htaccess) {
    Copy-Item $htaccess -Destination (Join-Path $APP_DIR ".htaccess") -Force
    Write-Host "    Synced: .htaccess" -ForegroundColor Gray
}

# 4d: Copy migration files
$dbSrc = Join-Path $tempDir "db"
if (Test-Path $dbSrc) {
    $dbDst = Join-Path (Split-Path $APP_DIR -Parent) "..\db"
    Copy-Item $dbSrc -Destination $dbDst -Recurse -Force
    Write-Host "    Synced: db/migrations" -ForegroundColor Gray
}

# 4e: Copy scripts
$scriptsSrc = Join-Path $tempDir "scripts"
if (Test-Path $scriptsSrc) {
    $scriptsDst = Join-Path (Split-Path $APP_DIR -Parent) "..\scripts"
    if (-not (Test-Path $scriptsDst)) {
        New-Item -ItemType Directory -Path $scriptsDst -Force | Out-Null
    }
    Get-ChildItem $scriptsSrc -File | ForEach-Object {
        Copy-Item $_.FullName -Destination $scriptsDst -Force
    }
    Write-Host "    Synced: scripts" -ForegroundColor Gray
}

# 4f: First-run — remove BLink legacy files
if ($FirstRun) {
    $legacyFiles = @("Data.php", "functions.php")
    foreach ($lf in $legacyFiles) {
        $legacyPath = Join-Path $APP_DIR $lf
        if (Test-Path $legacyPath) {
            Write-Host "    Removed legacy file: $lf" -ForegroundColor Gray
            Remove-Item $legacyPath -Force
        }
    }
}

# 4g: Ensure .env exists (create from .env.example if missing)
$envPath = Join-Path $APP_DIR ".env"
if (-not (Test-Path $envPath)) {
    $envExampleSrc = Join-Path $tempDir ".env.example"
    if (Test-Path $envExampleSrc) {
        Copy-Item $envExampleSrc -Destination $envPath -Force
        Write-Warning ".env was missing — created from .env.example. Edit it with device-specific values."
    }
}

Write-Ok "File sync complete"

# ── Step 5: Restart Apache ──────────────────────────────────
Write-Step 5 $totalSteps "Starting Apache..."
try {
    & $APACHE_BIN -k start 2>$null
    Start-Sleep -Seconds 5
    Write-Ok "Apache started"
} catch {
    Write-Warning "Could not start Apache — try starting it from XAMPP Control Panel"
}

# ── Step 6: Run database migrations ─────────────────────────
Write-Step 6 $totalSteps "Running database migrations..."

if (-not $SkipMigrate) {
    # Try CLI first (faster, more reliable)
    $migrateScript = Join-Path (Split-Path $APP_DIR -Parent) "..\scripts\migrate.php"
    $migrateDone = $false

    if (Test-Path $PHP_BIN) {
        try {
            $migrateOutput = & $PHP_BIN $migrateScript up 2>&1
            Write-Host $migrateOutput
            $migrateDone = $true
            Write-Ok "Migrations complete (CLI)"
        } catch {
            Write-Warning "CLI migration failed: $_"
        }
    }

    # Fallback: HTTP endpoint
    if (-not $migrateDone) {
        # Read DEPLOY_SECRET from .env
        $deploySecret = ""
        if (Test-Path $envPath) {
            $envLines = Get-Content $envPath
            foreach ($line in $envLines) {
                if ($line -match '^DEPLOY_SECRET=(.+)$') {
                    $deploySecret = $Matches[1].Trim()
                }
            }
        }
        if ($deploySecret -ne "") {
            try {
                $migrateResult = Invoke-WebRequest -Uri "http://localhost/Edutek/api/migrate-runner.php?action=up&key=$deploySecret" -TimeoutSec 60 -UseBasicParsing
                Write-Host $migrateResult.Content
                Write-Ok "Migrations complete (HTTP)"
            } catch {
                Write-Fail "Migration failed: $_"
            }
        } else {
            Write-Warning "DEPLOY_SECRET not set — skipping HTTP migration fallback"
        }
    }
} else {
    Write-Warning "Migrations skipped (--SkipMigrate)"
}

# ── Step 7: Diagnostic tests ────────────────────────────────
Write-Step 7 $totalSteps "Running diagnostics..."

if (-not $SkipDiagnose) {
    $diagnoseScript = Join-Path $PSScriptRoot "edupak-diagnose.ps1"
    if (Test-Path $diagnoseScript) {
        & $diagnoseScript
    } else {
        Write-Warning "edupak-diagnose.ps1 not found — skipping diagnostics"
    }
} else {
    Write-Warning "Diagnostics skipped (--SkipDiagnose)"
}

# ── Step 8: Cleanup ─────────────────────────────────────────
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
