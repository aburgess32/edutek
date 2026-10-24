# Edutek browser publish queue watcher. Runs as the "Edutek Import Publisher" scheduled task.
# Reads data\publish-queue\<batch>.request.json written by bulk-import-guided.php, re-validates it against the
# staged batch, refuses any batch that already has a publish record, backs up the database, then runs
# Publish-Batch.ps1 -ApprovedRequest non-interactively. Writes data\publish-status\<batch>.json before retiring
# each request with a unique .done name. A request is processed once; nothing is retried silently.
# Patch 4: requests may replace reviewed library files (Publish-Batch.ps1 archives the old copies; Undo-Publish.ps1 restores them).
# Patch 4b: browser undo. <batch>.undo-plan.request.json runs Undo-Publish.ps1 as a dry run; <batch>.undo.request.json runs it with
# -Execute -ApprovedRequest (it re-checks that the plan is unchanged). Results go to data\publish-status\<batch>.undo.json. Never -OverrideChanged.
param(
  [switch]$Once,
  [string]$Root = 'D:\edutek-system\bulk-loader-fresh-v05',
  [string]$RecordDir = 'D:\edutek-system\publish-records',
  [string]$DbBackupDir = 'D:\edutek-system\backups\db',
  [int]$MaxLearningCeiling = 200,
  [string]$LibraryRoot = 'D:\xampp\htdocs\Edutek\videos',
  [string]$RecoveryRoot = 'D:\edutek-system\publish-recovery'
)
$ErrorActionPreference = 'Stop'
$data = Join-Path $Root 'data'
$queue = Join-Path $data 'publish-queue'
$statusDir = Join-Path $data 'publish-status'
$guided = Join-Path $data 'guided-v08'
$publisher = Join-Path $Root 'Publish-Batch.ps1'
$undoScript = Join-Path $Root 'Undo-Publish.ps1'
New-Item -ItemType Directory -Force -Path $queue, $statusDir, $DbBackupDir | Out-Null
$enc = New-Object Text.UTF8Encoding($false)
try { $lock = [IO.File]::Open((Join-Path $queue 'runner.lock'), 'OpenOrCreate', 'ReadWrite', 'None') } catch { Write-Host 'Another publisher is already running.'; exit 1 }

