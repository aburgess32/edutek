<#
Restore-AudiobookParts.ps1  (r1, 2026-10-24)

Why: duplicate-quarantine run 20261023-063248 (Move-LibraryDuplicates-r2) moved
"exact duplicate" audio parts out of audiobook folders whose kept copy lives in a
DIFFERENT folder (e.g. "365 Bedtime Stories" -> kept in "Bedtime Stories",
"7 Habits of Highly Effective Families" -> kept as "Discipleship\Discipleship N.mp3").
audiobooks.php lists every top-level folder and listen.php plays only the audio
found inside that folder, so those folders now say "No playable audio found".

What it does: moves those parts back from the quarantine to their original paths
(same D: volume, a rename - nothing is copied or deleted) and re-inserts their
catalog rows from the run's own row backup. Kept copies are not touched.

  (default)   check only - prints the plan, writes nothing
  -Apply      backup + plan + journal in D:\edutek-system\backups\audiobook-restore\<stamp>,
              moves the parts back, restores rows, verifies with the real player page
  -Rollback latest|<stamp>   moves the restored parts back into the quarantine and
              removes exactly the rows this script inserted, then verifies
  -Scope Emptied (default)   only folders that have NO playable audio left
  -Scope EmptiedAndPartial   also folders that lost some parts to another folder
  -Only "<wildcard>"         limit to matching folder names, e.g. -Only "365 Bedtime*"
  -NoRows                    move files only, leave the catalog alone

Never deletes library files. Never overwrites: any target that already exists is skipped.
NOTE: while this restore is applied, Move-LibraryDuplicates-r2 -Rollback 20261023-063248
will refuse (paths/rows already back). Run this script's -Rollback first if you ever need that.
#>
[CmdletBinding()]
param(
  [switch]$Apply,
  [string]$Rollback = '',
  [ValidateSet('Emptied', 'EmptiedAndPartial')][string]$Scope = 'Emptied',
  [string]$Only = '',
  [switch]$NoRows,
  [string]$Run = '20261023-063248',
  [string]$Container = 'edupak-app',
  [string]$DockerContext = 'desktop-linux',
  [string]$DockerExe = ''
)
Set-StrictMode -Version 2
$ErrorActionPreference = 'Stop'
$utf8 = New-Object System.Text.UTF8Encoding($false)

$LibRoot  = 'D:\xampp\htdocs\Edutek\videos'
$RunBk    = "D:\edutek-system\backups\library-dupes\$Run"
$QRoot    = "D:\edutek-system\quarantine\library-dupes-$Run"
$BkRoot   = 'D:\edutek-system\backups\audiobook-restore'
$AudioExt = @('.mp3', '.m4a', '.m4b', '.wma', '.wav', '.flac', '.ogg')   # same list as listen.php
$Libs     = @('Audiobooks', 'Music')

function Say([string]$m) { Write-Host $m }
function Log([string]$bk, [string]$m) {
  $line = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $m
  [IO.File]::AppendAllText((Join-Path $bk 'journal.log'), $line + "`r`n", $utf8)
  Say "  $m"
}

# ------------------------------------------------------------------ docker / php helper
function Resolve-Docker {
  if ($DockerExe) { return $DockerExe }
  $p = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe'
  if (Test-Path -LiteralPath $p) { return $p }
  return 'docker'
}
$script:Docker = Resolve-Docker
$script:DockerArgs = @(); if ($DockerContext) { $script:DockerArgs = @('--context', $DockerContext) }

