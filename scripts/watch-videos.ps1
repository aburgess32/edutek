$watchPath = "D:\xampp\htdocs\Edutek\videos"
$triggerUrl = "http://localhost:8080/admin/rescan-videos.php?key=CHANGE_THIS_SECRET"
$debounceSeconds = 8
$script:lastRun = Get-Date "2000-01-01"

$fsw = New-Object System.IO.FileSystemWatcher
$fsw.Path = $watchPath
$fsw.IncludeSubdirectories = $true
$fsw.EnableRaisingEvents = $true
$fsw.NotifyFilter = [System.IO.NotifyFilters]'FileName, DirectoryName, LastWrite, CreationTime, Size'
$fsw.Filter = '*'

function Invoke-Rescan {
    $now = Get-Date
    if (($now - $script:lastRun).TotalSeconds -lt $debounceSeconds) {
        return
    }
    $script:lastRun = $now
    Start-Sleep -Seconds 2
    try {
        $response = Invoke-WebRequest -Uri $triggerUrl -UseBasicParsing -TimeoutSec 120
        Write-Host "$(Get-Date -Format s) Rescan triggered. Status: $($response.StatusCode)"
    } catch {
        Write-Host "$(Get-Date -Format s) Rescan failed: $($_.Exception.Message)"
    }
}

$action = {
    $path = $Event.SourceEventArgs.FullPath
    $change = $Event.SourceEventArgs.ChangeType
    Write-Host "$(Get-Date -Format s) Detected $change => $path"
    Invoke-Rescan
}

Register-ObjectEvent $fsw Created -Action $action | Out-Null
Register-ObjectEvent $fsw Changed -Action $action | Out-Null
Register-ObjectEvent $fsw Renamed -Action $action | Out-Null
Register-ObjectEvent $fsw Deleted -Action $action | Out-Null

Write-Host "Watching $watchPath for new/changed categories and videos..."
while ($true) { Start-Sleep -Seconds 5 }
