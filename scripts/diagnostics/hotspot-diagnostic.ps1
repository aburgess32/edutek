# ============================================================
# EduPak Hotspot & Concurrent Connection Diagnostic
# ============================================================
# Tests the device's ability to serve content to multiple
# mobile devices simultaneously via Windows Mobile Hotspot.
#
# Usage:
#   .\hotspot-diagnostic.ps1
#   .\hotspot-diagnostic.ps1 -StressTest        # simulate concurrent loads
#   .\hotspot-diagnostic.ps1 -MaxClients 20     # test with N concurrent requests
#
# Requires: Run on the EduPak BeeLink device
# ============================================================

param(
    [switch]$StressTest,
    [int]$MaxClients = 20,
    [string]$BaseUrl = "http://localhost/Edutek"
)

$ErrorActionPreference = "Continue"

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  EduPak Hotspot & Connection Diagnostic" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

# ── Section 1: Hotspot Status ──
Write-Host "=== 1. Mobile Hotspot Status ===" -ForegroundColor Yellow
Write-Host ""

# Check if Mobile Hotspot is enabled
$hotspotStatus = "Unknown"
try {
    $tetheringMgr = [Windows.Networking.NetworkOperators.NetworkOperatorTetheringManager, Windows.Networking.NetworkOperators, ContentType=WindowsRuntime]
    # Try to get the tethering manager
    $connProfile = [Windows.Networking.Connectivity.NetworkInformation, Windows.Networking.Connectivity, ContentType=WindowsRuntime]::GetInternetConnectionProfile()
    if ($connProfile) {
        $tethering = $tetheringMgr::CreateFromConnectionProfile($connProfile)
        $hotspotStatus = $tethering.TetheringOperationalState.ToString()
        $clientCount = $tethering.GetTetheringClients().Count
        Write-Host "  Hotspot state:     $hotspotStatus" -ForegroundColor $(if ($hotspotStatus -eq "On") { "Green" } else { "Red" })
        Write-Host "  Connected clients: $clientCount"
    }
} catch {
    Write-Host "  Could not query hotspot programmatically" -ForegroundColor Yellow
}

# Alternative: check via netsh
Write-Host ""
Write-Host "  Hotspot config (netsh):" -ForegroundColor Gray
$hostedNet = cmd /c 'netsh wlan show hostednetwork' 2>&1
$hostedNet | ForEach-Object { Write-Host "    $_" -ForegroundColor Gray }

# Check Windows Mobile Hotspot settings
Write-Host ""
Write-Host "  Mobile Hotspot settings:" -ForegroundColor Gray
try {
    $regPath = "HKLM:\SYSTEM\CurrentControlSet\Services\icssvc\Settings"
    if (Test-Path $regPath) {
        $maxPeers = (Get-ItemProperty $regPath -Name "MaxPeers" -ErrorAction SilentlyContinue).MaxPeers
        if ($maxPeers) {
            Write-Host "    Max peers (registry): $maxPeers" -ForegroundColor Green
        } else {
            Write-Host "    Max peers: 8 (Windows default)" -ForegroundColor Yellow
        }
    } else {
        Write-Host "    Max peers: 8 (Windows default)" -ForegroundColor Yellow
    }
} catch {
    Write-Host "    Max peers: 8 (Windows default)" -ForegroundColor Yellow
}

# ── Section 2: Network Interfaces ──
Write-Host ""
Write-Host "=== 2. Network Interfaces ===" -ForegroundColor Yellow
Write-Host ""

$adapters = Get-NetAdapter | Where-Object { $_.Status -eq "Up" } | Select-Object Name, InterfaceDescription, LinkSpeed, MacAddress
$adapters | ForEach-Object {
    $ips = (Get-NetIPAddress -InterfaceIndex (Get-NetAdapter -Name $_.Name).ifIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue).IPAddress
    Write-Host "  $($_.Name)" -ForegroundColor Green
    Write-Host "    Description: $($_.InterfaceDescription)"
    Write-Host "    Speed:       $($_.LinkSpeed)"
    Write-Host "    IP:          $($ips -join ', ')"
    Write-Host ""
}

# Find the hotspot adapter
$hotspotAdapter = Get-NetAdapter | Where-Object { $_.InterfaceDescription -match "Wi-Fi Direct|Microsoft Wi-Fi Direct|Hosted Network" -and $_.Status -eq "Up" }
if ($hotspotAdapter) {
    $hotspotIP = (Get-NetIPAddress -InterfaceIndex $hotspotAdapter.ifIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue).IPAddress
    Write-Host "  Hotspot adapter: $($hotspotAdapter.Name) ($hotspotIP)" -ForegroundColor Green
} else {
    Write-Host "  Hotspot adapter: Not detected (hotspot may not be active)" -ForegroundColor Yellow
    # Try to find it via IP range (192.168.137.x is typical for Windows hotspot)
    $possibleHotspot = Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -match "^192\.168\.137\." }
    if ($possibleHotspot) {
        Write-Host "  Possible hotspot IP: $($possibleHotspot.IPAddress)" -ForegroundColor Yellow
    }
}

