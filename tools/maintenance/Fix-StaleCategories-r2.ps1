<#
Fix-StaleCategories-r2.ps1  (Edutek, 2026-10-22, revision 2 of Fix-StaleCategories.ps1)

Targeted catalog fixes. It never runs a full index and never runs the catalog cleanup, and it does not touch .env.

 A. Adobe Photoshop / "ADOBE XD" card: 13 stale content_meta rows (ids 4405288-4405300). Indexed 2026-09-08 06:14 MDT
    while a copy of ADOBE XD sat in videos\Adobe Photoshop. That folder was recycled at 10:42 MDT the same day, and the
    real course is in videos\Adobe XD\ADOBE XD (rows 1091-1103). The rows are backed up, then deleted.
 B. "Adobe Lightroom Copy" category: 2 stale rows (ids 3735627, 3735628). Indexed 2026-08-25 15:03 MDT. The folder
    videos\Adobe Lightroom Copy was recycled 2026-09-08 06:00 MDT. The rows are backed up, then deleted.
 C. videos\Generative AI with LLMs - Copy: if it is byte-identical to "Generative AI with LLMs" (names, sizes and
    SHA256 of every file), the folder is MOVED (never deleted) to <ArchiveRoot>\<stamp>\ and its catalog rows are
    backed up, then deleted.
 E. autocad-copy: "AutoCad - Copy" category: 112 stale rows (indexed 2026-08-25 15:05-15:06 MDT; the folder
    videos\AutoCad - Copy was recycled 2026-09-08 06:01 MDT). Deleted only if the folder is absent, none of the rows
    resolve to a file, and every row has an original row in category "AutoCad" at the same relative path whose
    file exists. Rows are backed up first.
 S. Survey (read-only, every mode): lists every category/subcategory whose name ends in " - Copy" or " copy"
    (case-insensitive) with its rows, files on disk, folder and original. Nothing outside A-E is ever deleted.
 D. OPTIONAL, OFF by default (patch 6b now URL-encodes links, so '#' names work): with -IncludeRename, files under
    videos\Adobe XD whose names contain '#' are renamed by this rule:
      1. '#' followed by a digit (optional spaces between) becomes " No " ("Episode #3" -> "Episode No 3").
      2. Every other '#' is removed ("XD #Neumorphism" -> "XD Neumorphism").
      3. Runs of spaces collapse to one, and the base name is trimmed. The extension and everything else are kept.
    Renaming stops on any collision. Each renamed video is partially indexed through the Edutek Import Partial
    Indexer trigger folder (request "fixstale-<stamp>", which does not trigger the catalog cleanup). Then only the old
    row is deleted (backed up first).

Modes
  .\Fix-StaleCategories-r2.ps1                 check only (default): read-only plan, survey and HTTP check
  .\Fix-StaleCategories-r2.ps1 -Apply          backup, then A, B, E, C (and D with -IncludeRename), then HTTP verify;
                                               rolls back automatically on any failure
  .\Fix-StaleCategories-r2.ps1 -Rollback latest   (or -Rollback <stamp>) undoes a run from its backup folder
  .\Fix-StaleCategories-r2.ps1 -VerifyOnly     HTTP verify of the fixed state only
  .\Fix-StaleCategories-r2.ps1 -SelfTest       runs the steps, rollback, auto-rollback and refusal cases on THROWAWAY copies
                                            (temp folder tree plus a scratch DB table fsc_selftest_xxxxxxxx that is
                                            dropped at the end). It never touches the live library or live rows.
