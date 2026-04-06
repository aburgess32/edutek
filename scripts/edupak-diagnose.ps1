# ============================================================
# EduPak Diagnostic Suite
# ============================================================
# Tests all critical functionality after an update.
# Runs ON THE DEVICE in PowerShell.
#
# Usage:
#   .\edupak-diagnose.ps1
#
# Exit codes:
#   0 = All tests passed
#   1 = One or more tests failed
# ============================================================

$ErrorActionPreference = "Continue"
$baseUrl = "http://localhost/Edutek"
$results = @()

function Test-Endpoint {
    param([string]$Name, [string]$Url, [int]$ExpectCode = 200, [string]$ExpectContains = "")
    try {
        $r = Invoke-WebRequest -Uri $Url -TimeoutSec 15 -UseBasicParsing
        $codeOk = $r.StatusCode -eq $ExpectCode
        $contentOk = $true
        if ($ExpectContains -ne "") { $contentOk = $r.Content -match $ExpectContains }
        $st = if ($codeOk -and $contentOk) { "PASS" } else { "FAIL" }
        return [PSCustomObject]@{ Test = $Name; Status = $st; HTTP = $r.StatusCode; Detail = "" }
    } catch {
        return [PSCustomObject]@{ Test = $Name; Status = "FAIL"; HTTP = "ERR"; Detail = $_.Exception.Message }
    }
}

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  EduPak Diagnostic Suite" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

# Core pages
$results += Test-Endpoint -Name "Homepage" -Url "$baseUrl/"
$results += Test-Endpoint -Name "Login page" -Url "$baseUrl/find-user.php"
$results += Test-Endpoint -Name "Browse" -Url "$baseUrl/browse.php"

# API endpoints
$results += Test-Endpoint -Name "Search API" -Url "$baseUrl/api/search.php?q=math"
$results += Test-Endpoint -Name "Health check" -Url "$baseUrl/api/health-check.php" -ExpectContains "ok"

# Content pages
$results += Test-Endpoint -Name "Watch page" -Url "$baseUrl/watch.php"
$results += Test-Endpoint -Name "Audiobooks" -Url "$baseUrl/audiobooks.php"
$results += Test-Endpoint -Name "Khan Academy" -Url "$baseUrl/khan/index.html"
$results += Test-Endpoint -Name "Wikipedia" -Url "$baseUrl/Wiki/index.html"
$results += Test-Endpoint -Name "Kiwix" -Url "$baseUrl/Kiwix/"

# File system checks
$XR = if (Test-Path "D:\xampp") { "D:\xampp" } elseif (Test-Path "C:\xampp") { "C:\xampp" } else { "" }
$AD = "$XR\htdocs\Edutek"

$envSt = if (Test-Path "$AD\.env") { "PASS" } else { "FAIL" }
$results += [PSCustomObject]@{ Test = ".env file exists"; Status = $envSt; HTTP = "N/A"; Detail = "" }

$biSt = if (Test-Path "$AD\BUILD_INFO.txt") { "PASS" } else { "FAIL" }
$results += [PSCustomObject]@{ Test = "BUILD_INFO.txt"; Status = $biSt; HTTP = "N/A"; Detail = "" }

$contentDirs = @("videos", "khan", "Wiki", "Kiwix")
foreach ($dir in $contentDirs) {
    $dp = Join-Path $AD $dir
    $ds = if (Test-Path $dp) { "PASS" } else { "FAIL" }
    $results += [PSCustomObject]@{ Test = "Content dir: $dir"; Status = $ds; HTTP = "N/A"; Detail = "" }
}

# Disk space check
$dFree = [math]::Round((Get-PSDrive D -ErrorAction SilentlyContinue).Free / 1GB, 1)
$diskSt = if ($dFree -gt 10) { "PASS" } else { "FAIL" }
$diskDet = "${dFree}GB free on D:"
$results += [PSCustomObject]@{ Test = "Disk space (D: >10GB)"; Status = $diskSt; HTTP = "N/A"; Detail = $diskDet }

# MariaDB CLI check
$mysqlSt = "FAIL"
$mysqlDet = ""
try {
    $mt = cmd /c "`"$XR\mysql\bin\mysql.exe`" -u root -e `"SELECT 1`" 2>&1"
    if ($mt -match "1") { $mysqlSt = "PASS" } else { $mysqlDet = "No result" }
} catch { $mysqlDet = $_.Exception.Message }
$results += [PSCustomObject]@{ Test = "MariaDB CLI"; Status = $mysqlSt; HTTP = "N/A"; Detail = $mysqlDet }

# edupak database check
$dbSt = "FAIL"
$dbDet = ""
try {
    $dbt = cmd /c "`"$XR\mysql\bin\mysql.exe`" -u root edupak -e `"SHOW TABLES`" 2>&1"
    if ($LASTEXITCODE -eq 0) { $dbSt = "PASS"; $dbDet = "edupak DB accessible" } else { $dbDet = "edupak DB not found" }
} catch { $dbDet = $_.Exception.Message }
$results += [PSCustomObject]@{ Test = "edupak database"; Status = $dbSt; HTTP = "N/A"; Detail = $dbDet }

# Results
Write-Host ""
$results | Format-Table -AutoSize -Property Test, Status, HTTP, Detail
$passed = ($results | Where-Object { $_.Status -eq "PASS" }).Count
$total = $results.Count

Write-Host ""
$resColor = if ($passed -eq $total) { "Green" } else { "Yellow" }
Write-Host "Results: $passed / $total passed" -ForegroundColor $resColor

if ($passed -lt $total) {
    Write-Host ""
    Write-Warning "SOME TESTS FAILED"
    $failed = $results | Where-Object { $_.Status -eq "FAIL" }
    Write-Host "Failed tests:" -ForegroundColor Red
    foreach ($f in $failed) {
        $det = if ($f.Detail) { ": " + $f.Detail } else { "" }
        Write-Host "  - $($f.Test)$det" -ForegroundColor Red
    }
    exit 1
} else {
    Write-Host "All tests passed." -ForegroundColor Green
    exit 0
}