$HelperPhp = @'
<?php
if (PHP_SAPI !== 'cli') { exit(1); }
function ar_out($a) { echo "\nARR:" . base64_encode(json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n"; exit(0); }
try {
  $in = json_decode(base64_decode('__AR_INPUT__'), true);
  if (!is_array($in)) { ar_out(['state' => 'error', 'message' => 'bad input']); }
  $mode = (string)($in['mode'] ?? '');
  if ($mode === 'listen') {   /* read-only: what the real player page shows for each folder */
    $c = "BF-CBC"; $iv = "91011121"; $k = "hfjfydjnvhbjfi";
    $e = function ($s) use ($c, $iv, $k) { return str_replace('=', '[equal]', base64_encode(openssl_encrypt($s, $c, $k, 0, $iv))); };
    $out = [];
    foreach ($in['folders'] as $f) {   /* f = "Audiobooks/<name>" */
      $p = explode('/', $f, 2); $web = 'videos/' . $p[0] . '/' . rawurlencode($p[1]) . '/';
      $u = 'http://localhost/listen.php?&' . $e('videolink1') . '=&' . $e('videoname1') . '=&' . $e('videolink') . '=' . $e($web) . '&' . $e('videoname') . '=' . $e($p[1]);
      $h = curl_init($u); curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 60]); $b = (string)curl_exec($h); $st = curl_getinfo($h, CURLINFO_HTTP_CODE); curl_close($h);
      $src = ''; if (preg_match('#<audio id="audio-player" src="([^"]*)"#', $b, $m)) { $src = html_entity_decode($m[1]); }
      $as = 0; $ct = '';
      if ($src !== '') { $h = curl_init('http://localhost/' . $src); curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_RANGE => '0-1023', CURLOPT_TIMEOUT => 60]); curl_exec($h); $as = curl_getinfo($h, CURLINFO_HTTP_CODE); $ct = (string)curl_getinfo($h, CURLINFO_CONTENT_TYPE); curl_close($h); }
      $out[] = ['folder' => $f, 'page' => $st, 'noplay' => (strpos($b, 'No playable audio found') !== false), 'tracks' => preg_match_all('#fa-music#', $b) + ($src !== '' ? 1 : 0), 'first' => $as, 'type' => $ct];
    }
    ar_out(['state' => 'ok', 'folders' => $out]);
  }
  require_once '/var/www/html/includes/config.php';
  require_once '/var/www/html/includes/auth.php';
  $pdo = getDbConnection(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $t = 'content_meta';
  $rows = $in['rows'] ?? [];
  $byId = $pdo->prepare("SELECT id, content_id, file_path FROM `$t` WHERE id = ?");
  $byPath = $pdo->prepare("SELECT id FROM `$t` WHERE (file_path = ? OR content_id = ?) AND id <> ?");
  if ($mode === 'check') {   /* read-only */
    $res = [];
    foreach ($rows as $r) {
      $byId->execute([(int)$r['id']]); $x = $byId->fetch(PDO::FETCH_ASSOC);
      $byPath->execute([$r['file_path'], $r['content_id'], (int)$r['id']]); $o = $byPath->fetchColumn();
      $s = 'insert';
      if ($x) { $s = ($x['file_path'] === $r['file_path'] && $x['content_id'] === $r['content_id']) ? 'present' : 'id-taken'; }
      elseif ($o !== false) { $s = 'path-in-row-' . $o; }
      $res[] = ['id' => (int)$r['id'], 's' => $s];
    }
    ar_out(['state' => 'ok', 'rows' => $res]);
  }
  if ($mode === 'insert') {
    $pdo->beginTransaction(); $ins = []; $same = 0;
    foreach ($rows as $r) {
      foreach (array_keys($r) as $kk) { if (!preg_match('/^[a-z_0-9]+$/', $kk)) { $pdo->rollBack(); ar_out(['state' => 'error', 'message' => 'bad column']); } }
      $byId->execute([(int)$r['id']]); $x = $byId->fetch(PDO::FETCH_ASSOC);
      if ($x) {
        if ($x['file_path'] === $r['file_path'] && $x['content_id'] === $r['content_id']) { $same++; continue; }
        $pdo->rollBack(); ar_out(['state' => 'error', 'message' => 'id ' . $r['id'] . ' is used by another row; nothing inserted']);
      }
      $byPath->execute([$r['file_path'], $r['content_id'], (int)$r['id']]); $o = $byPath->fetchColumn();
      if ($o !== false) { $pdo->rollBack(); ar_out(['state' => 'error', 'message' => 'path of row ' . $r['id'] . ' is already in row ' . $o . '; nothing inserted']); }
      $kk = array_keys($r);
      $pdo->prepare("INSERT INTO `$t` (`" . implode('`,`', $kk) . "`) VALUES (" . implode(',', array_fill(0, count($kk), '?')) . ")")->execute(array_values($r));
      $ins[] = (int)$r['id'];
    }
    $pdo->commit(); ar_out(['state' => 'ok', 'inserted' => $ins, 'already' => $same]);
  }
  if ($mode === 'delete') {   /* only rows whose id AND paths still match what we inserted */
    $pdo->beginTransaction(); $n = 0; $gone = 0;
    $del = $pdo->prepare("DELETE FROM `$t` WHERE id = ? AND content_id = ? AND file_path = ?");
    foreach ($rows as $r) {
      $byId->execute([(int)$r['id']]); $x = $byId->fetch(PDO::FETCH_ASSOC);
      if (!$x) { $gone++; continue; }
      if ($x['file_path'] !== $r['file_path'] || $x['content_id'] !== $r['content_id']) { $pdo->rollBack(); ar_out(['state' => 'error', 'message' => 'row ' . $r['id'] . ' changed since restore; nothing deleted']); }
      $del->execute([(int)$r['id'], $r['content_id'], $r['file_path']]); $n += $del->rowCount();
    }
    $pdo->commit(); ar_out(['state' => 'ok', 'deleted' => $n, 'gone' => $gone]);
  }
  ar_out(['state' => 'error', 'message' => 'unknown mode']);
} catch (Throwable $ex) {
  try { if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable $e2) { }
  ar_out(['state' => 'error', 'message' => get_class($ex) . ': ' . $ex->getMessage()]);
}
'@

