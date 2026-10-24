param([switch]$DryRun,[switch]$Once,[string]$Root = 'D:\edutek-system\bulk-loader-fresh-v05',[string]$DbBackupDir = 'D:\edutek-system\backups\db')
$ErrorActionPreference = 'Stop'
$trig   = 'D:\edutek-system\bulk-loader-fresh-v05\data\index-trigger'
$status = 'D:\edutek-system\index-status'
$mdir   = Join-Path $status 'manifests'
New-Item -ItemType Directory -Force -Path $trig, $mdir | Out-Null
$enc = New-Object Text.UTF8Encoding($false)
$lockPath = Join-Path $trig 'runner.lock'
# Patch 3a: a restarted task can start before the old process has released the lock, so retry for up to 60 s.
$lock = $null; $lockTries = 0
while ($null -eq $lock) {
  try { $lock = [IO.File]::Open($lockPath, 'OpenOrCreate', 'ReadWrite', 'None') } catch {
    $lockTries++
    if ($lockTries -ge 30) { Write-Host ("{0}  Another runner is still holding {1} after 60 s. Giving up (exit 1). Nothing was indexed." -f (Get-Date -Format HH:mm:ss), $lockPath); exit 1 }
    if ($lockTries -eq 1) { Write-Host ("{0}  {1} is held by another runner; retrying every 2 s for up to 60 s." -f (Get-Date -Format HH:mm:ss), $lockPath) }
    Start-Sleep -Seconds 2
  }
}
# Patch 4b: after a successful partial index for a browser publish (publish-<id8>) or undo (undo-<id8>-HHmmss), run the catalog
# cleanup (content_meta rows whose files no longer exist) with catalog-cleanup-cli.php, copied into edupak-app /tmp for the run.
# Guards: the library mount must hold >= 30,000 PDF/MP4 files and >= 95% of the device list; a report pass runs first; more than
# 25 stale rows (or more than 0.5% of rows) are never deleted automatically, only reported. A database backup is taken before
# any row is deleted. Results: index-trigger\<request>.cleanup.json (shown in the browser) and index-trigger\catalog-cleanup.txt;
# audit CSVs go to D:\edutek-system\index-status. Never indexes and never touches files.
$ccScript = Join-Path $Root 'catalog-cleanup-cli.php'
$ccDevIndex = Join-Path $Root 'data\device-index.json'
function CC-Tail($s, $n) { $s = [string]$s; if ($s.Length -gt $n) { $s.Substring($s.Length - $n) } else { $s } }
function CC-Note($m) {
  Write-Host ("{0}  catalog cleanup: {1}" -f (Get-Date -Format HH:mm:ss), $m)
  try { [IO.File]::AppendAllText((Join-Path $trig 'catalog-cleanup.txt'), ((Get-Date).ToString('s') + '  ' + $m + "`r`n"), $enc) } catch {}
}
function CC-Run([string[]]$a) {
  $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = (& docker exec edupak-app php /tmp/edutek-catalog-cleanup.php @a 2>&1 | Out-String); $c = $LASTEXITCODE } finally { $ErrorActionPreference = $prev }
  $line = @($o -split "`n" | Where-Object { $_.Trim().StartsWith('{') }) | Select-Object -Last 1
  $j = $null; if ($line) { try { $j = $line | ConvertFrom-Json } catch {} }
  @{ code = $c; json = $j; raw = $o }
}
function CC-Backup([string]$tag) {
  New-Item -ItemType Directory -Force -Path $DbBackupDir | Out-Null
  $name = 'pre-cleanup-' + $tag + '-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.sql'; $dest = Join-Path $DbBackupDir $name
  & docker exec edupak-db sh -c ('mysqldump -uroot -p$MYSQL_ROOT_PASSWORD --single-transaction --default-character-set=utf8mb4 edupak > /tmp/' + $name + ' 2>/tmp/' + $name + '.err')
  if ($LASTEXITCODE -ne 0) { throw 'Database backup failed (mysqldump); nothing was deleted.' }
  & docker cp ('edupak-db:/tmp/' + $name) $dest | Out-Null; $cpOk = ($LASTEXITCODE -eq 0)
  & docker exec edupak-db rm -f ('/tmp/' + $name) ('/tmp/' + $name + '.err') | Out-Null
  if (-not $cpOk -or -not (Test-Path -LiteralPath $dest)) { throw 'Database backup could not be copied out of edupak-db; nothing was deleted.' }
  $fs = [IO.File]::OpenRead($dest); try { $n = [Math]::Min(400, $fs.Length); [void]$fs.Seek(-$n, 'End'); $buf = New-Object byte[] $n; [void]$fs.Read($buf, 0, $n) } finally { $fs.Close() }
  if (-not $enc.GetString($buf).Contains('-- Dump completed')) { throw "Database backup looks incomplete ($dest); nothing was deleted." }
  $dest
}
function Invoke-CatalogCleanup([string[]]$names) {
  $res = [ordered]@{ requests = $names; started = (Get-Date).ToString('s'); state = ''; library_files = 0; expect_files = 0; checked = 0; stale = 0; removed = 0; audit = ''; db_backup = ''; message = '' }
  try {
    try { $di = [IO.File]::ReadAllText($ccDevIndex, $enc) | ConvertFrom-Json; $res.expect_files = @($di.items | Where-Object { $_.lib -eq 'production' -and ([string]$_.path -match '\.(pdf|mp4)$') }).Count } catch {}
    if ($res.expect_files -lt 30000) { throw "The device list ($ccDevIndex) has $($res.expect_files) library files, fewer than 30,000, so the mount cannot be cross-checked. Nothing was deleted." }
    if (-not (Test-Path -LiteralPath $ccScript -PathType Leaf)) { throw "$ccScript not found. Nothing was deleted." }
    & docker cp $ccScript edupak-app:/tmp/edutek-catalog-cleanup.php | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not copy the cleanup script into edupak-app. Nothing was deleted.' }
    try {
      $a = @('--min-files=30000', ('--expect-files=' + $res.expect_files), '--max-delete=25', '--max-fraction=0.005', ('--tag=' + $names[0]))
      $r1 = CC-Run $a
      if (-not $r1.json) { throw ('The report pass gave no result (exit ' + $r1.code + '): ' + (CC-Tail $r1.raw 800).Trim()) }
      $res.library_files = [int]$r1.json.library_files; $res.checked = [int]$r1.json.checked; $res.stale = [int]$r1.json.stale; $res.audit = [string]$r1.json.audit
      if ($r1.json.state -ne 'report') { $res.state = $(if ($r1.json.state -eq 'held') { 'held' } else { 'error' }); $res.message = [string]$r1.json.message }
      elseif ($res.stale -eq 0) { $res.state = 'done'; $res.message = '0 stale rows removed.' }
      else {
        $res.db_backup = CC-Backup $names[0]
        $r2 = CC-Run ($a + @('--execute', ('--expect-stale=' + $res.stale)))
        if (-not $r2.json) { throw ('The cleanup pass gave no result (exit ' + $r2.code + '): ' + (CC-Tail $r2.raw 800).Trim()) }
        $res.state = $(if ($r2.json.state -eq 'done') { 'done' } elseif ($r2.json.state -eq 'held') { 'held' } else { 'error' })
        $res.removed = [int]$r2.json.removed; $res.message = [string]$r2.json.message; if ($r2.json.audit) { $res.audit = [string]$r2.json.audit }
      }
    } finally { & docker exec edupak-app rm -f /tmp/edutek-catalog-cleanup.php 2>&1 | Out-Null }
  } catch { if ($res.state -ne 'done') { $res.state = $(if ($_.Exception.Message -match 'Nothing was deleted|nothing was deleted') { 'held' } else { 'error' }) }; $res.message = $_.Exception.Message }
  $res.finished = (Get-Date).ToString('s')
  foreach ($nm in $names) { $tmp = Join-Path $trig ($nm + '.cleanup.tmp'); [IO.File]::WriteAllText($tmp, ($res | ConvertTo-Json -Compress), $enc); Move-Item $tmp (Join-Path $trig ($nm + '.cleanup.json')) -Force }
  CC-Note ("{0} after {1}: {2} (library files {3}, rows checked {4}, stale {5}, removed {6}{7}{8})" -f $res.state.ToUpper(), ($names -join ', '), $res.message, $res.library_files, $res.checked, $res.stale, $res.removed, $(if ($res.audit) { ', audit ' + $res.audit } else { '' }), $(if ($res.db_backup) { ', backup ' + $res.db_backup } else { '' }))
}
function Write-Result($name, $obj) {
  $tmp = Join-Path $trig ($name + '.result.tmp')
  [IO.File]::WriteAllText($tmp, ($obj | ConvertTo-Json -Compress), $enc)
  Move-Item $tmp (Join-Path $trig ($name + '.result.json')) -Force
}
try {
  Write-Host "Watching $trig  (Ctrl+C to stop)"
  do {
    try { [IO.File]::WriteAllText((Join-Path $trig 'indexer-heartbeat.txt'), (Get-Date).ToString('s'), $enc) } catch {}
    $reqs = @(Get-ChildItem $trig -Filter '*.request.json' -File -ErrorAction SilentlyContinue)
    if ($reqs.Count -gt 0) {
      Start-Sleep -Seconds 2
      $reqs = @(Get-ChildItem $trig -Filter '*.request.json' -File | Sort-Object LastWriteTime)
      $files = New-Object System.Collections.Generic.HashSet[string]
      $full = $false
      $valid = @()
      foreach ($r in $reqs) {
        $base = $r.Name -replace '\.request\.json$', ''
        $j = $null
        try { $j = Get-Content $r.FullName -Raw -Encoding UTF8 | ConvertFrom-Json } catch {}
        if ($j -and $j.mode -eq 'full') {
          Write-Result $base ([ordered]@{ batch = $base; state = 'rejected'; exit_code = 3; output = 'Full-library index requests are not accepted from the queue. A full index is a manual decision, run by hand only.' })
          Rename-Item -LiteralPath $r.FullName -NewName ($r.Name + '.' + [guid]::NewGuid().ToString('N') + '.done')
          Write-Host ("{0}  {1}  rejected (full mode is not accepted from the queue)" -f (Get-Date -Format HH:mm:ss), $base)
        }
        elseif ($j -and $j.files -and @($j.files).Count -gt 0) { foreach ($f in @($j.files)) { [void]$files.Add([string]$f) }; $valid += $r }
        else {
          Write-Result $base ([ordered]@{ batch = $base; state = 'failed'; exit_code = 2; output = 'Request has no files list.' })
          Rename-Item -LiteralPath $r.FullName -NewName ($r.Name + '.' + [guid]::NewGuid().ToString('N') + '.done')
          Write-Host ("{0}  {1}  rejected (no files list)" -f (Get-Date -Format HH:mm:ss), $base)
        }
      }
      if ($valid.Count -gt 0) {
        $started = Get-Date
        $manifestName = $null
        if (-not $full) {
          $manifestName = 'idx-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.json'
          [IO.File]::WriteAllText((Join-Path $mdir $manifestName), (ConvertTo-Json -InputObject @($files) -Compress), $enc)
        }
        if ($DryRun) { $out = "DRY RUN: index " + $(if ($full) { 'whole library' } else { "$($files.Count) listed files" }); $code = 0 }
        elseif ($full) { $out = (& docker exec edupak-app php /var/www/html/api/index-content-cli.php /content 2>&1 | Out-String); $code = $LASTEXITCODE }
        else { $out = (& docker exec edupak-app php /var/www/html/api/index-content-cli.php /content ("--files=/system/index-status/manifests/" + $manifestName) 2>&1 | Out-String); $code = $LASTEXITCODE }
        $finished = Get-Date
        $audit = Get-ChildItem $status -Filter 'index-run-*.csv' -ErrorAction SilentlyContinue | Where-Object { $_.LastWriteTime -ge $started.AddSeconds(-5) } | Sort-Object LastWriteTime -Descending | Select-Object -First 1
        $tail = if ($out.Length -gt 2000) { $out.Substring($out.Length - 2000) } else { $out }
        foreach ($r in $valid) {
          $base = $r.Name -replace '\.request\.json$', ''
          Write-Result $base ([ordered]@{ batch = $base; state = $(if ($code -eq 0) { 'complete' } else { 'failed' }); exit_code = $code; mode = $(if ($full) { 'full' } else { 'files' }); files = $files.Count; started = $started.ToString('s'); finished = $finished.ToString('s'); audit = $(if ($audit) { $audit.Name } else { $null }); output = $tail.Trim() })
          Rename-Item -LiteralPath $r.FullName -NewName ($r.Name + '.' + [guid]::NewGuid().ToString('N') + '.done')
          Write-Host ("{0}  {1}  exit {2}" -f (Get-Date -Format HH:mm:ss), $base, $code)
        }
        $ccNames = @($valid | ForEach-Object { $_.Name -replace '\.request\.json\..*$', '' -replace '\.request\.json$', '' } | Where-Object { $_ -cmatch '^(publish-[a-f0-9]{8}|undo-[a-f0-9]{8}-\d{6})$' })
        if ($code -eq 0 -and -not $DryRun -and -not $full -and $ccNames.Count -gt 0) { try { Invoke-CatalogCleanup $ccNames } catch { CC-Note ('ERROR: ' + $_.Exception.Message) } }
      }
    }
    if (-not $Once) { Start-Sleep -Seconds 5 }
  } while (-not $Once)
} finally { $lock.Close() }
