$watchPath = "D:\xampp\htdocs\Edutek\videos"
$debounceSeconds = 8
$script:lastRun = Get-Date "2000-01-01"

$fsw = New-Object System.IO.FileSystemWatcher
$fsw.Path = $watchPath
$fsw.IncludeSubdirectories = $true
$fsw.EnableRaisingEvents = $true
$fsw.NotifyFilter = [System.IO.NotifyFilters]'FileName, DirectoryName, LastWrite, CreationTime, Size'
$fsw.Filter = '*'

function Invoke-Reindex {
    $now = Get-Date
    if (($now - $script:lastRun).TotalSeconds -lt $debounceSeconds) {
        return
    }

    $script:lastRun = $now
    Start-Sleep -Seconds 3

    try {
        Write-Host "$(Get-Date -Format s) Running DB reindex..."
        docker exec edupak-app php /var/www/html/api/index-content-cli.php /content

        if ($LASTEXITCODE -eq 0) {
            Write-Host "$(Get-Date -Format s) Reindex complete."
        } else {
            Write-Host "$(Get-Date -Format s) Reindex failed with exit code $LASTEXITCODE"
        }
    }
    catch {
        Write-Host "$(Get-Date -Format s) Reindex failed: $($_.Exception.Message)"
    }
}

$action = {
    $path = $Event.SourceEventArgs.FullPath
    $change = $Event.SourceEventArgs.ChangeType
    Write-Host "$(Get-Date -Format s) Detected $change => $path"
    Invoke-Reindex
}

Register-ObjectEvent $fsw Created -Action $action | Out-Null
Register-ObjectEvent $fsw Changed -Action $action | Out-Null
Register-ObjectEvent $fsw Renamed -Action $action | Out-Null
Register-ObjectEvent $fsw Deleted -Action $action | Out-Null

Write-Host "Watching $watchPath and reindexing contentmeta on changes..."
while ($true) { Start-Sleep -Seconds 5 }