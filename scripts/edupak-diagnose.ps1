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
# Auto-detect: if DocumentRoot points to Edutek/, use root; otherwise use /Edutek
$XR = if (Test-Path "D:\xampp") { "D:\xampp" } elseif (Test-Path "C:\xampp") { "C:\xampp" } else { "" }
$httpdConf = "$XR\apache\conf\httpd.conf"
if ((Test-Path $httpdConf) -and (Get-Content $httpdConf -Raw) -match 'DocumentRoot ".*?/Edutek"') {
    $baseUrl = "http://localhost"
} else {
    $baseUrl = "http://localhost/Edutek"
}
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
$results += Test-Endpoint -Name "Kiwix" -Url "$baseUrl/kiwix/"

# File system checks
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


# â”€â”€ External AP (Joowin CF-EW72) checks â”€â”€
$gw = $null
$apSt = "FAIL"; $apDet = ""
try {
    $gw = (Get-NetIPConfiguration -InterfaceAlias "Ethernet" -ErrorAction SilentlyContinue).IPv4DefaultGateway.NextHop
    if ($gw) {
        $ping = Test-Connection -ComputerName $gw -Count 2 -Quiet
        if ($ping) { $apSt = "PASS"; $apDet = "AP at $gw" } else { $apDet = "AP $gw not responding" }
    } else { $apDet = "No Ethernet gateway found" }
} catch { $apDet = $_.Exception.Message }
$results += [PSCustomObject]@{ Test = "AP gateway ping"; Status = $apSt; HTTP = "N/A"; Detail = $apDet }

$apAdminSt = "FAIL"; $apAdminDet = ""
try {
    if ($gw) {
        $r = Invoke-WebRequest -Uri "http://$gw/" -TimeoutSec 5 -UseBasicParsing
        if ($r.StatusCode -eq 200) { $apAdminSt = "PASS"; $apAdminDet = "Admin at http://$gw/" } else { $apAdminDet = "HTTP $($r.StatusCode)" }
    } else { $apAdminDet = "Skipped - no gateway" }
} catch { $apAdminDet = $_.Exception.Message }
$results += [PSCustomObject]@{ Test = "AP admin panel"; Status = $apAdminSt; HTTP = "N/A"; Detail = $apAdminDet }

$netSt = "FAIL"; $netDet = ""
try {
    $ethIP = (Get-NetIPAddress -InterfaceAlias "Ethernet" -AddressFamily IPv4 -ErrorAction SilentlyContinue).IPAddress
    if ($ethIP) {
        $r = Invoke-WebRequest -Uri "http://$ethIP/" -TimeoutSec 10 -UseBasicParsing
        if ($r.StatusCode -eq 200) { $netSt = "PASS"; $netDet = "App at http://$ethIP/" } else { $netDet = "HTTP $($r.StatusCode)" }
    } else { $netDet = "No Ethernet IP found" }
} catch { $netDet = $_.Exception.Message }
$results += [PSCustomObject]@{ Test = "App via network IP"; Status = $netSt; HTTP = "N/A"; Detail = $netDet }

$fwSt = "FAIL"; $fwDet = ""
try {
    $rules = Get-NetFirewallRule -Direction Inbound -Action Allow -Enabled True -ErrorAction SilentlyContinue | Get-NetFirewallPortFilter -ErrorAction SilentlyContinue | Where-Object { $_.LocalPort -eq 80 -or $_.LocalPort -eq "Any" }
    if ($rules) { $fwSt = "PASS"; $fwDet = "Port 80 inbound allowed" } else { $fwDet = "No inbound rule for port 80" }
} catch { $fwDet = $_.Exception.Message }
$results += [PSCustomObject]@{ Test = "Firewall port 80"; Status = $fwSt; HTTP = "N/A"; Detail = $fwDet }

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