function Invoke-Helper([hashtable]$in) {
  $json = $in | ConvertTo-Json -Depth 8 -Compress
  $code = $HelperPhp.Replace('__AR_INPUT__', [Convert]::ToBase64String($utf8.GetBytes($json)))
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @($code | & $script:Docker @script:DockerArgs exec -i $Container php 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
  $line = @($o | Where-Object { $_.StartsWith('ARR:') }) | Select-Object -Last 1
  if (-not $line) { throw ("helper ($($in.mode)) gave no result: " + ((@($o) | Select-Object -Last 4) -join ' / ')) }
  $res = $utf8.GetString([Convert]::FromBase64String($line.Substring(4).Trim())) | ConvertFrom-Json
  if ($res.state -ne 'ok') { throw ("helper ($($in.mode)): " + $res.message) }
  return $res
}
function Invoke-Chunked([string]$mode, $rows, [int]$size = 200) {
  $all = @($rows); $out = New-Object System.Collections.ArrayList
  for ($i = 0; $i -lt $all.Count; $i += $size) {
    $n = [Math]::Min($size, $all.Count - $i)
    [void]$out.Add((Invoke-Helper @{ mode = $mode; rows = @($all[$i..($i + $n - 1)]) }))
  }
  return ,$out
}
function Get-ListenCheck([string[]]$tops) {
  $f = @($tops | ForEach-Object { $_.Replace('\', '/') })
  if (-not $f.Count) { return @() }
  return @((Invoke-Helper @{ mode = 'listen'; folders = $f }).folders)
}

# ------------------------------------------------------------------ helpers
function Get-Top([string]$p) {
  if (-not $p) { return '' }
  $pre = $LibRoot + '\'
  if (-not $p.StartsWith($pre, [StringComparison]::OrdinalIgnoreCase)) { return '' }
  $s = $p.Substring($pre.Length).Split('\')
  if ($s.Count -lt 3) { return '' }
  return $s[0] + '\' + $s[1]
}
function Get-AudioCount([string]$dir) {
  if (-not (Test-Path -LiteralPath $dir -PathType Container)) { return -1 }
  return @(Get-ChildItem -LiteralPath $dir -Recurse -File -Force -ErrorAction SilentlyContinue | Where-Object { $AudioExt -contains $_.Extension.ToLower() }).Count
}
function Test-File([string]$p) { try { return [IO.File]::Exists($p) } catch { return $false } }
function Get-Len([string]$p) { try { return (New-Object IO.FileInfo($p)).Length } catch { return -1 } }
function To-Hash($o) {   # PSCustomObject from ConvertFrom-Json -> ordered hashtable (keeps nulls)
  $h = [ordered]@{}; foreach ($pp in $o.PSObject.Properties) { $h[$pp.Name] = $pp.Value }; return $h
}

# ------------------------------------------------------------------ plan
function Get-Plan {
  if (-not (Test-Path -LiteralPath (Join-Path $RunBk 'manifest.csv'))) { throw "manifest not found: $RunBk\manifest.csv" }
  Say "Reading manifest of run $Run ..."
  $man = @(Import-Csv -LiteralPath (Join-Path $RunBk 'manifest.csv') -Encoding UTF8)
  $aud = New-Object System.Collections.ArrayList
  foreach ($e in $man) {
    if ($e.Type -ne 'file') { continue }
    $top = Get-Top $e.OriginalPath; if (-not $top) { continue }
    if ($Libs -notcontains $top.Split('\')[0]) { continue }
    $ext = ''; try { $ext = [IO.Path]::GetExtension($e.OriginalPath).ToLower() } catch { }
    if ($AudioExt -notcontains $ext) { continue }
    [void]$aud.Add([pscustomobject]@{ Top = $top; KeeperTop = (Get-Top $e.KeeperPath); Orig = $e.OriginalPath; Q = $e.QuarantinePath; Keeper = $e.KeeperPath; Size = [int64]$e.SizeBytes; RowIds = $e.RowIds })
  }
  $folders = @{}
  foreach ($g in ($aud | Group-Object Top)) {
    $cross = @($g.Group | Where-Object { $_.Top -ne $_.KeeperTop })
    if (-not $cross.Count) { continue }
    $name = $g.Name.Split('\')[1]
    if ($Only -and ($name -notlike $Only)) { continue }
    $cur = Get-AudioCount (Join-Path $LibRoot $g.Name)
    $kind = ''
    if ($cur -eq 0) { $kind = 'EMPTIED' } elseif ($cur -gt 0) { $kind = 'PARTIAL' } else { $kind = 'FOLDER-GONE' }
    $take = $false
    if ($kind -eq 'EMPTIED') { $take = $true }
    if ($kind -eq 'PARTIAL' -and $Scope -eq 'EmptiedAndPartial') { $take = $true }
    $items = @()
    if ($kind -eq 'EMPTIED') { $items = @($g.Group) } else { $items = $cross }
    $folders[$g.Name] = [pscustomobject]@{ Top = $g.Name; Kind = $kind; AudioNow = $cur; Lost = $items.Count; Selected = $take; Items = $items; Keepers = (@($items | ForEach-Object { $_.KeeperTop } | Sort-Object -Unique) -join '; ') }
  }
  $sel = New-Object System.Collections.ArrayList
  foreach ($f in ($folders.Values | Where-Object { $_.Selected } | Sort-Object Top)) {
    foreach ($it in $f.Items) {
      $st = 'OK'
      if (-not (Test-File $it.Q)) { $st = 'SKIP: not in quarantine' }
      elseif ((Get-Len $it.Q) -ne $it.Size) { $st = 'SKIP: quarantine size differs from manifest' }
      elseif (Test-File $it.Orig) { $st = 'SKIP: original path already has a file' }
      elseif ($it.Orig.Length -ge 260 -or $it.Q.Length -ge 260) { $st = 'SKIP: path 260+ chars' }
      [void]$sel.Add([pscustomobject]@{ Folder = $f.Top; Kind = $f.Kind; Status = $st; Size = $it.Size; OriginalPath = $it.Orig; QuarantinePath = $it.Q; KeeperPath = $it.Keeper; RowIds = $it.RowIds })
    }
  }
  # rows from the run's own backup (rows-*.json), matched by the original path
  $rows = New-Object System.Collections.ArrayList
  if (-not $NoRows -and $sel.Count) {
    $want = @{}
    foreach ($s in $sel) { if ($s.Status -eq 'OK') { $want[('content/' + $s.OriginalPath.Substring($LibRoot.Length + 1).Replace('\', '/'))] = $true } }
    foreach ($jf in (Get-ChildItem -LiteralPath $RunBk -Filter 'rows-*.json' | Sort-Object Name)) {
      $arr = [IO.File]::ReadAllText($jf.FullName, $utf8) | ConvertFrom-Json
      foreach ($r in $arr) { if ($want.ContainsKey([string]$r.file_path)) { [void]$rows.Add((To-Hash $r)) } }
    }
  }
  return [pscustomobject]@{ Folders = @($folders.Values | Sort-Object Kind, Top); Items = @($sel); Rows = @($rows) }
}

function Show-Plan($plan) {
  $em = @($plan.Folders | Where-Object { $_.Kind -eq 'EMPTIED' }); $pa = @($plan.Folders | Where-Object { $_.Kind -eq 'PARTIAL' })
  Say ''
  Say ("Folders with NO playable audio (emptied by the run): {0}" -f $em.Count)
  Say ("Folders that lost some parts to another folder:      {0}" -f $pa.Count)
  Say ("Scope: $Scope" + $(if ($Only) { "  Only: $Only" } else { '' }))
  Say ''
  foreach ($f in ($plan.Folders | Where-Object { $_.Selected })) { Say ("  {0,-8} {1}  (audio now {2}, parts to restore {3}; kept copies in: {4})" -f $f.Kind, $f.Top, $f.AudioNow, $f.Lost, $f.Keepers) }
  $ok = @($plan.Items | Where-Object { $_.Status -eq 'OK' }); $sk = @($plan.Items | Where-Object { $_.Status -ne 'OK' })
  $gb = (($ok | Measure-Object Size -Sum).Sum) / 1GB
  Say ''
  Say ("Parts to move back: {0} ({1:N2} GB, same drive - a rename, no copy); skipped: {2}" -f $ok.Count, $gb, $sk.Count)
  foreach ($s in ($sk | Select-Object -First 10)) { Say "  $($s.Status): $($s.OriginalPath)" }
  if ($NoRows) { Say 'Catalog rows: not touched (-NoRows)' } else { Say ("Catalog rows to restore from the run backup: {0}" -f $plan.Rows.Count) }
}

# ------------------------------------------------------------------ rollback
if ($Rollback) {
  $stamp = $Rollback
  if ($stamp -eq 'latest') {
    $last = Get-ChildItem -LiteralPath $BkRoot -Directory -ErrorAction SilentlyContinue | Where-Object { Test-Path -LiteralPath (Join-Path $_.FullName 'moved.tsv') } | Sort-Object Name | Select-Object -Last 1
    if (-not $last) { throw "no restore found in $BkRoot" }
    $stamp = $last.Name
  }
  $bk = Join-Path $BkRoot $stamp
  if (-not (Test-Path -LiteralPath (Join-Path $bk 'moved.tsv'))) { throw "not a restore folder: $bk" }
  Log $bk "ROLLBACK of restore $stamp started"
  $ins = Join-Path $bk 'rows-inserted.json'
  if (Test-Path -LiteralPath $ins) {
    $arr = @([IO.File]::ReadAllText($ins, $utf8) | ConvertFrom-Json | ForEach-Object { To-Hash $_ })
    if ($arr.Count) {
      $del = 0; foreach ($r in (Invoke-Chunked 'delete' $arr)) { $del += [int]$r.deleted }
      Log $bk "rows removed: $del of $($arr.Count)"
    }
  }
  $moved = @([IO.File]::ReadAllLines((Join-Path $bk 'moved.tsv'), $utf8) | Where-Object { $_ } | ForEach-Object { $p = $_.Split("`t"); [pscustomobject]@{ Orig = $p[0]; Q = $p[1]; Size = [int64]$p[2] } })
  $back = 0; $bad = New-Object System.Collections.ArrayList
  foreach ($m in $moved) {
    if (-not (Test-File $m.Orig)) { [void]$bad.Add("missing now: $($m.Orig)"); continue }
    if (Test-File $m.Q) { [void]$bad.Add("quarantine slot occupied: $($m.Q)"); continue }
    if ((Get-Len $m.Orig) -ne $m.Size) { [void]$bad.Add("size changed: $($m.Orig)"); continue }
    $qd = [IO.Path]::GetDirectoryName($m.Q); if (-not [IO.Directory]::Exists($qd)) { [void][IO.Directory]::CreateDirectory($qd) }
    [IO.File]::Move($m.Orig, $m.Q)   # throws if the target exists - never overwrites
    [IO.File]::AppendAllText((Join-Path $bk 'rolled-back.tsv'), "$($m.Orig)`t$($m.Q)`r`n", $utf8)
    $back++
  }
  $cd = Join-Path $bk 'created-dirs.txt'
  if (Test-Path -LiteralPath $cd) {
    foreach ($dd in ([IO.File]::ReadAllLines($cd, $utf8) | Where-Object { $_ } | Sort-Object Length -Descending)) {
      if ((Test-Path -LiteralPath $dd) -and -not @(Get-ChildItem -LiteralPath $dd -Force).Count) { Remove-Item -LiteralPath $dd; Log $bk "removed empty folder this restore created: $dd" }
    }
  }
  Log $bk "files moved back to quarantine: $back of $($moved.Count)"
  foreach ($b in $bad) { Log $bk "  NOT moved: $b" }
  if ($bad.Count) { Log $bk 'ROLLBACK finished WITH PROBLEMS (see above)'; exit 2 }
  Log $bk 'ROLLBACK done: every restored file is back in the quarantine and the inserted rows are removed'
  exit 0
}

# ------------------------------------------------------------------ check / apply
$plan = Get-Plan
Show-Plan $plan
$ok = @($plan.Items | Where-Object { $_.Status -eq 'OK' })
$tops = @($plan.Folders | Where-Object { $_.Selected } | ForEach-Object { $_.Top })
$blocking = @()

if ($plan.Rows.Count) {
  $chk = @(); foreach ($r in (Invoke-Chunked 'check' $plan.Rows)) { $chk += @($r.rows) }
  $grp = $chk | Group-Object s
  foreach ($g in $grp) { Say ("  rows {0}: {1}" -f $g.Name, $g.Count) }
  $blocking = @($chk | Where-Object { $_.s -ne 'insert' -and $_.s -ne 'present' })
  if ($blocking.Count) { Say ("  NOTE: {0} row(s) cannot be restored (id or path now used elsewhere); -Apply will stop. Use -NoRows to restore files only." -f $blocking.Count) }
}

if (-not $Apply) {
  Say ''
  Say 'Player page now (listen.php, what users see):'
  $lc = Get-ListenCheck $tops
  Say ("  folders showing 'No playable audio found': {0} of {1}" -f @($lc | Where-Object { $_.noplay }).Count, $lc.Count)
  Say ''
  Say 'CHECK ONLY - nothing was changed. Run again with -Apply to restore.'
  exit 0
}

if (-not $ok.Count) { Say 'Nothing to restore.'; exit 0 }
if ($plan.Rows.Count -and $blocking.Count) { throw 'some rows cannot be restored; nothing changed. Re-run with -NoRows to restore the files only.' }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$bk = Join-Path $BkRoot $stamp
if (Test-Path -LiteralPath $bk) { throw "backup folder already exists: $bk" }
[void](New-Item -ItemType Directory -Path $bk -Force)
Log $bk "RESTORE $stamp from run $Run, scope $Scope, only '$Only'"
$plan.Items | Export-Csv -LiteralPath (Join-Path $bk 'plan.csv') -NoTypeInformation -Encoding UTF8
$plan.Folders | Select-Object Top, Kind, AudioNow, Lost, Selected, Keepers | Export-Csv -LiteralPath (Join-Path $bk 'folders.csv') -NoTypeInformation -Encoding UTF8
[IO.File]::WriteAllText((Join-Path $bk 'rows-planned.json'), (ConvertTo-Json -InputObject @($plan.Rows) -Depth 4), $utf8)
$lc0 = Get-ListenCheck $tops
[IO.File]::WriteAllText((Join-Path $bk 'player-before.json'), (ConvertTo-Json -InputObject @($lc0) -Depth 4), $utf8)
Log $bk "backup written: plan.csv, folders.csv, rows-planned.json, player-before.json"
[IO.File]::WriteAllText((Join-Path $bk 'moved.tsv'), '', $utf8)

$n = 0
try {
  foreach ($it in $ok) {
    if (Test-File $it.OriginalPath) { Log $bk "skip (appeared meanwhile): $($it.OriginalPath)"; continue }
    $od = [IO.Path]::GetDirectoryName($it.OriginalPath)
    if (-not [IO.Directory]::Exists($od)) {
      [void][IO.Directory]::CreateDirectory($od)
      [IO.File]::AppendAllText((Join-Path $bk 'created-dirs.txt'), $od + "`r`n", $utf8)
    }
    [IO.File]::Move($it.QuarantinePath, $it.OriginalPath)   # throws if the target exists - never overwrites
    [IO.File]::AppendAllText((Join-Path $bk 'moved.tsv'), "$($it.OriginalPath)`t$($it.QuarantinePath)`t$($it.Size)`r`n", $utf8)
    $n++
    if ($n % 100 -eq 0) { Log $bk "moved $n / $($ok.Count)" }
  }
  Log $bk "moved back $n file(s)"
  if ($plan.Rows.Count) {
    $insIds = @(); $already = 0
    foreach ($r in (Invoke-Chunked 'insert' $plan.Rows)) { $insIds += @($r.inserted); $already += [int]$r.already }
    $insRows = @($plan.Rows | Where-Object { $insIds -contains [int]$_['id'] })
    [IO.File]::WriteAllText((Join-Path $bk 'rows-inserted.json'), (ConvertTo-Json -InputObject $insRows -Depth 4), $utf8)
    Log $bk "catalog rows restored: $($insIds.Count) (already present: $already)"
  }
} catch {
  Log $bk ("FAILED: " + $_.Exception.Message)
  Log $bk "undo what was done so far with:  -Rollback $stamp"
  exit 1
}

# verify
$probs = 0
foreach ($it in $ok) { if (-not (Test-File $it.OriginalPath) -or (Get-Len $it.OriginalPath) -ne $it.Size) { $probs++ } }
$lc = Get-ListenCheck $tops
[IO.File]::WriteAllText((Join-Path $bk 'player-after.json'), (ConvertTo-Json -InputObject @($lc) -Depth 4), $utf8)
$still = @($lc | Where-Object { $_.noplay -or $_.first -ne 206 })
Log $bk ("verify: files in place {0}/{1}; player pages playable {2}/{3}" -f ($ok.Count - $probs), $ok.Count, ($lc.Count - $still.Count), $lc.Count)
foreach ($s in $still) { Log $bk "  still not playable: $($s.folder) (first track HTTP $($s.first))" }
Log $bk ("DONE. Undo: powershell -NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`" -Rollback $stamp")
if ($probs -or $still.Count) { exit 2 }
exit 0
