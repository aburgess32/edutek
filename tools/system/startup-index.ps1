$ErrorActionPreference = 'Stop'

$project = 'C:\Users\Edutek\edutek'
$compose = Join-Path $project 'docker-compose.yml'

$systemRoot = 'D:\edutek-system'
$statusDir = Join-Path $systemRoot 'index-status'
$logDir = Join-Path $systemRoot 'logs'
$logFile = Join-Path $logDir 'startup-index.log'

New-Item -ItemType Directory -Force -Path $statusDir, $logDir | Out-Null
Set-Location $project

function Write-IndexLog([string]$message) {
    "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $message" |
        Out-File -FilePath $logFile -Append -Encoding utf8
}

try {
    $deadline = (Get-Date).AddMinutes(10)

    do {
        $running = @(
            docker compose -f $compose ps --status running --services 2>$null
        )

        if ($running -contains 'app' -and $running -contains 'db') {
            break
        }

        Start-Sleep -Seconds 15
    } while ((Get-Date) -lt $deadline)

    if ($running -notcontains 'app' -or $running -notcontains 'db') {
        throw 'EduPak Docker services app and db were not ready within 10 minutes.'
    }

    Write-IndexLog 'Starting startup content assessment.'

    $output = @(
        docker compose -f $compose exec -T app php /var/www/html/api/index-content-cli.php 2>&1
    )

    $exitCode = $LASTEXITCODE
    $timestamp = Get-Date -Format 'yyyy-MM-dd_HH-mm-ss'
    $reportPath = Join-Path $statusDir "scan-$timestamp.json"

    $report = [ordered]@{
        scanned_at     = (Get-Date).ToString('o')
        trigger        = 'startup'
        exit_code      = $exitCode
        status         = if ($exitCode -eq 0) { 'completed' } else { 'failed' }
        command_output = @($output | ForEach-Object { "$_" })
    }

$report | ConvertTo-Json -Depth 5 |
    Set-Content -Path $reportPath -Encoding utf8

$output | Out-File -FilePath $logFile -Append -Encoding utf8

$outputText = ($output -join "`n")

$noChanges = (
    $exitCode -eq 0 -and
    $outputText -match 'Indexing complete\.' -and
    $outputText -match '0 new, 0 updated'
)

if ($noChanges) {
    $upToDatePath = Join-Path $statusDir 'index-up-to-date.json'

    $upToDate = [ordered]@{
        status          = 'up_to_date'
        checked_at      = (Get-Date).ToString('o')
        trigger         = 'startup'
        content_root    = '/content'
        action          = 'No index updates were needed.'
        command_output  = @($output | ForEach-Object { "$_" })
    }

    $upToDate | ConvertTo-Json -Depth 5 |
        Set-Content -Path $upToDatePath -Encoding utf8

    Write-IndexLog 'Content index is up to date. No index updates were needed.'
}
else {
    Remove-Item (Join-Path $statusDir 'index-up-to-date.json') -Force -ErrorAction SilentlyContinue
    Write-IndexLog 'Content differences were found, or the indexer reported an error.'
}

    Get-ChildItem $statusDir -Filter 'scan-*.json' |
        Sort-Object LastWriteTime -Descending |
        Select-Object -Skip 7 |
        Remove-Item -Force

    if ($exitCode -ne 0) {
        throw "Indexer failed with exit code $exitCode."
    }

    Write-IndexLog 'Startup content assessment completed.'
    exit 0
}
catch {
    Write-IndexLog "ERROR: $($_.Exception.Message)"
    exit 1
}