# ============================================================
# EduPak Device Audit Script
# ============================================================
# Run this on a new or unknown EduPak device to gather
# system info before the first update. Outputs a summary
# to the console and to a text file.
#
# Usage:
#   .\device-audit-template.ps1
#   .\device-audit-template.ps1 -OutputFile "C:\audit-DEVICENAME.txt"
#
# Run in PowerShell as Administrator for full access.
# ============================================================

param(
    [string]$OutputFile = ""
)

$ErrorActionPreference = "Continue"

if ($OutputFile -eq "") {
    $hostname = $env:COMPUTERNAME
    $OutputFile = "device-audit-$hostname.txt"
}

$report = @()

function Add-Section($title) {
    $script:report += ""
    $script:report += "== $title =="
    $script:report += ("-" * (4 + $title.Length))
}

function Add-Line($label, $value) {
    $script:report += "  ${label}: $value"
}

function Add-Raw($text) {
    $script:report += "  $text"
}

# ── Header ───────────────────────────────────────────────────
$report += "============================================"
$report += "  EduPak Device Audit"
$report += "  Date: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
$report += "  Host: $env:COMPUTERNAME"
$report += "============================================"

# ── System Info ──────────────────────────────────────────────
Add-Section "Operating System"
$os = Get-CimInstance -ClassName Win32_OperatingSystem
Add-Line "OS" "$($os.Caption) $($os.Version)"
Add-Line "Architecture" "$($os.OSArchitecture)"
Add-Line "Computer Name" "$env:COMPUTERNAME"

# ── Storage ──────────────────────────────────────────────────
Add-Section "Storage"
Get-CimInstance -ClassName Win32_LogicalDisk -Filter "DriveType=3" | ForEach-Object {
    $freeGB = [math]::Round($_.FreeSpace / 1GB, 1)
    $totalGB = [math]::Round($_.Size / 1GB, 1)
    Add-Line "$($_.DeviceID)" "${totalGB}GB total, ${freeGB}GB free"
}

# ── XAMPP Detection ──────────────────────────────────────────
Add-Section "XAMPP"
$xampPaths = @("D:\xampp", "C:\xampp")
$xamppRoot = ""
foreach ($p in $xampPaths) {
    if (Test-Path $p) {
        $xamppRoot = $p
        Add-Line "XAMPP root" $p
        break
    }
}
if ($xamppRoot -eq "") {
    Add-Line "XAMPP" "NOT FOUND"
} else {
    # PHP version
    $phpBin = "$xamppRoot\php\php.exe"
    if (Test-Path $phpBin) {
        $phpVer = & $phpBin -v 2>&1 | Select-Object -First 1
        Add-Line "PHP" $phpVer
    }

    # Apache version
    $apacheBin = "$xamppRoot\apache\bin\httpd.exe"
    if (Test-Path $apacheBin) {
        $apacheVer = & $apacheBin -v 2>&1 | Select-Object -First 1
        Add-Line "Apache" $apacheVer
    }

    # MariaDB version
    $mysqlBin = "$xamppRoot\mysql\bin\mysql.exe"
    if (Test-Path $mysqlBin) {
        $mysqlVer = & $mysqlBin --version 2>&1
        Add-Line "MariaDB" $mysqlVer
    }
}

# ── App Directory ────────────────────────────────────────────
Add-Section "Application"
$appDir = "$xamppRoot\htdocs\Edutek"
if (Test-Path $appDir) {
    Add-Line "App directory" $appDir

    # Check for .env
    Add-Line ".env exists" $(if (Test-Path "$appDir\.env") { "YES" } else { "NO" })

    # Check for legacy BLink files
    Add-Line "Data.php (BLink legacy)" $(if (Test-Path "$appDir\Data.php") { "PRESENT" } else { "not found" })
    Add-Line "functions.php (BLink legacy)" $(if (Test-Path "$appDir\functions.php") { "PRESENT" } else { "not found" })

    # Content directories
    $contentDirs = @("videos", "khan", "Wiki", "Kiwix", "images")
    foreach ($dir in $contentDirs) {
        $dirPath = Join-Path $appDir $dir
        if (Test-Path $dirPath) {
            $itemCount = (Get-ChildItem $dirPath -Recurse -File -ErrorAction SilentlyContinue | Measure-Object).Count
            $dirSize = (Get-ChildItem $dirPath -Recurse -File -ErrorAction SilentlyContinue | Measure-Object -Property Length -Sum).Sum
            $dirSizeGB = [math]::Round($dirSize / 1GB, 2)
            Add-Line "$dir" "${itemCount} files, ${dirSizeGB}GB"
        } else {
            Add-Line "$dir" "NOT FOUND"
        }
    }
} else {
    Add-Line "App directory" "NOT FOUND at $appDir"
}