Backups: <BackupRoot>\<stamp>\ (rows-*.json are exact row dumps used by rollback; *.csv are readable copies;
journal.json; log.txt).
#>
[CmdletBinding()]
param(
  [switch]$Apply,
  [string]$Rollback = '',
  [switch]$VerifyOnly,
  [switch]$SelfTest,
  [string]$Library = 'D:\xampp\htdocs\Edutek\videos',
  [string]$BackupRoot = 'D:\edutek-system\backups\fix-stale-categories',
  [string]$ArchiveRoot = 'D:\edutek-system\archive\library-duplicates',
  [string]$TriggerDir = 'D:\edutek-system\bulk-loader-fresh-v05\data\index-trigger',
  [string]$Container = 'edupak-app',
  [string]$DockerExe = '',
  [string]$DockerContext = 'desktop-linux',
  [string]$BaseUrl = 'http://localhost:8080',
  [int]$IndexTimeoutSec = 240,
  [switch]$SkipGenAiCopy,
  [switch]$IncludeRename,       # the '#' rename step (D) is skipped unless this is given
  [switch]$SkipHttp,
  [string]$FailAt = ''          # test hook for -SelfTest: after-rows | after-move | after-rename | after-index
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$utf8 = New-Object System.Text.UTF8Encoding($false)
$script:Table = 'content_meta'
$script:Sim = $false
$script:Log = ''
$script:Curl = Join-Path $env:SystemRoot 'System32\curl.exe'
$SkipRename = -not $IncludeRename
$Me = '.\Fix-StaleCategories-r2.ps1'

# ---------------------------------------------------------------- fixed targets (verified 2026-10-22)
$GroupA = @{ key = 'ps-xd'; label = 'Adobe Photoshop / ADOBE XD (stale duplicate of the Adobe XD course)'
             filter = @{ category = 'Adobe Photoshop'; subcategory = 'ADOBE XD' }; prefix = 'content/Adobe Photoshop/ADOBE XD/'
             expect = @(4405288..4405300); absentFolder = 'Adobe Photoshop\ADOBE XD' }
$GroupB = @{ key = 'lr-copy'; label = 'Adobe Lightroom Copy (folder recycled 2026-09-08)'
             filter = @{ category = 'Adobe Lightroom Copy' }; prefix = 'content/Adobe Lightroom Copy/'
             expect = @(3735627, 3735628); absentFolder = 'Adobe Lightroom Copy' }
$GroupE = @{ key = 'autocad-copy'; label = 'AutoCad - Copy (folder recycled 2026-09-08)'
             filter = @{ category = 'AutoCad - Copy' }; prefix = 'content/AutoCad - Copy/'
             expect = @(3746223,3746224,3746225,3746226,3746227,3746228,3746229,3746230,3746231,3746232,3746233,3746234,3746241,3746242,3746243,3746244,3746245,3746246,3746247,3746248,3746249,3746250,3746256,3746257,3746258,3746259,3746262,3746263,3746264,3746265,3746266,3746267,3746271,3746272,3746273,3746274,3746275,3746276,3746277,3746282,3746283,3746284,3746285,3746286,3746290,3746291,3746292,3746293,3746296,3746297,3746298,3746299,3746302,3746303,3746304,3746305,3746306,3746310,3746311,3746312,3746315,3746316,3746318,3746319,3746320,3746321,3746322,3746326,3746327,3746328,3746329,3746330,3746331,3746332,3746337,3746338,3746339,3746340,3746341,3746345,3746346,3746347,3746348,3746351,3746352,3746353,3746354,3746357,3746358,3746359,3746360,3746363,3746364,3746365,3746368,3746369,3746370,3746371,3746372,3746373,3746374,3746375,3746376,3746377,3746378,3746379,3746380,3746381,3746389,3746390,3746391,3746392); absentFolder = 'AutoCad - Copy'; orig = 'AutoCad' }
$Groups = @($GroupA, $GroupB, $GroupE)
$StepCategories = @{ 'Adobe Lightroom Copy' = 'lr-copy'; 'AutoCad - Copy' = 'autocad-copy'; 'Generative AI with LLMs - Copy' = 'genai-copy' }
$GenOrig = 'Generative AI with LLMs'
$GenCopy = 'Generative AI with LLMs - Copy'
$RenameScope = 'Adobe XD'
$XdExpectVideos = 13
$VideoExt = @('.mp4', '.m4v', '.webm', '.mov', '.mkv', '.avi')

# ---------------------------------------------------------------- output
function Say([string]$m) { Write-Host $m; if ($script:Log) { [IO.File]::AppendAllText($script:Log, $m + "`r`n", $utf8) } }

# ---------------------------------------------------------------- naming rule
function Get-SafeName([string]$name) {
  $ext = [IO.Path]::GetExtension($name)
  $base = $name.Substring(0, $name.Length - $ext.Length)
  $b = [regex]::Replace($base, '\s*#\s*(?=\d)', ' No ')
  $b = $b.Replace('#', '')
  $b = [regex]::Replace($b, ' {2,}', ' ').Trim()
  return $b + $ext
}

# ---------------------------------------------------------------- DB helper (runs inside the app container, prints base64 JSON)
$HelperPhp = @'
<?php
if (PHP_SAPI !== 'cli') { exit(1); }
function fsc_out($a) {
    $j = json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($j === false) { $j = json_encode(['state' => 'error', 'message' => 'json_encode failed: ' . json_last_error_msg()]); }
    echo "\nFSC:" . base64_encode($j) . "\n"; exit(0);
}
function fsc_rows($rows) {
    $rows = array_values($rows); $j = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($j === false) { fsc_out(['state' => 'error', 'message' => 'row json_encode failed: ' . json_last_error_msg()]); }
    return ['state' => 'ok', 'count' => count($rows), 'rows' => $rows, 'rows_b64' => base64_encode($j)];
}
try {
    $in = json_decode(base64_decode('__FSC_INPUT__'), true);
    if (!is_array($in)) { fsc_out(['state' => 'error', 'message' => 'bad input']); }
    require_once '/var/www/html/includes/config.php';
    require_once '/var/www/html/includes/auth.php';
    $pdo = getDbConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $t = (string)($in['table'] ?? '');
    if (!preg_match('/^(content_meta|fsc_selftest_[0-9a-f]{8})$/', $t)) { fsc_out(['state' => 'error', 'message' => 'table not allowed']); }
    $scratch = (strpos($t, 'fsc_selftest_') === 0);
    $mode = (string)($in['mode'] ?? '');

    if ($mode === 'ping') { fsc_out(['state' => 'ok', 'count' => (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()]); }

    if ($mode === 'urls') {
        require_once '/var/www/html/includes/tiles.php';
        $u = []; foreach ($in['categories'] as $c) { $u[$c] = 'tutorials.php?&' . tileEncrypt('course') . '=' . tileEncrypt($c); }
        fsc_out(['state' => 'ok', 'urls' => $u]);
    }

    if ($mode === 'select') {
        $rows = [];
        $add = function ($sql, $p) use ($pdo, &$rows) { $s = $pdo->prepare($sql); $s->execute($p); foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[(int)$r['id']] = $r; } };
        foreach (($in['filters'] ?? []) as $f) {
            if (isset($f['subcategory'])) { $add("SELECT * FROM `$t` WHERE category = ? AND subcategory = ?", [$f['category'], $f['subcategory']]); }
            else { $add("SELECT * FROM `$t` WHERE category = ?", [$f['category']]); }
        }
        foreach (($in['prefixes'] ?? []) as $p) { $e = addcslashes($p, '\\%_') . '%'; $add("SELECT * FROM `$t` WHERE file_path LIKE ? OR content_id LIKE ?", [$e, $e]); }
        foreach (($in['paths'] ?? []) as $p) { $add("SELECT * FROM `$t` WHERE file_path = ? OR content_id = ?", [$p, $p]); }
        foreach (($in['ids'] ?? []) as $id) { $add("SELECT * FROM `$t` WHERE id = ?", [(int)$id]); }
        ksort($rows);
        fsc_out(fsc_rows($rows));
    }

    if ($mode === 'copysurvey') {
        $s = $pdo->query("SELECT id, category, subcategory, file_path, created_at FROM `$t` WHERE LOWER(category) LIKE '% copy' OR LOWER(subcategory) LIKE '% copy' ORDER BY id");
        fsc_out(fsc_rows($s->fetchAll(PDO::FETCH_ASSOC)));
    }

    if ($mode === 'catcount') {
        $o = []; $s = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE category = ?");
        foreach ($in['categories'] as $c) { $s->execute([$c]); $o[$c] = (int)$s->fetchColumn(); }
        fsc_out(['state' => 'ok', 'counts' => $o]);
    }

    if ($mode === 'delete') {
        $pdo->beginTransaction(); $n = 0;
        $d = $pdo->prepare("DELETE FROM `$t` WHERE id = ? AND file_path = ?");
        foreach ($in['items'] as $it) {
            $d->execute([(int)$it['id'], (string)$it['file_path']]);
            if ($d->rowCount() !== 1) { $pdo->rollBack(); fsc_out(['state' => 'error', 'message' => 'row ' . $it['id'] . ' not found with the expected path; nothing deleted']); }
            $n++;
        }
        $pdo->commit(); fsc_out(['state' => 'ok', 'deleted' => $n]);
    }

    if ($mode === 'restore') {
        $rows = json_decode(base64_decode((string)$in['rows_b64']), true);
        if (!is_array($rows)) { fsc_out(['state' => 'error', 'message' => 'bad backup rows']); }
        $pdo->beginTransaction(); $ins = 0; $same = 0;
        $byId = $pdo->prepare("SELECT * FROM `$t` WHERE id = ?");
        $byPath = $pdo->prepare("SELECT id FROM `$t` WHERE file_path = ? AND id <> ?");
        foreach ($rows as $r) {
            foreach (array_keys($r) as $k) { if (!preg_match('/^[a-z_]+$/', $k)) { $pdo->rollBack(); fsc_out(['state' => 'error', 'message' => 'bad column in backup']); } }
            $byId->execute([(int)$r['id']]); $ex = $byId->fetch(PDO::FETCH_ASSOC);
            if ($ex) {
                if ($ex['file_path'] === $r['file_path']) { $same++; continue; }
                $pdo->rollBack(); fsc_out(['state' => 'error', 'message' => 'id ' . $r['id'] . ' is now used by another row; nothing restored']);
            }
            $byPath->execute([$r['file_path'], (int)$r['id']]); $o = $byPath->fetchColumn();
            if ($o !== false) { $pdo->rollBack(); fsc_out(['state' => 'error', 'message' => 'the path of row ' . $r['id'] . ' is now in row ' . $o . '; nothing restored']); }
            $k = array_keys($r);
            $pdo->prepare("INSERT INTO `$t` (`" . implode('`,`', $k) . "`) VALUES (" . implode(',', array_fill(0, count($k), '?')) . ")")->execute(array_values($r));
            $ins++;
        }
        $pdo->commit(); fsc_out(['state' => 'ok', 'inserted' => $ins, 'already' => $same]);
    }

    /* ---- scratch-table modes for -SelfTest only ---- */
    if (!$scratch) { fsc_out(['state' => 'error', 'message' => "mode $mode is only allowed on a scratch table"]); }
    if ($mode === 'clone') {
        $ids = array_map('intval', $in['ids']); if (!$ids) { fsc_out(['state' => 'error', 'message' => 'no ids']); }
        $pdo->exec("CREATE TABLE `$t` LIKE content_meta");
        $pdo->exec("INSERT INTO `$t` SELECT * FROM content_meta WHERE id IN (" . implode(',', $ids) . ")");
        fsc_out(['state' => 'ok', 'count' => (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()]);
    }
    if ($mode === 'dump') { fsc_out(fsc_rows($pdo->query("SELECT * FROM `$t` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC))); }
    if ($mode === 'simindex') {
        $s = $pdo->prepare("SELECT * FROM `$t` WHERE file_path = ?"); $s->execute([$in['old']]); $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) { fsc_out(['state' => 'error', 'message' => 'simindex: old row not found']); }
        unset($r['id']); $r['file_path'] = $in['new']; $r['content_id'] = $in['new']; $r['thumbnail_path'] = $in['thumb']; $r['title'] = $in['title'];
        $k = array_keys($r);
        $pdo->prepare("INSERT INTO `$t` (`" . implode('`,`', $k) . "`) VALUES (" . implode(',', array_fill(0, count($k), '?')) . ")")->execute(array_values($r));
        fsc_out(['state' => 'ok', 'id' => (int)$pdo->lastInsertId()]);
    }
    if ($mode === 'drop') { $pdo->exec("DROP TABLE IF EXISTS `$t`"); fsc_out(['state' => 'ok']); }
    fsc_out(['state' => 'error', 'message' => 'unknown mode']);
} catch (Throwable $e) {
    try { if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable $e2) { }
    fsc_out(['state' => 'error', 'message' => get_class($e) . ': ' . $e->getMessage()]);
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

# ---------------------------------------------------------------- files
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
function Compare-Trees([string]$a, [string]$b) {
  $ta = Get-Tree $a; $tb = Get-Tree $b; $diff = New-Object System.Collections.ArrayList
  foreach ($k in $ta.files.Keys) { if (-not $tb.files.ContainsKey($k)) { [void]$diff.Add("only in original: $k") } elseif ($ta.files[$k].size -ne $tb.files[$k].size) { [void]$diff.Add("size differs: $k") } }
  foreach ($k in $tb.files.Keys) { if (-not $ta.files.ContainsKey($k)) { [void]$diff.Add("only in copy: $k") } }
  foreach ($k in $ta.dirs.Keys) { if (-not $tb.dirs.ContainsKey($k)) { [void]$diff.Add("folder only in original: $k") } }
  foreach ($k in $tb.dirs.Keys) { if (-not $ta.dirs.ContainsKey($k)) { [void]$diff.Add("folder only in copy: $k") } }
  $list = New-Object System.Collections.ArrayList; $bytes = [long]0
  if ($diff.Count -eq 0) {
    foreach ($k in ($tb.files.Keys | Sort-Object)) {
      $ha = (Get-FileHash -LiteralPath (Join-Path $a $k) -Algorithm SHA256).Hash
      $hb = (Get-FileHash -LiteralPath (Join-Path $b $k) -Algorithm SHA256).Hash
      if ($ha -ne $hb) { [void]$diff.Add("content differs: $k") }
      $bytes += $tb.files[$k].size
      [void]$list.Add([pscustomobject]@{ rel = $k; size = $tb.files[$k].size; sha256 = $hb; sha256_original = $ha })
    }
  }
  return [pscustomobject]@{ identical = ($diff.Count -eq 0); diffs = @($diff); files = @($list); bytes = $bytes; fileCount = $tb.files.Count; dirCount = $tb.dirs.Count }
}

# ---------------------------------------------------------------- plan (read-only)
function Get-Plan {
  $p = [ordered]@{ problems = New-Object System.Collections.ArrayList; notes = New-Object System.Collections.ArrayList; groups = @(); genai = $null; rename = $null; survey = @() }
  foreach ($g in $Groups) {
    $r = Invoke-Helper @{ mode = 'select'; filters = @($g.filter); prefixes = @($g.prefix) }
    $rows = @($r.rows); $ids = @($rows | ForEach-Object { [int]$_.id } | Sort-Object)
    $present = @($rows | Where-Object { $lp = Get-LibPath $_.file_path; $lp -and (Test-Path -LiteralPath $lp) })
    $state = 'ok'
    if ($rows.Count -eq 0) { $state = 'already-clean' }
    elseif ((@($ids) -join ',') -ne (@($g.expect) -join ',')) { $state = 'unexpected'; [void]$p.problems.Add("$($g.key): found ids $($ids -join ',') but expected $($g.expect -join ','); nothing will be deleted") }
    if ($present.Count -gt 0) { $state = 'unexpected'; [void]$p.problems.Add("$($g.key): $($present.Count) row(s) now point to EXISTING files (e.g. $($present[0].file_path)); nothing will be deleted") }
    if (Test-Path -LiteralPath (Join-Path $Library $g.absentFolder)) { $state = 'unexpected'; [void]$p.problems.Add("$($g.key): folder $($g.absentFolder) exists again in the library; nothing will be deleted") }
    $outside = @($rows | Where-Object { -not ([string]$_.file_path).StartsWith($g.prefix, [StringComparison]::OrdinalIgnoreCase) })
    if ($outside.Count) { $state = 'unexpected'; [void]$p.problems.Add("$($g.key): $($outside.Count) row(s) point outside $($g.prefix); nothing will be deleted") }
    $origInfo = ''
    if ($g.ContainsKey('orig') -and $g.orig -and $rows.Count) {
      $od = Join-Path $Library $g.orig
      $ro = Invoke-Helper @{ mode = 'select'; filters = @(@{ category = $g.orig }) }
      $map = @{}; foreach ($x in @($ro.rows)) { $map[[string]$x.file_path] = $true }
      $oFiles = @(@($ro.rows) | Where-Object { $lp = Get-LibPath $_.file_path; $lp -and (Test-Path -LiteralPath $lp) }).Count
      $noOrig = @($rows | Where-Object { $_.file_path.Length -gt $g.prefix.Length } | Where-Object { $op = 'content/' + $g.orig + '/' + ([string]$_.file_path).Substring($g.prefix.Length); $lp = Get-LibPath $op; -not $map.ContainsKey($op) -or -not $lp -or -not (Test-Path -LiteralPath $lp) })
      $origInfo = "original '$($g.orig)': folder exists $(Test-Path -LiteralPath $od), $(@($ro.rows).Count) rows, $oFiles with files; copy rows with an original row+file at the same relative path: $($rows.Count - $noOrig.Count)/$($rows.Count)"
      if (-not (Test-Path -LiteralPath $od)) { $state = 'unexpected'; [void]$p.problems.Add("$($g.key): original folder $($g.orig) is missing; nothing will be deleted") }
      if (@($ro.rows).Count -eq 0) { $state = 'unexpected'; [void]$p.problems.Add("$($g.key): original category $($g.orig) has no rows; nothing will be deleted") }
      if ($noOrig.Count) { $state = 'unexpected'; [void]$p.problems.Add("$($g.key): $($noOrig.Count) row(s) have no original row with an existing file (e.g. $($noOrig[0].file_path)); nothing will be deleted") }
    }
    $p.groups += [pscustomobject]@{ key = $g.key; label = $g.label; state = $state; rows = $rows; text = (Get-RowsText $r); origInfo = $origInfo }
  }
  if (-not $SkipGenAiCopy) {
    $a = Join-Path $Library $GenOrig; $b = Join-Path $Library $GenCopy
    $ga = [pscustomobject]@{ state = 'ok'; cmp = $null; rows = @(); text = '[]'; src = $b }
    if (-not (Test-Path -LiteralPath $b)) {
      $r = Invoke-Helper @{ mode = 'select'; filters = @(@{ category = $GenCopy }); prefixes = @("content/$GenCopy/") }
      if (@($r.rows).Count -eq 0) { $ga.state = 'already-clean' } else { $ga.state = 'unexpected'; [void]$p.problems.Add("genai-copy: folder is gone but $(@($r.rows).Count) rows remain; not handled here") }
    } elseif (-not (Test-Path -LiteralPath $a)) { $ga.state = 'unexpected'; [void]$p.problems.Add("genai-copy: the original folder $GenOrig is missing; the copy is kept") }
    else {
      $ga.cmp = Compare-Trees $a $b
      if (-not $ga.cmp.identical) { $ga.state = 'unexpected'; [void]$p.problems.Add("genai-copy: NOT identical to the original (" + (@($ga.cmp.diffs | Select-Object -First 5) -join '; ') + "); the copy is kept") }
      $r = Invoke-Helper @{ mode = 'select'; filters = @(@{ category = $GenCopy }); prefixes = @("content/$GenCopy/") }
      $ga.rows = @($r.rows); $ga.text = Get-RowsText $r
      $bad = @($ga.rows | Where-Object { -not ([string]$_.file_path).StartsWith("content/$GenCopy/", [StringComparison]::OrdinalIgnoreCase) })
      if ($bad.Count) { $ga.state = 'unexpected'; [void]$p.problems.Add("genai-copy: $($bad.Count) rows of the copy category point outside the copy folder") }
      $ro = Invoke-Helper @{ mode = 'select'; prefixes = @("content/$GenOrig/") }
      $origRel = @{}; foreach ($x in @($ro.rows)) { $origRel[([string]$x.file_path).Substring(("content/$GenOrig/").Length)] = $true }
      $missing = @($ga.rows | Where-Object { -not $origRel.ContainsKey(([string]$_.file_path).Substring(("content/$GenCopy/").Length)) })
      if ($missing.Count) { [void]$p.notes.Add("genai-copy: $($missing.Count) copy rows have no matching row in '$GenOrig' (e.g. $($missing[0].file_path)); the files are identical, so a partial index of '$GenOrig' would cover them") }
      $dst = Join-Path $ArchiveRoot ('<stamp>\' + $GenCopy)
      if ([IO.Path]::GetPathRoot($Library) -ne [IO.Path]::GetPathRoot($ArchiveRoot)) { $ga.state = 'unexpected'; [void]$p.problems.Add("genai-copy: archive root is on another drive than the library; a same-drive move is required") }
    }
    $p.genai = $ga
  }
  if (-not $SkipRename) {
    $scope = Join-Path $Library $RenameScope
    $rn = [pscustomobject]@{ state = 'ok'; items = @(); videos = @(); text = '[]'; oldRows = @() }
    $cands = @(Get-ChildItem -LiteralPath $scope -Recurse -Force | Where-Object { -not $_.PSIsContainer -and $_.Name.Contains('#') })
    $targets = @{}
    foreach ($f in $cands) {
      $new = Get-SafeName $f.Name; $np = Join-Path $f.DirectoryName $new
      $rel = $f.FullName.Substring($Library.TrimEnd('\').Length + 1) -replace '\\', '/'
      $nrel = ($rel.Substring(0, $rel.Length - $f.Name.Length) + $new)
      $it = [pscustomobject]@{ old = $f.FullName; new = $np; oldName = $f.Name; newName = $new; oldRel = $rel; newRel = $nrel; size = $f.Length; isVideo = ($VideoExt -contains $f.Extension.ToLower()) }
      if ($new -eq $f.Name -or $new -eq $f.Extension) { $rn.state = 'unexpected'; [void]$p.problems.Add("rename: no usable new name for $rel") }
      if (Test-Path -LiteralPath $np) { $rn.state = 'unexpected'; [void]$p.problems.Add("rename: target already exists: $nrel") }
      if ($targets.ContainsKey($np)) { $rn.state = 'unexpected'; [void]$p.problems.Add("rename: two files map to $nrel") }
      $targets[$np] = $true; $rn.items += $it
    }
    $rn.videos = @($rn.items | Where-Object { $_.isVideo })
    if ($rn.items.Count -eq 0) { $rn.state = 'already-clean' }
    else {
      $r = Invoke-Helper @{ mode = 'select'; paths = @($rn.videos | ForEach-Object { 'content/' + $_.oldRel }) }
      $rn.oldRows = @($r.rows); $rn.text = Get-RowsText $r
      foreach ($v in $rn.videos) {
        $m = @($rn.oldRows | Where-Object { $_.file_path -eq ('content/' + $v.oldRel) })
        if ($m.Count -ne 1) { $rn.state = 'unexpected'; [void]$p.problems.Add("rename: expected 1 catalog row for $($v.oldRel), found $($m.Count)") }
      }
      if ($rn.videos.Count) {
        $n = Invoke-Helper @{ mode = 'select'; paths = @($rn.videos | ForEach-Object { 'content/' + $_.newRel }) }
        if (@($n.rows).Count) { $rn.state = 'unexpected'; [void]$p.problems.Add("rename: a catalog row already exists for the new name") }
      }
    }
    $p.rename = $rn
  }
  $p.survey = @(Get-CopySurvey)
  return [pscustomobject]$p
}

function Get-CopySurvey {
  # read-only: every category / subcategory whose name ends in ' - Copy' or ' copy' (case-insensitive)
  $rx = '(?i) copy$'
  $rows = @((Invoke-Helper @{ mode = 'copysurvey' }).rows)
  $ent = [ordered]@{}
  foreach ($r in $rows) {
    $keys = @()
    if ([string]$r.category -match $rx) { $keys += , @('category', [string]$r.category, [string]$r.category) }
    if ([string]$r.subcategory -match $rx) { $keys += , @('subcategory', ("{0} / {1}" -f $r.category, $r.subcategory), [string]$r.category) }
    foreach ($k in $keys) {
      $id = $k[0] + '|' + $k[1]
      if (-not $ent.Contains($id)) { $ent[$id] = [pscustomobject]@{ level = $k[0]; name = $k[1]; category = $k[2]; subcategory = $(if ($k[0] -eq 'subcategory') { [string]$r.subcategory } else { '' }); rows = New-Object System.Collections.ArrayList } }
      [void]$ent[$id].rows.Add($r)
    }
  }
  $out = @()
  foreach ($e in $ent.Values) {
    $rs = @($e.rows)
    $files = @($rs | Where-Object { $lp = Get-LibPath $_.file_path; $lp -and (Test-Path -LiteralPath $lp) }).Count
    $dirs = @($rs | ForEach-Object { $lp = Get-LibPath $_.file_path; if ($lp) { Split-Path $lp } } | Select-Object -Unique)
    $dirsOnDisk = @($dirs | Where-Object { Test-Path -LiteralPath $_ }).Count
    $folder = $(if ($e.level -eq 'category') { Join-Path $Library $e.category } else { Join-Path (Join-Path $Library $e.category) $e.subcategory })
    $folderExists = Test-Path -LiteralPath $folder
    $origName = [regex]::Replace($(if ($e.level -eq 'category') { $e.category } else { $e.subcategory }), '(?i)( - copy| copy)$', '')
    if ($e.level -eq 'category') { $or = @((Invoke-Helper @{ mode = 'select'; filters = @(@{ category = $origName }) }).rows); $oFolder = Join-Path $Library $origName }
    else { $or = @((Invoke-Helper @{ mode = 'select'; filters = @(@{ category = $e.category; subcategory = $origName }) }).rows); $oFolder = Join-Path (Join-Path $Library $e.category) $origName }
    $oFiles = @($or | Where-Object { $lp = Get-LibPath $_.file_path; $lp -and (Test-Path -LiteralPath $lp) }).Count
    $stale = (-not $folderExists) -and $dirsOnDisk -eq 0 -and $files -eq 0
    $hasOrig = $or.Count -gt 0 -and $oFiles -gt 0 -and (Test-Path -LiteralPath $oFolder)
    $step = ''; if ($e.level -eq 'category' -and $StepCategories.ContainsKey($e.category)) { $step = $StepCategories[$e.category] }
    $verdict = $(if ($step) { "handled by step $step" } elseif ($stale -and $hasOrig) { 'stale + original exists: candidate, NOT deleted by this script (needs its own reviewed step)' } elseif ($stale) { 'stale, but NO original found: report only' } else { 'folder/files exist: not stale, left alone' })
    $c = @($rs | ForEach-Object { [string]$_.created_at } | Sort-Object)
    $out += [pscustomobject]@{ level = $e.level; name = $e.name; rows = $rs.Count; files = $files; folderExists = $folderExists; stale = $stale
                               created = $(if ($c.Count) { "$($c[0])..$($c[-1]) UTC" } else { '' }); original = $origName; origRows = $or.Count; origFiles = $oFiles; origFolder = (Test-Path -LiteralPath $oFolder); step = $step; verdict = $verdict }
  }
  return $out
}

function Show-Plan($p) {
  Say ''
  Say '=== Plan ==='
  foreach ($g in $p.groups) {
    Say ("[{0}] {1}: {2} row(s), state {3}" -f $g.key, $g.label, @($g.rows).Count, $g.state)
    if ($g.origInfo) { Say ("     " + $g.origInfo) }
    $gr = @($g.rows)
    if ($gr.Count -gt 15) { foreach ($s in @($gr | Group-Object subcategory | Sort-Object Name)) { Say ("     {0,3} row(s)  {1}" -f $s.Count, $s.Name) }; Say ("     ids {0} .. {1}" -f $gr[0].id, $gr[-1].id) }
    else { foreach ($r in $gr) { Say ("     id {0}  {1}" -f $r.id, $r.file_path) } }
  }
  if ($p.genai) {
    $g = $p.genai
    if ($g.cmp) { Say ("[genai-copy] '$GenCopy': {0} files, {1:N0} bytes, {2} folders, identical={3}; {4} catalog row(s); state {5}" -f $g.cmp.fileCount, $g.cmp.bytes, $g.cmp.dirCount, $g.cmp.identical, @($g.rows).Count, $g.state); Say ("     will move to: " + (Join-Path $ArchiveRoot ('<stamp>\' + $GenCopy))) }
    else { Say "[genai-copy] state $($g.state)" }
  }
  if ($p.rename) {
    Say ("[rename-hash] {0} file(s) under videos\{1} with '#', {2} video(s), state {3}" -f @($p.rename.items).Count, $RenameScope, @($p.rename.videos).Count, $p.rename.state)
    foreach ($i in @($p.rename.items)) { Say ("     '{0}'  ->  '{1}'" -f $i.oldRel, $i.newName) }
    foreach ($r in @($p.rename.oldRows)) { Say ("     old row id {0} ({1}/{2}) will be removed after the new path is indexed" -f $r.id, $r.category, $r.subcategory) }
  }
  if ($p.rename -eq $null) { Say "[rename-hash] skipped (default; patch 6b encodes links). Use -IncludeRename to run it." }
  Say ''
  Say "=== Survey: categories/subcategories ending in ' - Copy' or ' copy' (read-only) ==="
  if (@($p.survey).Count -eq 0) { Say '  none' }
  foreach ($s in @($p.survey)) {
    Say ("  [{0}] '{1}': {2} row(s), {3} file(s) on disk, folder exists {4}, created {5}" -f $s.level, $s.name, $s.rows, $s.files, $s.folderExists, $s.created)
    Say ("       original '{0}': {1} row(s), {2} file(s), folder exists {3}  =>  {4}" -f $s.original, $s.origRows, $s.origFiles, $s.origFolder, $s.verdict)
  }
  foreach ($n in $p.notes) { Say "  note: $n" }
  if ($p.problems.Count) { Say ''; Say 'PROBLEMS (nothing is changed while these exist):'; foreach ($x in $p.problems) { Say "  - $x" } } else { Say ''; Say 'No problems found.' }
}

# ---------------------------------------------------------------- partial index through the trigger folder
function Test-IndexerAlive {
  $hb = Join-Path $TriggerDir 'indexer-heartbeat.txt'
  if (-not (Test-Path -LiteralPath $hb)) { return "no heartbeat file in $TriggerDir" }
  $age = ((Get-Date) - (Get-Item -LiteralPath $hb).LastWriteTime).TotalSeconds
  if ($age -gt 90) { return ("partial indexer heartbeat is " + [int]$age + " s old; it does not seem to be running") }
  return ''
}
function Request-Index([string]$name, [string[]]$files) {
  $req = [ordered]@{ batch = $name; files = @($files) }
  $tmp = Join-Path $TriggerDir ($name + '.request.tmp'); $final = Join-Path $TriggerDir ($name + '.request.json'); $resPath = Join-Path $TriggerDir ($name + '.result.json')
  if ((Test-Path -LiteralPath $final) -or (Test-Path -LiteralPath $resPath)) { throw "index request $name already exists" }
  [IO.File]::WriteAllText($tmp, ($req | ConvertTo-Json -Compress), $utf8)
  Move-Item -LiteralPath $tmp -Destination $final
  Say ("  queued partial index request $name (" + @($files).Count + " file(s))")
  $deadline = (Get-Date).AddSeconds($IndexTimeoutSec)
  while ((Get-Date) -lt $deadline) {
    if (Test-Path -LiteralPath $resPath) { Start-Sleep -Milliseconds 500; try { return (Get-Content -LiteralPath $resPath -Raw -Encoding UTF8 | ConvertFrom-Json) } catch { } }
    Start-Sleep -Seconds 3
  }
  if (Test-Path -LiteralPath $final) { Remove-Item -LiteralPath $final -ErrorAction SilentlyContinue; Say "  index request was not picked up in time and was withdrawn" }
  return $null
}

# ---------------------------------------------------------------- HTTP
function Invoke-Curl([string[]]$a) {
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @(& $script:Curl @a 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
  return (@($o) -join "`n").Trim()
}
function New-Jar { $j = Join-Path $env:TEMP ('fsc-jar-' + [guid]::NewGuid().ToString('N') + '.txt'); [void](Invoke-Curl @('-s', '-g', '-o', 'NUL', '-c', $j, '-b', $j, "$BaseUrl/guest.php")); return $j }
function Get-Page([string]$jar, [string]$rel) {
  $f = Join-Path $env:TEMP ('fsc-page-' + [guid]::NewGuid().ToString('N') + '.html')
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

function Invoke-HttpCheck([switch]$Expect, $renamedNames) {
  # -Expect: assert the fixed state; otherwise just report the current state
  $fails = New-Object System.Collections.ArrayList
  $cats = @('Adobe Photoshop', 'Adobe XD', 'Adobe Lightroom', 'Adobe Lightroom Copy', $GenOrig, $GenCopy, 'AutoCad', 'AutoCad - Copy')
  $urls = (Invoke-Helper @{ mode = 'urls'; categories = $cats } 'content_meta').urls
  $jar = New-Jar
  try {
    Say ''; Say "=== HTTP check ($BaseUrl, guest session) ==="
    $ps = Test-CategoryPage $jar $urls.'Adobe Photoshop'
    $hasXd = @($ps.sections | Where-Object { $_.title -eq 'ADOBE XD' }).Count -gt 0
    Say ("Adobe Photoshop page {0}: {1} cards; 'ADOBE XD' card present: {2}" -f $ps.code, @($ps.sections).Count, $hasXd)
    if ($Expect -and ($ps.code -ne '200' -or $hasXd -or @($ps.sections).Count -eq 0)) { [void]$fails.Add('Photoshop page still shows the ADOBE XD card (or failed)') }

    $xd = Test-CategoryPage $jar $urls.'Adobe XD' -Media
    $card = @($xd.sections | Where-Object { $_.title -eq 'ADOBE XD' })
    $okMedia = @($xd.watch | Where-Object { Test-MediaOk $_ }).Count
    $badImg = @($xd.imgs | Where-Object { $_.code -ne '200' })
    Say ("Adobe XD page {0}: card 'ADOBE XD' {1} videos; watch links {2}; media OK {3}/{2}; thumbnails OK {4}/{5}" -f $xd.code, $(if ($card) { $card[0].count } else { 'missing' }), @($xd.watch).Count, $okMedia, (@($xd.imgs).Count - $badImg.Count), @($xd.imgs).Count)
    foreach ($w in $xd.watch) { if (-not (Test-MediaOk $w) -or $w.src -match '%23') { Say ("   watch {0} -> {1} -> {2} {3}" -f $w.watch, $w.src, $w.media, $w.type) } }
    foreach ($i in $badImg) { Say ("   thumbnail {0} -> {1}" -f $i.url, $i.code) }
    foreach ($n in @($renamedNames)) {
      $hit = @($xd.watch | Where-Object { [Uri]::UnescapeDataString($_.src).EndsWith('/' + $n) })
      if ($hit) { Say ("   renamed video: {0} -> watch {1} -> {2} {3}" -f $n, $hit[0].watch, $hit[0].media, $hit[0].type) } else { Say "   renamed video $n : no link on the page"; if ($Expect) { [void]$fails.Add("renamed video $n has no link on the Adobe XD page") } }
      if ($hit -and -not (Test-MediaOk $hit[0])) { [void]$fails.Add("renamed video $n does not play") }
    }
    if ($Expect) {
      if ($xd.code -ne '200' -or -not $card -or $card[0].count -ne $XdExpectVideos) { [void]$fails.Add("Adobe XD page does not show ADOBE XD with $XdExpectVideos videos") }
      if (@($xd.watch).Count -ne $XdExpectVideos -or $okMedia -ne @($xd.watch).Count) { [void]$fails.Add("Adobe XD: $okMedia of $(@($xd.watch).Count) videos play") }
      if (@($renamedNames).Count -and @($xd.watch | Where-Object { $_.src -match '%23' }).Count) { [void]$fails.Add("Adobe XD still has a '#' video") }
      if ($badImg.Count) { if (@($renamedNames).Count) { [void]$fails.Add("Adobe XD: $($badImg.Count) thumbnail(s) fail") } else { Say "   warning: $($badImg.Count) Adobe XD thumbnail(s) fail (not changed by this script)" } }
    }

    foreach ($c in @('Adobe Lightroom Copy', 'AutoCad - Copy', $GenCopy)) {
      $pg = Test-CategoryPage $jar $urls.$c
      Say ("{0} page {1}: {2} cards, {3} watch links" -f $c, $pg.code, @($pg.sections).Count, @($pg.links).Count)
      if ($Expect -and (@($pg.sections).Count -ne 0 -or @($pg.links).Count -ne 0)) { [void]$fails.Add("$c page still lists content") }
    }
    $lr = Test-CategoryPage $jar $urls.'Adobe Lightroom' -Media
    Say ("Adobe Lightroom page {0} (not changed by this script): {1}; media {2}" -f $lr.code, ((@($lr.sections) | ForEach-Object { "$($_.title) | $($_.count)" }) -join '; '), ((@($lr.watch) | ForEach-Object { $_.media }) -join ','))
    $ga = Test-CategoryPage $jar $urls.$GenOrig -Media
    $gok = @($ga.watch | Where-Object { Test-MediaOk $_ }).Count
    Say ("{0} page {1}: {2} cards, media OK {3}/{4}" -f $GenOrig, $ga.code, @($ga.sections).Count, $gok, @($ga.watch).Count)
    if ($Expect -and ($ga.code -ne '200' -or @($ga.sections).Count -eq 0 -or $gok -ne @($ga.watch).Count)) { [void]$fails.Add("$GenOrig page failed") }

    $ac = Test-CategoryPage $jar $urls.'AutoCad' -Media
    $aok = @($ac.watch | Where-Object { Test-MediaOk $_ }).Count
    Say ("AutoCad page {0}: {1} cards, media OK {2}/{3}" -f $ac.code, @($ac.sections).Count, $aok, @($ac.watch).Count)
    foreach ($w in @($ac.watch | Where-Object { -not (Test-MediaOk $_) } | Select-Object -First 5)) { Say ("   watch {0} -> {1} -> {2} {3}" -f $w.watch, $w.src, $w.media, $w.type) }
    if ($Expect -and ($ac.code -ne '200' -or @($ac.sections).Count -eq 0 -or @($ac.watch).Count -eq 0 -or $aok -ne @($ac.watch).Count)) { [void]$fails.Add("AutoCad page failed") }

    $cc = (Invoke-Helper @{ mode = 'catcount'; categories = @('Adobe Lightroom Copy', 'AutoCad - Copy', $GenCopy, 'AutoCad') } 'content_meta').counts
    $psx = @((Invoke-Helper @{ mode = 'select'; filters = @($GroupA.filter) } 'content_meta').rows).Count
    Say ("catalog rows: Adobe Photoshop/ADOBE XD {0}, Adobe Lightroom Copy {1}, AutoCad - Copy {2}, {3} {4}, AutoCad {5}" -f $psx, $cc.'Adobe Lightroom Copy', $cc.'AutoCad - Copy', $GenCopy, $cc.$GenCopy, $cc.'AutoCad')
  } finally { Remove-Item -LiteralPath $jar -Force -ErrorAction SilentlyContinue }
  return @($fails)
}

# ---------------------------------------------------------------- journal
function Save-Journal($bk, $J) { [IO.File]::WriteAllText((Join-Path $bk 'journal.json'), ($J | ConvertTo-Json -Depth 6), $utf8) }
function Write-RowsBackup($bk, [string]$name, [string]$text) {
  [IO.File]::WriteAllText((Join-Path $bk "rows-$name.json"), $text, $utf8)
  $rows = @($text | ConvertFrom-Json)
  if ($rows.Count) { $rows | Export-Csv -LiteralPath (Join-Path $bk "rows-$name.csv") -NoTypeInformation -Encoding UTF8 }
}
function Check-Fail([string]$at) { if ($FailAt -and $FailAt -eq $at) { throw "injected test failure at $at" } }

# ---------------------------------------------------------------- apply
function Invoke-Apply($plan, [string]$stamp) {
  $bk = Join-Path $BackupRoot $stamp
  if (Test-Path -LiteralPath $bk) { throw "backup folder $bk already exists" }
  [void](New-Item -ItemType Directory -Path $bk)
  $script:Log = Join-Path $bk 'log.txt'
  Say "Backup folder: $bk"
  $J = [ordered]@{ stamp = $stamp; started = (Get-Date).ToString('s'); table = $script:Table; library = $Library; state = 'started'
                   deleted = @(); genai = $null; renamed = @(); index_request = ''; new_rows = @(); http = '' }
  foreach ($g in $plan.groups) { if ($g.state -eq 'ok') { Write-RowsBackup $bk $g.key $g.text } }
  if ($plan.genai -and $plan.genai.state -eq 'ok') {
    Write-RowsBackup $bk 'genai-copy' $plan.genai.text
    $plan.genai.cmp.files | Export-Csv -LiteralPath (Join-Path $bk 'genai-copy-files.csv') -NoTypeInformation -Encoding UTF8
  }
  if ($plan.rename -and $plan.rename.state -eq 'ok') {
    Write-RowsBackup $bk 'xd-hash-old' $plan.rename.text
    $plan.rename.items | Select-Object oldRel, newRel, size, isVideo | Export-Csv -LiteralPath (Join-Path $bk 'rename-map.csv') -NoTypeInformation -Encoding UTF8
  }
  Save-Journal $bk $J
  try {
    foreach ($g in $plan.groups) {
      if ($g.state -ne 'ok') { continue }
      $items = @($g.rows | ForEach-Object { @{ id = [int]$_.id; file_path = [string]$_.file_path } })
      $r = Invoke-Helper @{ mode = 'delete'; items = $items }
      $J.deleted += $g.key; Save-Journal $bk $J
      Say ("[{0}] deleted {1} stale row(s)" -f $g.key, $r.deleted)
    }
    Check-Fail 'after-rows'
    if ($plan.genai -and $plan.genai.state -eq 'ok') {
      $src = $plan.genai.src; $dstParent = Join-Path $ArchiveRoot $stamp; $dst = Join-Path $dstParent $GenCopy
      if (Test-Path -LiteralPath $dst) { throw "archive target $dst already exists" }
      if (-not (Test-Path -LiteralPath $dstParent)) { [void](New-Item -ItemType Directory -Path $dstParent -Force) }
      Move-Item -LiteralPath $src -Destination $dst
      $J.genai = [ordered]@{ from = $src; to = $dst; rows_deleted = $false }; Save-Journal $bk $J
      $t = Get-Tree $dst
      if ($t.files.Count -ne $plan.genai.cmp.fileCount -or (Test-Path -LiteralPath $src)) { throw "archive check failed after the move ($($t.files.Count) files at $dst)" }
      Say ("[genai-copy] moved folder ({0} files) to {1}" -f $t.files.Count, $dst)
      Check-Fail 'after-move'
      $still = @($plan.genai.rows | Where-Object { $lp = Get-LibPath $_.file_path; $lp -and (Test-Path -LiteralPath $lp) })
      if ($still.Count) { throw "after the move $($still.Count) copy row(s) still resolve to a file" }
      if (@($plan.genai.rows).Count) {
        $r = Invoke-Helper @{ mode = 'delete'; items = @($plan.genai.rows | ForEach-Object { @{ id = [int]$_.id; file_path = [string]$_.file_path } }) }
        $J.genai.rows_deleted = $true; $J.deleted += 'genai-copy'; Save-Journal $bk $J
        Say ("[genai-copy] deleted {0} catalog row(s)" -f $r.deleted)
      }
    }
    if ($plan.rename -and $plan.rename.state -eq 'ok') {
      foreach ($i in $plan.rename.items) {
        if (Test-Path -LiteralPath $i.new) { throw "rename target appeared: $($i.newRel)" }
        Rename-Item -LiteralPath $i.old -NewName $i.newName
        $J.renamed += [ordered]@{ old = $i.old; new = $i.new; oldRel = $i.oldRel; newRel = $i.newRel; isVideo = $i.isVideo }; Save-Journal $bk $J
        Say ("[rename-hash] '{0}' -> '{1}'" -f $i.oldName, $i.newName)
      }
      Check-Fail 'after-rename'
      $vids = @($plan.rename.videos)
      if ($vids.Count) {
        if ($script:Sim) {
          foreach ($v in $vids) {
            $thumb = 'content/' + ($v.newRel -replace '\.[^./]+$', '.jpg')
            [void](Invoke-Helper @{ mode = 'simindex'; old = 'content/' + $v.oldRel; new = 'content/' + $v.newRel; thumb = $thumb; title = [IO.Path]::GetFileNameWithoutExtension($v.newName) })
          }
          Say ("[rename-hash] (self-test) simulated the partial index of {0} file(s)" -f $vids.Count)
        } else {
          $name = 'fixstale-' + $stamp
          $J.index_request = $name; Save-Journal $bk $J
          $res = Request-Index $name @($vids | ForEach-Object { $_.newRel })
          if ($null -eq $res) { throw "no index result within $IndexTimeoutSec s" }
          $out = ''; if ($res.PSObject.Properties['output']) { $out = [string]$res.output }
          $line = ($out -split "`r?`n" | Where-Object { $_ -match '^Indexed \d+ items' } | Select-Object -First 1)
          Say ("  indexer: state={0}  {1}" -f $res.state, $line)
          if ($res.state -ne 'complete' -or $line -notmatch ('^Indexed ' + $vids.Count + ' items')) { throw "partial index did not index the renamed file(s)" }
        }
        $n = Invoke-Helper @{ mode = 'select'; paths = @($vids | ForEach-Object { 'content/' + $_.newRel }) }
        $J.new_rows = @(@($n.rows) | ForEach-Object { [int]$_.id }); Save-Journal $bk $J
        foreach ($v in $vids) {
          $m = @(@($n.rows) | Where-Object { $_.file_path -eq ('content/' + $v.newRel) })
          if ($m.Count -ne 1) { throw "expected 1 new catalog row for $($v.newRel), found $($m.Count)" }
          $old = @($plan.rename.oldRows | Where-Object { $_.file_path -eq ('content/' + $v.oldRel) })[0]
          if ($m[0].category -ne $old.category -or $m[0].subcategory -ne $old.subcategory) { throw "new row for $($v.newRel) has category '$($m[0].category)/$($m[0].subcategory)', expected '$($old.category)/$($old.subcategory)'" }
          Say ("[rename-hash] new row id {0}: {1} | {2} | thumb {3}" -f $m[0].id, $m[0].category, $m[0].subcategory, $m[0].thumbnail_path)
        }
        Check-Fail 'after-index'
        $r = Invoke-Helper @{ mode = 'delete'; items = @($plan.rename.oldRows | ForEach-Object { @{ id = [int]$_.id; file_path = [string]$_.file_path } }) }
        $J.deleted += 'xd-hash-old'; Save-Journal $bk $J
        Say ("[rename-hash] deleted {0} old row(s)" -f $r.deleted)
      }
    }
    if (-not $SkipHttp -and -not $script:Sim) {
      $names = @(); if ($plan.rename -and $plan.rename.state -eq 'ok') { $names = @($plan.rename.videos | ForEach-Object { $_.newName }) }
      $f = @(Invoke-HttpCheck -Expect $names)
      if ($f.Count) { $J.http = 'failed: ' + ($f -join '; '); Save-Journal $bk $J; throw ("HTTP verify failed: " + ($f -join '; ')) }
      $J.http = 'ok'
      Say 'HTTP verify: OK'
    }
    $J.state = 'done'; $J.finished = (Get-Date).ToString('s'); Save-Journal $bk $J
    Say "DONE. Rollback: $Me -Rollback $stamp"
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
  $deleted = @($J.deleted)
  # D: rename back, drop the new rows, restore the old rows
  $ren = @($J.renamed)
  if ($ren.Count) {
    $newPaths = @($ren | Where-Object { $_.isVideo } | ForEach-Object { 'content/' + $_.newRel })
    if ($newPaths.Count) {
      try {
        $n = Invoke-Helper @{ mode = 'select'; paths = $newPaths }
        if (@($n.rows).Count) {
          [IO.File]::WriteAllText((Join-Path $bk ('rows-xd-new-at-rollback-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.json')), (Get-RowsText $n), $utf8)
          $r = Invoke-Helper @{ mode = 'delete'; items = @(@($n.rows) | ForEach-Object { @{ id = [int]$_.id; file_path = [string]$_.file_path } }) }
          Say ("  [rename-hash] removed {0} new row(s) (backed up)" -f $r.deleted)
        }
      } catch { $ok = $false; Say ("  [rename-hash] could not remove new rows: " + $_.Exception.Message) }
    }
    [array]::Reverse($ren)
    foreach ($i in $ren) {
      if ((Test-Path -LiteralPath $i.new) -and -not (Test-Path -LiteralPath $i.old)) { Rename-Item -LiteralPath $i.new -NewName ([IO.Path]::GetFileName($i.old)); Say ("  [rename-hash] renamed back: " + [IO.Path]::GetFileName($i.old)) }
      elseif (Test-Path -LiteralPath $i.old) { Say ("  [rename-hash] already back: " + [IO.Path]::GetFileName($i.old)) }
      else { $ok = $false; Say ("  [rename-hash] cannot rename back, neither name is clean: " + $i.oldRel) }
    }
  }
  $order = @('xd-hash-old', 'genai-copy', 'autocad-copy', 'lr-copy', 'ps-xd')
  if ($J.genai) {
    if ((Test-Path -LiteralPath $J.genai.to) -and -not (Test-Path -LiteralPath $J.genai.from)) { Move-Item -LiteralPath $J.genai.to -Destination $J.genai.from; Say ("  [genai-copy] moved folder back to " + $J.genai.from) }
    elseif (Test-Path -LiteralPath $J.genai.from) { if (Test-Path -LiteralPath $J.genai.to) { $ok = $false; Say "  [genai-copy] both the library folder and the archive exist; left as is" } else { Say '  [genai-copy] folder already in the library' } }
    else { $ok = $false; Say ("  [genai-copy] archive folder not found: " + $J.genai.to) }
  }
  foreach ($k in $order) {
    if ($deleted -notcontains $k) { continue }
    $f = Join-Path $bk "rows-$k.json"
    try {
      $text = [IO.File]::ReadAllText($f, $utf8)
      $r = Invoke-Helper @{ mode = 'restore'; rows_b64 = [Convert]::ToBase64String($utf8.GetBytes($text)) }
      Say ("  [{0}] restored {1} row(s), {2} already present" -f $k, $r.inserted, $r.already)
    } catch { $ok = $false; Say ("  [{0}] restore FAILED: {1}" -f $k, $_.Exception.Message) }
  }
  $J2 = Get-Content -LiteralPath $jp -Raw -Encoding UTF8 | ConvertFrom-Json
  Add-Member -InputObject $J2 -NotePropertyName rolled_back -NotePropertyValue ((Get-Date).ToString('s') + $(if ($ok) { ' ok' } else { ' incomplete' })) -Force
  [IO.File]::WriteAllText($jp, ($J2 | ConvertTo-Json -Depth 6), $utf8)
  return $ok
}

# ---------------------------------------------------------------- self-test on throwaway copies
function Assert([bool]$c, [string]$m) { if ($c) { Say "  PASS  $m" } else { Say "  FAIL  $m"; $script:stFails++ } }
function Get-TreeSig([string]$root) { $t = Get-Tree $root -Hash; return ((@($t.files.Values | Sort-Object rel | ForEach-Object { "$($_.rel)|$($_.size)|$($_.sha256)" }) + @($t.dirs.Keys | Sort-Object)) -join "`n") }
function Invoke-SelfTest {
  $script:stFails = 0
  foreach ($c in @(@('Episode #3.mp4', 'Episode No 3.mp4'), @('Part#2 Intro.mp4', 'Part No 2 Intro.mp4'), @('#1 Hit.mp4', 'No 1 Hit.mp4'),
                   @('9 Soft UI Design in Adobe XD #Neumorphism.mp4', '9 Soft UI Design in Adobe XD Neumorphism.mp4'), @('A # 3  b.png', 'A No 3 b.png'),
                   @('C# Basics.mp4', 'C Basics.mp4'), @('x #tag.jpg', 'x tag.jpg'), @('keep  double  #x.mp4', 'keep double x.mp4'))) {
    Assert ((Get-SafeName $c[0]) -ceq $c[1]) ("rule: '{0}' -> '{1}' (got '{2}')" -f $c[0], $c[1], (Get-SafeName $c[0]))
  }
  $live = $Library
  $tmp = Join-Path $env:TEMP ('fsc-selftest-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
  $hex = [guid]::NewGuid().ToString('N').Substring(0, 8)
  $tbl = 'fsc_selftest_' + $hex
  $created = $false
  try {
    # throwaway library: same names as live, tiny contents
    Set-Variable -Scope Script -Name Library -Value (Join-Path $tmp 'lib'); Set-Variable -Scope Script -Name ArchiveRoot -Value (Join-Path $tmp 'archive'); Set-Variable -Scope Script -Name BackupRoot -Value (Join-Path $tmp 'backups')
    foreach ($src in @((Join-Path $live 'Adobe XD'), (Join-Path $live $GenOrig), (Join-Path $live 'AutoCad'))) {
      $base = $src.Substring($live.Length + 1)
      foreach ($i in @(Get-ChildItem -LiteralPath $src -Recurse -Force)) {
        $rel = $base + $i.FullName.Substring($src.Length)
        $dst = Join-Path $Library $rel
        if ($i.PSIsContainer) { [void](New-Item -ItemType Directory -Path $dst -Force) } else { $d = Split-Path $dst; if (-not (Test-Path -LiteralPath $d)) { [void](New-Item -ItemType Directory -Path $d -Force) }; [IO.File]::WriteAllText($dst, "selftest $rel", $utf8) }
      }
    }
    $ga = Join-Path $Library $GenOrig; $gc = Join-Path $Library $GenCopy
    [void](New-Item -ItemType Directory -Path $gc)
    foreach ($i in @(Get-ChildItem -LiteralPath $ga -Recurse -Force)) { $d = Join-Path $gc $i.FullName.Substring($ga.Length + 1); if ($i.PSIsContainer) { [void](New-Item -ItemType Directory -Path $d -Force) } else { Copy-Item -LiteralPath $i.FullName -Destination $d } }
    Say "throwaway library: $Library"
    # scratch table with copies of the affected rows
    $ids = New-Object System.Collections.ArrayList
    $sel = Invoke-Helper @{ mode = 'select'; filters = @($GroupA.filter, $GroupB.filter, $GroupE.filter, @{ category = 'AutoCad' }, @{ category = $GenCopy }, @{ category = $GenOrig }, @{ category = 'Adobe XD' }) } 'content_meta'
    foreach ($r in @($sel.rows)) { [void]$ids.Add([int]$r.id) }
    $script:Table = $tbl
    $c = Invoke-Helper @{ mode = 'clone'; ids = @($ids) }; $created = $true
    Say ("scratch table {0}: {1} rows copied" -f $tbl, $c.count)
    $script:Sim = $true; Set-Variable -Scope Script -Name SkipHttp -Value $true
    $dbBefore = Get-RowsText (Invoke-Helper @{ mode = 'dump' })
    $fsBefore = Get-TreeSig $Library

    Say ''; Say '--- self-test 1: plan'
    $plan = Get-Plan; Show-Plan $plan
    Assert ($plan.problems.Count -eq 0) 'plan has no problems'
    Assert ((@($plan.groups | Where-Object { $_.state -eq 'ok' })).Count -eq 3) 'all three stale groups (ps-xd, lr-copy, autocad-copy) found with the expected ids'
    Assert ($plan.genai.state -eq 'ok' -and $plan.genai.cmp.identical) 'copy folder identical'
    if ($SkipRename) { Assert ($null -eq $plan.rename) 'rename step skipped by default' } else { Assert (@($plan.rename.videos).Count -eq 1) 'one # video to rename' }
    $sv = @($plan.survey)
    Assert ((@($sv | Where-Object { $_.level -eq 'category' } | ForEach-Object { $_.name } | Sort-Object) -join '|') -eq 'Adobe Lightroom Copy|AutoCad - Copy|Generative AI with LLMs - Copy') 'survey lists exactly the three copy categories'
    Assert (@($sv | Where-Object { $_.name -eq 'AutoCad - Copy' -and $_.stale -and $_.step -eq 'autocad-copy' -and $_.origFiles -gt 0 }).Count -eq 1) 'survey: AutoCad - Copy stale, original present, handled by its step'

    Say ''; Say '--- self-test 2: apply'
    $ok = Invoke-Apply $plan 'st-apply'
    Assert $ok 'apply succeeded'
    $after = Invoke-Helper @{ mode = 'dump' }; $ar = @($after.rows)
    Assert (@($ar | Where-Object { $GroupA.expect -contains [int]$_.id -or $GroupB.expect -contains [int]$_.id -or $GroupE.expect -contains [int]$_.id }).Count -eq 0) 'stale rows deleted (13 + 2 + 112)'
    Assert (@($ar | Where-Object { $_.category -eq 'AutoCad' }).Count -eq 112) 'original AutoCad rows untouched (112)'
    Assert (@($ar | Where-Object { $_.category -eq $GenCopy }).Count -eq 0) 'copy rows deleted'
    Assert (-not (Test-Path -LiteralPath $gc) -and (Test-Path -LiteralPath (Join-Path $ArchiveRoot "st-apply\$GenCopy"))) 'copy folder moved to the archive'
    if ($SkipRename) {
      Assert (@($ar | Where-Object { $_.file_path -like '*Adobe XD #Neumorphism.mp4' }).Count -eq 1 -and (Test-Path -LiteralPath (Join-Path $Library 'Adobe XD\ADOBE XD\9 Soft UI Design in Adobe XD #Neumorphism.mp4'))) "'#' video and its row left as they are (rename skipped)"
    } else {
      Assert (@($ar | Where-Object { $_.file_path -like '*#*' }).Count -eq 0 -and @($ar | Where-Object { $_.file_path -like '*Adobe XD Neumorphism.mp4' }).Count -eq 1) 'old # row replaced by the new row'
      Assert ((Test-Path -LiteralPath (Join-Path $Library 'Adobe XD\ADOBE XD\9 Soft UI Design in Adobe XD Neumorphism.mp4')) -and @(Get-ChildItem -LiteralPath (Join-Path $Library 'Adobe XD') -Recurse -Force | Where-Object { $_.Name.Contains('#') }).Count -eq 0) 'files renamed'
    }
    Assert (@($ar | Where-Object { $_.category -eq 'Adobe XD' }).Count -eq 13 -and @($ar | Where-Object { $_.category -eq $GenOrig }).Count -eq 34) 'real Adobe XD and original rows untouched (13 / 34)'

    Say ''; Say '--- self-test 3: rollback'
    $script:Log = ''
    $ok = Invoke-Undo (Join-Path $BackupRoot 'st-apply')
    Assert $ok 'rollback succeeded'
    Assert ((Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore) 'rows identical to before (exact JSON)'
    Assert ((Get-TreeSig $Library) -ceq $fsBefore) 'files identical to before (names, sizes, SHA256)'

    $fas = @('after-rows', 'after-move'); if (-not $SkipRename) { $fas += @('after-rename', 'after-index') }
    foreach ($fa in $fas) {
      Say ''; Say "--- self-test: injected failure $fa -> automatic rollback"
      $script:Log = ''
      Set-Variable -Scope Script -Name FailAt -Value $fa
      $ok = Invoke-Apply (Get-Plan) ('st-' + $fa)
      Set-Variable -Scope Script -Name FailAt -Value ''
      Assert (-not $ok) "apply reported failure ($fa)"
      Assert ((Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore) "rows identical to before ($fa)"
      Assert ((Get-TreeSig $Library) -ceq $fsBefore) "files identical to before ($fa)"
    }
    $script:Log = ''
    Say ''; Say '--- self-test: refusals'
    $one = @(Get-ChildItem -LiteralPath $gc -Recurse -Force | Where-Object { -not $_.PSIsContainer })[0].FullName
    $keep = [IO.File]::ReadAllBytes($one); [IO.File]::WriteAllText($one, 'changed', $utf8)
    $p2 = Get-Plan; Assert ($p2.genai.state -eq 'unexpected') 'non-identical copy is refused'
    [IO.File]::WriteAllBytes($one, $keep)
    if (-not $SkipRename) {
      $col = Join-Path $Library 'Adobe XD\ADOBE XD\9 Soft UI Design in Adobe XD Neumorphism.png'; [IO.File]::WriteAllText($col, 'x', $utf8)
      $p3 = Get-Plan; Assert ($p3.rename.state -eq 'unexpected') 'rename collision is refused'
      Remove-Item -LiteralPath $col -Force
    }
    $acf = Join-Path $Library 'AutoCad - Copy\x'; [void](New-Item -ItemType Directory -Path $acf -Force)
    $p5 = Get-Plan; Assert (@($p5.groups | Where-Object { $_.key -eq 'autocad-copy' })[0].state -eq 'unexpected') 'autocad-copy refused when its folder reappears'
    Remove-Item -LiteralPath (Join-Path $Library 'AutoCad - Copy') -Recurse -Force
    $of = Get-LibPath ([string]@((Invoke-Helper @{ mode = 'select'; filters = @(@{ category = 'AutoCad' }) }).rows)[0].file_path)
    $ob = [IO.File]::ReadAllBytes($of); Remove-Item -LiteralPath $of -Force
    $p6 = Get-Plan; Assert (@($p6.groups | Where-Object { $_.key -eq 'autocad-copy' })[0].state -eq 'unexpected') 'autocad-copy refused when an original file is missing'
    [IO.File]::WriteAllBytes($of, $ob)
    $ren = Join-Path $Library 'AutoCad-moved'; Rename-Item -LiteralPath (Join-Path $Library 'AutoCad') -NewName 'AutoCad-moved'
    $p7 = Get-Plan; Assert (@($p7.groups | Where-Object { $_.key -eq 'autocad-copy' })[0].state -eq 'unexpected') 'autocad-copy refused when the original folder is missing'
    Rename-Item -LiteralPath $ren -NewName 'AutoCad'
    $lr = Join-Path $Library 'Adobe Lightroom Copy\x'; [void](New-Item -ItemType Directory -Path $lr -Force)
    $p4 = Get-Plan; Assert (@($p4.groups | Where-Object { $_.key -eq 'lr-copy' })[0].state -eq 'unexpected') 'stale rows kept when their folder reappears'
    Remove-Item -LiteralPath (Join-Path $Library 'Adobe Lightroom Copy') -Recurse -Force
    Assert ((Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore -and (Get-TreeSig $Library) -ceq $fsBefore) 'refusals changed nothing'
  } finally {
    if ($created) { try { [void](Invoke-Helper @{ mode = 'drop' }) ; Say "scratch table $tbl dropped" } catch { Say "could not drop $tbl : $($_.Exception.Message)" } }
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
  $bk = ''
  if ($Rollback -eq 'latest') { $d = @(Get-ChildItem -LiteralPath $BackupRoot -ErrorAction SilentlyContinue | Where-Object { $_.PSIsContainer } | Where-Object { Test-Path -LiteralPath (Join-Path $_.FullName 'journal.json') } | Sort-Object Name); if ($d.Count) { $bk = $d[-1].FullName } }
  else { $bk = Join-Path $BackupRoot $Rollback }
  if (-not $bk -or -not (Test-Path -LiteralPath (Join-Path $bk 'journal.json'))) { Write-Host "No run found to roll back ($Rollback)"; exit 1 }
  $script:Log = Join-Path $bk 'log.txt'
  Say ''; Say ("=== Rollback of {0} ({1}) ===" -f (Split-Path $bk -Leaf), (Get-Date).ToString('s'))
  if (Invoke-Undo $bk) { Say 'Rollback complete.'; exit 0 } else { Say 'Rollback INCOMPLETE - see messages above.'; exit 3 }
}

if ($VerifyOnly) {
  $vn = @(); if ($IncludeRename) { $vn = @('9 Soft UI Design in Adobe XD Neumorphism.mp4') }
  $f = @(Invoke-HttpCheck -Expect $vn)
  if ($f.Count) { Say ''; Say 'VERIFY FAILED:'; foreach ($x in $f) { Say "  - $x" }; exit 1 } else { Say ''; Say 'VERIFY OK'; exit 0 }
}

$plan = Get-Plan
Show-Plan $plan
if (-not $Apply) {
  if (-not $SkipHttp) { [void](Invoke-HttpCheck @()) }
  Say ''; Say 'Check only - nothing was changed. Run with -Apply to fix.'
  if ($plan.problems.Count) { exit 1 } else { exit 0 }
}
if ($plan.problems.Count) { Say 'Not applying because of the problems above.'; exit 1 }
$todo = @($plan.groups | Where-Object { $_.state -eq 'ok' }).Count + $(if ($plan.genai -and $plan.genai.state -eq 'ok') { 1 } else { 0 }) + $(if ($plan.rename -and $plan.rename.state -eq 'ok') { 1 } else { 0 })
if ($todo -eq 0) { Say 'Nothing to do - already clean.'; exit 0 }
if ($plan.rename -and $plan.rename.state -eq 'ok' -and @($plan.rename.videos).Count) { $e = Test-IndexerAlive; if ($e) { Say "Not applying: $e"; exit 1 } }
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
if (Invoke-Apply $plan $stamp) { exit 0 } else { exit 2 }
