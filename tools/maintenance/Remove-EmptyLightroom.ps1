<#
Remove-EmptyLightroom.ps1  (Edutek, 2026-10-22)

Removes what is left of the Adobe Lightroom courses that have no videos. The user has no originals for them.
The working course "Lightroom Classic CC Essential Training" and its playable video #21 (row 825) are KEPT.

What goes (fixed list, verified 2026-10-22; anything unexpected stops the run):
  1. catalog row 826, video #83 "83 - Creating a collection and selecting a template.mp4" (the file is 0 bytes)
  2. that 0-byte file
  3. the cover image <Course>.jpg of each of the 8 empty courses (in videos\Adobe Lightroom)
  4. the 8 empty course folders (each holds only an empty "thumbs" folder)
Files and folders are MOVED to <QuarantineRoot>\lightroom-empty-<stamp>\ with the same layout (nothing is deleted);
you purge that folder yourself later. Only row 826 is deleted from the catalog, after a backup.
Left alone: the working course (folder, cover, #21 video and thumbnail), "Lightroom Tutorials.jpg" and
"ightroom Tutorials.jpg" (category images), and every other category.

Safety checks (the run stops if any fails): the course folders hold no files at all (hidden ones included); no
catalog row points into them; no row uses a cover as its thumbnail; row 826 has the expected path and its file
is 0 bytes; row 825's video is present; Adobe Lightroom has no rows other than 825 and 826; the quarantine is on
the same drive as the library.

Modes
  .\Remove-EmptyLightroom.ps1                    check only (default): read-only list of what goes, plus HTTP check
  .\Remove-EmptyLightroom.ps1 -Apply             backup, delete row 826, move the items to quarantine, verify
                                                 (disk, catalog, HTTP); rolls back automatically on any failure
  .\Remove-EmptyLightroom.ps1 -Rollback latest   (or -Rollback <stamp>) moves the items back, restores row 826
  .\Remove-EmptyLightroom.ps1 -VerifyOnly        HTTP verify of the cleaned state only
  .\Remove-EmptyLightroom.ps1 -SelfTest          apply, rollback, injected failures and refusals on THROWAWAY copies
                                                 (temp folder tree plus scratch table lre_selftest_xxxxxxxx, dropped
                                                 at the end). It never touches the live library or live rows.
Backups: <BackupRoot>\<stamp>\ (rows-lr-826.json exact row dump used by rollback, rows-lr-826.csv, items.csv,
journal.json, log.txt). Rollback after the quarantine was purged can only restore the row.
#>
[CmdletBinding()]
param(
  [switch]$Apply,
  [string]$Rollback = '',
  [switch]$VerifyOnly,
  [switch]$SelfTest,
  [string]$Library = 'D:\xampp\htdocs\Edutek\videos',
  [string]$BackupRoot = 'D:\edutek-system\backups\lightroom-empty',
  [string]$QuarantineRoot = 'D:\edutek-system\quarantine',
  [string]$Container = 'edupak-app',
  [string]$DockerExe = '',
  [string]$DockerContext = 'desktop-linux',
  [string]$BaseUrl = 'http://localhost:8080',
  [switch]$SkipHttp,
  [string]$FailAt = ''          # test hook for -SelfTest: after-rows | mid-move | after-move | verify
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$utf8 = New-Object System.Text.UTF8Encoding($false)
$script:Table = 'content_meta'
$script:Sim = $false
$script:Log = ''
$script:Curl = Join-Path $env:SystemRoot 'System32\curl.exe'
$Me = '.\Remove-EmptyLightroom.ps1'

# ---------------------------------------------------------------- fixed targets (verified 2026-10-22)
$Cat = 'Adobe Lightroom'
$KeepCourse = 'Lightroom Classic CC Essential Training'
$KeepId = 825
$KeepFile = '21 - Adding flags, stars, and labels more quickly.mp4'
$DropId = 826
$DropFile = '83 - Creating a collection and selecting a template.mp4'
$KeepPath = "content/$Cat/$KeepCourse/$KeepFile"
$DropPath = "content/$Cat/$KeepCourse/$DropFile"
$EmptyCourses = @(
  'Enhancing a Landscape Photo with Lightroom',
  'How To Get Started With Lightroom 5',
  'Lightroom 5 In Depth - Books and Print',
  'Lightroom CC First Look',
  'Lightroom Classic CC 2015 and  Lightroom Mobile- Raw Workflows',
  'Lightroom Classic CC 2015 and Photoshop Workflows- Start-to-Finish Studies',
  'Using Lightroom and Photoshop Together',
  'Using Wacom Tablets with Lightroom Classic CC 2015'
)
$LeaveAlone = @('Lightroom Tutorials.jpg', 'ightroom Tutorials.jpg', "$KeepCourse.jpg")

# ---------------------------------------------------------------- output
function Say([string]$m) { Write-Host $m; if ($script:Log) { [IO.File]::AppendAllText($script:Log, $m + "`r`n", $utf8) } }

# ---------------------------------------------------------------- DB helper (runs inside the app container, prints base64 JSON)
$HelperPhp = @'
<?php
if (PHP_SAPI !== 'cli') { exit(1); }
function lre_out($a) {
    $j = json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($j === false) { $j = json_encode(['state' => 'error', 'message' => 'json_encode failed: ' . json_last_error_msg()]); }
    echo "\nFSC:" . base64_encode($j) . "\n"; exit(0);
}
function lre_rows($rows) {
    $rows = array_values($rows); $j = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($j === false) { lre_out(['state' => 'error', 'message' => 'row json_encode failed: ' . json_last_error_msg()]); }
    return ['state' => 'ok', 'count' => count($rows), 'rows' => $rows, 'rows_b64' => base64_encode($j)];
}
try {
    $in = json_decode(base64_decode('__FSC_INPUT__'), true);
    if (!is_array($in)) { lre_out(['state' => 'error', 'message' => 'bad input']); }
    require_once '/var/www/html/includes/config.php';
    require_once '/var/www/html/includes/auth.php';
    $pdo = getDbConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $t = (string)($in['table'] ?? '');
    if (!preg_match('/^(content_meta|lre_selftest_[0-9a-f]{8})$/', $t)) { lre_out(['state' => 'error', 'message' => 'table not allowed']); }
    $scratch = (strpos($t, 'lre_selftest_') === 0);
    $mode = (string)($in['mode'] ?? '');

    if ($mode === 'ping') { lre_out(['state' => 'ok', 'count' => (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()]); }

    if ($mode === 'urls') {
        require_once '/var/www/html/includes/tiles.php';
        $u = []; foreach ($in['categories'] as $c) { $u[$c] = 'tutorials.php?&' . tileEncrypt('course') . '=' . tileEncrypt($c); }
        lre_out(['state' => 'ok', 'urls' => $u]);
    }

    if ($mode === 'select') {
        $rows = [];
        $add = function ($sql, $p) use ($pdo, &$rows) { $s = $pdo->prepare($sql); $s->execute($p); foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[(int)$r['id']] = $r; } };
        foreach (($in['filters'] ?? []) as $f) { $add("SELECT * FROM `$t` WHERE category = ?", [$f['category']]); }
        foreach (($in['prefixes'] ?? []) as $p) { $e = addcslashes($p, '\\%_') . '%'; $add("SELECT * FROM `$t` WHERE file_path LIKE ? OR content_id LIKE ? OR thumbnail_path LIKE ?", [$e, $e, $e]); }
        foreach (($in['paths'] ?? []) as $p) { $add("SELECT * FROM `$t` WHERE file_path = ? OR content_id = ? OR thumbnail_path = ?", [$p, $p, $p]); }
        foreach (($in['ids'] ?? []) as $id) { $add("SELECT * FROM `$t` WHERE id = ?", [(int)$id]); }
        ksort($rows);
        lre_out(lre_rows($rows));
    }

    if ($mode === 'delete') {
        $pdo->beginTransaction(); $n = 0;
        $d = $pdo->prepare("DELETE FROM `$t` WHERE id = ? AND file_path = ?");
        foreach ($in['items'] as $it) {
            $d->execute([(int)$it['id'], (string)$it['file_path']]);
            if ($d->rowCount() !== 1) { $pdo->rollBack(); lre_out(['state' => 'error', 'message' => 'row ' . $it['id'] . ' not found with the expected path; nothing deleted']); }
            $n++;
        }
        $pdo->commit(); lre_out(['state' => 'ok', 'deleted' => $n]);
    }

    if ($mode === 'restore') {
        $rows = json_decode(base64_decode((string)$in['rows_b64']), true);
        if (!is_array($rows)) { lre_out(['state' => 'error', 'message' => 'bad backup rows']); }
        $pdo->beginTransaction(); $ins = 0; $same = 0;
        $byId = $pdo->prepare("SELECT * FROM `$t` WHERE id = ?");
        $byPath = $pdo->prepare("SELECT id FROM `$t` WHERE file_path = ? AND id <> ?");
        foreach ($rows as $r) {
            foreach (array_keys($r) as $k) { if (!preg_match('/^[a-z_]+$/', $k)) { $pdo->rollBack(); lre_out(['state' => 'error', 'message' => 'bad column in backup']); } }
            $byId->execute([(int)$r['id']]); $ex = $byId->fetch(PDO::FETCH_ASSOC);
            if ($ex) {
                if ($ex['file_path'] === $r['file_path']) { $same++; continue; }
                $pdo->rollBack(); lre_out(['state' => 'error', 'message' => 'id ' . $r['id'] . ' is now used by another row; nothing restored']);
            }
            $byPath->execute([$r['file_path'], (int)$r['id']]); $o = $byPath->fetchColumn();
            if ($o !== false) { $pdo->rollBack(); lre_out(['state' => 'error', 'message' => 'the path of row ' . $r['id'] . ' is now in row ' . $o . '; nothing restored']); }
            $k = array_keys($r);
            $pdo->prepare("INSERT INTO `$t` (`" . implode('`,`', $k) . "`) VALUES (" . implode(',', array_fill(0, count($k), '?')) . ")")->execute(array_values($r));
            $ins++;
        }
        $pdo->commit(); lre_out(['state' => 'ok', 'inserted' => $ins, 'already' => $same]);
    }

    /* ---- scratch-table modes for -SelfTest only ---- */
    if (!$scratch) { lre_out(['state' => 'error', 'message' => "mode $mode is only allowed on a scratch table"]); }
    if ($mode === 'clone') {
        $ids = array_map('intval', $in['ids']); if (!$ids) { lre_out(['state' => 'error', 'message' => 'no ids']); }
        $pdo->exec("CREATE TABLE `$t` LIKE content_meta");
        $pdo->exec("INSERT INTO `$t` SELECT * FROM content_meta WHERE id IN (" . implode(',', $ids) . ")");
        lre_out(['state' => 'ok', 'count' => (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()]);
    }
    if ($mode === 'dump') { lre_out(lre_rows($pdo->query("SELECT * FROM `$t` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC))); }
    if ($mode === 'addrow') {
        $s = $pdo->prepare("SELECT * FROM `$t` WHERE id = ?"); $s->execute([(int)$in['from']]); $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) { lre_out(['state' => 'error', 'message' => 'addrow: source row not found']); }
        unset($r['id']); $r['file_path'] = $in['path']; $r['content_id'] = $in['path']; $r['thumbnail_path'] = $in['thumb'];
        $k = array_keys($r);
        $pdo->prepare("INSERT INTO `$t` (`" . implode('`,`', $k) . "`) VALUES (" . implode(',', array_fill(0, count($k), '?')) . ")")->execute(array_values($r));
        lre_out(['state' => 'ok', 'id' => (int)$pdo->lastInsertId()]);
    }
    if ($mode === 'delid') { $pdo->prepare("DELETE FROM `$t` WHERE id = ?")->execute([(int)$in['id']]); lre_out(['state' => 'ok']); }
    if ($mode === 'drop') { $pdo->exec("DROP TABLE IF EXISTS `$t`"); lre_out(['state' => 'ok']); }
    lre_out(['state' => 'error', 'message' => 'unknown mode']);
} catch (Throwable $e) {
    try { if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable $e2) { }
    lre_out(['state' => 'error', 'message' => get_class($e) . ': ' . $e->getMessage()]);
}
'@

function Resolve-Docker {
  if ($DockerExe) { return $DockerExe }
  $p = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe'
  if (Test-Path -LiteralPath $p) { return $p }
  return 'docker'
}
$script:Docker = Resolve-Docker
$script:DockerArgs = @(); if ($DockerContext) { $script:DockerArgs = @('--context', $DockerContext) }

function Invoke-Helper([hashtable]$in, [string]$table = '') {
  if (-not $table) { $table = $script:Table }
  $in['table'] = $table
  $json = $in | ConvertTo-Json -Depth 8 -Compress
  $code = $HelperPhp.Replace('__FSC_INPUT__', [Convert]::ToBase64String($utf8.GetBytes($json)))
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @($code | & $script:Docker @script:DockerArgs exec -i $Container php 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
  $line = @($o | Where-Object { $_.StartsWith('FSC:') }) | Select-Object -Last 1
  if (-not $line) { throw ("DB helper ($($in.mode)) gave no result: " + ((@($o) | Select-Object -Last 4) -join ' / ')) }
  $text = $utf8.GetString([Convert]::FromBase64String($line.Substring(4).Trim()))
  $res = $text | ConvertFrom-Json
  if ($res.state -ne 'ok') { throw ("DB helper ($($in.mode)): " + $res.message) }
  return $res
}
function Get-RowsText($res) { return $utf8.GetString([Convert]::FromBase64String($res.rows_b64)) }

function Get-LibPath([string]$fp) {
  if ($fp -notmatch '^content/(.+)$') { return $null }
  return (Join-Path $Library ($Matches[1] -replace '/', '\'))
}
function Get-Tree([string]$root, [switch]$Hash) {
  $files = @{}; $dirs = @{}
  foreach ($i in @(Get-ChildItem -LiteralPath $root -Recurse -Force)) {
    $rel = $i.FullName.Substring($root.TrimEnd('\').Length).TrimStart('\')
    if ($i.PSIsContainer) { $dirs[$rel] = $true; continue }
    $h = ''; if ($Hash) { $h = (Get-FileHash -LiteralPath $i.FullName -Algorithm SHA256).Hash }
    $files[$rel] = [pscustomobject]@{ rel = $rel; size = $i.Length; sha256 = $h }
  }
  return [pscustomobject]@{ files = $files; dirs = $dirs }
}
function Get-TreeSig([string]$root) {
  if (-not (Test-Path -LiteralPath $root)) { return '<absent>' }
  $t = Get-Tree $root -Hash
  return ((@($t.files.Values | Sort-Object rel | ForEach-Object { "$($_.rel)|$($_.size)|$($_.sha256)" }) + @($t.dirs.Keys | Sort-Object | ForEach-Object { "dir|$_" })) -join "`n")
}

# ---------------------------------------------------------------- plan (read-only)
function Get-Plan {
  $p = [ordered]@{ problems = New-Object System.Collections.ArrayList; notes = New-Object System.Collections.ArrayList
                   state = 'ok'; rows = @(); dropRow = $null; dropText = '[]'; items = @(); courses = @() }
  $catDir = Join-Path $Library $Cat
  if (-not (Test-Path -LiteralPath $catDir)) { [void]$p.problems.Add("folder videos\$Cat not found"); $p.state = 'unexpected'; return [pscustomobject]$p }
  $r = Invoke-Helper @{ mode = 'select'; filters = @(@{ category = $Cat }); prefixes = @("content/$Cat/") }
  $rows = @($r.rows); $p.rows = $rows
  # the row that stays
  $keep = @($rows | Where-Object { [int]$_.id -eq $KeepId })
  if ($keep.Count -ne 1 -or [string]$keep[0].file_path -ne $KeepPath) { [void]$p.problems.Add("row $KeepId (#21, the video to keep) not found with path $KeepPath") }
  $kp = Get-LibPath $KeepPath
  if (-not (Test-Path -LiteralPath $kp) -or (Get-Item -LiteralPath $kp).Length -le 0) { [void]$p.problems.Add("the working video #21 is missing or empty: $kp") }
  if (-not (Test-Path -LiteralPath (Join-Path $catDir "$KeepCourse.jpg"))) { [void]$p.notes.Add("cover of the working course is missing (not changed by this script)") }
  # the row that goes
  $drop = @($rows | Where-Object { [int]$_.id -eq $DropId })
  $dp = Get-LibPath $DropPath
  if ($drop.Count -eq 1) {
    if ([string]$drop[0].file_path -ne $DropPath) { [void]$p.problems.Add("row $DropId has path '$($drop[0].file_path)', expected $DropPath") }
    $p.dropRow = $drop[0]
    $p.dropText = $utf8.GetString([Convert]::FromBase64String((Invoke-Helper @{ mode = 'select'; ids = @($DropId) }).rows_b64))
  }
  # any other row in the category, or pointing into the folder, stops the run
  $others = @($rows | Where-Object { [int]$_.id -ne $KeepId -and [int]$_.id -ne $DropId })
  foreach ($o in $others) { [void]$p.problems.Add("unexpected catalog row $($o.id) ($($o.category) / $($o.subcategory)): $($o.file_path) | thumb $($o.thumbnail_path)") }
  # the 0-byte file
  if (Test-Path -LiteralPath $dp) {
    $len = (Get-Item -LiteralPath $dp).Length
    if ($len -ne 0) { [void]$p.problems.Add("video #83 is no longer 0 bytes ($len bytes): it may have been restored; nothing will be removed") }
    else { $p.items += [pscustomobject]@{ kind = '0-byte video'; course = $KeepCourse; src = $dp; rel = ($dp.Substring($Library.TrimEnd('\').Length + 1)); size = 0; sha256 = ''; detail = "row $DropId" } }
    if ($drop.Count -ne 1) { [void]$p.problems.Add("the 0-byte video exists but row $DropId is gone; not handled") }
  } elseif ($drop.Count -eq 1) { [void]$p.problems.Add("row $DropId exists but its 0-byte file is gone; not handled") }
  # the 8 empty courses
  foreach ($c in $EmptyCourses) {
    $d = Join-Path $catDir $c; $cv = Join-Path $catDir "$c.jpg"
    $info = [pscustomobject]@{ course = $c; folder = (Test-Path -LiteralPath $d); cover = (Test-Path -LiteralPath $cv); subfolders = ''; files = 0 }
    if ($info.folder) {
      if (-not (Get-Item -LiteralPath $d -Force).PSIsContainer) { [void]$p.problems.Add("'$c' is a file, not a folder") }
      else {
        $all = @(Get-ChildItem -LiteralPath $d -Recurse -Force)
        $files = @($all | Where-Object { -not $_.PSIsContainer })
        $info.files = $files.Count
        $info.subfolders = (@($all | Where-Object { $_.PSIsContainer } | ForEach-Object { $_.FullName.Substring($d.Length + 1) }) -join ', ')
        if ($files.Count) { [void]$p.problems.Add("course '$c' is NOT empty: $($files.Count) file(s), e.g. $($files[0].FullName.Substring($d.Length + 1)); nothing will be removed") }
        $p.items += [pscustomobject]@{ kind = 'empty folder'; course = $c; src = $d; rel = "$Cat\$c"; size = 0; sha256 = ''; detail = $(if ($info.subfolders) { 'contains only empty folder(s): ' + $info.subfolders } else { 'empty' }) }
      }
    }
    if ($info.cover) {
      $fi = Get-Item -LiteralPath $cv -Force
      $p.items += [pscustomobject]@{ kind = 'cover image'; course = $c; src = $cv; rel = "$Cat\$c.jpg"; size = $fi.Length; sha256 = (Get-FileHash -LiteralPath $cv -Algorithm SHA256).Hash; detail = '' }
    }
    $p.courses += $info
  }
  # covers or the video used as a thumbnail / path by any row
  $refPaths = @($EmptyCourses | ForEach-Object { "content/$Cat/$_.jpg" })
  $refs = @((Invoke-Helper @{ mode = 'select'; paths = $refPaths; prefixes = @($EmptyCourses | ForEach-Object { "content/$Cat/$_/" }) }).rows)
  foreach ($x in $refs) { if (-not (@($others) | Where-Object { $_.id -eq $x.id })) { [void]$p.problems.Add("catalog row $($x.id) ($($x.category)) uses a course folder or cover: $($x.file_path) | thumb $($x.thumbnail_path)") } }
  if ([IO.Path]::GetPathRoot($Library) -ne [IO.Path]::GetPathRoot($QuarantineRoot)) { [void]$p.problems.Add("quarantine root $QuarantineRoot is not on the library drive; a same-drive move is required") }
  # order: 0-byte video, covers, folders
  $p.items = @(@($p.items | Where-Object { $_.kind -eq '0-byte video' }) + @($p.items | Where-Object { $_.kind -eq 'cover image' }) + @($p.items | Where-Object { $_.kind -eq 'empty folder' }))
  if ($p.problems.Count) { $p.state = 'unexpected' } elseif ($p.items.Count -eq 0 -and $null -eq $p.dropRow) { $p.state = 'already-clean' }
  return [pscustomobject]$p
}

function Show-Plan($p) {
  Say ''
  Say "=== Plan: Adobe Lightroom empty courses (library $Library, table $($script:Table)) ==="
  Say ("Catalog rows in '{0}': {1}" -f $Cat, (@($p.rows | ForEach-Object { "$($_.id) $([IO.Path]::GetFileName([string]$_.file_path))" }) -join '; '))
  Say ("  KEEP   row {0}  {1}" -f $KeepId, $KeepPath)
  if ($p.dropRow) { Say ("  DELETE row {0}  {1}  (created {2} UTC, title '{3}')" -f $p.dropRow.id, $p.dropRow.file_path, $p.dropRow.created_at, $p.dropRow.title) } else { Say "  row $DropId already gone" }
  Say ("Items moved to {0}\lightroom-empty-<stamp>\ (same layout): {1}" -f $QuarantineRoot, @($p.items).Count)
  $n = 0
  foreach ($i in @($p.items)) { $n++; Say ("  {0,2}. {1,-13} {2}{3}{4}" -f $n, $i.kind, $i.rel, $(if ($i.kind -eq 'cover image') { "  ($($i.size) bytes)" } else { '' }), $(if ($i.detail) { "  [$($i.detail)]" } else { '' })) }
  Say 'Courses:'
  foreach ($c in @($p.courses)) { Say ("  '{0}': folder {1}, files {2}, cover {3}" -f $c.course, $(if ($c.folder) { 'present' } else { 'gone' }), $c.files, $(if ($c.cover) { 'present' } else { 'gone' })) }
  Say ("Left alone: course '{0}' (folder, cover, #21 video + thumbnail, thumbs folder), {1}" -f $KeepCourse, (($LeaveAlone | Where-Object { $_ -ne "$KeepCourse.jpg" }) -join ', '))
  foreach ($x in $p.notes) { Say "  note: $x" }
  if ($p.problems.Count) { Say ''; Say 'PROBLEMS (nothing is changed while these exist):'; foreach ($x in $p.problems) { Say "  - $x" } }
  elseif ($p.state -eq 'already-clean') { Say ''; Say 'Already clean: nothing to remove.' }
  else { Say ''; Say 'No problems found.' }
}

# ---------------------------------------------------------------- HTTP
function Invoke-Curl([string[]]$a) {
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @(& $script:Curl @a 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
  return (@($o) -join "`n").Trim()
}
function New-Jar { $j = Join-Path $env:TEMP ('lre-jar-' + [guid]::NewGuid().ToString('N') + '.txt'); [void](Invoke-Curl @('-s', '-g', '-o', 'NUL', '-c', $j, '-b', $j, "$BaseUrl/guest.php")); return $j }
function Get-Page([string]$jar, [string]$rel) {
  $f = Join-Path $env:TEMP ('lre-page-' + [guid]::NewGuid().ToString('N') + '.html')
  $code = Invoke-Curl @('-s', '-g', '-b', $jar, '-c', $jar, '-o', $f, '-w', '%{http_code}', "$BaseUrl/$rel")
  $body = ''; if (Test-Path -LiteralPath $f) { $body = [IO.File]::ReadAllText($f, $utf8); Remove-Item -LiteralPath $f -Force }
  return [pscustomobject]@{ code = $code; body = $body }
}
function ConvertTo-BrowserUrl([string]$src) {
  # what a browser requests for an unencoded relative src: path ends at '#' (fragment) or '?' (query)
  $cut = $src.IndexOfAny([char[]]@('#', '?')); if ($cut -ge 0) { $src = $src.Substring(0, $cut) }
  $sb = New-Object System.Text.StringBuilder
  foreach ($ch in $src.ToCharArray()) {
    if ([int]$ch -gt 126 -or ' "<>`{}|\^'.IndexOf($ch) -ge 0) { foreach ($by in $utf8.GetBytes([string]$ch)) { [void]$sb.Append('%' + $by.ToString('X2')) } } else { [void]$sb.Append($ch) }
  }
  return $sb.ToString()
}
function Test-CategoryPage([string]$jar, [string]$rel, [switch]$Media) {
  $pg = Get-Page $jar $rel
  $secs = @([regex]::Matches($pg.body, 'tut-section-title">([^<]*)</p>\s*<div class="tut-section-count">(\d+)\s+video') | ForEach-Object { [pscustomobject]@{ title = [Net.WebUtility]::HtmlDecode($_.Groups[1].Value).Trim(); count = [int]$_.Groups[2].Value } })
  $links = @([regex]::Matches($pg.body, 'href="(watch\.php[^"]*)"') | ForEach-Object { [Net.WebUtility]::HtmlDecode($_.Groups[1].Value) } | Select-Object -Unique)
  # thumbnails: raw 'content/...' (before patch 6b) or already-encoded '/content/...' (patch 6b)
  $imgs = @([regex]::Matches($pg.body, '<img[^>]*src="(/?content/[^"]*)"') | ForEach-Object { [Net.WebUtility]::HtmlDecode($_.Groups[1].Value) } | Select-Object -Unique)
  $watch = @(); $imgRes = @()
  if ($Media) {
    foreach ($l in $links) {
      $w = Get-Page $jar $l
      $src = [Net.WebUtility]::HtmlDecode([regex]::Match($w.body, 'id="wp-video"\s+src="([^"]+)"').Groups[1].Value)
      $mc = '-'; $mt = ''
      if ($src) { $r = (Invoke-Curl @('-s', '-g', '-b', $jar, '-r', '0-1023', '-o', 'NUL', '-w', '%{http_code}|%{content_type}', "$BaseUrl$src")).Split('|'); $mc = $r[0]; if ($r.Count -gt 1) { $mt = $r[1] } }
      $watch += [pscustomobject]@{ watch = $w.code; src = $src; media = $mc; type = $mt }
    }
    foreach ($i in $imgs) { if ($i.StartsWith('/')) { $u = $i.TrimStart('/') } else { $u = ConvertTo-BrowserUrl $i }; $imgRes += [pscustomobject]@{ src = $i; url = "/$u"; code = (Invoke-Curl @('-s', '-g', '-b', $jar, '-o', 'NUL', '-w', '%{http_code}', "$BaseUrl/$u")) } }
  }
  return [pscustomobject]@{ rel = $rel; code = $pg.code; sections = $secs; links = $links; watch = $watch; imgs = $imgRes }
}
function Test-MediaOk($w) { return (($w.media -eq '206' -or $w.media -eq '200') -and $w.type -like 'video/*') }

function Invoke-HttpCheck([switch]$Expect) {
  # -Expect: assert the cleaned state; otherwise just report
  $fails = New-Object System.Collections.ArrayList
  $urls = (Invoke-Helper @{ mode = 'urls'; categories = @($Cat) } 'content_meta').urls
  $jar = New-Jar
  try {
    Say ''; Say "=== HTTP check ($BaseUrl, guest session) ==="
    $lr = Test-CategoryPage $jar $urls.$Cat -Media
    Say ("Adobe Lightroom page {0}: {1}" -f $lr.code, ((@($lr.sections) | ForEach-Object { "'$($_.title)' $($_.count) video(s)" }) -join '; '))
    foreach ($w in @($lr.watch)) { Say ("   watch {0} -> {1} -> {2} {3}" -f $w.watch, [Uri]::UnescapeDataString([string]$w.src), $w.media, $w.type) }
    foreach ($i in @($lr.imgs)) { Say ("   image {0} -> {1}" -f [Uri]::UnescapeDataString($i.url), $i.code) }
    $covers = @()
    foreach ($c in $EmptyCourses) { $u = '/content/' + [Uri]::EscapeDataString($Cat) + '/' + [Uri]::EscapeDataString("$c.jpg"); $covers += [pscustomobject]@{ course = $c; code = (Invoke-Curl @('-s', '-g', '-b', $jar, '-o', 'NUL', '-w', '%{http_code}', "$BaseUrl$u")) } }
    Say ("   empty-course cover URLs: " + ((@($covers) | ForEach-Object { $_.code }) -join ','))
    if ($Expect) {
      $sec = @($lr.sections)
      if ($lr.code -ne '200') { [void]$fails.Add("Lightroom page returned $($lr.code)") }
      if ($sec.Count -ne 1 -or $sec[0].title -ne $KeepCourse -or $sec[0].count -ne 1) { [void]$fails.Add("Lightroom page should show only '$KeepCourse' with 1 video") }
      $w = @($lr.watch)
      if ($w.Count -ne 1) { [void]$fails.Add("Lightroom page has $($w.Count) watch links, expected 1") }
      elseif (-not (Test-MediaOk $w[0]) -or $w[0].watch -ne '200' -or -not ([Uri]::UnescapeDataString([string]$w[0].src)).EndsWith('/' + $KeepFile)) { [void]$fails.Add("video #21 does not play (watch $($w[0].watch), media $($w[0].media) $($w[0].type))") }
      if (@($lr.imgs | Where-Object { $_.code -ne '200' }).Count) { [void]$fails.Add("a Lightroom thumbnail fails") }
      if (@($lr.links).Count -ne 1) { [void]$fails.Add("unexpected watch links on the page") }
      if (@($covers | Where-Object { $_.code -eq '200' }).Count) { [void]$fails.Add("a removed cover image is still served") }
    }
  } finally { Remove-Item -LiteralPath $jar -Force -ErrorAction SilentlyContinue }
  return @($fails)
}

# ---------------------------------------------------------------- journal
function Save-Journal($bk, $J) { [IO.File]::WriteAllText((Join-Path $bk 'journal.json'), ($J | ConvertTo-Json -Depth 6), $utf8) }
function Check-Fail([string]$at) { if ($FailAt -and $FailAt -eq $at) { throw "injected test failure at $at" } }

function Test-AppliedState($plan, [string]$qdir) {
  $f = New-Object System.Collections.ArrayList
  $now = @((Invoke-Helper @{ mode = 'select'; filters = @(@{ category = $Cat }); prefixes = @("content/$Cat/") }).rows)
  if (@($now | Where-Object { [int]$_.id -eq $DropId }).Count) { [void]$f.Add("row $DropId still present") }
  if (@($now | Where-Object { [int]$_.id -eq $KeepId }).Count -ne 1 -or @($now).Count -ne 1) { [void]$f.Add("expected only row $KeepId in $Cat, found $(@($now).Count) row(s)") }
  foreach ($i in @($plan.items)) {
    if (Test-Path -LiteralPath $i.src) { [void]$f.Add("still in the library: $($i.rel)") }
    if (-not (Test-Path -LiteralPath (Join-Path $qdir $i.rel))) { [void]$f.Add("not in quarantine: $($i.rel)") }
  }
  $kp = Get-LibPath $KeepPath
  if (-not (Test-Path -LiteralPath $kp) -or (Get-Item -LiteralPath $kp).Length -le 0) { [void]$f.Add("video #21 missing") }
  return @($f)
}

# ---------------------------------------------------------------- apply
function Invoke-Apply($plan, [string]$stamp) {
  $bk = Join-Path $BackupRoot $stamp
  $qdir = Join-Path $QuarantineRoot ('lightroom-empty-' + $stamp)
  if (Test-Path -LiteralPath $bk) { throw "backup folder $bk already exists" }
  if (Test-Path -LiteralPath $qdir) { throw "quarantine folder $qdir already exists" }
  [void](New-Item -ItemType Directory -Path $bk -Force)
  $script:Log = Join-Path $bk 'log.txt'
  Say "Backup folder: $bk"
  Say "Quarantine:    $qdir"
  $J = [ordered]@{ stamp = $stamp; started = (Get-Date).ToString('s'); table = $script:Table; library = $Library; quarantine = $qdir; state = 'started'
                   deleted = @(); moved = @(); http = '' }
  if ($plan.dropRow) {
    [IO.File]::WriteAllText((Join-Path $bk 'rows-lr-826.json'), $plan.dropText, $utf8)
    ($plan.dropText | ConvertFrom-Json) | Export-Csv -LiteralPath (Join-Path $bk 'rows-lr-826.csv') -NoTypeInformation -Encoding UTF8
  }
  @($plan.items) | Select-Object kind, course, rel, size, sha256, detail, src | Export-Csv -LiteralPath (Join-Path $bk 'items.csv') -NoTypeInformation -Encoding UTF8
  Save-Journal $bk $J
  try {
    if ($plan.dropRow) {
      $r = Invoke-Helper @{ mode = 'delete'; items = @(@{ id = [int]$plan.dropRow.id; file_path = [string]$plan.dropRow.file_path }) }
      $J.deleted += 'lr-826'; Save-Journal $bk $J
      Say ("deleted catalog row {0} ({1} row)" -f $DropId, $r.deleted)
    }
    Check-Fail 'after-rows'
    $n = 0
    foreach ($i in @($plan.items)) {
      $dst = Join-Path $qdir $i.rel
      if (Test-Path -LiteralPath $dst) { throw "quarantine target exists: $dst" }
      if (-not (Test-Path -LiteralPath $i.src)) { throw "item vanished before the move: $($i.rel)" }
      if ($i.kind -eq 'empty folder' -and @(Get-ChildItem -LiteralPath $i.src -Recurse -Force | Where-Object { -not $_.PSIsContainer }).Count) { throw "folder is no longer empty: $($i.rel)" }
      $dp = Split-Path $dst
      if (-not (Test-Path -LiteralPath $dp)) { [void](New-Item -ItemType Directory -Path $dp -Force) }
      Move-Item -LiteralPath $i.src -Destination $dst
      $J.moved += [ordered]@{ kind = $i.kind; from = $i.src; to = $dst; rel = $i.rel }; Save-Journal $bk $J
      if ((Test-Path -LiteralPath $i.src) -or -not (Test-Path -LiteralPath $dst)) { throw "move check failed for $($i.rel)" }
      Say ("moved {0,-13} {1}" -f $i.kind, $i.rel)
      $n++; if ($n -eq 3) { Check-Fail 'mid-move' }
    }
    Check-Fail 'after-move'
    $sf = @(Test-AppliedState $plan $qdir)
    if ($sf.Count) { throw ("state check failed: " + ($sf -join '; ')) }
    Say 'state check (catalog + disk): OK'
    Check-Fail 'verify'
    if (-not $SkipHttp -and -not $script:Sim) {
      $f = @(Invoke-HttpCheck -Expect)
      if ($f.Count) { $J.http = 'failed: ' + ($f -join '; '); Save-Journal $bk $J; throw ("HTTP verify failed: " + ($f -join '; ')) }
      $J.http = 'ok'
      Say 'HTTP verify: OK'
    }
    $J.state = 'done'; $J.finished = (Get-Date).ToString('s'); Save-Journal $bk $J
    Say "DONE. Quarantine to purge later: $qdir"
    Say "Rollback (while the quarantine still exists): $Me -Rollback $stamp"
    return $true
  } catch {
    Say ("ERROR: " + $_.Exception.Message)
    $J.state = 'failed'; $J.error = $_.Exception.Message; Save-Journal $bk $J
    Say 'Rolling back automatically ...'
    $ok = Invoke-Undo $bk
    if ($ok) { Say 'Automatic rollback complete; the state is as before.' } else { Say "Automatic rollback INCOMPLETE; see $bk\log.txt" }
    return $false
  }
}

# ---------------------------------------------------------------- undo
function Invoke-Undo([string]$bk) {
  $jp = Join-Path $bk 'journal.json'
  if (-not (Test-Path -LiteralPath $jp)) { Say "no journal in $bk"; return $false }
  $J = Get-Content -LiteralPath $jp -Raw -Encoding UTF8 | ConvertFrom-Json
  if ($J.table -ne $script:Table) { Say "journal is for table $($J.table), not $($script:Table)"; return $false }
  if (-not $script:Log) { $script:Log = Join-Path $bk 'log.txt' }
  $ok = $true
  $mv = @($J.moved); [array]::Reverse($mv)
  foreach ($m in $mv) {
    if ((Test-Path -LiteralPath $m.to) -and -not (Test-Path -LiteralPath $m.from)) {
      $pp = Split-Path $m.from; if (-not (Test-Path -LiteralPath $pp)) { [void](New-Item -ItemType Directory -Path $pp -Force) }
      Move-Item -LiteralPath $m.to -Destination $m.from; Say ("  moved back: " + $m.rel)
    } elseif (Test-Path -LiteralPath $m.from) {
      if (Test-Path -LiteralPath $m.to) { $ok = $false; Say ("  both the library and the quarantine have " + $m.rel + "; left as is") } else { Say ("  already back: " + $m.rel) }
    } else { $ok = $false; Say ("  cannot move back (quarantine purged?): " + $m.rel) }
  }
  if (@($J.deleted) -contains 'lr-826') {
    try {
      $text = [IO.File]::ReadAllText((Join-Path $bk 'rows-lr-826.json'), $utf8)
      $r = Invoke-Helper @{ mode = 'restore'; rows_b64 = [Convert]::ToBase64String($utf8.GetBytes($text)) }
      Say ("  restored {0} row(s), {1} already present" -f $r.inserted, $r.already)
    } catch { $ok = $false; Say ("  row restore FAILED: " + $_.Exception.Message) }
  }
  # remove the run's quarantine folder only if no file is left in it
  $q = [string]$J.quarantine
  if ($q -and (Test-Path -LiteralPath $q)) {
    if (@(Get-ChildItem -LiteralPath $q -Recurse -Force | Where-Object { -not $_.PSIsContainer }).Count -eq 0) { Remove-Item -LiteralPath $q -Recurse -Force; Say "  removed the now-empty quarantine folder $q" }
    else { Say "  quarantine folder still holds files: $q" }
  }
  $J2 = Get-Content -LiteralPath $jp -Raw -Encoding UTF8 | ConvertFrom-Json
  Add-Member -InputObject $J2 -NotePropertyName rolled_back -NotePropertyValue ((Get-Date).ToString('s') + $(if ($ok) { ' ok' } else { ' incomplete' })) -Force
  [IO.File]::WriteAllText($jp, ($J2 | ConvertTo-Json -Depth 6), $utf8)
  return $ok
}
function Get-RunFolder([string]$which) {
  if ($which -eq 'latest') {
    $d = @(Get-ChildItem -LiteralPath $BackupRoot -Force -ErrorAction SilentlyContinue | Where-Object { $_.PSIsContainer } | Where-Object { Test-Path -LiteralPath (Join-Path $_.FullName 'journal.json') } | Sort-Object Name)
    if ($d.Count) { return $d[-1].FullName } else { return '' }
  }
  return (Join-Path $BackupRoot $which)
}

# ---------------------------------------------------------------- self-test on throwaway copies
function Assert([bool]$c, [string]$m) { if ($c) { Say "  PASS  $m" } else { Say "  FAIL  $m"; $script:stFails++ } }
function Invoke-SelfTest {
  $script:stFails = 0
  $live = $Library
  $tmp = Join-Path $env:TEMP ('lre-selftest-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
  $tbl = 'lre_selftest_' + [guid]::NewGuid().ToString('N').Substring(0, 8)
  $created = $false
  try {
    Set-Variable -Scope Script -Name Library -Value (Join-Path $tmp 'lib'); Set-Variable -Scope Script -Name QuarantineRoot -Value (Join-Path $tmp 'quarantine'); Set-Variable -Scope Script -Name BackupRoot -Value (Join-Path $tmp 'backups')
    # throwaway library: same names and folders as live videos\Adobe Lightroom, tiny contents, 0-byte files stay 0 bytes
    $src = Join-Path $live $Cat
    foreach ($i in @(Get-ChildItem -LiteralPath $src -Recurse -Force)) {
      $rel = $Cat + $i.FullName.Substring($src.Length); $dst = Join-Path $Library $rel
      if ($i.PSIsContainer) { [void](New-Item -ItemType Directory -Path $dst -Force) }
      else { $d = Split-Path $dst; if (-not (Test-Path -LiteralPath $d)) { [void](New-Item -ItemType Directory -Path $d -Force) }; if ($i.Length -eq 0) { [IO.File]::WriteAllBytes($dst, [byte[]]@()) } else { [IO.File]::WriteAllText($dst, "selftest $rel", $utf8) } }
    }
    [void](New-Item -ItemType Directory -Path (Join-Path $Library 'Other Category\Course') -Force); [IO.File]::WriteAllText((Join-Path $Library 'Other Category\Course\v.mp4'), 'x', $utf8)
    Say "throwaway library: $Library"
    $sel = Invoke-Helper @{ mode = 'select'; filters = @(@{ category = $Cat }); prefixes = @("content/$Cat/") } 'content_meta'
    $ids = @(@($sel.rows) | ForEach-Object { [int]$_.id })
    $script:Table = $tbl
    $c = Invoke-Helper @{ mode = 'clone'; ids = $ids }; $created = $true
    Say ("scratch table {0}: {1} rows copied" -f $tbl, $c.count)
    $script:Sim = $true; Set-Variable -Scope Script -Name SkipHttp -Value $true
    $dbBefore = Get-RowsText (Invoke-Helper @{ mode = 'dump' })
    $fsBefore = Get-TreeSig $Library

    Say ''; Say '--- self-test 0: guards'
    $g1 = $false; try { [void](Invoke-Helper @{ mode = 'drop' } 'content_meta') } catch { $g1 = $true }
    Assert $g1 'scratch-only helper modes are refused on the live table'
    $g2 = $false; try { [void](Invoke-Helper @{ mode = 'ping' } 'users') } catch { $g2 = $true }
    Assert $g2 'other tables are refused'

    Say ''; Say '--- self-test 1: plan'
    $plan = Get-Plan; Show-Plan $plan
    Assert ($plan.problems.Count -eq 0 -and $plan.state -eq 'ok') 'plan has no problems'
    Assert ($null -ne $plan.dropRow -and [int]$plan.dropRow.id -eq $DropId) 'row 826 (#83) is the only row to delete'
    Assert (@($plan.items | Where-Object { $_.kind -eq '0-byte video' }).Count -eq 1) 'one 0-byte video'
    Assert (@($plan.items | Where-Object { $_.kind -eq 'cover image' }).Count -eq 8) 'eight cover images'
    Assert (@($plan.items | Where-Object { $_.kind -eq 'empty folder' }).Count -eq 8) 'eight empty course folders'
    Assert (@($plan.items | Where-Object { $_.rel -like "*$KeepCourse.jpg" -or $_.rel -like '*Tutorials.jpg' -or $_.rel -eq "$Cat\$KeepCourse" }).Count -eq 0) 'working course, its cover and the category images are not in the list'

    Say ''; Say '--- self-test 2: apply'
    $ok = Invoke-Apply $plan 'st-apply'
    Assert $ok 'apply succeeded'
    $ar = @((Invoke-Helper @{ mode = 'dump' }).rows)
    Assert (@($ar | Where-Object { [int]$_.id -eq $DropId }).Count -eq 0 -and @($ar | Where-Object { [int]$_.id -eq $KeepId }).Count -eq 1 -and $ar.Count -eq ($ids.Count - 1)) 'row 826 deleted, row 825 kept, nothing else touched'
    $q = Join-Path $QuarantineRoot 'lightroom-empty-st-apply'
    $catL = Join-Path $Library $Cat
    $left = @(Get-ChildItem -LiteralPath $catL -Force | ForEach-Object { $_.Name } | Sort-Object)
    Assert (($left -join '|') -eq ((@($KeepCourse, "$KeepCourse.jpg", 'ightroom Tutorials.jpg', 'Lightroom Tutorials.jpg') | Sort-Object) -join '|')) ("library keeps only the working course + 3 images (now: " + ($left -join ', ') + ")")
    Assert ((Test-Path -LiteralPath (Join-Path $catL "$KeepCourse\$KeepFile")) -and -not (Test-Path -LiteralPath (Join-Path $catL "$KeepCourse\$DropFile")) -and (Test-Path -LiteralPath (Join-Path $catL "$KeepCourse\thumbs"))) 'video #21 and the thumbs folder kept, #83 gone'
    $qf = Join-Path $q "$Cat\$KeepCourse\$DropFile"
    Assert ((Test-Path -LiteralPath $qf) -and (Get-Item -LiteralPath $qf).Length -eq 0) 'the 0-byte video is in quarantine'
    Assert (@($EmptyCourses | Where-Object { (Test-Path -LiteralPath (Join-Path $q "$Cat\$_.jpg")) -and (Test-Path -LiteralPath (Join-Path $q "$Cat\$_\thumbs")) }).Count -eq 8) 'all 8 covers and folders are in quarantine with the same layout'
    Assert (Test-Path -LiteralPath (Join-Path $Library 'Other Category\Course\v.mp4')) 'other categories untouched'
    $p2 = Get-Plan
    Assert ($p2.state -eq 'already-clean' -and $p2.problems.Count -eq 0) 'a second check reports already clean'

    Say ''; Say '--- self-test 3: rollback latest'
    $script:Log = ''
    $bkL = Get-RunFolder 'latest'
    Assert ($bkL -like '*st-apply') "'latest' finds the run ($bkL)"
    $ok = Invoke-Undo $bkL
    Assert $ok 'rollback succeeded'
    Assert ((Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore) 'rows identical to before (exact JSON)'
    Assert ((Get-TreeSig $Library) -ceq $fsBefore) 'files and folders identical to before (names, sizes, SHA256, empty folders)'
    Assert (-not (Test-Path -LiteralPath $q)) 'empty quarantine folder removed'

    foreach ($fa in @('after-rows', 'mid-move', 'after-move', 'verify')) {
      Say ''; Say "--- self-test: injected failure $fa -> automatic rollback"
      $script:Log = ''
      Set-Variable -Scope Script -Name FailAt -Value $fa
      $ok = Invoke-Apply (Get-Plan) ('st-' + $fa)
      Set-Variable -Scope Script -Name FailAt -Value ''
      Assert (-not $ok) "apply reported failure ($fa)"
      Assert ((Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore) "rows identical to before ($fa)"
      Assert ((Get-TreeSig $Library) -ceq $fsBefore) "files identical to before ($fa)"
      Assert (-not (Test-Path -LiteralPath (Join-Path $QuarantineRoot ('lightroom-empty-st-' + $fa)))) "no quarantine left behind ($fa)"
    }

    $script:Log = ''
    Say ''; Say '--- self-test: refusals'
    $c0 = $EmptyCourses[0]
    $hid = Join-Path $Library "$Cat\$c0\thumbs\desktop.ini"; [IO.File]::WriteAllText($hid, 'x', $utf8); (Get-Item -LiteralPath $hid -Force).Attributes = 'Hidden'
    Assert ((Get-Plan).state -eq 'unexpected') 'a course with any file (even a hidden one) is refused'
    Remove-Item -LiteralPath $hid -Force
    $z = Join-Path $Library "$Cat\$KeepCourse\$DropFile"; [IO.File]::WriteAllText($z, 'restored video', $utf8)
    Assert ((Get-Plan).state -eq 'unexpected') 'refused when #83 is no longer 0 bytes'
    [IO.File]::WriteAllBytes($z, [byte[]]@())
    $k = Join-Path $Library "$Cat\$KeepCourse\$KeepFile"; $kb = [IO.File]::ReadAllBytes($k); Remove-Item -LiteralPath $k -Force
    Assert ((Get-Plan).state -eq 'unexpected') 'refused when the working video #21 is missing'
    [IO.File]::WriteAllBytes($k, $kb)
    $nid = (Invoke-Helper @{ mode = 'addrow'; from = $KeepId; path = "content/$Cat/$c0/new.mp4"; thumb = '' }).id
    Assert ((Get-Plan).state -eq 'unexpected') 'refused when a row points into an empty course'
    [void](Invoke-Helper @{ mode = 'delid'; id = $nid })
    $nid = (Invoke-Helper @{ mode = 'addrow'; from = $KeepId; path = 'content/Other Category/Course/v.mp4'; thumb = "content/$Cat/$c0.jpg" }).id
    Assert ((Get-Plan).state -eq 'unexpected') 'refused when another row uses a cover as its thumbnail'
    [void](Invoke-Helper @{ mode = 'delid'; id = $nid })
    $sq = $QuarantineRoot; Set-Variable -Scope Script -Name QuarantineRoot -Value 'Z:\elsewhere\quarantine'
    Assert ((Get-Plan).state -eq 'unexpected') 'refused when the quarantine is on another drive'
    Set-Variable -Scope Script -Name QuarantineRoot -Value $sq
    Assert ((Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore -and (Get-TreeSig $Library) -ceq $fsBefore) 'refusals changed nothing'
  } finally {
    if ($created) { try { [void](Invoke-Helper @{ mode = 'drop' }); Say "scratch table $tbl dropped" } catch { Say "could not drop $tbl : $($_.Exception.Message)" } }
    $script:Table = 'content_meta'
    if ($tmp.StartsWith($env:TEMP) -and (Test-Path -LiteralPath $tmp)) { Remove-Item -LiteralPath $tmp -Recurse -Force; Say "throwaway folder removed" }
  }
  Say ''
  if ($script:stFails) { Say "SELF-TEST: $($script:stFails) FAILURE(S)"; return $false } else { Say 'SELF-TEST: all passed'; return $true }
}

# ---------------------------------------------------------------- main
if (-not (Test-Path -LiteralPath $script:Curl)) { $script:Curl = 'curl.exe' }
[void](Invoke-Helper @{ mode = 'ping' } 'content_meta')

if ($SelfTest) { if (Invoke-SelfTest) { exit 0 } else { exit 1 } }

if ($Rollback) {
  $bk = Get-RunFolder $Rollback
  if (-not $bk -or -not (Test-Path -LiteralPath (Join-Path $bk 'journal.json'))) { Write-Host "No run found to roll back ($Rollback)"; exit 1 }
  $script:Log = Join-Path $bk 'log.txt'
  Say ''; Say ("=== Rollback of {0} ({1}) ===" -f (Split-Path $bk -Leaf), (Get-Date).ToString('s'))
  if (Invoke-Undo $bk) { Say 'Rollback complete.'; exit 0 } else { Say 'Rollback INCOMPLETE - see messages above.'; exit 3 }
}

if ($VerifyOnly) {
  $f = @(Invoke-HttpCheck -Expect)
  if ($f.Count) { Say ''; Say 'VERIFY FAILED:'; foreach ($x in $f) { Say "  - $x" }; exit 1 } else { Say ''; Say 'VERIFY OK'; exit 0 }
}

$plan = Get-Plan
Show-Plan $plan
if (-not $Apply) {
  if (-not $SkipHttp) { [void](Invoke-HttpCheck) }
  Say ''; Say 'Check only - nothing was changed. Run with -Apply to remove.'
  if ($plan.problems.Count) { exit 1 } else { exit 0 }
}
if ($plan.problems.Count) { Say 'Not applying because of the problems above.'; exit 1 }
if ($plan.state -eq 'already-clean') { Say 'Nothing to do - already clean.'; exit 0 }
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
if (Invoke-Apply $plan $stamp) { exit 0 } else { exit 2 }