# ── Section 3: Connected Devices (ARP table) ──
Write-Host ""
Write-Host "=== 3. Connected Devices ===" -ForegroundColor Yellow
Write-Host ""

$arpEntries = Get-NetNeighbor -State Reachable, Stale -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notmatch "^(127\.|224\.|255\.)" }
if ($arpEntries) {
    Write-Host "  IP Address         MAC Address          State       Interface" -ForegroundColor Gray
    Write-Host "  ---------------    -----------------    ----------  ---------" -ForegroundColor Gray
    $arpEntries | ForEach-Object {
        $ifName = (Get-NetAdapter -InterfaceIndex $_.InterfaceIndex -ErrorAction SilentlyContinue).Name
        Write-Host "  $($_.IPAddress.PadRight(20)) $($_.LinkLayerAddress.PadRight(20)) $($_.State.ToString().PadRight(12)) $ifName"
    }
    Write-Host ""
    Write-Host "  Total reachable devices: $($arpEntries.Count)" -ForegroundColor Green
} else {
    Write-Host "  No connected devices found" -ForegroundColor Yellow
}

# ── Section 4: Apache Status ──
Write-Host ""
Write-Host "=== 4. Apache Web Server ===" -ForegroundColor Yellow
Write-Host ""

$httpd = Get-Process httpd -ErrorAction SilentlyContinue
if ($httpd) {
    $workerCount = ($httpd | Measure-Object).Count
    $totalMem = ($httpd | Measure-Object WorkingSet64 -Sum).Sum / 1MB
    Write-Host "  Apache:      Running ($workerCount processes)" -ForegroundColor Green
    Write-Host "  Memory:      $([math]::Round($totalMem, 0)) MB"
    
    # Check Apache config for MaxRequestWorkers
    $apacheConf = "D:\xampp\apache\conf\httpd.conf"
    if (Test-Path $apacheConf) {
        $maxWorkers = Select-String -Path $apacheConf -Pattern "MaxRequestWorkers|MaxClients|ThreadsPerChild" -ErrorAction SilentlyContinue
        if ($maxWorkers) {
            Write-Host "  Config:" -ForegroundColor Gray
            $maxWorkers | ForEach-Object { Write-Host "    $($_.Line.Trim())" -ForegroundColor Gray }
        }
    }

    # Check extra conf for mpm_winnt
    $mpmConf = "D:\xampp\apache\conf\extra\httpd-mpm.conf"
    if (Test-Path $mpmConf) {
        $mpmSettings = Select-String -Path $mpmConf -Pattern "ThreadsPerChild|MaxConnectionsPerChild|MaxRequestWorkers" -ErrorAction SilentlyContinue | ForEach-Object { $_.Line.Trim() } | Where-Object { $_ -notmatch "^#" }
        if ($mpmSettings) {
            Write-Host "  MPM config:" -ForegroundColor Gray
            $mpmSettings | ForEach-Object { Write-Host "    $_" -ForegroundColor Gray }
        }
    }
} else {
    Write-Host "  Apache:      NOT RUNNING" -ForegroundColor Red
}

# ── Section 5: Port 80 listeners ──
Write-Host ""
Write-Host "=== 5. HTTP Listeners ===" -ForegroundColor Yellow
Write-Host ""

$listeners = Get-NetTCPConnection -LocalPort 80 -ErrorAction SilentlyContinue
if ($listeners) {
    $listening = $listeners | Where-Object { $_.State -eq "Listen" }
    $established = $listeners | Where-Object { $_.State -eq "Established" }
    Write-Host "  Listening:    $($listening.Count) socket(s)"
    Write-Host "  Established:  $($established.Count) active connection(s)"
    Write-Host "  Total:        $($listeners.Count)"
    
    if ($established.Count -gt 0) {
        Write-Host ""
        Write-Host "  Active connections:" -ForegroundColor Gray
        $established | Group-Object RemoteAddress | ForEach-Object {
            Write-Host "    $($_.Name): $($_.Count) connection(s)" -ForegroundColor Gray
        }
    }
} else {
    Write-Host "  No listeners on port 80" -ForegroundColor Red
}