function Log($m) { Write-Host ('{0}  {1}' -f (Get-Date -Format HH:mm:ss), $m) }
function Read-Json($p) { [IO.File]::ReadAllText($p, $enc) | ConvertFrom-Json }
function S($v) { if ($v -is [datetime]) { $v.ToString('s') } elseif ($null -eq $v) { '' } else { [string]$v } }
function Tail($s, $n) { $s = S $s; if ($s.Length -gt $n) { $s.Substring($s.Length - $n) } else { $s } }
function Write-Status($id, $o) {
  $o['updated'] = (Get-Date).ToString('s')
  $tmp = Join-Path $statusDir ($id + '.status.tmp')
  [IO.File]::WriteAllText($tmp, ($o | ConvertTo-Json -Depth 5 -Compress), $enc)
  Move-Item -LiteralPath $tmp -Destination (Join-Path $statusDir ($id + '.json')) -Force
}
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
# Patch 3b: keep the device index (the library list the guided page checks for duplicates) fresh. Rebuilds it with
# Build-DeviceIndex.ps1 after a publish or any publish-record change, and when it is older than 6 hours (retried at most every 30 min).
# Also rebuilt once when the publisher task starts. Runs synchronously (about 30 s), after the indexer check, so indexing is never delayed by it. It only lists files.
$devScript = Join-Path $Root 'Build-DeviceIndex.ps1'
$devIndex = Join-Path $data 'device-index.json'
$script:dixDirty = $true; $script:dixTry = [datetime]::MinValue
function Update-DeviceIndexIfDue {
  $now = Get-Date; $age = 1000000
  if (Test-Path -LiteralPath $devIndex) { $age = ($now - (Get-Item -LiteralPath $devIndex).LastWriteTime).TotalHours }
  $why = $(if ($script:dixDirty) { 'publisher started, or a publish or publish record changed' } elseif ($age -ge 6) { 'the list is older than 6 hours' } else { '' })
  if (-not $why) { return }
  if (-not $script:dixDirty -and ($now - $script:dixTry).TotalMinutes -lt 30) { return }
  $script:dixDirty = $false; $script:dixTry = $now
  $out = ''; $code = -1
  if (-not (Test-Path -LiteralPath $devScript)) { $out = 'Build-DeviceIndex.ps1 not found.' } else {
    Log "rebuilding the device index ($why)"
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { $out = (& (Get-Process -Id $PID).Path -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $devScript -Out $devIndex -Root $LibraryRoot 2>&1 | Out-String); $code = $LASTEXITCODE }
    catch { $out = $_.Exception.Message } finally { $ErrorActionPreference = $prev }
  }
  $msg = "device index rebuild ($why): exit $code. " + (Tail $out 600).Trim()
  Log $msg
  try { [IO.File]::WriteAllText((Join-Path $queue 'device-index-refresh.txt'), ((Get-Date).ToString('s') + '  ' + $msg), $enc) } catch {}
}
function Status-FromRecord($id, $rec, $message) {
  $it = @($rec.items); $learn = @($it | Where-Object { -not $_.support })
  # Patch 4: a record reversed by Undo-Publish.ps1 shows as 'undone' in the browser, which then offers to reverse the staging and start again.
  $map = @{ published = 'published'; failed = 'failed'; publishing = 'publishing'; reversed = 'undone'; attention = 'attention' }
  $state = $map[[string]$rec.state]; if (-not $state) { $state = 'attention' }
  $mib = [math]::Round(((@($it | Measure-Object -Property bytes -Sum)[0].Sum) / 1MB), 1)
  [ordered]@{ batch = $id; state = $state; record_state = [string]$rec.state
    learning = $learn.Count; covers = $it.Count - $learn.Count; copied = @($it | Where-Object { $_.state -eq 'copied' -or $_.state -eq 'replaced' }).Count; mib = $mib
    replaced = @($it | Where-Object { $_.replace }).Count; replaced_learning = @($learn | Where-Object { $_.replace }).Count; archive_manifest = (S $rec.archive_manifest); undone = (S $rec.undone)
    undo_index_request = (S $rec.undo_index_request)
    index_request = $(if ($rec.index_requested) { 'publish-' + $id.Substring(0, 8) } else { $null })
    started = (S $rec.started); finished = (S $rec.finished); record = (Join-Path $RecordDir ($id + '.json')); message = (Tail ((S $message) + ' ' + (S $rec.error)).Trim() 3000) }
}
function Sync-Records {
  # Mirrors existing publish records (including ones made by hand, e.g. 57915b08, or reversed by Undo-Publish.ps1) so the page never offers a repeat.
  foreach ($f in @(Get-ChildItem -LiteralPath $RecordDir -Filter '*.json' -File -ErrorAction SilentlyContinue)) {
    if ($f.Name -notmatch '^([a-f0-9]{32})\.json$') { continue }
    $id = $Matches[1]
    if (-not (Test-Path -LiteralPath (Join-Path $guided $id) -PathType Container)) { continue }
    try {
      $rec = Read-Json $f.FullName; $sp = Join-Path $statusDir ($id + '.json'); $cur = $null
      if (Test-Path -LiteralPath $sp) { $cur = Read-Json $sp }
      if (-not $cur -or (S $cur.record_state) -ne (S $rec.state)) { $o = Status-FromRecord $id $rec 'Status taken from the publish record.'; $o['synced'] = $true; Write-Status $id $o; $script:dixDirty = $true; Log "synced status for $($id.Substring(0,8)) from record ($($rec.state))" }
    } catch { Log "could not sync $($f.Name): $($_.Exception.Message)" }
  }
}
function Check-Request($id, $req) {
  $p = New-Object System.Collections.Generic.List[string]
  $bd = Join-Path $guided $id; $bj = Join-Path $bd 'batch.json'
  if (Test-Path -LiteralPath (Join-Path $RecordDir ($id + '.json'))) { $p.Add('A publish record already exists for this batch. A batch is never published twice.'); return @{ problems = $p } }
  if (-not $req) { $p.Add('The request is not valid JSON.'); return @{ problems = $p } }
  if (-not (Test-Path -LiteralPath $bj -PathType Leaf)) { $p.Add('The staged batch was not found.'); return @{ problems = $p } }
  if ((Get-Item -LiteralPath $bd -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { $p.Add('The batch folder is a link.'); return @{ problems = $p } }
  $b = Read-Json $bj
  if ((S $req.batch) -cne $id) { $p.Add('The request names a different batch.') }
  if ($b.state -ne 'complete') { $p.Add("The staged batch state is '$($b.state)', not 'complete'.") }
  if (-not $b.approval -or (S $req.revision) -cne (S $b.approval)) { $p.Add('The approval revision does not match the staged batch.') }
  $want = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  $learning = New-Object System.Collections.Generic.List[string]; $byTarget = @{}
  $wantR = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($prop in @($b.operations.PSObject.Properties)) {
    $op = $prop.Value; $row = $b.rows.($prop.Name); $t = S $op.target
    if ($op.state -ne 'copied' -or -not $op.sha256) { $p.Add("Not a verified staged copy: $t"); continue }
    [void]$want.Add($t + '|' + (S $op.sha256).ToLower())
    $src = Join-Path (Join-Path $bd 'files') ($t -replace '/', '\')
    if (-not (Test-Path -LiteralPath $src -PathType Leaf)) { $p.Add("Staged file missing: $t") }
    elseif ((Get-Item -LiteralPath $src).Length -ne [int64]$op.bytes) { $p.Add("Staged file size changed: $t") }
    if (-not $row.support) { $learning.Add($t); $byTarget[$t] = $row; if ($row.replace) { [void]$wantR.Add($t + '|' + [int64]$row.replace.bytes + '|' + [int64]$row.replace.mtime); if ((S $row.replace.path) -cne $t) { $p.Add("Replace choice does not match its target: $t") } } }
  }
  $got = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($t in @($req.targets)) { if ($t -and -not $got.Add((S $t.target) + '|' + (S $t.sha256).ToLower())) { $p.Add('The request lists a file twice.') } }
  if (-not $want.SetEquals($got)) { $p.Add('The requested files or hashes differ from the staged batch.') }
  if ([int]$req.learning_count -ne $learning.Count -or [int]$req.file_count -ne $want.Count) { $p.Add('The requested counts differ from the staged batch.') }
  $gotR = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($x in @($req.replace)) { if ($x) { [void]$gotR.Add((S $x.target) + '|' + [int64]$x.bytes + '|' + [int64]$x.mtime) } }
  if (-not $wantR.SetEquals($gotR) -or [int]$req.replace_count -ne $wantR.Count) { $p.Add('The requested replacements differ from the reviewed plan.') }
  $phrase = $(if ($wantR.Count -gt 0) { "PUBLISH $($learning.Count - $wantR.Count) NEW REPLACE $($wantR.Count)" } else { "PUBLISH $($learning.Count)" })
  if ((S $req.confirm) -cne $phrase) { $p.Add('The typed confirmation does not match.') }
  if ($learning.Count -gt $MaxLearningCeiling) { $p.Add("$($learning.Count) learning items exceed the publisher limit of $MaxLearningCeiling.") }
  $ack = New-Object System.Collections.Generic.List[string]
  foreach ($a in @($req.allow_same_size)) {
    if (-not $a) { continue }; $a = S $a
    if (-not $byTarget.ContainsKey($a) -or -not $byTarget[$a].duplicate_add) { $p.Add("Same-size acknowledgement is not backed by the reviewed plan: $a") } else { $ack.Add($a) }
  }
  @{ problems = $p; learning = $learning.Count; ack = [string[]]$ack.ToArray() }
}
function Backup-Db($id) {
  $name = 'pre-publish-' + $id.Substring(0, 8) + '-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.sql'
  $dest = Join-Path $DbBackupDir $name
  & docker exec edupak-db sh -c ('mysqldump -uroot -p$MYSQL_ROOT_PASSWORD --single-transaction --default-character-set=utf8mb4 edupak > /tmp/' + $name + ' 2>/tmp/' + $name + '.err')
  if ($LASTEXITCODE -ne 0) { throw 'Database backup failed (mysqldump).' }
  & docker cp ('edupak-db:/tmp/' + $name) $dest | Out-Null
  $cpOk = ($LASTEXITCODE -eq 0)
  & docker exec edupak-db rm -f ('/tmp/' + $name) ('/tmp/' + $name + '.err') | Out-Null
  if (-not $cpOk -or -not (Test-Path -LiteralPath $dest)) { throw 'Database backup could not be copied out of edupak-db.' }
  $fs = [IO.File]::OpenRead($dest); try { $n = [Math]::Min(400, $fs.Length); [void]$fs.Seek(-$n, 'End'); $buf = New-Object byte[] $n; [void]$fs.Read($buf, 0, $n) } finally { $fs.Close() }
  if (-not $enc.GetString($buf).Contains('-- Dump completed')) { throw "Database backup looks incomplete: $dest" }
  $dest
}
function Write-UndoStatus($id, $o) {
  $o['updated'] = (Get-Date).ToString('s')
  $tmp = Join-Path $statusDir ($id + '.undo.tmp')
  [IO.File]::WriteAllText($tmp, ($o | ConvertTo-Json -Depth 7 -Compress), $enc)
  Move-Item -LiteralPath $tmp -Destination (Join-Path $statusDir ($id + '.undo.json')) -Force
}
function Process-Undo($r, $id, $kind) {
  $recPath = Join-Path $RecordDir ($id + '.json'); $usp = Join-Path $statusDir ($id + '.undo.json')
  $req = $null; try { $req = Read-Json $r.FullName } catch {}
  $cur = $null; if (Test-Path -LiteralPath $usp) { try { $cur = Read-Json $usp } catch {} }
  $p = New-Object System.Collections.Generic.List[string]
  if (-not $req) { $p.Add('The undo request is not valid JSON.') }
  elseif ((S $req.batch) -cne $id -or (S $req.kind) -cne $kind) { $p.Add('The undo request names a different batch or kind.') }
  if (-not (Test-Path -LiteralPath $recPath -PathType Leaf)) { $p.Add('This batch has no publish record, so there is nothing to undo.') }
  else { $rec = Read-Json $recPath; if ((S $rec.state) -cne 'published') { $p.Add("The publish record state is '$($rec.state)'. Only a published import can be undone from the browser; use Undo-Publish.ps1 on the Beelink.") } }
  if ($kind -eq 'undo') {
    if (-not $cur -or (S $cur.state) -cne 'planned' -or -not $cur.plan -or (S $cur.plan.outcome) -cne 'planned') { $p.Add('No current undo plan was shown for this import. Show the undo plan again.') }
    elseif ((S $req.plan_fingerprint) -cne (S $cur.plan.fingerprint)) { $p.Add('The undo plan changed since it was shown. Show the undo plan again.') }
  }
  if ($p.Count -gt 0) { Write-UndoStatus $id ([ordered]@{ batch = $id; state = 'refused'; kind = $kind; message = ($p -join "`n") + ' Nothing was changed.' }); Log "$($id.Substring(0,8))  $kind refused: $($p[0])"; return }
  $planOut = Join-Path $statusDir ($id + '.undo-result.json')
  Remove-Item -LiteralPath $planOut -Force -ErrorAction SilentlyContinue
  Write-UndoStatus $id ([ordered]@{ batch = $id; state = $(if ($kind -eq 'undo') { 'undoing' } else { 'planning' }); kind = $kind; started = (Get-Date).ToString('s'); plan = $(if ($cur) { $cur.plan } else { $null }) })
  $args3 = @{ BatchId = $id; PlanOut = $planOut; RecordDir = $RecordDir; RecoveryRoot = $RecoveryRoot; DataRoot = $data }
  if ($kind -eq 'undo') { $args3['Execute'] = $true; $args3['ApprovedRequest'] = $r.FullName }
  $out = ''; $err = ''
  try { $out = (& $undoScript @args3 *>&1 | Out-String) } catch { $err = $_.Exception.Message }
  $res = $null; if (Test-Path -LiteralPath $planOut) { try { $res = Read-Json $planOut } catch {}; Remove-Item -LiteralPath $planOut -Force -ErrorAction SilentlyContinue }
  $oc = $(if ($res) { S $res.outcome } else { '' })
  if ($kind -eq 'undo-plan') { $state = $(if ($err) { 'failed' } elseif ($oc -eq 'planned') { 'planned' } elseif ($oc -eq 'stopped') { 'stopped' } else { 'failed' }) }
  else { $state = $(if ($err) { 'failed' } elseif ($oc -eq 'done') { 'done' } elseif ($oc -eq 'stopped') { 'stopped' } elseif ($oc -eq 'mismatch') { 'mismatch' } else { 'failed' }) }
  $msg = ((S $(if ($res) { $res.message } else { '' })) + ' ' + $err).Trim()
  if (-not $msg -and $state -eq 'failed') { $msg = (Tail $out 2000).Trim() }
  Write-UndoStatus $id ([ordered]@{ batch = $id; state = $state; kind = $kind; finished = (Get-Date).ToString('s'); plan = $res; message = (Tail $msg 3000); output = (Tail $out 3000) })
  if ($kind -eq 'undo') { $script:dixDirty = $true }
  Log "$($id.Substring(0,8))  $kind -> $state"
}
function Process-Request($r, $id) {
  $rp0 = Join-Path $RecordDir ($id + '.json')
  if (Test-Path -LiteralPath $rp0) { $o = Status-FromRecord $id (Read-Json $rp0) 'A new publish request was refused: this batch already has a publish record and is never published twice.'; $o['synced'] = $true; Write-Status $id $o; Log "$($id.Substring(0,8))  refused: record exists"; return }
  $st = [ordered]@{ batch = $id; state = 'checking'; requested = $r.LastWriteTime.ToString('s'); message = '' }
  Write-Status $id $st
  $req = $null; try { $req = Read-Json $r.FullName } catch {}
  $c = Check-Request $id $req
  if ($c.problems.Count -gt 0) { $st.state = 'blocked'; $st.message = ($c.problems -join "`n"); Write-Status $id $st; Log "$($id.Substring(0,8))  blocked: $($c.problems[0])"; return }
  $st.state = 'publishing'; $st.started = (Get-Date).ToString('s'); Write-Status $id $st
  try { $st.db_backup = Backup-Db $id } catch { $st.state = 'blocked'; $st.message = $_.Exception.Message + ' Nothing was copied.'; Write-Status $id $st; Log "$($id.Substring(0,8))  blocked: db backup"; return }
  $out = ''; $err = ''
  $args2 = @{ BatchId = $id; Execute = $true; ApprovedRequest = $r.FullName; MaxLearning = $c.learning; AllowSameSizeTarget = $c.ack }
  try { $out = (& $publisher @args2 *>&1 | Out-String) } catch { $err = $_.Exception.Message }
  $recPath = Join-Path $RecordDir ($id + '.json')
  if (Test-Path -LiteralPath $recPath) {
    $s2 = Status-FromRecord $id (Read-Json $recPath) ((Tail $out 2500) + ' ' + $err)
    $s2['db_backup'] = $st.db_backup; $s2['requested'] = $st.requested; Write-Status $id $s2; $script:dixDirty = $true; Log "$($id.Substring(0,8))  $($s2.state)"
  } else {
    $st.state = 'blocked'; $st.message = ('Publish-Batch.ps1 did not publish; nothing was copied. ' + $err + "`n" + (Tail $out 3000)).Trim(); Write-Status $id $st; Log "$($id.Substring(0,8))  blocked by Publish-Batch"
  }
}
try {
  Log "Watching $queue  (Ctrl+C to stop)"
  do {
    [IO.File]::WriteAllText((Join-Path $queue 'heartbeat.txt'), (Get-Date).ToString('s'), $enc)
    try { Sync-Records } catch { Log "sync error: $($_.Exception.Message)" }
    $reqs = @(Get-ChildItem -LiteralPath $queue -Filter '*.request.json' -File -ErrorAction SilentlyContinue)
    if ($reqs.Count -gt 0) {
      Start-Sleep -Seconds 2
      foreach ($r in @(Get-ChildItem -LiteralPath $queue -Filter '*.request.json' -File | Sort-Object LastWriteTime)) {
        if ($r.Name -cmatch '^([a-f0-9]{32})\.(undo-plan|undo)\.request\.json$') {
          $uid = $Matches[1]; $uk = $Matches[2]
          try { Process-Undo $r $uid $uk } catch { Write-UndoStatus $uid ([ordered]@{ batch = $uid; state = 'failed'; kind = $uk; message = 'Publisher error: ' + $_.Exception.Message }); Log "$($uid.Substring(0,8))  $uk error: $($_.Exception.Message)" }
          Retire $r; continue
        }
        if ($r.Name -cnotmatch '^([a-f0-9]{32})\.request\.json$') { Log "ignored unexpected file $($r.Name)"; Retire $r; continue }
        $id = $Matches[1]
        try { Process-Request $r $id } catch {
          $em = 'Publisher error: ' + $_.Exception.Message; $rp = Join-Path $RecordDir ($id + '.json')
          if (Test-Path -LiteralPath $rp) { Write-Status $id (Status-FromRecord $id (Read-Json $rp) $em) } else { Write-Status $id ([ordered]@{ batch = $id; state = 'blocked'; message = $em + ' Nothing was copied.' }) }
          Log "$($id.Substring(0,8))  error: $($_.Exception.Message)"
        }
        Retire $r
      }
    }
    try { Wake-Indexer } catch { Log "indexer check error: $($_.Exception.Message)" }
    try { Update-DeviceIndexIfDue } catch { Log "device index check error: $($_.Exception.Message)" }
    if (-not $Once) { Start-Sleep -Seconds 5 }
  } while (-not $Once)
} finally { $lock.Close() }
