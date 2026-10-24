param(
  [Parameter(Mandatory=$true)][string]$BatchId,
  [switch]$Execute,
  [switch]$OverrideChanged,
  [string]$ApprovedRequest = '',
  [string]$PlanOut = '',
  [string]$RecordDir = 'D:\edutek-system\publish-records',
  [string]$RecoveryRoot = 'D:\edutek-system\publish-recovery',
  [string]$DataRoot = 'D:\edutek-system\bulk-loader-fresh-v05\data'
)
# Undo a published batch. Dry run by default; -Execute asks for the typed word UNDO.
#  - Files that were NEW in the batch: moved to publish-recovery\<batch>\ and their database rows deleted (as before).
#  - Files that REPLACED a library file (patch 4): the replacement is moved to publish-recovery\<batch>\replaced-by-this-batch\,
#    the archived original (replaced-archive\...\manifest.json) is moved back and its SHA256 checked, and a partial index
#    request (undo-<id8>, listed files only) refreshes their database rows. The manifest is marked 'restored'.
#  - Refuses when a replaced file was changed after publishing, unless -OverrideChanged (the changed file is then kept in recovery).
#  - Refuses when an archived original is missing or damaged (for example deleted by Clear-ReplacedArchive.ps1): it cannot be restored.
# Patch 4b (browser undo, run by Run-PublishQueue.ps1): -PlanOut <file> also writes the plan (counts, files, fingerprint) or the result as JSON.
#  -Execute -ApprovedRequest <undo request> runs without the typed UNDO, but only when the request names this batch and its plan
#  fingerprint and counts equal the plan computed now. -OverrideChanged is refused together with -ApprovedRequest (console only).
#  Run by hand without these parameters, the script behaves exactly as before.
$ErrorActionPreference = 'Stop'
$recDir = $RecordDir
$recoveryRoot = $RecoveryRoot
$trig = Join-Path $DataRoot 'index-trigger'
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Native([string]$rel) { $rel.Replace('/', [IO.Path]::DirectorySeparatorChar) }
function Get-Sha([string]$p) { (Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLower() }
function Move-Into([string]$from, [string]$to) {
  New-Item -ItemType Directory -Force -Path ([IO.Path]::GetDirectoryName($to)) | Out-Null
  if (Test-Path -LiteralPath $to) { $to = $to + '.' + (Get-Date -Format 'yyyyMMdd-HHmmss') }
  [IO.File]::Move($from, $to); $to
}
function SqlQ([string]$s) { "'" + $s.Replace('\','\\').Replace("'","''") + "'" }
function Invoke-Sql([string]$sql) {
  $f = Join-Path $env:TEMP ('pub-' + [guid]::NewGuid().ToString('N') + '.sql')
  [IO.File]::WriteAllText($f, $sql, $utf8)
  $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try {
    docker cp $f edupak-db:/tmp/pub.sql | Out-Null
    $out = docker exec edupak-db sh -c 'mysql -N -B -uroot -p$MYSQL_ROOT_PASSWORD --default-character-set=utf8mb4 edupak < /tmp/pub.sql 2>/dev/null'
    if ($LASTEXITCODE -ne 0) { throw 'Database command failed' }
    return $out
  } finally { $ErrorActionPreference = $prev; Remove-Item $f -Force -ErrorAction SilentlyContinue }
}

$m = @(Get-ChildItem $recDir -Filter "$BatchId*.json")
if ($m.Count -ne 1) { throw "Publish record for '$BatchId' matched $($m.Count) files." }
$recPath = $m[0].FullName
$rec = [IO.File]::ReadAllText($recPath, $utf8) | ConvertFrom-Json
if (@('published','failed','publishing','attention') -notcontains $rec.state) { throw "Record state is '$($rec.state)'; nothing to undo." }
$recovery = Join-Path $recoveryRoot $rec.batch

# Archive manifest (only for batches that replaced library files).
$man = $null; $manPath = [string]$rec.archive_manifest; $archByTarget = @{}
if (@($rec.items | Where-Object { $_.replace }).Count -gt 0) {
  if ($manPath -and (Test-Path -LiteralPath $manPath -PathType Leaf)) {
    $man = [IO.File]::ReadAllText($manPath, $utf8) | ConvertFrom-Json
    foreach ($f in @($man.files)) { if ($f -and ($f.state -eq 'archived' -or $f.state -eq 'restored')) { $archByTarget[[string]$f.target] = $f } }
  }
}

$plan = @(); $conflicts = @(); $refuse = @()
foreach ($i in $rec.items) {
  $act = 'absent'; $a = $archByTarget[[string]$i.target]; $dstHash = $null
  if (Test-Path -LiteralPath $i.dst -PathType Leaf) { $dstHash = Get-Sha $i.dst }
  if ($i.replace) {
    if (-not $a) {
      if ($i.state -eq 'replaced') { $refuse += "The archived original of $($i.target) is not listed in $manPath (archive deleted or moved). It cannot be restored, so nothing is undone." }
      else { $act = 'untouched' }   # publishing stopped before this file was replaced: the original never left.
    } elseif ($a.state -eq 'restored') {
      # An earlier undo run already put the original back.
      if ($dstHash -eq [string]$a.original_sha256) { $act = 'already-original' } else { $act = 'conflict'; $conflicts += "Changed after an earlier undo restored it, left in place: $($i.target)" }
    } else {
      if (-not (Test-Path -LiteralPath $a.archive_path -PathType Leaf)) { $refuse += "The archived original is missing: $($a.archive_path). $($i.target) cannot be restored, so nothing is undone." }
      elseif ((Get-Sha $a.archive_path) -ne [string]$a.original_sha256) { $refuse += "The archived original is damaged (SHA256 differs): $($a.archive_path). Nothing is undone." }
      if ($null -eq $dstHash) { $act = 'restore' }
      elseif ($dstHash -eq $i.sha256) { $act = 'restore' }
      elseif ($dstHash -eq [string]$a.original_sha256) { $act = 'already-original' }
      else {
        $act = 'restore-changed'
        if (-not $OverrideChanged) { $refuse += "Changed since publishing: $($i.target). The file there now is neither this batch's replacement nor the original. Re-run with -OverrideChanged to keep that file in recovery and restore the original." }
      }
    }
  } elseif ($null -ne $dstHash) {
    if ($dstHash -eq $i.sha256) { $act = 'move' } else { $act = 'conflict'; $conflicts += "Changed since publish, left in place: $($i.target)" }
  }
  $plan += [pscustomobject]@{ item = $i; action = $act; arch = $a; partial = (Test-Path -LiteralPath ($i.dst + '.partial')) }
}
$gen = @()
foreach ($i in $rec.items) {
  if ($i.gen_thumb) {
    $jpg = [IO.Path]::ChangeExtension($i.dst, '.jpg')
    if (Test-Path -LiteralPath $jpg) { $gen += [pscustomobject]@{ path = $jpg; rel = [IO.Path]::ChangeExtension($i.target, '.jpg') } }
  }
}
$dbIds = @($plan | Where-Object { $_.item.media -and -not $_.item.replace -and $_.action -ne 'conflict' } | ForEach-Object { SqlQ ('content/' + $_.item.target) })
$dbCount = 0
if ($dbIds.Count -gt 0) {
  $dbCount = [int](("" + (Invoke-Sql ("SELECT COUNT(*) FROM content_meta WHERE content_id IN (" + ($dbIds -join ',') + ");"))).Trim())
}
$restores = @($plan | Where-Object { $_.action -eq 'restore' -or $_.action -eq 'restore-changed' })
$fpLines = @(@($plan | ForEach-Object { $_.action + '|' + [string]$_.item.target }) + @($gen | ForEach-Object { 'gen|' + [string]$_.rel }) | Sort-Object) + @("db|$dbCount")
$sha = [Security.Cryptography.SHA256]::Create(); $fp = -join ($sha.ComputeHash($utf8.GetBytes($fpLines -join "`n")) | ForEach-Object { $_.ToString('x2') })
$counts = [ordered]@{ move = @($plan | Where-Object { $_.action -eq 'move' }).Count; restore = $restores.Count
  original = @($plan | Where-Object { $_.action -eq 'already-original' -or $_.action -eq 'untouched' }).Count; gen = $gen.Count
  absent = @($plan | Where-Object { $_.action -eq 'absent' }).Count; db_rows = $dbCount; db_expected = $dbIds.Count }
$idxName = $null
function Emit-Plan([string]$outcome, [string]$msg) {
  if (-not $PlanOut) { return }
  $files = @($plan | Where-Object { $_.action -ne 'absent' } | ForEach-Object { [ordered]@{ action = $_.action; target = [string]$_.item.target; archive = $(if ($_.arch) { [string]$_.arch.archive_path } else { '' }); media = [bool]$_.item.media } })
  $o = [ordered]@{ batch = [string]$rec.batch; record_state = [string]$rec.state; outcome = $outcome; message = $msg; fingerprint = $fp; counts = $counts
    files = $files; generated = @($gen | ForEach-Object { [string]$_.rel }); conflicts = @($conflicts); refuse = @($refuse)
    changed = @($plan | Where-Object { $_.action -eq 'restore-changed' }).Count; index_request = $idxName; written = (Get-Date).ToString('s') }
  $tmp = $PlanOut + '.tmp'
  [IO.File]::WriteAllText($tmp, ($o | ConvertTo-Json -Depth 5), $utf8)
  Move-Item -LiteralPath $tmp -Destination $PlanOut -Force
}
if ($ApprovedRequest) {
  if ($OverrideChanged) { Emit-Plan 'mismatch' '-OverrideChanged is never used for a browser request. Run Undo-Publish.ps1 by hand on the Beelink for that.'; throw '-OverrideChanged cannot be combined with -ApprovedRequest.' }
  if (-not $Execute) { throw '-ApprovedRequest needs -Execute.' }
  $areq = $null; try { $areq = [IO.File]::ReadAllText($ApprovedRequest, $utf8) | ConvertFrom-Json } catch {}
  $bad = @()
  if (-not $areq) { $bad += 'the request is not valid JSON' } else {
    if ([string]$areq.batch -cne [string]$rec.batch) { $bad += 'it names a different batch' }
    if ([string]$areq.kind -cne 'undo') { $bad += 'it is not an undo request' }
    if ([string]$areq.plan_fingerprint -cne $fp) { $bad += 'the plan changed since it was shown' }
    foreach ($k in @('move', 'restore', 'gen', 'db_rows')) { if ($null -eq $areq.counts -or [string]$areq.counts.$k -cne [string]$counts[$k]) { $bad += "count '$k' differs" } }
  }
  if ($bad.Count -gt 0) {
    $mm = 'The undo request does not match the undo plan computed now (' + ($bad -join '; ') + '). Nothing was changed. Show the undo plan again.'
    Write-Host $mm -ForegroundColor Red; Emit-Plan 'mismatch' $mm; return
  }
}

Write-Host ""
Write-Host "Undo plan for batch $($rec.batch)"
Write-Host ("  New files to move to recovery:      {0}" -f @($plan | Where-Object { $_.action -eq 'move' }).Count)
Write-Host ("  Replaced files to restore:          {0}" -f $restores.Count)
foreach ($p in $restores) { Write-Host ("    restore " + $p.item.target + "  <=  " + $p.arch.archive_path + $(if ($p.action -eq 'restore-changed') { '  (CHANGED file there is kept in recovery)' } else { '' })) }
Write-Host ("  Already the original / untouched:   {0}" -f @($plan | Where-Object { $_.action -eq 'already-original' -or $_.action -eq 'untouched' }).Count)
Write-Host ("  Generated thumbnails to move:       {0}" -f $gen.Count)
Write-Host ("  Already absent:                     {0}" -f @($plan | Where-Object { $_.action -eq 'absent' }).Count)
Write-Host ("  Database rows to delete:            {0} (at most {1} expected; replaced files keep their rows and are re-indexed)" -f $dbCount, $dbIds.Count)
foreach ($c in $conflicts) { Write-Host ("  ! " + $c) -ForegroundColor Yellow }
if ($refuse.Count -gt 0) { Write-Host ""; Write-Host 'STOPPED. Nothing was changed:' -ForegroundColor Red; foreach ($x in $refuse) { Write-Host ("  - " + $x) -ForegroundColor Red }; Emit-Plan 'stopped' ('STOPPED. Nothing was changed: ' + ($refuse -join ' ')); return }
if ($dbCount -gt $dbIds.Count) { Write-Host 'STOPPED: more database rows match than this batch published.' -ForegroundColor Red; Emit-Plan 'stopped' 'STOPPED: more database rows match than this batch published. Nothing was changed.'; return }
if (-not $Execute) { Write-Host ""; Write-Host 'DRY RUN. Nothing was changed. Re-run with -Execute to undo.' -ForegroundColor Green; Emit-Plan 'planned' 'Dry run. Nothing was changed.'; return }

if ($ApprovedRequest) { $conf = 'UNDO'; Write-Host 'Approved in the browser: the request matches this plan, so the typed UNDO is not asked.' }
else { $conf = Read-Host 'Type UNDO to undo this batch (move its new files to recovery, restore replaced originals, delete the new files'' database rows)' }
if ($conf -ne 'UNDO') { Write-Host 'Cancelled.'; return }

if ($dbIds.Count -gt 0) {
  Invoke-Sql ("DELETE FROM content_meta WHERE content_id IN (" + ($dbIds -join ',') + ");") | Out-Null
  $left = [int](("" + (Invoke-Sql ("SELECT COUNT(*) FROM content_meta WHERE content_id IN (" + ($dbIds -join ',') + ");"))).Trim())
  if ($left -ne 0) { throw "Database rows remain after delete: $left" }
}
$restored = @()
foreach ($p in $plan) {
  if ($p.partial) { Remove-Item -LiteralPath ($p.item.dst + '.partial') -Force }
  if ($p.action -eq 'move') {
    [void](Move-Into $p.item.dst (Join-Path $recovery (Native $p.item.target)))
  }
  if ($p.action -eq 'restore' -or $p.action -eq 'restore-changed') {
    $sub = $(if ($p.action -eq 'restore') { 'replaced-by-this-batch' } else { 'changed-after-publish' })
    if (Test-Path -LiteralPath $p.item.dst) { [void](Move-Into $p.item.dst (Join-Path (Join-Path $recovery $sub) (Native $p.item.target))) }
    [IO.File]::Move($p.arch.archive_path, $p.item.dst)
    if ((Get-Sha $p.item.dst) -ne [string]$p.arch.original_sha256) { throw "Restored file does not match the original SHA256: $($p.item.dst)" }
    $restored += $p
    Write-Host ("  restored " + $p.item.target)
  }
}
foreach ($g in $gen) {
  [void](Move-Into $g.path (Join-Path (Join-Path $recovery 'generated') (Native $g.rel)))
}
foreach ($d in @($rec.created_dirs) | Sort-Object { $_.Length } -Descending) {
  if ((Test-Path -LiteralPath $d) -and -not (Get-ChildItem -LiteralPath $d -Force | Select-Object -First 1)) { Remove-Item -LiteralPath $d -Force }
}
$idxFiles = @($restored | Where-Object { $_.item.media } | ForEach-Object { [string]$_.item.target })
if ($idxFiles.Count -gt 0) {
  $idxName = 'undo-' + ([string]$rec.batch).Substring(0, 8) + '-' + (Get-Date -Format 'HHmmss')
  New-Item -ItemType Directory -Force -Path $trig | Out-Null
  $tmpReq = Join-Path $trig ($idxName + '.request.tmp')
  [IO.File]::WriteAllText($tmpReq, ([ordered]@{ batch = $idxName; files = $idxFiles } | ConvertTo-Json -Compress), $utf8)
  Move-Item -LiteralPath $tmpReq -Destination (Join-Path $trig ($idxName + '.request.json')) -Force
}
if ($man) {
  foreach ($f in @($man.files)) { if ($f -and @($restored | Where-Object { $_.arch.target -eq $f.target }).Count) { $f.state = 'restored' } }
  $man.state = 'restored'; $man | Add-Member -NotePropertyName restored -NotePropertyValue ((Get-Date).ToString('s')) -Force
  [IO.File]::WriteAllText($manPath, ($man | ConvertTo-Json -Depth 5), $utf8)
}
$rec.state = $(if ($conflicts.Count -gt 0) { 'attention' } else { 'reversed' })
$rec | Add-Member -NotePropertyName undone -NotePropertyValue ((Get-Date).ToString('s')) -Force
$rec | Add-Member -NotePropertyName undo_index_request -NotePropertyValue $idxName -Force
$rec | Add-Member -NotePropertyName undo_restored -NotePropertyValue $restored.Count -Force
[IO.File]::WriteAllText($recPath, ($rec | ConvertTo-Json -Depth 6), $utf8)
Write-Host ""
Write-Host ("UNDO finished: " + $rec.state) -ForegroundColor Green
Write-Host "Moved files are in $recovery"
if ($restored.Count) { Write-Host "$($restored.Count) replaced file(s) were restored from the archive." }
if ($idxName) { Write-Host "Partial index request $idxName was queued for the restored files (listed files only)." }
Write-Host 'In the browser this import now shows as undone; reverse its staging there to start again.'
Emit-Plan 'done' ("UNDO finished: " + $rec.state + ". " + $restored.Count + " replaced file(s) restored. Moved files are in " + $recovery)
