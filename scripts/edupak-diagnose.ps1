# ============================================================
# EduPak Diagnostic Suite
# ============================================================
# Tests all critical functionality after an update.
# Returns pass/fail for each test with details.
# Runs ON THE DEVICE in PowerShell.
#
# Usage:
#   .\edupak-diagnose.ps1
#
# Exit codes:
#   0 — All tests passed
#   1 — One or more tests failed
# ============================================================

$ErrorActionPreference = "Continue"

$baseUrl = "http://localhost/Edutek"
$results = @()

function Test-Endpoint {
    param(
        [string]$Name,
        [string]$Url,
        [int]$ExpectCode = 200,
        [string]$ExpectContains = ""
    )
    try {
        $response = Invoke-WebRequest -Uri $Url -TimeoutSec 15 -UseBasicParsing
        $codeOk = $response.StatusCode -eq $ExpectCode
        $contentOk = if ($ExpectContains -ne "") {
            $response.Content -match $ExpectContains
        } else {
            $true
        }
        $status = if ($codeOk -and $contentOk) { "PASS" } else { "FAIL" }
        return [PSCustomObject]@{
            Test   = $Name
            Status = $status
            HTTP   = $response.StatusCode
            Detail = ""
        }
    } catch {
        return [PSCustomObject]@{
            Test   = $Name
            Status = "FAIL"
            HTTP   = "ERR"
            Detail = $_.Exception.Message
        }
    }
}

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  EduPak Diagnostic Suite" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

# ── Core pages ───────────────────────────────────────────────
$results += Test-Endpoint -Name "Homepage" -Url "$baseUrl/" -ExpectContains "EduPak"
$results += Test-Endpoint -Name "Login page" -Url "$baseUrl/find-user.php"
$results += Test-Endpoint -Name "Browse" -Url "$baseUrl/browse.php"

# ── API endpoints ────────────────────────────────────────────
$results += Test-Endpoint -Name "Search API" -Url "$baseUrl/api/search.php?q=math"
$results += Test-Endpoint -Name "Health check" -Url "$baseUrl/api/health-check.php" -ExpectContains "ok"

# ── Content pages ────────────────────────────────────────────
$results += Test-Endpoint -Name "Watch page" -Url "$baseUrl/watch.php"
$results += Test-Endpoint -Name "Audiobooks" -Url "$baseUrl/audiobooks.php"
$results += Test-Endpoint -Name "Khan Academy" -Url "$baseUrl/khan/index.html"
$results += Test-Endpoint -Name "Wikipedia" -Url "$baseUrl/Wiki/index.html"
$results += Test-Endpoint -Name "Kiwix" -Url "$baseUrl/Kiwix/"

# ── Teacher features ─────────────────────────────────────────
$results += Test-Endpoint -Name "Teacher dashboard" -Url "$baseUrl/api/teacher/dashboard.php"

# ── Database checks (via health endpoint) ────────────────────
$results += Test-Endpoint -Name "DB connection" -Url "$baseUrl/api/health-check.php" -ExpectContains "database.*ok"

# ── File system checks ───────────────────────────────────────
$XAMPP_ROOT = if (Test-Path "D:\xampp") { "D:\xampp" } elseif (Test-Path "C:\xampp") { "C:\xampp" } else { "" }
$APP_DIR = "$XAMPP_ROOT\htdocs\Edutek"

# Check .env exists
$envCheck = if (Test-Path "$APP_DIR\.env") { "PASS" } else { "FAIL" }
$results += [PSCustomObject]@{ Test = ".env file exists"; Status = $envCheck; HTTP = "N/A"; Detail = "" }

# Check content directories
$contentDirs = @("videos", "khan", "Wiki", "Kiwix")
foreach ($dir in $contentDirs) {
    $dirPath = Join-Path $APP_DIR $dir
    $dirStatus = if (Test-Path $dirPath) { "PASS" } else { "FAIL" }
    $results += [PSCustomObject]@{ Test = "Content dir: $dir"; Status = $dirStatus; HTTP = "N/A"; Detail = "" }
}

# Check MariaDB CLI
$mysqlStatus = "FAIL"
$mysqlDetail = ""
try {
    $mysqlTest = cmd /c "`"$XAMPP_ROOT\mysql\bin\mysql.exe`" -u root -e `"SELECT 1`" 2>&1"
    if ($mysqlTest -match "1") { $mysqlStatus = "PASS" }
    else { $mysqlDetail = "mysql.exe did not return expected result" }
} catch {
    $mysqlDetail = $_.Exception.Message
}
$results += [PSCustomObject]@{ Test = "MariaDB CLI"; Status = $mysqlStatus; HTTP = "N/A"; Detail = $mysqlDetail }

# ── Results ──────────────────────────────────────────────────
Write-Host ""
$results | Format-Table -AutoSize -Property Test, Status, HTTP, Detail

$passed = ($results | Where-Object { $_.Status -eq "PASS" }).Count
$total = $results.Count

Write-Host ""
Write-Host "Results: $passed / $total passed" -ForegroundColor $(if ($passed -eq $total) { "Green" } else { "Yellow" })

if ($passed -lt $total) {
    Write-Host ""
    Write-Warning "SOME TESTS FAILED — review results above before proceeding"
    $failed = $results | Where-Object { $_.Status -eq "FAIL" }
    Write-Host "Failed tests:" -ForegroundColor Red
    foreach ($f in $failed) {
        Write-Host "  - $($f.Test)$(if ($f.Detail) { ': ' + $f.Detail })" -ForegroundColor Red
    }
    exit 1
} else {
    Write-Host "All tests passed." -ForegroundColor Green
    exit 0
}
