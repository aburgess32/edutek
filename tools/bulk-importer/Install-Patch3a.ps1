# Install-Patch3a.ps1 - Edutek importer patch 3a: the partial indexer always runs after a browser publish.
# Run in Windows PowerShell on the Beelink as edutek1\edutek: first without -Apply (checks only), then with -Apply.
# Changes Run-ImportIndexer.ps1 (lock retry up to 60 s + indexer-heartbeat.txt), Run-PublishQueue.ps1 (starts the
# existing 'Edutek Import Partial Indexer' task when index requests wait and it is idle), Publish-Batch.ps1 (message).
# Every input and output is hash-checked; nothing live changes without -Apply.
# Rollback: copy the three files back from the backup folder printed by -Apply, then restart both tasks (see patch-3a.md).
param(
  [switch]$Apply,
  [string]$Root = 'D:\edutek-system\bulk-loader-fresh-v05',
  [string]$BackupRoot = 'D:\edutek-system\backups\importer-autoindex'
)
$ErrorActionPreference = 'Stop'
function NL([string]$s) { $s -replace "`r`n", "`n" }
function RootPath([string]$name) { [IO.Path]::Combine($Root, $name) }
function HashOf($path) { (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash }
function Build($src, $tmp, $inHash, $outHash, $pairs) {
  $h = HashOf $src
  if ($h -ne $inHash) { throw "$src has unexpected SHA256 $h. It differs from the reviewed version. Stop." }
  $b = [IO.File]::ReadAllBytes($src); $bom = $b.Length -ge 3 -and $b[0] -eq 0xEF -and $b[1] -eq 0xBB -and $b[2] -eq 0xBF
  $enc = New-Object System.Text.UTF8Encoding($bom); $off = $(if ($bom) { 3 } else { 0 })
  $t = $enc.GetString($b, $off, $b.Length - $off)
  foreach ($p in $pairs) { $n = ([regex]::Matches($t, [regex]::Escape($p[0]))).Count; if ($n -ne 1) { throw "$src : marker found $n times, expected 1." } }
  foreach ($p in $pairs) { $t = $t.Replace($p[0], $p[1]) }
  [IO.File]::WriteAllText($tmp, $t, $enc)
  if ((HashOf $tmp) -ne $outHash) { Remove-Item -LiteralPath $tmp; throw "Built copy of $src does not match the expected hash. Stop." }
  "Built OK: $tmp"
}

$idx = RootPath 'Run-ImportIndexer.ps1'; $pq = RootPath 'Run-PublishQueue.ps1'; $pub = RootPath 'Publish-Batch.ps1'
$tI = RootPath '_p3-Run-ImportIndexer.ps1'; $tQ = RootPath '_p3-Run-PublishQueue.ps1'; $tP = RootPath '_p3-Publish-Batch.ps1'
$hIin = 'A82A1B4EE415CF368A72C11BE8FAAC8EE5A99542810FE457A635D2415561D58C'; $hIout = '7B35FAAF94D4CD4C38EF16EE3EBEE655D829640D8C61A6C41603E5C27AFA2D72'
$hQin = '8E642495904E20A1F72C3E0B5FA446FA8DA68022FB1DEB7BE336C16C0D8250E7'; $hQout = 'B71E640935BC66BE907DDF5EBC30175A7A1AC97F30B564F26149935D32F11AE9'
$hPin = 'A977B053DB165AB6410223152ED1B8D56A866E81F1B5B04ADA76FC6A1F088AEE'; $hPout = '15783D770E47D1613B0A4E83796781742DA9772248C81BE94EE7F40DC73FAE49'
if ((HashOf $idx) -eq $hIout -and (HashOf $pq) -eq $hQout -and (HashOf $pub) -eq $hPout) { 'Patch 3a is already installed. Nothing to do.'; return }

$repI = @(
  ,@((NL @'
try { $lock = [IO.File]::Open($lockPath, 'OpenOrCreate', 'ReadWrite', 'None') } catch { Write-Host 'Another runner is already running.'; exit 1 }
'@), (NL @'
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
'@))
  ,@((NL @'
  do {
    $reqs = @(Get-ChildItem $trig -Filter '*.request.json' -File -ErrorAction SilentlyContinue)
'@), (NL @'
  do {
    try { [IO.File]::WriteAllText((Join-Path $trig 'indexer-heartbeat.txt'), (Get-Date).ToString('s'), $enc) } catch {}
    $reqs = @(Get-ChildItem $trig -Filter '*.request.json' -File -ErrorAction SilentlyContinue)
'@))
)
$repQ = @(
  ,@((NL @'
function Retire($f) { Rename-Item -LiteralPath $f.FullName -NewName ($f.Name + '.' + [guid]::NewGuid().ToString('N') + '.done') }
'@), (NL @'
function Retire($f) { Rename-Item -LiteralPath $f.FullName -NewName ($f.Name + '.' + [guid]::NewGuid().ToString('N') + '.done') }
# Patch 3a: while index requests wait, make sure the partial indexer task is running. This only starts the existing
# 'Edutek Import Partial Indexer' task when it is idle (Ready), at most once per 2 minutes. It never writes, edits or replays requests.
$trigDir = Join-Path $data 'index-trigger'
$indexerTask = 'Edutek Import Partial Indexer'
$script:wakeLast = [datetime]::MinValue; $script:wakeNoteLast = [datetime]::MinValue
function Write-IndexerNote($m) {
  Log $m
  try { [IO.File]::WriteAllText((Join-Path $queue 'indexer-watch.txt'), ((Get-Date).ToString('s') + '  ' + $m), $enc) } catch {}
}
function Wake-Indexer {
  $pend = @(Get-ChildItem -LiteralPath $trigDir -Filter '*.request.json' -File -ErrorAction SilentlyContinue)
  if ($pend.Count -eq 0) { return }
  $now = Get-Date; $hb = Join-Path $trigDir 'indexer-heartbeat.txt'; $age = -1
  if (Test-Path -LiteralPath $hb) { $age = [int]($now - (Get-Item -LiteralPath $hb).LastWriteTime).TotalSeconds }
  $ageText = $(if ($age -lt 0) { 'no indexer heartbeat' } else { "indexer heartbeat $age s old" })
  $quiet = ($now - $script:wakeNoteLast).TotalSeconds -lt 120
  try { $state = [string](Get-ScheduledTask -TaskName $indexerTask -ErrorAction Stop).State } catch {
    if (-not $quiet) { $script:wakeNoteLast = $now; Write-IndexerNote "could not read task '$indexerTask': $($_.Exception.Message)" }
    return
  }
  if ($state -ne 'Ready') {
    if (($age -lt 0 -or $age -gt 120) -and -not $quiet) { $script:wakeNoteLast = $now; Write-IndexerNote "$($pend.Count) index request(s) waiting; task '$indexerTask' is $state ($ageText). Not starting it." }
    return
  }
  if (($now - $script:wakeLast).TotalSeconds -lt 120) { return }
  $script:wakeLast = $now; $script:wakeNoteLast = $now
  try { Start-ScheduledTask -TaskName $indexerTask -ErrorAction Stop; Write-IndexerNote "started task '$indexerTask': it was idle with $($pend.Count) index request(s) waiting ($ageText)." }
  catch { Write-IndexerNote "could not start task '$indexerTask': $($_.Exception.Message)" }
}
'@))
  ,@((NL @'
    if (-not $Once) { Start-Sleep -Seconds 5 }
'@), (NL @'
    try { Wake-Indexer } catch { Log "indexer check error: $($_.Exception.Message)" }
    if (-not $Once) { Start-Sleep -Seconds 5 }
'@))
)
$repP = @(
  ,@('  Write-Host "An indexer request was queued. Run the runner once to index these files."', '  Write-Host "Indexing request $reqName was queued. Indexing starts automatically (Edutek Import Partial Indexer); watch the Step 4 status in the browser until it says Indexing complete."')
)

Build $idx $tI $hIin $hIout $repI
Build $pq $tQ $hQin $hQout $repQ
Build $pub $tP $hPin $hPout $repP
foreach ($f in $tI, $tQ, $tP) { $e = $null; [void][System.Management.Automation.Language.Parser]::ParseFile($f, [ref]$null, [ref]$e); if ($e.Count) { throw "PowerShell parse errors in $f : $e" } }
'PowerShell parse checks passed.'
foreach ($n in 'Edutek Import Partial Indexer', 'Edutek Import Publisher') { $t = Get-ScheduledTask -TaskName $n -ErrorAction Stop; "Task '$n': $($t.State)" }

if (-not $Apply) { ''; 'ALL CHECKS PASSED. Nothing live was changed. Re-run with -Apply to install.'; return }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'; $bak = Join-Path $BackupRoot $stamp
New-Item -ItemType Directory -Force -Path $bak | Out-Null
foreach ($f in $idx, $pq, $pub) { $d = Join-Path $bak ([IO.Path]::GetFileName($f)); Copy-Item -LiteralPath $f -Destination $d; if ((HashOf $f) -ne (HashOf $d)) { throw "Backup mismatch: $f" } }
"Backups OK in $bak"
Move-Item -LiteralPath $tI -Destination $idx -Force
Move-Item -LiteralPath $tQ -Destination $pq -Force
Move-Item -LiteralPath $tP -Destination $pub -Force
if ((HashOf $idx) -ne $hIout -or (HashOf $pq) -ne $hQout -or (HashOf $pub) -ne $hPout) { throw "Live hash mismatch after install. Run the rollback from $bak." }
''; "INSTALLED. Backups: $bak"
'Next: restart the indexer task, then the publisher task, as described in the instructions. The running copies still use the old code until then.'