# ── Section 6: Stress Test ──
if ($StressTest) {
    Write-Host ""
    Write-Host "=== 6. Concurrent Connection Stress Test ===" -ForegroundColor Yellow
    Write-Host "  Simulating $MaxClients concurrent requests..." -ForegroundColor Yellow
    Write-Host ""

    $pages = @(
        @{ name = "Homepage"; url = "$BaseUrl/" },
        @{ name = "Browse"; url = "$BaseUrl/browse.php" },
        @{ name = "Search"; url = "$BaseUrl/result.php?q=math" },
        @{ name = "Health Check"; url = "$BaseUrl/api/health-check.php" },
        @{ name = "Watch Page"; url = "$BaseUrl/watch.php" }
    )

    # Warm up
    Write-Host "  Warming up..." -ForegroundColor Gray
    try { $null = Invoke-WebRequest -Uri "$BaseUrl/" -TimeoutSec 10 -UseBasicParsing } catch {}

    # Test increasing concurrent loads
    $testResults = @()
    foreach ($concurrency in @(1, 2, 5, 8, 10, 15, $MaxClients)) {
        if ($concurrency -gt $MaxClients) { continue }
        
        Write-Host "  Testing $concurrency concurrent requests..." -NoNewline
        
        $jobs = @()
        $startTime = Get-Date
        
        for ($i = 0; $i -lt $concurrency; $i++) {
            $page = $pages[$i % $pages.Count]
            $url = $page.url
            $jobs += Start-Job -ScriptBlock {
                param($u)
                $sw = [System.Diagnostics.Stopwatch]::StartNew()
                try {
                    $r = Invoke-WebRequest -Uri $u -TimeoutSec 30 -UseBasicParsing
                    $sw.Stop()
                    return @{ status = $r.StatusCode; time = $sw.ElapsedMilliseconds; error = $null }
                } catch {
                    $sw.Stop()
                    return @{ status = 0; time = $sw.ElapsedMilliseconds; error = $_.Exception.Message.Substring(0, [Math]::Min(80, $_.Exception.Message.Length)) }
                }
            } -ArgumentList $url
        }

        $null = Wait-Job $jobs -Timeout 60
        $elapsed = ((Get-Date) - $startTime).TotalMilliseconds
        $results = $jobs | ForEach-Object { Receive-Job $_ -ErrorAction SilentlyContinue }
        Remove-Job $jobs -Force -ErrorAction SilentlyContinue

        $succeeded = ($results | Where-Object { $_.status -eq 200 }).Count
        $failed = $concurrency - $succeeded
        $avgTime = if ($results.Count -gt 0) { [math]::Round(($results | Measure-Object -Property time -Average).Average) } else { 0 }
        $maxTime = if ($results.Count -gt 0) { [math]::Round(($results | Measure-Object -Property time -Maximum).Maximum) } else { 0 }
        
        $color = if ($failed -eq 0) { "Green" } elseif ($failed -lt $concurrency / 2) { "Yellow" } else { "Red" }
        Write-Host " $succeeded/$concurrency OK, avg ${avgTime}ms, max ${maxTime}ms" -ForegroundColor $color

        $testResults += [PSCustomObject]@{
            Concurrent = $concurrency
            Succeeded = $succeeded
            Failed = $failed
            AvgMs = $avgTime
            MaxMs = $maxTime
            TotalMs = [math]::Round($elapsed)
        }
    }

    Write-Host ""
    Write-Host "  Summary:" -ForegroundColor Yellow
    $testResults | Format-Table -AutoSize
    
    # Find the breaking point
    $breakPoint = $testResults | Where-Object { $_.Failed -gt 0 } | Select-Object -First 1
    if ($breakPoint) {
        Write-Host "  Breaking point: $($breakPoint.Concurrent) concurrent requests ($($breakPoint.Failed) failures)" -ForegroundColor Red
    } else {
        Write-Host "  All tests passed up to $MaxClients concurrent connections" -ForegroundColor Green
    }
}

# ── Section 7: Recommendations ──
Write-Host ""
Write-Host "=== Recommendations ===" -ForegroundColor Yellow
Write-Host ""
Write-Host "  Windows Mobile Hotspot limits:"
Write-Host "    - Default max: 8 devices"
Write-Host "    - Can be increased via registry (HKLM\SYSTEM\CurrentControlSet\Services\icssvc\Settings\MaxPeers)"
Write-Host "    - Practical limit depends on WiFi adapter capabilities"
Write-Host ""
Write-Host "  To increase max hotspot devices:" -ForegroundColor Cyan
Write-Host '    Set-ItemProperty -Path "HKLM:\SYSTEM\CurrentControlSet\Services\icssvc\Settings" -Name "MaxPeers" -Value 20 -Type DWord'
Write-Host "    Then restart the Mobile Hotspot"
Write-Host ""
Write-Host "  For more than 20 devices, consider:" -ForegroundColor Cyan
Write-Host "    - Using a dedicated WiFi access point connected via Ethernet"
Write-Host "    - Setting up the BeeLink as a DHCP server with a real AP"
Write-Host ""

# ── Section 8: Quick connectivity test for connected devices ──
Write-Host "=== Connected Device Reach Test ===" -ForegroundColor Yellow
Write-Host ""

$connectedIPs = (Get-NetNeighbor -State Reachable -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -match "^192\.168\." }).IPAddress
if ($connectedIPs) {
    Write-Host "  Pinging $($connectedIPs.Count) connected devices..." -ForegroundColor Gray
    foreach ($ip in $connectedIPs) {
        $ping = Test-Connection -ComputerName $ip -Count 1 -Quiet -ErrorAction SilentlyContinue
        $status = if ($ping) { "OK" } else { "FAIL" }
        $color = if ($ping) { "Green" } else { "Red" }
        Write-Host "    $ip : $status" -ForegroundColor $color
    }
} else {
    Write-Host "  No devices on local subnet detected" -ForegroundColor Yellow
    Write-Host "  (Enable Mobile Hotspot and connect devices to test)" -ForegroundColor Gray
}

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  Diagnostic complete" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