# ── Database ─────────────────────────────────────────────────
Add-Section "Database"
if ($xamppRoot -ne "") {
    $mysqlExe = "$xamppRoot\mysql\bin\mysql.exe"

    # Test CLI connectivity
    $cliWorks = $false
    try {
        $testResult = cmd /c "`"$mysqlExe`" -u root -e `"SELECT 1`" 2>&1"
        if ($testResult -match "1") {
            Add-Line "MariaDB CLI" "WORKING"
            $cliWorks = $true
        } else {
            Add-Line "MariaDB CLI" "NOT RESPONDING (may need privilege table repair)"
        }
    } catch {
        Add-Line "MariaDB CLI" "ERROR: $($_.Exception.Message)"
    }

    if ($cliWorks) {
        # List databases
        $dbs = cmd /c "`"$mysqlExe`" -u root -e `"SHOW DATABASES`" 2>&1"
        Add-Line "Databases" ""
        foreach ($db in ($dbs -split "`n" | Select-Object -Skip 1)) {
            $db = $db.Trim()
            if ($db -ne "") { Add-Raw "- $db" }
        }

        # Check for edupak database
        $hasEdupak = $dbs -match "edupak"
        Add-Line "edupak DB exists" $(if ($hasEdupak) { "YES" } else { "NO" })
    }

    # Check privilege table health
    Add-Section "MariaDB Privilege Table Check"
    $dbFile = "$xamppRoot\mysql\data\mysql\db.MAD"
    if (Test-Path $dbFile) {
        $dbSize = (Get-Item $dbFile).Length
        $dbSizeKB = [math]::Round($dbSize / 1KB, 1)
        Add-Line "mysql\db.MAD size" "${dbSizeKB}KB"
        if ($dbSize -gt 100000) {
            Add-Line "STATUS" "POSSIBLY CORRUPTED (expected ~24KB, found ${dbSizeKB}KB)"
        } else {
            Add-Line "STATUS" "OK"
        }
    }
    $backupDir = "$xamppRoot\mysql\backup\mysql"
    Add-Line "Backup db files exist" $(if (Test-Path "$backupDir\db.frm") { "YES" } else { "NO" })
}

# ── Network ──────────────────────────────────────────────────
Add-Section "Network"
$ip = (Get-NetIPAddress -AddressFamily IPv4 -InterfaceAlias "Wi-Fi*","Ethernet*" -ErrorAction SilentlyContinue | Select-Object -First 1)
if ($ip) {
    Add-Line "IP Address" $ip.IPAddress
}
Add-Line "SSH service" $(if (Get-Service sshd -ErrorAction SilentlyContinue) { (Get-Service sshd).Status } else { "Not installed" })
Add-Line "WinRM service" $(if (Get-Service WinRM -ErrorAction SilentlyContinue) { (Get-Service WinRM).Status } else { "Not installed" })

# ── Git ──────────────────────────────────────────────────────
Add-Section "Git"
try {
    $gitVer = git --version 2>&1
    Add-Line "Git" $gitVer
} catch {
    Add-Line "Git" "Not installed"
}

# ── Output ───────────────────────────────────────────────────
$report += ""
$report += "============================================"
$report += "  Audit complete"
$report += "============================================"

# Print to console
$report | ForEach-Object { Write-Host $_ }

# Save to file
$report | Out-File -FilePath $OutputFile -Encoding UTF8
Write-Host ""
Write-Host "Audit saved to: $OutputFile" -ForegroundColor Green
