# ============================================================
# EduPak Rollback Script
# ============================================================
# Restores the app and database to a previous backup.
# Runs ON THE DEVICE in PowerShell.
#
# Usage:
#   .\edupak-rollback.ps1                              # Restore latest backup
#   .\edupak-rollback.ps1 -Timestamp "20260405-134500"  # Specific backup
# ============================================================

param(
    [string]$Timestamp = ""
)

$ErrorActionPreference = "Stop"

# ── Configuration ────────────────────────────────────────────
$XAMPP_ROOT = if (Test-Path "D:\xampp") { "D:\xampp" }
              elseif (Test-Path "C:\xampp") { "C:\xampp" }
              else { throw "XAMPP installation not found" }

$APP_DIR      = "$XAMPP_ROOT\htdocs\Edutek"
$BACKUP_ROOT  = "D:\edupak-backups"
$MYSQL_BIN    = "$XAMPP_ROOT\mysql\bin"
$APACHE_BIN   = "$XAMPP_ROOT\apache\bin\httpd.exe"
$PROTECTED_DIRS = @("videos", "khan", "Wiki", "Kiwix", "images")

# ── Find backup ─────────────────────────────────────────────
if ($Timestamp -eq "") {
    $latest = Get-ChildItem $BACKUP_ROOT -Directory -ErrorAction SilentlyContinue |
              Sort-Object Name -Descending |
              Select-Object -First 1
    if (-not $latest) {
        throw "No backups found in $BACKUP_ROOT"
    }
    $Timestamp = $latest.Name
}

$backupDir = Join-Path $BACKUP_ROOT $Timestamp
if (-not (Test-Path $backupDir)) {
    throw "Backup not found: $backupDir"
}

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  EduPak Rollback" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  Rolling back to: $Timestamp"
Write-Host "  Backup dir:      $backupDir"
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

# ── Step 1: Stop Apache ─────────────────────────────────────
Write-Host "[1/4] Stopping Apache..." -ForegroundColor Yellow
try {
    & $APACHE_BIN -k stop 2>$null
    Start-Sleep -Seconds 3
    Write-Host "  [OK] Apache stopped" -ForegroundColor Green
} catch {
    Write-Warning "Could not stop Apache — may not be running"
}

# ── Step 2: Restore app files ───────────────────────────────
Write-Host "[2/4] Restoring app files..." -ForegroundColor Yellow

# Check for both backup types (full first-run vs incremental)
$appBackup = Join-Path $backupDir "app-full"
if (-not (Test-Path $appBackup)) {
    $appBackup = Join-Path $backupDir "app"
}

if (Test-Path $appBackup) {
    # Remove current app code (NOT content dirs)
    Get-ChildItem $APP_DIR -Force | Where-Object {
        $_.Name -notin $PROTECTED_DIRS
    } | ForEach-Object {
        Remove-Item $_.FullName -Recurse -Force
    }

    # Copy backup files back
    Get-ChildItem $appBackup -Force | ForEach-Object {
        Copy-Item $_.FullName -Destination (Join-Path $APP_DIR $_.Name) -Recurse -Force
    }
    Write-Host "  [OK] App files restored" -ForegroundColor Green
} else {
    Write-Warning "No app backup found in $backupDir"
}

# ── Step 3: Restore database ────────────────────────────────
Write-Host "[3/4] Restoring database..." -ForegroundColor Yellow

$dbDump = Join-Path $backupDir "edupak-db.sql"
$rawDbBackup = Join-Path $backupDir "db-edupak-raw"

if (Test-Path $dbDump) {
    # Restore from SQL dump
    try {
        cmd /c "`"$MYSQL_BIN\mysql.exe`" -u root edupak < `"$dbDump`" 2>&1"
        Write-Host "  [OK] Database restored from SQL dump" -ForegroundColor Green
    } catch {
        Write-Host "  [FAIL] SQL restore failed: $_" -ForegroundColor Red
    }
} elseif (Test-Path $rawDbBackup) {
    # Restore from raw data files — requires MariaDB restart
    Write-Warning "Restoring from raw data files — MariaDB must be restarted"
    Get-Process -Name "mysqld" -ErrorAction SilentlyContinue | Stop-Process -Force
    Start-Sleep -Seconds 3
    $dataDir = "$XAMPP_ROOT\mysql\data\edupak"
    if (Test-Path $dataDir) { Remove-Item $dataDir -Recurse -Force }
    Copy-Item $rawDbBackup -Destination $dataDir -Recurse -Force
    # Restart MariaDB
    Start-Process "$XAMPP_ROOT\mysql\bin\mysqld.exe" -ArgumentList "--defaults-file=`"$XAMPP_ROOT\mysql\bin\my.ini`"" -WindowStyle Hidden
    Start-Sleep -Seconds 5
    Write-Host "  [OK] Database restored from raw files" -ForegroundColor Green
} else {
    Write-Warning "No database backup found in $backupDir"
}

# ── Step 4: Restart Apache ──────────────────────────────────
Write-Host "[4/4] Starting Apache..." -ForegroundColor Yellow
try {
    & $APACHE_BIN -k start 2>$null
    Start-Sleep -Seconds 5
    Write-Host "  [OK] Apache started" -ForegroundColor Green
} catch {
    Write-Warning "Could not start Apache — try XAMPP Control Panel"
}

# ── Verify ───────────────────────────────────────────────────
Write-Host ""
Write-Host "Verifying site..." -ForegroundColor Yellow
try {
    $response = Invoke-WebRequest -Uri "http://localhost/Edutek/" -TimeoutSec 10 -UseBasicParsing
    Write-Host "  Site status: HTTP $($response.StatusCode)" -ForegroundColor Green
} catch {
    Write-Host "  Site status: UNREACHABLE" -ForegroundColor Red
    Write-Warning "Check Apache via XAMPP Control Panel"
}

Write-Host ""
Write-Host "============================================" -ForegroundColor Green
Write-Host "  Rollback complete." -ForegroundColor Green
Write-Host "  Restored to: $Timestamp" -ForegroundColor Green
Write-Host "============================================" -ForegroundColor Green
