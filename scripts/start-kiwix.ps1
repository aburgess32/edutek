# EduPak Kiwix Startup Script
# Starts kiwix-serve with all .zim files in the Kiwix directory
$kiwixExe = "D:\xampp\htdocs\Edutek\Kiwix\kiwix-tools\kiwix-serve.exe"
$zimDir = "D:\xampp\htdocs\Edutek\Kiwix"

if (-not (Test-Path $kiwixExe)) { exit 1 }

$zimFiles = Get-ChildItem "$zimDir\*.zim" | ForEach-Object { $_.FullName }
if ($zimFiles.Count -eq 0) { exit 1 }

# Kill any existing kiwix-serve
Get-Process -Name "kiwix-serve" -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep -Seconds 2

# Start kiwix-serve
$args = @("--port=8888") + $zimFiles
Start-Process -FilePath $kiwixExe -ArgumentList $args -WindowStyle Hidden