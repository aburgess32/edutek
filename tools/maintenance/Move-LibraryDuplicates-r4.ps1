<#
Move-LibraryDuplicates-r4.ps1  -  (r4: never empties an Audiobooks or Music book folder; a copy inside a book folder stays when the other copy is elsewhere; r3: subcategory tile covers never move; r2: watch-sample check tells pre-existing dead rows from real breakage)  Edutek library: quarantine exact duplicate spare copies, REMOVE-LARGER near-duplicates,
folders they leave empty, and every .exe/.com file. Windows PowerShell 5.1, StrictMode 2.0.

Default: CHECK ONLY. Re-verifies every candidate from the r3b marks CSV against the disk and the live catalog and writes
a report CSV to the Desktop. Nothing is changed.
  -Apply                     re-checks everything, then: writes the manifest, backs up the catalog rows, moves the files
                             and folders to D:\edutek-system\quarantine\library-dupes-<stamp>\ (same folder layout),
                             deletes exactly the backed-up rows, verifies (files, rows, category pages, playback, 404s)
                             and rolls back by itself if anything fails
  -ExcludeNearDuplicates     leave the REMOVE-LARGER near-duplicates (and their covers) out
  -ExcludeCrossFolderNear    leave out only the near-duplicates whose kept copy is in another top-level folder
  -Rehash                    ignore all saved hashes and hash every spare and keeper again (hours)
  -Continue latest|<stamp>   finish a run that was interrupted (power cut, window closed) while moving / deleting rows
  -Rollback latest|<stamp>   moves everything back, restores the rows exactly, verifies
  -SelfTest                  runs everything on throwaway copies and a scratch table (live data untouched)
  -Resume                    accepted for compatibility; saved hashes are always reused when size+mtime still match

A spare copy (exact, from the marks CSV, Action QUARANTINE) moves only when ALL hold:
  * it still exists, and its SHA256 is the one in the CSV (cache entry with the same size+mtime, or re-hashed);
  * its keeper still exists with the same SHA256, and is not itself moved;
  * if the spare has a live catalog row, the keeper has one too (the spare's rows are deleted, never repointed);
  * if the spare has a live row and the keeper is in a different top-level folder: REPORT only, not moved;
  * no catalog row that stays uses it as thumbnail_path; it is not a KEEP/REVIEW file of any other set.
Covers/thumbnails move only together with the item they belong to. REVIEW rows and set N00237 are never moved.
Subcategory tile covers never move: <Category>\<Name>.jpg|jpeg|png|webp when <Name> is a folder in <Category> or a catalog
subcategory (the app shows these by file name, not via thumbnail_path, so no catalog row protects them).
A REMOVE-LARGER near-duplicate moves when it still exists unchanged (size, mtime not after the analysis), its kept smaller
copy still exists unchanged and has a live row wherever the larger one had one. Folders move only when every file under
them moves in this run; category root folders and the library root never move. Never runs an index, never touches the
held catalog cleanup, never repoints a row.
#>
[CmdletBinding()]
param(
  [switch]$Apply,
  [string]$Rollback = '',
  [string]$Continue = '',
  [switch]$SelfTest,
  [switch]$Resume,
  [switch]$Rehash,
  [switch]$ExcludeNearDuplicates,
  [switch]$ExcludeCrossFolderNear,
  [string]$MarksCsv = '',
  [string]$Library = 'D:\xampp\htdocs\Edutek\videos',
  [string]$BackupRoot = 'D:\edutek-system\backups\library-dupes',
  [string]$QuarantineRoot = 'D:\edutek-system\quarantine',
  [string]$R3Cache = 'D:\edutek-system\reports\dupe-scan\hash-cache.tsv',
  [string]$CacheFile = 'D:\edutek-system\scratch\library-dupes-hashes.tsv',
  [string]$ReportDir = '',
  [string[]]$VerifyCategories = @('GCSE', 'Audiobooks', 'Books', 'Social Studies Miscellaneous', 'Construction Technology'),
  [string]$BaseUrl = 'http://localhost:8080',
  [string]$Container = 'edupak-app',
  [string]$DockerContext = 'desktop-linux',
  [string]$DockerExe = '',
  [string]$TriggerDir = 'D:\edutek-system\bulk-loader-fresh-v05\data\index-trigger',
  [int]$HeartbeatMaxAgeSec = 90,
  [int]$Threads = 4,
  [string]$FailAt = ''
)
Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$utf8 = New-Object System.Text.UTF8Encoding($false)
$OutputEncoding = $utf8
$ImageExt = @('.jpg', '.jpeg', '.png', '.webp', '.gif', '.bmp')
$MediaExt = @('.mp4', '.m4v', '.mkv', '.avi', '.mov', '.webm', '.wmv', '.flv', '.mpg', '.mpeg', '.3gp', '.ts', '.mp3', '.m4a', '.m4b', '.wav', '.ogg', '.oga', '.aac', '.flac', '.wma', '.opus', '.pdf', '.epub')
$NeverSets = @('N00237')
if (-not $ReportDir) { $ReportDir = [Environment]::GetFolderPath('Desktop') }
if (-not $MarksCsv) { $MarksCsv = Join-Path ([Environment]::GetFolderPath('Desktop')) 'Duplicate analysis r3 library 2026-10-22 1628.csv' }

$script:Lib = $Library.TrimEnd('\'); $script:BRoot = $BackupRoot; $script:QRoot = $QuarantineRoot; $script:Cache = $CacheFile
$script:R3 = $R3Cache; $script:Marks = $MarksCsv; $script:Report = $ReportDir; $script:Table = 'content_meta'; $script:Sim = $false
$script:Cats = @($VerifyCategories); $script:ExNear = $ExcludeNearDuplicates.IsPresent; $script:ExCross = $ExcludeCrossFolderNear.IsPresent
$script:LastUndoRefused = $false; $script:Heartbeat = Join-Path $TriggerDir 'indexer-heartbeat.txt'; $script:FailAt = $FailAt; $script:Log = ''
$script:HashStats = [pscustomobject]@{ cached = 0; hashed = 0; bytes = [int64]0; seconds = 0.0 }
$script:Chunk = 250
$script:Curl = Join-Path $env:SystemRoot 'System32\curl.exe'
if (-not (Test-Path -LiteralPath $script:Curl)) { $script:Curl = 'curl.exe' }

function Say([string]$m) { Write-Host $m; if ($script:Log) { try { [IO.File]::AppendAllText($script:Log, $m + "`r`n", $utf8) } catch { } } }
function From-JsonArray([string]$t) { if (-not $t) { return @() }; return @(($t | ConvertFrom-Json) | ForEach-Object { $_ }) }
function Check-Fail([string]$at) {
  if ($script:FailAt -and $script:FailAt -eq $at) {
    if ($at -like 'crash-*') { throw "SIMULATED CRASH at $at" }
    throw "injected test failure at $at"
  }
}
function Fmt-GB([double]$b) { return ('{0:N2} GB' -f ($b / 1GB)) }
function Sum-Size($items) { $s = [int64]0; foreach ($x in @($items)) { $s += [int64]$x.size }; return $s }
function New-OrdinalMap { return (New-Object System.Collections.Hashtable ([StringComparer]::OrdinalIgnoreCase)) }
function Get-BookFolder([string]$rel) { if (-not $rel) { return '' }; $sg = $rel.Split('\'); if ($sg.Count -lt 3) { return '' }; if (@('audiobooks', 'music') -notcontains $sg[0].ToLowerInvariant()) { return '' }; return ($sg[0] + '\' + $sg[1]) }
function Get-Top([string]$rel) { $i = $rel.IndexOf('\'); if ($i -lt 0) { return '(library root)' }; return $rel.Substring(0, $i) }
function To-FilePath([string]$rel) { return ('content/' + $rel.Replace('\', '/')) }
function ConvertTo-RelKey([string]$fp) {
  if (-not $fp) { return '' }
  $k = $fp.Replace('\', '/').TrimStart('/')
  if ($k.ToLowerInvariant().StartsWith('content/')) { $k = $k.Substring(8) } else { return '' }
  return $k.Replace('/', '\').ToLowerInvariant()
}

# ---------------------------------------------------------------- DB helper (runs inside the app container; input/output base64 over stdin/stdout)
$HelperPhp = @'
<?php
if (PHP_SAPI !== 'cli') { exit(1); }
function hd_out($a) {
    $j = json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($j === false) { $j = json_encode(['state' => 'error', 'message' => 'json_encode failed: ' . json_last_error_msg()]); }
    echo "\nHDR:" . base64_encode($j) . "\n"; exit(0);
}
function hd_rows($rows) {
    $rows = array_values($rows); $j = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($j === false) { hd_out(['state' => 'error', 'message' => 'row json_encode failed: ' . json_last_error_msg()]); }
    return ['state' => 'ok', 'count' => count($rows), 'rows_b64' => base64_encode($j)];
}
try {
    $in = json_decode(base64_decode('__HD_INPUT__'), true);
    if (!is_array($in)) { hd_out(['state' => 'error', 'message' => 'bad input']); }
    require_once '/var/www/html/includes/config.php';
    require_once '/var/www/html/includes/auth.php';
    $pdo = getDbConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $t = (string)($in['table'] ?? '');
    if (!preg_match('/^(content_meta|ld_selftest_[0-9a-f]{8})$/', $t)) { hd_out(['state' => 'error', 'message' => 'table not allowed']); }
    $scratch = (strpos($t, 'ld_selftest_') === 0);
    $mode = (string)($in['mode'] ?? '');

    if ($mode === 'ping') { hd_out(['state' => 'ok', 'count' => (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()]); }

    if ($mode === 'rows') {   /* read-only, streamed */
        $st = $pdo->query("SELECT id, content_id, file_path, thumbnail_path, category FROM `$t`"); $n = 0;
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            echo 'R:' . $r[0] . "\t" . base64_encode((string)$r[1]) . "\t" . base64_encode((string)$r[2]) . "\t" . base64_encode((string)$r[3]) . "\t" . base64_encode((string)$r[4]) . "\n"; $n++;
        }
        hd_out(['state' => 'ok', 'count' => $n]);
    }

    if ($mode === 'select') {   /* read-only */
        $rows = []; $s = $pdo->prepare("SELECT * FROM `$t` WHERE id = ?");
        foreach (($in['ids'] ?? []) as $id) { $s->execute([(int)$id]); $r = $s->fetch(PDO::FETCH_ASSOC); if ($r) { $rows[(int)$r['id']] = $r; } }
        ksort($rows); hd_out(hd_rows($rows));
    }

    if ($mode === 'have') {   /* read-only: which ids exist */
        $s = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE id = ?"); $have = 0; $missing = [];
        foreach (($in['ids'] ?? []) as $id) { $s->execute([(int)$id]); if ((int)$s->fetchColumn() > 0) { $have++; } else { $missing[] = (int)$id; } }
        hd_out(['state' => 'ok', 'have' => $have, 'missing' => $missing]);
    }

    if ($mode === 'catcount') {   /* read-only */
        $s = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE category = ?"); $out = [];
        foreach (($in['cats'] ?? []) as $c) { $s->execute([(string)$c]); $out[] = [(string)$c, (int)$s->fetchColumn()]; }
        hd_out(['state' => 'ok', 'counts' => $out]);
    }

    if ($mode === 'url') {   /* read-only */
        if ($scratch) { hd_out(['state' => 'ok', 'urls' => []]); }
        require_once '/var/www/html/includes/tiles.php'; $out = [];
        foreach (($in['cats'] ?? []) as $c) { $out[] = [(string)$c, 'tutorials.php?&' . tileEncrypt('course') . '=' . tileEncrypt((string)$c)]; }
        hd_out(['state' => 'ok', 'urls' => $out]);
    }

    if ($mode === 'refs') {   /* read-only: other tables that mention these content_ids / file paths */
        $refs = [];
        if (!$scratch) {
            $keys = array_values(array_unique(array_map('strval', $in['keys'] ?? [])));
            $rt = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('content_id','file_path','content_path','thumbnail_path') AND DATA_TYPE IN ('char','varchar','text','mediumtext','longtext','tinytext') AND TABLE_NAME <> 'content_meta' AND TABLE_NAME NOT LIKE '%selftest%'")->fetchAll(PDO::FETCH_NUM);
            foreach ($rt as $c) { foreach (array_chunk($keys, 200) as $ch) {
                $q = $pdo->prepare("SELECT `{$c[1]}` k, COUNT(*) n FROM `{$c[0]}` WHERE `{$c[1]}` IN (" . implode(',', array_fill(0, count($ch), '?')) . ") GROUP BY `{$c[1]}`");
                $q->execute($ch); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) { $refs[] = ['table' => $c[0] . '.' . $c[1], 'key' => $x['k'], 'n' => (int)$x['n']]; } } }
        }
        hd_out(['state' => 'ok', 'refs' => $refs]);
    }

    if ($mode === 'delete') {   /* exact id + file_path + content_id; a row already gone is reported, a changed row aborts the batch */
        $pdo->beginTransaction(); $n = 0; $gone = [];
        $d = $pdo->prepare("DELETE FROM `$t` WHERE id = ? AND file_path <=> ? AND content_id <=> ?");
        $e = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE id = ?");
        foreach ($in['items'] as $it) {
            $d->execute([(int)$it['id'], $it['file_path'], $it['content_id']]);
            if ($d->rowCount() === 1) { $n++; continue; }
            $e->execute([(int)$it['id']]);
            if ((int)$e->fetchColumn() > 0) { $pdo->rollBack(); hd_out(['state' => 'error', 'message' => 'row ' . $it['id'] . ' no longer has the backed-up path/content_id; nothing in this batch deleted']); }
            $gone[] = (int)$it['id'];
        }
        $pdo->commit(); hd_out(['state' => 'ok', 'deleted' => $n, 'gone' => $gone]);
    }

    if ($mode === 'restore') {   /* exact backed-up rows, all or nothing per batch, idempotent */
        $rows = json_decode(base64_decode((string)$in['rows_b64']), true);
        if (!is_array($rows)) { hd_out(['state' => 'error', 'message' => 'bad backup rows']); }
        $pdo->beginTransaction(); $ins = 0; $same = 0;
        $byId = $pdo->prepare("SELECT * FROM `$t` WHERE id = ?");
        $byPath = $pdo->prepare("SELECT id FROM `$t` WHERE file_path = ? AND id <> ?");
        foreach ($rows as $r) {
            foreach (array_keys($r) as $k) { if (!preg_match('/^[a-z_0-9]+$/', $k)) { $pdo->rollBack(); hd_out(['state' => 'error', 'message' => 'bad column in backup']); } }
            $byId->execute([(int)$r['id']]); $ex = $byId->fetch(PDO::FETCH_ASSOC);
            if ($ex) {
                if ($ex['file_path'] === $r['file_path'] && $ex['content_id'] === $r['content_id']) { $same++; continue; }
                $pdo->rollBack(); hd_out(['state' => 'error', 'message' => 'id ' . $r['id'] . ' is now used by another row; nothing in this batch restored']);
            }
            $byPath->execute([$r['file_path'], (int)$r['id']]); $o = $byPath->fetchColumn();
            if ($o !== false) { $pdo->rollBack(); hd_out(['state' => 'error', 'message' => 'the path of row ' . $r['id'] . ' is now in row ' . $o . '; nothing in this batch restored']); }
            $k = array_keys($r);
            $pdo->prepare("INSERT INTO `$t` (`" . implode('`,`', $k) . "`) VALUES (" . implode(',', array_fill(0, count($k), '?')) . ")")->execute(array_values($r));
            $ins++;
        }
        $pdo->commit(); hd_out(['state' => 'ok', 'inserted' => $ins, 'already' => $same]);
    }

    if ($mode === 'compare') {   /* read-only: backed-up rows vs the table now */
        $rows = json_decode(base64_decode((string)$in['rows_b64']), true);
        if (!is_array($rows)) { hd_out(['state' => 'error', 'message' => 'bad backup rows']); }
        $g = $pdo->prepare("SELECT * FROM `$t` WHERE id = ?");
        $same = 0; $changed = []; $missing = 0;
        foreach ($rows as $r) {
            $id = (int)$r['id']; $g->execute([$id]); $c = $g->fetch(PDO::FETCH_ASSOC);
            if (!$c) { $missing++; continue; }
            $diff = []; foreach ($r as $k => $v) { if (!array_key_exists($k, $c) || $c[$k] !== $v) { $diff[] = $k; } }
            if ($diff) { $changed[] = $id . ':' . implode('+', $diff); } else { $same++; }
        }
        hd_out(['state' => 'ok', 'rows' => count($rows), 'same' => $same, 'changed' => $changed, 'missing' => $missing]);
    }

    /* ---- scratch-table modes for -SelfTest only ---- */
    if (!$scratch) { hd_out(['state' => 'error', 'message' => "mode $mode is only allowed on a scratch table"]); }
    if ($mode === 'create') { $pdo->exec("CREATE TABLE `$t` LIKE content_meta"); hd_out(['state' => 'ok']); }
    if ($mode === 'seed') {
        $s = $pdo->prepare("INSERT INTO `$t` (id, content_id, file_path, title, category, subcategory, content_type, thumbnail_path, source) VALUES (?,?,?,?,?,?,?,?,'selftest')");
        $n = 0; foreach ($in['items'] as $it) { $s->execute([(int)$it['id'], (string)$it['file_path'], (string)$it['file_path'], (string)$it['title'], (string)$it['category'], '', 'video', ((string)$it['thumb'] === '' ? null : (string)$it['thumb'])]); $n++; }
        hd_out(['state' => 'ok', 'count' => $n]);
    }
    if ($mode === 'dump') { hd_out(hd_rows($pdo->query("SELECT * FROM `$t` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC))); }
    if ($mode === 'drop') { $pdo->exec("DROP TABLE IF EXISTS `$t`"); hd_out(['state' => 'ok']); }
    hd_out(['state' => 'error', 'message' => 'unknown mode']);
} catch (Throwable $e) {
    try { if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable $e2) { }
    hd_out(['state' => 'error', 'message' => get_class($e) . ': ' . $e->getMessage()]);
}
'@

# ---------------------------------------------------------------- docker / helper
function Resolve-Docker {
  if ($DockerExe) { return $DockerExe }
  $p = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe'
  if (Test-Path -LiteralPath $p) { return $p }
  return 'docker'
}
$script:Docker = Resolve-Docker
$script:DockerArgs = @(); if ($DockerContext) { $script:DockerArgs = @('--context', $DockerContext) }
function Invoke-HelperRaw([hashtable]$in) {
  $in['table'] = $script:Table
  $json = $in | ConvertTo-Json -Depth 8 -Compress
  $code = $HelperPhp.Replace('__HD_INPUT__', [Convert]::ToBase64String($utf8.GetBytes($json)))
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @($code | & $script:Docker @script:DockerArgs exec -i $Container php 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
  $line = @($o | Where-Object { $_.StartsWith('HDR:') }) | Select-Object -Last 1
  if (-not $line) { throw ("DB helper ($($in.mode)) gave no result: " + ((@($o) | Select-Object -Last 4) -join ' / ')) }
  $res = $utf8.GetString([Convert]::FromBase64String($line.Substring(4).Trim())) | ConvertFrom-Json
  if ($res.state -ne 'ok') { throw ("DB helper ($($in.mode)): " + $res.message) }
  return [pscustomobject]@{ res = $res; lines = $o }
}
function Invoke-Helper([hashtable]$in) { return (Invoke-HelperRaw $in).res }
function Get-RowsText($res) { return $utf8.GetString([Convert]::FromBase64String($res.rows_b64)) }
function Split-Chunks($list, [int]$size) {
  $all = @($list); $out = New-Object System.Collections.ArrayList
  for ($i = 0; $i -lt $all.Count; $i += $size) { $n = [Math]::Min($size, $all.Count - $i); [void]$out.Add(@($all[$i..($i + $n - 1)])) }
  return ,$out
}
function Get-CatalogRows {
  $r = Invoke-HelperRaw @{ mode = 'rows' }
  $rows = New-Object System.Collections.Generic.List[object]
  foreach ($l in $r.lines) {
    if (-not $l.StartsWith('R:')) { continue }
    $p = $l.Substring(2).Split("`t")
    if ($p.Count -lt 5) { continue }
    $rows.Add([pscustomobject]@{ id = [int64]$p[0]; content_id = $utf8.GetString([Convert]::FromBase64String($p[1])); file_path = $utf8.GetString([Convert]::FromBase64String($p[2]))
        thumb = $utf8.GetString([Convert]::FromBase64String($p[3])); category = $utf8.GetString([Convert]::FromBase64String($p[4])) })
  }
  if ($rows.Count -ne [int]$r.res.count) { throw "catalog read incomplete: got $($rows.Count) of $($r.res.count) rows" }
  return ,$rows
}
function Get-CatCounts([string[]]$cats) {
  $m = @{}; if (@($cats).Count -eq 0) { return $m }
  $r = Invoke-Helper @{ mode = 'catcount'; cats = @($cats) }
  foreach ($x in @($r.counts)) { $m[[string]$x[0]] = [int]$x[1] }
  return $m
}
function Get-HaveCount($ids) {
  $have = 0; $missing = @()
  foreach ($ch in (Split-Chunks $ids 500)) { $r = Invoke-Helper @{ mode = 'have'; ids = @($ch) }; $have += [int]$r.have; $missing += @($r.missing) }
  return [pscustomobject]@{ have = $have; missing = $missing }
}

# ---------------------------------------------------------------- heartbeat
function Get-HeartbeatProblem {
  if (-not (Test-Path -LiteralPath $script:Heartbeat)) { return "no indexer heartbeat file ($script:Heartbeat)" }
  $age = ((Get-Date) - (Get-Item -LiteralPath $script:Heartbeat).LastWriteTime).TotalSeconds
  if ($age -gt $HeartbeatMaxAgeSec) { return ("indexer heartbeat is " + [int]$age + " s old (limit $HeartbeatMaxAgeSec s); the partial indexer does not seem to be running") }
  return ''
}

# ---------------------------------------------------------------- HTTP (read-only GETs)
function Invoke-Curl([string[]]$a) {
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @(& $script:Curl @a 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
  return (@($o) -join "`n").Trim()
}
$script:Jar = ''
function Get-Jar {
  if (-not $script:Jar) {
    $script:Jar = Join-Path $env:TEMP ('ld-jar-' + [guid]::NewGuid().ToString('N') + '.txt')
    [void](Invoke-Curl @('-s', '-g', '-o', 'NUL', '-c', $script:Jar, '-b', $script:Jar, "$BaseUrl/guest.php"))
  }
  return $script:Jar
}
function Get-Page([string]$rel) {
  $jar = Get-Jar
  $f = Join-Path $env:TEMP ('ld-page-' + [guid]::NewGuid().ToString('N') + '.html')
  $code = Invoke-Curl @('-s', '-g', '-b', $jar, '-c', $jar, '-o', $f, '-w', '%{http_code}', "$BaseUrl/$rel")
  $body = ''; if (Test-Path -LiteralPath $f) { $body = [IO.File]::ReadAllText($f, $utf8); Remove-Item -LiteralPath $f -Force }
  return [pscustomobject]@{ code = $code; body = $body }
}
function Encode-Path([string]$p) { return ((@($p -split '/') | ForEach-Object { [Uri]::EscapeDataString($_) }) -join '/') }
function Test-Media([string]$filePath) {
  # filePath like content/Cat/Sub/name.mp4 ; ranged GET of the first 1 KB -> 206/200 and not an HTML page
  if ($script:Sim) {
    $k = ConvertTo-RelKey $filePath
    if ($k -and (Test-Path -LiteralPath (Join-Path $script:Lib $k))) { return [pscustomobject]@{ ok = $true; code = '206'; info = '206|video/mp4 (simulated)' } }
    return [pscustomobject]@{ ok = $false; code = '404'; info = '404|text/html (simulated)' }
  }
  $jar = Get-Jar
  $r = Invoke-Curl @('-s', '-g', '-b', $jar, '-o', 'NUL', '-r', '0-1023', '-w', '%{http_code}|%{content_type}', ("$BaseUrl/" + (Encode-Path $filePath)))
  $code = ($r -split '\|')[0]
  $ok = ($r -match '^(206|200)\|' -and $r -notmatch '\|text/html')
  return [pscustomobject]@{ ok = $ok; code = $code; info = $r }
}
function Test-MediaUrl([string]$src) {
  # src exactly as the watch page gives it (relative or absolute)
  if ($src -notmatch '^https?://') { $src = "$BaseUrl/" + $src.TrimStart('/') }
  $jar = Get-Jar
  $r = Invoke-Curl @('-s', '-g', '-b', $jar, '-o', 'NUL', '-r', '0-1023', '-w', '%{http_code}|%{content_type}', $src)
  return [pscustomobject]@{ ok = ($r -match '^(206|200)\|' -and $r -notmatch '\|text/html'); code = ($r -split '\|')[0]; info = $r }
}
function Get-CatPage([string]$url) {
  $pg = Get-Page $url
  $sum = 0; $links = @()
  if ($pg.code -eq '200') {
    foreach ($m in [regex]::Matches($pg.body, 'tut-section-count">(\d+)\s')) { $sum += [int]$m.Groups[1].Value }
    $links = @([regex]::Matches($pg.body, 'href="(watch\.php[^"]*)"') | ForEach-Object { [Net.WebUtility]::HtmlDecode($_.Groups[1].Value) })
  }
  return [pscustomobject]@{ code = $pg.code; sum = $sum; links = $links }
}
# r2: map a player src (/content/Cat/Sub/file.mp4, percent-encoded) to its library path
function ConvertFrom-MediaSrc([string]$src) {
  $p = $src -replace '^https?://[^/]+', ''
  $q = $p.IndexOf('?'); if ($q -ge 0) { $p = $p.Substring(0, $q) }
  $p = [Uri]::UnescapeDataString($p).TrimStart('/')
  if (-not $p.ToLowerInvariant().StartsWith('content/')) { return '' }
  return (Join-Path $script:Lib ($p.Substring(8).Replace('/', '\')))
}
# r2: was this library path (or a folder above it) moved by this run?
function Test-InMoved([string]$path, $movedSet) {
  if (-not $path -or $null -eq $movedSet) { return $false }
  $x = $path.TrimEnd('\')
  while ($x -and $x.Length -gt $script:Lib.Length) { if ($movedSet.Contains($x)) { return $true }; $x = Split-Path -Parent $x }
  return $false
}
# r2: sample up to $n playable items from the category page (checks up to 10 links, spread over the page).
# A failing sample is a real problem when the file it points at was moved by this run, or the file is still in the
# library but is not served. A sample whose file was already absent before this run (not in the manifest) is a
# pre-existing dead catalog row (held missing-file cleanup): reported as a note, not a failure.
function Test-WatchSamples($links, [int]$n, $movedSet) {
  $out = @()
  $list = @($links); if ($list.Count -eq 0) { return $out }
  $max = [Math]::Min(10, $list.Count)
  $idx = @(for ($k = 0; $k -lt $max; $k++) { [int][Math]::Floor($k * $list.Count / $max) }) | Select-Object -Unique
  $good = 0
  foreach ($i in $idx) {
    if ($good -ge $n) { break }
    $w = Get-Page $list[$i]
    $m = [regex]::Match($w.body, 'id="wp-video"\s+src="([^"]+)"')
    if ($w.code -ne '200' -or -not $m.Success) { $out += [pscustomobject]@{ link = $list[$i]; ok = $false; pre = $false; info = "watch page $($w.code), no video tag" }; continue }
    $src = [Net.WebUtility]::HtmlDecode($m.Groups[1].Value)
    $t = Test-MediaUrl $src
    if ($t.ok) { $good++; $out += [pscustomobject]@{ link = $list[$i]; ok = $true; pre = $false; info = $t.info }; continue }
    $lp = ConvertFrom-MediaSrc $src
    if (Test-InMoved $lp $movedSet) { $out += [pscustomobject]@{ link = $list[$i]; ok = $false; pre = $false; info = "$($t.info); page still links a file moved by this run: $lp" }; continue }
    if ($lp -and -not [IO.File]::Exists($lp)) { $out += [pscustomobject]@{ link = $list[$i]; ok = $true; pre = $true; info = "$($t.info); file was already missing before this run (not in manifest): $lp" }; continue }
    $out += [pscustomobject]@{ link = $list[$i]; ok = $false; pre = $false; info = "$($t.info); file exists but is not served: $src" }
  }
  return $out
}
# category state = DB row count + page section-count sum (they are equal on a healthy site)
function Get-CatState([string[]]$cats) {
  $db = Get-CatCounts $cats
  $urls = @{}
  if (-not $script:Sim) { foreach ($x in @((Invoke-Helper @{ mode = 'url'; cats = @($cats) }).urls)) { $urls[[string]$x[0]] = [string]$x[1] } }
  $out = @()
  foreach ($c in $cats) {
    $o = [pscustomobject]@{ cat = $c; db = [int]$db[$c]; code = 'skipped'; page = -1; url = ''; links = @() }
    if ($urls.ContainsKey($c)) { $pg = Get-CatPage $urls[$c]; $o.code = $pg.code; $o.page = $pg.sum; $o.url = $urls[$c]; $o.links = $pg.links }
    $out += $o
  }
  return $out
}

# ---------------------------------------------------------------- SHA256: saved hashes (r3b cache + own cache) or parallel hashing
$HashScript = {
  param([string]$path)
  try {
    $sha = New-Object System.Security.Cryptography.SHA256CryptoServiceProvider
    $fs = New-Object System.IO.FileStream($path, [IO.FileMode]::Open, [IO.FileAccess]::Read, ([IO.FileShare]::ReadWrite -bor [IO.FileShare]::Delete), 4194304, [IO.FileOptions]::SequentialScan)
    try { $h = $sha.ComputeHash($fs) } finally { $fs.Dispose(); $sha.Dispose() }
    return ([BitConverter]::ToString($h) -replace '-', '')
  } catch { return ('ERROR: ' + $_.Exception.Message) }
}
$script:R3Map = $null; $script:OwnMap = $null
function Load-HashCaches {
  $script:R3Map = New-Object System.Collections.Hashtable ([StringComparer]::Ordinal)
  $script:OwnMap = New-OrdinalMap
  if (Test-Path -LiteralPath $script:R3) {
    $sr = New-Object IO.StreamReader($script:R3, $utf8)
    try { while ($null -ne ($l = $sr.ReadLine())) { if ($l.StartsWith("F`t")) { $p = $l.Split("`t"); if ($p.Count -ge 3) { $script:R3Map[$p[1]] = $p[2].ToUpperInvariant() } } } } finally { $sr.Dispose() }
  }
  if (Test-Path -LiteralPath $script:Cache) {
    foreach ($l in [IO.File]::ReadAllLines($script:Cache, $utf8)) {
      $p = $l -split "`t", 5
      if ($p.Count -lt 5 -or $p[0] -notmatch '^[0-9A-F]{64}$') { continue }
      $script:OwnMap[$p[4]] = [pscustomobject]@{ sha = $p[0]; size = [int64]$p[1]; mtime = [int64]$p[2] }
    }
  }
  Say ("  saved hashes: " + $script:R3Map.Count + " from the r3b scan cache, " + $script:OwnMap.Count + " from $script:Cache")
}
function Get-SavedHash($fi, [string]$rel) {
  $k = $rel.ToLowerInvariant() + '|' + $fi.Length + '|' + $fi.LastWriteTimeUtc.Ticks
  if ($script:R3Map.ContainsKey($k)) { return $script:R3Map[$k] }
  $o = $script:OwnMap[$fi.FullName]
  if ($o -and $o.size -eq $fi.Length -and $o.mtime -eq $fi.LastWriteTimeUtc.Ticks) { return $o.sha }
  return ''
}
function Get-Hashes($files) {
  # $files: FileInfo objects (unique). returns hashtable FullName -> SHA256 (or 'ERROR: ...')
  $res = New-OrdinalMap
  $todo = @($files)
  $total = [int64]0; foreach ($f in $todo) { $total += $f.Length }
  $script:HashStats.hashed = $todo.Count; $script:HashStats.bytes = $total
  Say ("  SHA256 to compute: " + $todo.Count + " file(s), " + (Fmt-GB $total) + " ($Threads threads)")
  if ($todo.Count -eq 0) { return $res }
  $cd = Split-Path -Parent $script:Cache; if (-not (Test-Path -LiteralPath $cd)) { [void](New-Item -ItemType Directory -Path $cd -Force) }
  $pool = [runspacefactory]::CreateRunspacePool(1, [Math]::Max(1, $Threads)); $pool.Open()
  $sw = [Diagnostics.Stopwatch]::StartNew(); $done = [int64]0; $n = 0; $lastSay = 0
  $queue = New-Object System.Collections.Queue; foreach ($f in $todo) { $queue.Enqueue($f) }
  $inflight = New-Object System.Collections.ArrayList
  try {
    while ($queue.Count -gt 0 -or $inflight.Count -gt 0) {
      while ($queue.Count -gt 0 -and $inflight.Count -lt [Math]::Max(1, $Threads)) {
        $f = $queue.Dequeue(); $ps = [powershell]::Create(); $ps.RunspacePool = $pool
        [void]$ps.AddScript($HashScript.ToString()).AddArgument($f.FullName)
        [void]$inflight.Add([pscustomobject]@{ ps = $ps; h = $ps.BeginInvoke(); f = $f })
      }
      Start-Sleep -Milliseconds 100
      foreach ($j in @($inflight | Where-Object { $_.h.IsCompleted })) {
        $o = $j.ps.EndInvoke($j.h); $j.ps.Dispose(); $inflight.Remove($j)
        $h = ''; if ($o.Count -gt 0) { $h = [string]$o[0] }
        if (-not $h) { $h = 'ERROR: no result' }
        $res[$j.f.FullName] = $h; $done += $j.f.Length; $n++
        if ($h -match '^[0-9A-F]{64}$') {
          [IO.File]::AppendAllText($script:Cache, ($h + "`t" + $j.f.Length + "`t" + $j.f.LastWriteTimeUtc.Ticks + "`t" + (Get-Date).ToUniversalTime().Ticks + "`t" + $j.f.FullName + "`r`n"), $utf8)
        }
        if ($n % 500 -eq 0) { Say ("    hashed $n / $($todo.Count) files, " + (Fmt-GB $done) + ' of ' + (Fmt-GB $total)) }
      }
      $sec = $sw.Elapsed.TotalSeconds
      if ($sec - $lastSay -ge 30) {
        $lastSay = $sec; $rate = 0; if ($sec -gt 0) { $rate = $done / 1MB / $sec }
        $eta = ''; if ($rate -gt 0) { $eta = ', about ' + [int](($total - $done) / 1MB / $rate / 60) + ' min left' }
        Say ("    hashed $n / $($todo.Count) files, " + (Fmt-GB $done) + ' of ' + (Fmt-GB $total) + (' ({0:N0} MB/s' -f $rate) + $eta + ')')
      }
    }
  } finally {
    foreach ($j in @($inflight)) { try { $j.ps.Stop(); $j.ps.Dispose() } catch { } }
    $pool.Close(); $pool.Dispose()
  }
  $script:HashStats.seconds = [Math]::Round($sw.Elapsed.TotalSeconds, 1)
  Say ("  SHA256 done: $n file(s), " + (Fmt-GB $total) + " in " + $script:HashStats.seconds + ' s')
  return $res
}

# ---------------------------------------------------------------- plan (read-only)
function Get-List($map, [string]$k) { if ($k -and $map.ContainsKey($k)) { return $map[$k].ToArray() }; return @() }
function New-PlanItem([string]$kind, [string]$set, [string]$rel) {
  return [pscustomobject]@{ kind = $kind; set = $set; rel = $rel; full = (Join-Path $script:Lib $rel); size = [int64]0; mtime = [int64]0
    sha = ''; csv_sha = ''; csv_mb = [double]0; keeper = ''; keeper_sha = ''; keeper_rows = ''; partner = ''; rows = @(); row_ids = ''; csv_ids = ''
    decision = ''; reason = ''; check = ''; cat = (Get-Top $rel); folder = ''; cross = $false; fi = $null; kfi = $null }
}
function Set-D($it, [string]$d, [string]$why) { $it.decision = $d; $it.reason = $why }
function Get-Scope([string]$kind) {
  switch ($kind) { 'exact' { return 'exact' } 'cover' { return 'exact' } 'near' { return 'near' } 'near-cover' { return 'near' } default { return 'exe-com' } }
}
function Get-Plan([bool]$rehash) {
  $P = [pscustomobject]@{ items = (New-Object System.Collections.Generic.List[object]); folders = @(); notes = (New-Object System.Collections.Generic.List[string])
    problems = (New-Object System.Collections.Generic.List[string]); rows = @(); refs = @(); catDelta = @{}; keeperRowIds = @(); keepSamples = @(); goneSamples = @(); libFiles = 0 }
  if (-not (Test-Path -LiteralPath $script:Marks)) { $P.problems.Add("marks CSV not found: $script:Marks"); return $P }
  if (-not (Test-Path -LiteralPath $script:Lib)) { $P.problems.Add("library not found: $script:Lib"); return $P }
  $scanRef = (Get-Item -LiteralPath $script:Marks).LastWriteTimeUtc.Ticks
  $prefix = $script:Lib + '\'
  $MB = [double]1048576
  Say "  reading $script:Marks"
  $marks = @(Import-Csv -LiteralPath $script:Marks -Encoding UTF8)
  foreach ($c in @('SetId', 'Action', 'Reason', 'Path', 'SizeMB', 'SHA256', 'CatalogId(s)')) {
    if ($marks.Count -eq 0 -or -not ($marks[0].PSObject.Properties.Name -contains $c)) { $P.problems.Add("marks CSV has no column '$c'"); return $P }
  }
  Say ("  " + $marks.Count + " analysis rows")

  Say "  listing $script:Lib ..."
  $files = New-OrdinalMap; $byDir = New-OrdinalMap; $allRel = New-Object System.Collections.Generic.List[string]
  $di = New-Object IO.DirectoryInfo $script:Lib
  foreach ($f in $di.EnumerateFiles('*', [IO.SearchOption]::AllDirectories)) {
    $rel = $f.FullName.Substring($prefix.Length)
    $files[$rel] = $f; $allRel.Add($rel)
    $d = [IO.Path]::GetDirectoryName($rel)
    if (-not $byDir.ContainsKey($d)) { $byDir[$d] = New-Object System.Collections.Generic.List[object] }
    $byDir[$d].Add($f)
  }
  $P.libFiles = $allRel.Count
  Say ("  " + $allRel.Count + " files in the library")

  Say "  reading the catalog (read-only) ..."
  $rows = Get-CatalogRows
  $rowsBy = New-OrdinalMap; $thumbBy = New-OrdinalMap
  foreach ($r in $rows) {
    $k = ConvertTo-RelKey $r.file_path
    if ($k) { if (-not $rowsBy.ContainsKey($k)) { $rowsBy[$k] = New-Object System.Collections.Generic.List[object] }; $rowsBy[$k].Add($r) }
    $t = ConvertTo-RelKey $r.thumb
    if ($t) { if (-not $thumbBy.ContainsKey($t)) { $thumbBy[$t] = New-Object System.Collections.Generic.List[object] }; $thumbBy[$t].Add($r) }
  }
  Say ("  " + $rows.Count + " catalog rows")

  # roles from the analysis
  $protect = New-OrdinalMap; $keepOf = @{}; $keepMb = New-OrdinalMap; $inMarks = New-OrdinalMap
  foreach ($m in $marks) {
    if (-not $m.Path.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) { $P.problems.Add("path outside the library in the marks CSV: $($m.Path)"); continue }
    $rel = $m.Path.Substring($prefix.Length); $inMarks[$rel] = $true
    if ($m.Action -eq 'KEEP' -or $m.Action -eq 'REVIEW') { $protect[$rel] = ($m.SetId + ' ' + $m.Action) }
    if ($m.Action -eq 'KEEP') { $keepMb[$rel] = [double]$m.SizeMB; if ($m.SetId.StartsWith('N')) { $keepOf[$m.SetId] = $rel } }
  }
  if ($P.problems.Count) { return $P }
  $exe = New-OrdinalMap
  foreach ($rel in $allRel) { $e = [IO.Path]::GetExtension($rel).ToLowerInvariant(); if ($e -eq '.exe' -or $e -eq '.com') { $exe[$rel] = $true } }

  # candidates
  $rx = New-Object regex '^duplicate of (.+) \(([^()]*)\)(; .*)?$'
  $items = $P.items
  foreach ($m in $marks) {
    $rel = $m.Path.Substring($prefix.Length)
    if ($exe.ContainsKey($rel)) { continue }
    $act = [string]$m.Action; $set = [string]$m.SetId; $kind = ''
    if ($act -eq 'QUARANTINE') { if ($m.SHA256) { $kind = 'exact' } else { $kind = 'cover' } }
    elseif ($act -eq 'REMOVE-LARGER') { $kind = 'near' }
    elseif ($act -eq 'REVIEW') { if ($set.StartsWith('N')) { $kind = 'near' } else { $kind = 'exact' } }
    else { continue }
    $it = New-PlanItem $kind $set $rel
    $it.csv_sha = ([string]$m.SHA256).ToUpperInvariant(); $it.csv_ids = [string]$m.'CatalogId(s)'; $it.csv_mb = [double]$m.SizeMB
    if ($act -eq 'REVIEW') { Set-D $it 'EXCLUDED' 'REVIEW row of the analysis: never moved' }
    elseif ($NeverSets -contains $set) { Set-D $it 'EXCLUDED' "set $set is never moved" }
    elseif ($kind -eq 'near' -and $script:ExNear) { Set-D $it 'EXCLUDED' 'near-duplicate left out (-ExcludeNearDuplicates)' }
    if ($kind -eq 'exact' -and $act -eq 'QUARANTINE') {
      $mm = $rx.Match([string]$m.Reason)
      if ($mm.Success) { $it.keeper = $mm.Groups[1].Value } elseif (-not $it.decision) { Set-D $it 'SKIP' 'keeper not readable from the analysis reason' }
    }
    if ($kind -eq 'cover') { $it.partner = ([string]$m.Reason) -replace '^cover/thumbnail of quarantined ', '' }
    if ($kind -eq 'near' -and $act -eq 'REMOVE-LARGER') {
      if ($keepOf.ContainsKey($set)) { $it.keeper = $keepOf[$set] } elseif (-not $it.decision) { Set-D $it 'SKIP' 'no KEEP row in this near-duplicate set' }
    }
    $items.Add($it)
  }
  foreach ($rel in @($exe.Keys | Sort-Object)) { $items.Add((New-PlanItem 'exe-com' '' $rel)) }

  # re-verify on disk
  Load-HashCaches
  $need = New-OrdinalMap; $i = 0
  foreach ($it in $items) {
    $i++; if ($i % 500 -eq 0) { Say "  checked $i / $($items.Count) candidates on disk" }
    if ($it.decision) { continue }
    $fi = $files[$it.rel]
    if (-not $fi) { Set-D $it 'SKIP' 'file no longer exists'; continue }
    $it.fi = $fi; $it.full = $fi.FullName; $it.size = [int64]$fi.Length; $it.mtime = [int64]$fi.LastWriteTimeUtc.Ticks
    if ($it.kind -ne 'exe-com' -and $protect.ContainsKey($it.rel)) { Set-D $it 'SKIP' ("also a keeper/review file (" + $protect[$it.rel] + ")"); continue }
    if ($it.kind -eq 'exe-com') { $sv = ''; if (-not $rehash) { $sv = Get-SavedHash $fi $it.rel }; if ($sv) { $it.sha = $sv; $it.check = 'saved hash, same size+mtime' } else { $need[$fi.FullName] = $fi }; continue }
    if ($it.kind -eq 'cover') { if ($it.mtime -gt $scanRef) { Set-D $it 'SKIP' 'changed after the analysis' }; continue }
    $kfi = $files[$it.keeper]
    if (-not $kfi) { Set-D $it 'SKIP' "keeper no longer exists: $($it.keeper)"; continue }
    $it.kfi = $kfi
    if ($it.kind -eq 'exact') {
      if ($rehash) { $need[$fi.FullName] = $fi; $need[$kfi.FullName] = $kfi; continue }
      $s = Get-SavedHash $fi $it.rel; if ($s) { $it.sha = $s; $it.check = 'saved hash, same size+mtime' } else { $need[$fi.FullName] = $fi }
      $s = Get-SavedHash $kfi $it.keeper; if ($s) { $it.keeper_sha = $s } else { $need[$kfi.FullName] = $kfi }
    }
    if ($it.kind -eq 'near') {
      if ([Math]::Abs($it.size - $it.csv_mb * $MB) -gt (0.006 * $MB)) { Set-D $it 'SKIP' 'size differs from the analysis'; continue }
      if ($it.mtime -gt $scanRef) { Set-D $it 'SKIP' 'changed after the analysis'; continue }
      if ($kfi.LastWriteTimeUtc.Ticks -gt $scanRef) { Set-D $it 'SKIP' 'kept smaller copy changed after the analysis'; continue }
      if ($keepMb.ContainsKey($it.keeper) -and [Math]::Abs($kfi.Length - $keepMb[$it.keeper] * $MB) -gt (0.006 * $MB)) { Set-D $it 'SKIP' 'kept smaller copy size differs from the analysis'; continue }
      $it.check = 'size + mtime unchanged since the analysis'
    }
  }
  $h = Get-Hashes @($need.Values)
  foreach ($it in $items) {
    if ($it.decision) { continue }
    if ($it.kind -eq 'exe-com') { if (-not $it.sha) { $it.sha = [string]$h[$it.full]; $it.check = 'hashed now' }; if ($it.sha -notmatch '^[0-9A-F]{64}$') { Set-D $it 'SKIP' "could not hash: $($it.sha)" }; continue }
    if ($it.kind -ne 'exact') { continue }
    if (-not $it.sha) { $it.sha = [string]$h[$it.full]; $it.check = 'hashed now' }
    if (-not $it.keeper_sha) { $it.keeper_sha = [string]$h[$it.kfi.FullName] }
    if ($it.sha -ne $it.csv_sha) { Set-D $it 'SKIP' "SHA256 differs from the analysis ($($it.sha))"; continue }
    if ($it.keeper_sha -ne $it.csv_sha) { Set-D $it 'SKIP' "keeper SHA256 differs ($($it.keeper_sha))"; continue }
  }

  # catalog guards
  foreach ($it in $items) {
    if ($it.decision) { continue }
    $sr = @(Get-List $rowsBy $it.rel)
    if ($it.kind -eq 'exact' -or $it.kind -eq 'near') {
      $kr = @(Get-List $rowsBy $it.keeper)
      $it.keeper_rows = (@($kr | ForEach-Object { $_.id }) -join ' ')
      if ($sr.Count -gt 0 -and $kr.Count -eq 0) { Set-D $it 'SKIP' ("has live row " + (@($sr | ForEach-Object { $_.id }) -join ' ') + " but the keeper has none"); continue }
      $it.cross = ((Get-Top $it.keeper) -ne $it.cat)
      # r4: a copy inside an Audiobooks/Music book folder stays when the other copy is in a different folder
      $bfS = Get-BookFolder $it.rel; $bfK = Get-BookFolder $it.keeper
      if ($bfS -and ($bfS -ne $bfK)) { Set-D $it 'REPORT' ("r4: copy inside book folder " + $bfS + " stays; other copy is elsewhere: " + $it.keeper); continue }
      if ($it.kind -eq 'exact' -and $sr.Count -gt 0 -and $it.cross) { Set-D $it 'REPORT' ("live row, keeper is in another top-level folder: " + $it.keeper); continue }
      if ($it.kind -eq 'near' -and $it.cross -and $script:ExCross) { Set-D $it 'REPORT' ("near-duplicate kept in another top-level folder (-ExcludeCrossFolderNear): " + $it.keeper); continue }
    }
    Set-D $it 'MOVE' ''
  }

  # covers of moving near-duplicates (same folder, same name stem, image)
  $extra = @()
  foreach ($it in $items) {
    if ($it.kind -ne 'near' -or $it.decision -ne 'MOVE') { continue }
    $d = [IO.Path]::GetDirectoryName($it.rel); $stem = [IO.Path]::GetFileNameWithoutExtension($it.rel)
    $sib = @(Get-List $byDir $d)
    $shared = @($sib | Where-Object { $_.FullName -ne $it.full -and ($MediaExt -contains $_.Extension.ToLowerInvariant()) -and [IO.Path]::GetFileNameWithoutExtension($_.Name) -eq $stem })
    if ($shared.Count) { continue }
    foreach ($f in @($sib | Where-Object { ($ImageExt -contains $_.Extension.ToLowerInvariant()) -and [IO.Path]::GetFileNameWithoutExtension($_.Name) -eq $stem })) {
      $crel = $f.FullName.Substring($prefix.Length)
      if ($inMarks.ContainsKey($crel) -or $exe.ContainsKey($crel)) { continue }
      $c = New-PlanItem 'near-cover' $it.set $crel
      $c.fi = $f; $c.full = $f.FullName; $c.size = [int64]$f.Length; $c.mtime = [int64]$f.LastWriteTimeUtc.Ticks; $c.partner = $it.rel; $c.check = 'cover found next to the near-duplicate'
      Set-D $c 'MOVE' ''; $extra += $c
    }
  }
  foreach ($c in $extra) { $items.Add($c) }

  # r3: subcategory tile covers (<Category>\<Name>.jpg|jpeg|png|webp) stay: tutorials.php shows them by file name for folder/subcategory <Name>
  $subNames = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
  foreach ($r in $rows) { $k = ConvertTo-RelKey $r.file_path; if ($k) { $sg = $k.Split('\'); if ($sg.Count -ge 3) { [void]$subNames.Add($sg[0] + '\' + $sg[1]) } } }
  foreach ($it in $items) {
    if ($it.decision -ne 'MOVE') { continue }
    $sg = $it.rel.Split('\'); if ($sg.Count -ne 2) { continue }
    if (@('.jpg', '.jpeg', '.png', '.webp') -notcontains [IO.Path]::GetExtension($sg[1]).ToLowerInvariant()) { continue }
    $stem = $sg[0] + '\' + [IO.Path]::GetFileNameWithoutExtension($sg[1])
    if ($subNames.Contains($stem) -or [IO.Directory]::Exists((Join-Path $script:Lib $stem))) { Set-D $it 'SKIP' "subcategory tile cover of $stem (shown by file name)" }
  }
  # r4: never empty an Audiobooks/Music book folder: if every playable/media file in it would move, nothing in it moves
  $bfTot = New-OrdinalMap; $bfMv = New-OrdinalMap
  foreach ($rel in $allRel) { $bf = Get-BookFolder $rel; if (-not $bf) { continue }; if ($MediaExt -notcontains [IO.Path]::GetExtension($rel).ToLowerInvariant()) { continue }; $bfTot[$bf] = 1 + [int]$bfTot[$bf] }
  foreach ($it in $items) { if ($it.decision -ne 'MOVE') { continue }; $bf = Get-BookFolder $it.rel; if (-not $bf) { continue }; if ($MediaExt -notcontains [IO.Path]::GetExtension($it.rel).ToLowerInvariant()) { continue }; $bfMv[$bf] = 1 + [int]$bfMv[$bf] }
  foreach ($bf in @($bfMv.Keys)) {
    if ([int]$bfMv[$bf] -lt [int]$bfTot[$bf]) { continue }
    foreach ($it in $items) { if ($it.decision -eq 'MOVE' -and (Get-BookFolder $it.rel) -eq $bf) { Set-D $it 'REPORT' ("r4: would leave book folder " + $bf + " with no media; kept") } }
  }
  # fixed point: covers follow their item; nothing a staying row uses as thumbnail; no keeper moves
  $round = 0
  do {
    $changed = $false; $round++
    $mv = New-OrdinalMap; foreach ($it in $items) { if ($it.decision -eq 'MOVE') { $mv[$it.rel] = $it } }
    $del = @{}; foreach ($it in $mv.Values) { foreach ($r in (Get-List $rowsBy $it.rel)) { $del[[string]$r.id] = $true } }
    foreach ($it in $items) {
      if ($it.decision -ne 'MOVE') { continue }
      if (($it.kind -eq 'cover' -or $it.kind -eq 'near-cover') -and -not $mv.ContainsKey($it.partner)) { Set-D $it 'SKIP' "cover/thumbnail of an item that stays: $($it.partner)"; $changed = $true; continue }
      if ($it.keeper -and $mv.ContainsKey($it.keeper)) { Set-D $it 'SKIP' "its keeper would move too: $($it.keeper)"; $changed = $true; continue }
      foreach ($r in (Get-List $thumbBy $it.rel)) { if (-not $del.ContainsKey([string]$r.id)) { Set-D $it 'SKIP' "used as thumbnail by catalog row $($r.id), which stays"; $changed = $true; break } }
    }
  } while ($changed -and $round -lt 50)
  $mv = New-OrdinalMap; foreach ($it in $items) { if ($it.decision -eq 'MOVE') { $mv[$it.rel] = $it } }

  # rows to delete, keeper rows that must stay, per-category deltas
  $delRows = New-Object System.Collections.Generic.List[object]; $seen = @{}; $keepIds = @{}
  foreach ($it in $mv.Values) {
    $sr = @(Get-List $rowsBy $it.rel); $it.rows = $sr; $it.row_ids = (@($sr | ForEach-Object { $_.id }) -join ' ')
    foreach ($r in $sr) { if (-not $seen.ContainsKey([string]$r.id)) { $seen[[string]$r.id] = $true; $delRows.Add($r); $P.catDelta[[string]$r.category] = 1 + [int]$P.catDelta[[string]$r.category] } }
    if ($sr.Count -and $it.keeper) { foreach ($r in (Get-List $rowsBy $it.keeper)) { $keepIds[[string]$r.id] = $r } }
  }
  $P.rows = $delRows.ToArray()
  $P.keeperRowIds = @($keepIds.Keys | ForEach-Object { [int64]$_ } | Sort-Object)
  foreach ($k in @($keepIds.Keys)) { if ($seen.ContainsKey($k)) { $P.problems.Add("row $k is both a keeper row and a row to delete") } }

  # samples for the after-apply HTTP check
  $withRows = @($mv.Values | Where-Object { $_.rows.Count -gt 0 -and $_.keeper } | Sort-Object rel)
  $pick = @(); foreach ($ext in @('.mp4', '.mp3', '.pdf', '.m4a', '.webm', '.mkv')) { $x = @($withRows | Where-Object { [IO.Path]::GetExtension($_.rel).ToLowerInvariant() -eq $ext }); if ($x.Count) { $pick += $x[0]; if ($x.Count -gt 2) { $pick += $x[[int]($x.Count / 2)] } } }
  $pick = @($pick | Select-Object -First 8)
  $P.keepSamples = @($pick | ForEach-Object { @(Get-List $rowsBy $_.keeper)[0].file_path })
  $P.goneSamples = @($pick | ForEach-Object { $_.rows[0].file_path })

  # folders that end up with no file left (every file under them moves now); never the library or a category root
  $tot = New-OrdinalMap; $mvc = New-OrdinalMap; $junk = New-OrdinalMap
  foreach ($rel in $allRel) {
    $isMv = $mv.ContainsKey($rel); $nl = [IO.Path]::GetFileName($rel).ToLowerInvariant(); $isJunk = (@('thumbs.db', 'desktop.ini', '.ds_store') -contains $nl)
    $d = [IO.Path]::GetDirectoryName($rel)
    while ($d) {
      $tot[$d] = 1 + [int]$tot[$d]
      if ($isMv) { $mvc[$d] = 1 + [int]$mvc[$d] } elseif ($isJunk) { $junk[$d] = 1 + [int]$junk[$d] }
      $d = [IO.Path]::GetDirectoryName($d)
    }
  }
  $qual = New-OrdinalMap; $junkOnly = 0
  foreach ($d in @($mvc.Keys)) {
    if (-not $d.Contains('\')) { continue }
    if ([int]$tot[$d] -eq [int]$mvc[$d]) { $qual[$d] = $true }
    elseif ([int]$tot[$d] -eq ([int]$mvc[$d] + [int]$junk[$d])) { $junkOnly++ }
  }
  $folders = @()
  foreach ($d in @($qual.Keys | Sort-Object)) {
    if ($qual.ContainsKey([IO.Path]::GetDirectoryName($d))) { continue }
    $dfi = New-Object IO.DirectoryInfo (Join-Path $script:Lib $d)
    $subs = @($dfi.EnumerateDirectories('*', [IO.SearchOption]::AllDirectories))
    $folders += [pscustomobject]@{ rel = $dfi.FullName.Substring($prefix.Length); full = $dfi.FullName; files = [int]$mvc[$d]; size = [int64]0; subdirs = $subs.Count; dirs = (1 + $subs.Count) }
  }
  $fmap = New-OrdinalMap; foreach ($f in $folders) { $fmap[$f.rel] = $f }
  foreach ($it in $mv.Values) {
    $d = [IO.Path]::GetDirectoryName($it.rel)
    while ($d) { if ($fmap.ContainsKey($d)) { $it.folder = $fmap[$d].rel; $fmap[$d].size += $it.size; break }; $d = [IO.Path]::GetDirectoryName($d) }
  }
  $P.folders = $folders
  if ($junkOnly) { $P.notes.Add("$junkOnly folder(s) would be empty except for Thumbs.db/desktop.ini/.DS_Store; they stay (those files are not on the list)") }

  # other tables that mention the rows to delete (read-only)
  if ($P.rows.Count -and -not $script:Sim) {
    $keys = @(); foreach ($r in $P.rows) { if ($r.content_id) { $keys += $r.content_id }; $keys += $r.file_path }
    $refs = @(); foreach ($ch in (Split-Chunks $keys 400)) { $refs += @((Invoke-Helper @{ mode = 'refs'; keys = @($ch) }).refs) }
    $P.refs = $refs
  }
  return $P
}
function Get-Todo($p) { return @($p.items | Where-Object { $_.decision -eq 'MOVE' }) }
function Show-Plan($p) {
  $all = $p.items.ToArray()
  Say ''
  Say ("Library: " + $p.libFiles + " files. Analysis: $script:Marks")
  foreach ($sc in @('exact', 'near', 'exe-com')) {
    $g = @($all | Where-Object { (Get-Scope $_.kind) -eq $sc })
    $title = @{ 'exact' = 'EXACT COPIES (spare copies + their covers)'; 'near' = 'NEAR-DUPLICATES (REMOVE-LARGER + their covers)'; 'exe-com' = 'EXE / COM FILES' }[$sc]
    Say ''; Say $title
    if ($g.Count -eq 0) { Say '  (none)'; continue }
    foreach ($d in @('MOVE', 'REPORT', 'SKIP', 'EXCLUDED')) {
      $x = @($g | Where-Object { $_.decision -eq $d }); if ($x.Count -eq 0) { continue }
      $kinds = (@($x | Group-Object kind | Sort-Object Name | ForEach-Object { "$($_.Count) $($_.Name)" }) -join ', ')
      $rowsN = 0; foreach ($it in $x) { $rowsN += @($it.rows).Count }
      $extra = ''; if ($d -eq 'MOVE') { $extra = "   catalog rows to delete: $rowsN" }
      Say (('  {0,-9} {1,6} files {2,12}   ({3}){4}' -f $d, $x.Count, (Fmt-GB (Sum-Size $x)), $kinds, $extra))
      if ($d -ne 'MOVE') { foreach ($r in @($x | Group-Object { ($_.reason -replace '[:(].*$', '').Trim() } | Sort-Object Count -Descending | Select-Object -First 8)) { Say (('                 {0,6}  {1}' -f $r.Count, $r.Name)) } }
    }
    if ($sc -eq 'near') {
      $c = @($g | Where-Object { $_.decision -eq 'MOVE' -and $_.cross })
      if ($c.Count) { Say ("  of the near MOVE files, " + $c.Count + " (" + (Fmt-GB (Sum-Size $c)) + ") are kept in ANOTHER top-level folder; leave them out with -ExcludeCrossFolderNear") }
    }
    if ($sc -eq 'exe-com') {
      foreach ($it in $g) { Say ("  " + $it.decision + "  " + $it.full + "  " + $it.size + " bytes  SHA256 " + $it.sha + "  catalog row: " + $(if (@($it.rows).Count) { $it.row_ids } elseif ($it.decision -eq 'MOVE') { 'none' } else { '-' })) }
    }
  }
  Say ''; Say 'EMPTY FOLDERS (every file under them moves in this run; moved whole, layout kept)'
  $f = @($p.folders); $dirs = 0; $fl = 0; foreach ($x in $f) { $dirs += $x.dirs; $fl += $x.files }
  Say ("  MOVE      " + $f.Count + " folder(s) at the top, " + $dirs + " folders in total incl. subfolders; they hold " + $fl + " of the files above (" + (Fmt-GB (Sum-Size $f)) + ")")
  $todo = @(Get-Todo $p)
  Say ''
  Say ("TOTAL MOVE: " + $todo.Count + " files, " + (Fmt-GB (Sum-Size $todo)) + "; catalog rows to delete: " + @($p.rows).Count + "; keeper rows that must stay: " + @($p.keeperRowIds).Count)
  $cd = @($p.catDelta.Keys | Sort-Object { -[int]$p.catDelta[$_] } | Select-Object -First 12 | ForEach-Object { "$_ -$($p.catDelta[$_])" })
  if ($cd.Count) { Say ("  rows by category: " + ($cd -join ', ')) }
  if (@($p.refs).Count) {
    $g = @($p.refs | Group-Object table | ForEach-Object { $n = 0; foreach ($x in $_.Group) { $n += [int]$x.n }; "$($_.Name): $n rows mention $($_.Count) of them" })
    Say ("  other tables that mention the rows to delete (history/logs, not changed): " + ($g -join '; '))
  } elseif (@($p.rows).Count -and -not $script:Sim) { Say '  other tables that mention the rows to delete: none' }
  foreach ($n in $p.notes.ToArray()) { Say "  note: $n" }
  foreach ($n in $p.problems.ToArray()) { Say "  PROBLEM: $n" }
}
function Export-Plan($p, [string]$path) {
  $out = New-Object System.Collections.Generic.List[object]
  foreach ($it in $p.items.ToArray()) {
    $out.Add([pscustomobject]([ordered]@{ Scope = (Get-Scope $it.kind); Kind = $it.kind; Decision = $it.decision; Reason = $it.reason; SetId = $it.set; Category = $it.cat; Path = $it.full
      SizeBytes = $it.size; SHA256 = $it.sha; Check = $it.check; KeeperPath = $(if ($it.keeper) { Join-Path $script:Lib $it.keeper } else { $it.partner }); KeeperSHA256 = $it.keeper_sha
      RowIdsToDelete = $it.row_ids; KeeperRowIds = $it.keeper_rows; CrossTopFolder = $(if ($it.cross) { 'Yes' } else { '' }); MovesWithFolder = $it.folder }))
  }
  foreach ($f in @($p.folders)) {
    $out.Add([pscustomobject]([ordered]@{ Scope = 'folder'; Kind = 'folder'; Decision = 'MOVE'; Reason = "$($f.files) file(s) and $($f.subdirs) subfolder(s); every file moves in this run"; SetId = ''; Category = (Get-Top $f.rel); Path = $f.full
      SizeBytes = $f.size; SHA256 = ''; Check = ''; KeeperPath = ''; KeeperSHA256 = ''; RowIdsToDelete = ''; KeeperRowIds = ''; CrossTopFolder = ''; MovesWithFolder = '' }))
  }
  $out | Export-Csv -LiteralPath $path -NoTypeInformation -Encoding UTF8
}

# ---------------------------------------------------------------- runs: journal, manifest, backups
$OpenStates = @('planned', 'backed-up', 'moving', 'moved', 'rows-deleted', 'verifying', 'rollback-failed', 'failed')
$ContinueStates = @('backed-up', 'moving', 'moved', 'rows-deleted', 'verifying')
function Get-Stamp { return (Get-Date).ToString('yyyyMMdd-HHmmss') }
function Get-NewStamp {
  $s = Get-Stamp; $t = $s; $n = 1
  while ((Test-Path -LiteralPath (Join-Path $script:BRoot $t)) -or (Test-Path -LiteralPath (Join-Path $script:QRoot "library-dupes-$t"))) { $n++; $t = "$s-$n" }
  return $t
}
function Read-Json([string]$path) { return ([IO.File]::ReadAllText($path, $utf8) | ConvertFrom-Json) }
function Write-Json([string]$path, $obj) { [IO.File]::WriteAllText($path, ($obj | ConvertTo-Json -Depth 8), $utf8) }
function Save-Journal($bk, [string]$state, [string]$note) {
  $jp = Join-Path $bk 'journal.json'
  $j = Read-Json $jp
  $j.state = $state; $j.updated = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss'); if ($note) { $j.note = $note }
  Write-Json $jp $j
  [IO.File]::AppendAllText((Join-Path $bk 'journal.log'), ((Get-Date).ToString('yyyy-MM-dd HH:mm:ss') + "  $state  $note`r`n"), $utf8)
}
function Get-Runs {
  if (-not (Test-Path -LiteralPath $script:BRoot)) { return @() }
  return @(Get-ChildItem -LiteralPath $script:BRoot -Directory | Where-Object { Test-Path -LiteralPath (Join-Path $_.FullName 'journal.json') } | Sort-Object Name | ForEach-Object {
      $j = Read-Json (Join-Path $_.FullName 'journal.json'); [pscustomobject]@{ stamp = $_.Name; dir = $_.FullName; state = [string]$j.state } })
}
function Resolve-Run([string]$which, [string[]]$states) {
  $runs = @(Get-Runs)
  if ($which -eq 'latest') {
    if ($runs.Count -eq 0) { throw "no runs in $script:BRoot" }
    $r = $runs[$runs.Count - 1]
  } else {
    $r = @($runs | Where-Object { $_.stamp -eq $which }) | Select-Object -First 1
    if (-not $r) { throw "no run '$which' in $script:BRoot" }
  }
  if ($states -notcontains $r.state) { throw ("run $($r.stamp) is in state '$($r.state)'; nothing to do (allowed: " + ($states -join ', ') + ')') }
  return $r
}
$ManCols = @('type', 'src', 'dst', 'size', 'mtime', 'sha', 'keeper', 'rows', 'kind', 'files')
function Write-Manifest([string]$path, $entries) {
  $sb = New-Object System.Text.StringBuilder
  [void]$sb.AppendLine(($ManCols -join "`t"))
  foreach ($e in $entries) { [void]$sb.AppendLine((@($ManCols | ForEach-Object { ([string]$e.$_).Replace("`t", ' ') }) -join "`t")) }
  [IO.File]::WriteAllText($path, $sb.ToString(), $utf8)
}
function Read-Manifest([string]$bk) {
  $out = New-Object System.Collections.Generic.List[object]
  $lines = [IO.File]::ReadAllLines((Join-Path $bk 'manifest.tsv'), $utf8)
  for ($i = 1; $i -lt $lines.Count; $i++) {
    if (-not $lines[$i]) { continue }
    $p = $lines[$i].Split("`t")
    $out.Add([pscustomobject]@{ type = $p[0]; src = $p[1]; dst = $p[2]; size = [int64]$p[3]; mtime = [int64]$p[4]; sha = $p[5]; keeper = $p[6]; rows = $p[7]; kind = $p[8]; files = [int]$p[9] })
  }
  return $out.ToArray()
}
function Get-FileHashText([string]$p) { return (Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash }

function New-Run($p, [string]$stamp, $before) {
  $bk = Join-Path $script:BRoot $stamp; $q = Join-Path $script:QRoot "library-dupes-$stamp"
  if (Test-Path -LiteralPath $bk) { throw "backup folder already exists: $bk" }
  if (Test-Path -LiteralPath $q) { throw "quarantine folder already exists: $q" }
  [void](New-Item -ItemType Directory -Path $bk)
  $script:Log = Join-Path $bk 'run.log'
  $todo = @(Get-Todo $p)
  $exp = @(); foreach ($b in @($before)) { $exp += [pscustomobject]@{ cat = $b.cat; before = $b.db; expected = ($b.db - [int]$p.catDelta[$b.cat]); url = $b.url } }
  Write-Json (Join-Path $bk 'journal.json') ([pscustomobject]@{ stamp = $stamp; state = 'planned'; created = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss'); updated = ''; note = ''
      library = $script:Lib; quarantine = $q; table = $script:Table; machine = $env:COMPUTERNAME; script = $PSCommandPath; rowfiles = @(); rowfile_sha = @(); rows = @($p.rows).Count })
  # manifest: individual files, whole folders, and the files inside each folder
  $m = New-Object System.Collections.Generic.List[object]
  foreach ($f in @($p.folders)) { $m.Add([pscustomobject]@{ type = 'dir'; src = $f.full; dst = (Join-Path $q $f.rel); size = $f.size; mtime = 0; sha = ''; keeper = ''; rows = ''; kind = 'folder'; files = $f.files }) }
  foreach ($it in $todo) {
    $t = 'file'; if ($it.folder) { $t = 'file-in-dir' }
    $kp = ''; if ($it.keeper) { $kp = Join-Path $script:Lib $it.keeper } elseif ($it.partner) { $kp = Join-Path $script:Lib $it.partner }
    $m.Add([pscustomobject]@{ type = $t; src = $it.full; dst = (Join-Path $q $it.rel); size = $it.size; mtime = $it.mtime; sha = $it.sha; keeper = $kp; rows = $it.row_ids; kind = $it.kind; files = 0 })
  }
  Write-Manifest (Join-Path $bk 'manifest.tsv') $m
  $m | ForEach-Object { [pscustomobject]([ordered]@{ OriginalPath = $_.src; SizeBytes = $_.size; SHA256 = $_.sha; KeeperPath = $_.keeper; RowIds = $_.rows; Type = $_.type; Kind = $_.kind; QuarantinePath = $_.dst }) } |
    Export-Csv -LiteralPath (Join-Path $bk 'manifest.csv') -NoTypeInformation -Encoding UTF8
  Write-Json (Join-Path $bk 'checks.json') ([pscustomobject]@{ cats = @($exp); keeper_ids = @($p.keeperRowIds); keep_samples = @($p.keepSamples); gone_samples = @($p.goneSamples)
      del_ids = @(@($p.rows) | ForEach-Object { $_.id }) })
  Say ("  run folder $bk; manifest: " + $m.Count + ' entries (' + @($p.folders).Count + ' folders, ' + $todo.Count + ' files)')
  return $bk
}
function Backup-Rows($p, [string]$bk) {
  $files = @(); $shas = @(); $n = 0; $i = 0; $all = New-Object System.Collections.Generic.List[object]
  foreach ($ch in (Split-Chunks @(@($p.rows) | ForEach-Object { $_.id }) $script:Chunk)) {
    $i++
    $r = Invoke-Helper @{ mode = 'select'; ids = @($ch) }
    if ([int]$r.count -ne @($ch).Count) { throw "row backup: asked for $(@($ch).Count) rows, got $($r.count)" }
    $txt = Get-RowsText $r
    $fp = Join-Path $bk ('rows-{0:D4}.json' -f $i)
    [IO.File]::WriteAllText($fp, $txt, $utf8)
    $files += (Split-Path -Leaf $fp); $shas += (Get-FileHashText $fp); $n += [int]$r.count
    foreach ($x in @(From-JsonArray $txt)) { $all.Add($x) }
  }
  if ($n -ne @($p.rows).Count) { throw "row backup incomplete: $n of $(@($p.rows).Count)" }
  if ($all.Count) { $all | Export-Csv -LiteralPath (Join-Path $bk 'rows.csv') -NoTypeInformation -Encoding UTF8 }
  $jp = Join-Path $bk 'journal.json'; $j = Read-Json $jp; $j.rowfiles = @($files); $j.rowfile_sha = @($shas); Write-Json $jp $j
  Say "  backed up $n catalog row(s) in $($files.Count) file(s)"
}
function Test-BackupUnchanged([string]$bk) {
  $j = Read-Json (Join-Path $bk 'journal.json'); $f = @($j.rowfiles); $s = @($j.rowfile_sha)
  for ($i = 0; $i -lt $f.Count; $i++) {
    $fp = Join-Path $bk $f[$i]
    if (-not (Test-Path -LiteralPath $fp)) { throw "backup file missing: $fp" }
    if ((Get-FileHashText $fp) -ne $s[$i]) { throw "backup file changed since it was written: $fp" }
  }
}

# ---------------------------------------------------------------- phases (each one safe to run again)
function Move-Files([string]$bk) {
  $m = @(Read-Manifest $bk)
  $files = @($m | Where-Object { $_.type -eq 'file' }); $dirs = @($m | Where-Object { $_.type -eq 'dir' }); $inDir = @($m | Where-Object { $_.type -eq 'file-in-dir' })
  $logp = Join-Path $bk 'moved.log'; $n = 0; $total = $files.Count + $inDir.Count; $done = 0; $half = [int]($files.Count / 2)
  foreach ($e in $files) {
    $n++
    if ($n -eq $half -or ($files.Count -eq 1 -and $n -eq 1)) { Check-Fail 'mid-move'; Check-Fail 'crash-mid-move' }
    $se = [IO.File]::Exists($e.src); $de = [IO.File]::Exists($e.dst)
    if (-not $se -and $de) { $done++; continue }
    if ($se -and $de) { throw "both the original and the quarantine copy exist: $($e.src)" }
    if (-not $se) { throw "file to move is gone: $($e.src)" }
    $fi = New-Object IO.FileInfo $e.src
    if ($fi.Length -ne [int64]$e.size -or $fi.LastWriteTimeUtc.Ticks -ne [int64]$e.mtime) { throw "file changed since the check: $($e.src)" }
    $pd = [IO.Path]::GetDirectoryName($e.dst); if (-not [IO.Directory]::Exists($pd)) { [void][IO.Directory]::CreateDirectory($pd) }
    [IO.File]::Move($e.src, $e.dst)
    [IO.File]::AppendAllText($logp, ("file`t" + $e.src + "`t" + $e.dst + "`r`n"), $utf8)
    $done++
    if ($done % 500 -eq 0) { Say "    moved $done / $total files"; Save-Journal $bk 'moving' "$done of $total files moved" }
  }
  $byTop = New-OrdinalMap; foreach ($d in $dirs) { $byTop[$d.src] = New-Object System.Collections.Generic.List[object] }
  foreach ($e in $inDir) { $p = [IO.Path]::GetDirectoryName($e.src); while ($p) { if ($byTop.ContainsKey($p)) { $byTop[$p].Add($e); break }; $p = [IO.Path]::GetDirectoryName($p) } }
  foreach ($d in $dirs) {
    $se = [IO.Directory]::Exists($d.src); $de = [IO.Directory]::Exists($d.dst)
    $mine = $byTop[$d.src].ToArray()
    if (-not $se -and $de) { $done += $mine.Count; continue }
    if ($se -and $de) { throw "both the original and the quarantine folder exist: $($d.src)" }
    if (-not $se) { throw "folder to move is gone: $($d.src)" }
    $want = New-OrdinalMap; foreach ($e in $mine) { $want[$e.src] = $e }
    foreach ($f in (New-Object IO.DirectoryInfo $d.src).EnumerateFiles('*', [IO.SearchOption]::AllDirectories)) {
      $e = $want[$f.FullName]
      if (-not $e) { throw "folder gained a file that is not on the list, not moved: $($f.FullName)" }
      if ($f.Length -ne [int64]$e.size -or $f.LastWriteTimeUtc.Ticks -ne [int64]$e.mtime) { throw "file changed since the check: $($f.FullName)" }
      $want.Remove($f.FullName)
    }
    if ($want.Count) { throw "folder lost $($want.Count) listed file(s) since the check: $($d.src)" }
    $pd = [IO.Path]::GetDirectoryName($d.dst); if (-not [IO.Directory]::Exists($pd)) { [void][IO.Directory]::CreateDirectory($pd) }
    [IO.Directory]::Move($d.src, $d.dst)
    [IO.File]::AppendAllText($logp, ("dir`t" + $d.src + "`t" + $d.dst + "`r`n"), $utf8)
    $done += $mine.Count
    if ([int]($done / 500) -ne [int](($done - $mine.Count) / 500)) { Say "    moved $done / $total files"; Save-Journal $bk 'moving' "$done of $total files moved" }
  }
  Say "  moved $done file(s): $($files.Count) one by one, $($inDir.Count) inside $($dirs.Count) folder(s)"
}
function Remove-Rows([string]$bk) {
  $j = Read-Json (Join-Path $bk 'journal.json'); $n = 0; $gone = 0
  Test-BackupUnchanged $bk
  foreach ($rf in @($j.rowfiles)) {
    $rows = @(From-JsonArray ([IO.File]::ReadAllText((Join-Path $bk $rf), $utf8)))
    $items = @($rows | ForEach-Object { @{ id = [int64]$_.id; file_path = $_.file_path; content_id = $_.content_id } })
    $r = Invoke-Helper @{ mode = 'delete'; items = @($items) }
    $n += [int]$r.deleted; $gone += @($r.gone).Count
  }
  Say "  deleted $n catalog row(s)$(if ($gone) { "; $gone were already gone (earlier attempt)" })"
}
function Test-AfterApply([string]$bk) {
  $c = Read-Json (Join-Path $bk 'checks.json'); $bad = New-Object System.Collections.Generic.List[string]
  $m = @(Read-Manifest $bk)
  $movedSet = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase); foreach ($e in $m) { [void]$movedSet.Add(([string]$e.src).TrimEnd('\')) }   # r2
  $miss = 0; $left = 0
  foreach ($e in $m) {
    if ($e.type -eq 'dir') { if ([IO.Directory]::Exists($e.src)) { $left++ }; if (-not [IO.Directory]::Exists($e.dst)) { $miss++ }; continue }
    if ([IO.File]::Exists($e.src)) { $left++ }; if (-not [IO.File]::Exists($e.dst)) { $miss++ }
  }
  if ($left) { $bad.Add("$left original(s) still in the library") }; if ($miss) { $bad.Add("$miss item(s) missing from quarantine") }
  $ids = @($c.del_ids)
  if ($ids.Count) { $h = Get-HaveCount $ids; if ($h.have -ne 0) { $bad.Add("$($h.have) row(s) that should be deleted still exist") } }
  $k = @($c.keeper_ids)
  if ($k.Count) { $h = Get-HaveCount $k; if ($h.have -ne $k.Count) { $bad.Add(("keeper rows missing: " + (@($h.missing) | Select-Object -First 10) -join ' ')) } }
  $cats = @($c.cats)
  if ($cats.Count) {
    $st = @(Get-CatState @($cats | ForEach-Object { $_.cat }))
    foreach ($x in $cats) {
      $s = @($st | Where-Object { $_.cat -eq $x.cat })[0]
      $msg = "  $($x.cat): rows $($x.before) -> $($s.db) (expected $($x.expected)); page HTTP $($s.code)"; if ($s.page -ge 0) { $msg += ", page shows $($s.page)" }
      Say $msg
      if ($s.db -ne [int]$x.expected) { $bad.Add("$($x.cat): $($s.db) rows, expected $($x.expected)") }
      if (-not $script:Sim) {
        if ($s.code -ne '200') { $bad.Add("$($x.cat) page returned HTTP $($s.code)") }
        elseif ($s.page -ne [int]$x.expected) { $bad.Add("$($x.cat) page shows $($s.page), expected $($x.expected)") }
        $ws = @(Test-WatchSamples $s.links 2 $movedSet)
        foreach ($w in $ws) { if (-not $w.ok) { $bad.Add("$($x.cat) watch sample failed: $($w.info)") } }
        $pre = @($ws | Where-Object { $_.pre }).Count; $play = @($ws | Where-Object { $_.ok -and -not $_.pre }).Count
        if ($pre) { Say "    $($x.cat): $pre sampled page item(s) point at files that were already missing before this run (held missing-file cleanup); not caused by this run" }
        if ($play -eq 0 -and @($ws).Count) { Say "    $($x.cat): no playable item among the sampled page links; playback is covered by the kept-copy check" }
      }
    }
  }
  $okK = 0; foreach ($fp in @($c.keep_samples)) { $t = Test-Media $fp; if ($t.ok) { $okK++ } else { $bad.Add("kept copy does not play: $fp ($($t.info))") } }
  $okG = 0; foreach ($fp in @($c.gone_samples)) { $t = Test-Media $fp; if (-not $t.ok) { $okG++ } else { $bad.Add("removed path is still served: $fp ($($t.info))") } }
  Say ("  kept copies that play: $okK / " + @($c.keep_samples).Count + "; removed paths no longer served: $okG / " + @($c.gone_samples).Count)
  return ,$bad
}
function Invoke-Phases([string]$bk) {
  $j = Read-Json (Join-Path $bk 'journal.json'); $state = [string]$j.state
  try {
    if ($state -eq 'backed-up' -or $state -eq 'moving') {
      Save-Journal $bk 'moving' 'moving files'
      Say '  moving files ...'; Move-Files $bk
      Check-Fail 'after-move'
      Save-Journal $bk 'moved' ''; $state = 'moved'
    }
    if ($state -eq 'moved') {
      Say '  deleting catalog rows ...'; Remove-Rows $bk
      Check-Fail 'after-rows'
      Save-Journal $bk 'rows-deleted' ''; $state = 'rows-deleted'
    }
    if ($state -eq 'rows-deleted' -or $state -eq 'verifying') {
      Save-Journal $bk 'verifying' ''
      Say '  verifying ...'
      $bad = Test-AfterApply $bk
      Check-Fail 'verify'
      if ($bad.Count) { throw ("verification failed: " + ($bad -join '; ')) }
      Save-Journal $bk 'done' 'applied and verified'
    }
  } catch {
    $msg = $_.Exception.Message
    if ($msg -like 'SIMULATED CRASH*') { Say "  $msg (left as is; finish with -Continue latest or undo with -Rollback latest)"; throw }
    Say "  FAILED: $msg"; Say '  rolling back automatically ...'
    Invoke-AutoRollback $bk $msg
    throw "apply failed and was rolled back: $msg"
  }
}
function Invoke-AutoRollback([string]$bk, [string]$why) {
  try { Save-Journal $bk 'failed' $why; Invoke-Undo $bk }
  catch { Save-Journal $bk 'rollback-failed' $_.Exception.Message; throw ("apply failed ($why) AND the automatic rollback failed: " + $_.Exception.Message + ". Run -Rollback $(Split-Path -Leaf $bk) after fixing the cause.") }
}
function Invoke-Undo([string]$bk) {
  $script:LastUndoRefused = $false
  $m = @(Read-Manifest $bk)
  $j = Read-Json (Join-Path $bk 'journal.json')
  $coll = @()
  foreach ($e in $m) {
    if ($e.type -eq 'dir') { if ([IO.Directory]::Exists($e.dst) -and (Test-Path -LiteralPath $e.src)) { $coll += $e.src } }
    elseif ($e.type -eq 'file') { if ([IO.File]::Exists($e.dst) -and (Test-Path -LiteralPath $e.src)) { $coll += $e.src } }
  }
  if ($coll.Count) { $script:LastUndoRefused = $true; throw ("rollback refused, nothing changed: something new now exists at " + $coll.Count + " original path(s), e.g. " + $coll[0]) }
  Test-BackupUnchanged $bk
  $nd = 0; $nf = 0
  foreach ($e in @($m | Where-Object { $_.type -eq 'dir' })) {
    if ([IO.Directory]::Exists($e.dst)) { $pd = [IO.Path]::GetDirectoryName($e.src); if (-not [IO.Directory]::Exists($pd)) { [void][IO.Directory]::CreateDirectory($pd) }; [IO.Directory]::Move($e.dst, $e.src); $nd++ }
  }
  foreach ($e in @($m | Where-Object { $_.type -eq 'file' })) {
    if ([IO.File]::Exists($e.dst)) { $pd = [IO.Path]::GetDirectoryName($e.src); if (-not [IO.Directory]::Exists($pd)) { [void][IO.Directory]::CreateDirectory($pd) }; [IO.File]::Move($e.dst, $e.src); $nf++ }
    if ($nf -gt 0 -and $nf % 500 -eq 0) { Say "    moved back $nf files" }
  }
  Say "  moved back $nf file(s) and $nd folder(s)"
  $ins = 0; $same = 0
  foreach ($rf in @($j.rowfiles)) {
    $txt = [IO.File]::ReadAllText((Join-Path $bk $rf), $utf8)
    $r = Invoke-Helper @{ mode = 'restore'; rows_b64 = [Convert]::ToBase64String($utf8.GetBytes($txt)) }
    $ins += [int]$r.inserted; $same += [int]$r.already
  }
  Say "  restored $ins catalog row(s)$(if ($same) { " ($same were already there)" })"
  # tidy: remove now-empty folders inside this run's quarantine folder only
  $q = [string]$j.quarantine
  if ($q -and [IO.Directory]::Exists($q)) {
    foreach ($d in @([IO.Directory]::GetDirectories($q, '*', [IO.SearchOption]::AllDirectories) | Sort-Object { $_.Length } -Descending)) {
      if (@([IO.Directory]::GetFileSystemEntries($d)).Count -eq 0) { [IO.Directory]::Delete($d) }
    }
    if (@([IO.Directory]::GetFileSystemEntries($q)).Count -eq 0) { [IO.Directory]::Delete($q) }
  }
  # verify
  $bad = @()
  foreach ($e in $m) {
    if ($e.type -eq 'dir') { if (-not [IO.Directory]::Exists($e.src)) { $bad += "folder not back: $($e.src)" } }
    elseif (-not [IO.File]::Exists($e.src)) { $bad += "file not back: $($e.src)" }
  }
  foreach ($rf in @($j.rowfiles)) {
    $txt = [IO.File]::ReadAllText((Join-Path $bk $rf), $utf8)
    $r = Invoke-Helper @{ mode = 'compare'; rows_b64 = [Convert]::ToBase64String($utf8.GetBytes($txt)) }
    if ([int]$r.same -ne [int]$r.rows) { $bad += "$rf : $($r.same) of $($r.rows) rows identical (missing $($r.missing), changed $(@($r.changed).Count))" }
  }
  if ($bad.Count) { throw ("rollback verification failed: " + (@($bad | Select-Object -First 5) -join '; ')) }
  Save-Journal $bk 'rolled-back' "moved back $nf files + $nd folders, restored $ins rows"
  Say '  rollback verified: every file and folder is back, every row identical to the backup'
}

# ---------------------------------------------------------------- entry points
function Get-ReportPath([string]$label) {
  $s = Get-Stamp; $p = Join-Path $script:Report "Library duplicates $label $s.csv"; $n = 1
  while (Test-Path -LiteralPath $p) { $n++; $p = Join-Path $script:Report "Library duplicates $label $s-$n.csv" }
  return $p
}
function Show-CatState($st, $p) {
  foreach ($s in @($st)) {
    $exp = $s.db - [int]$p.catDelta[$s.cat]
    $pg = ''; if ($s.page -ge 0) { $pg = ", page HTTP $($s.code) shows $($s.page)" } elseif (-not $script:Sim) { $pg = ", page HTTP $($s.code)" }
    Say ("  {0}: {1} rows now{2}; after apply {3}" -f $s.cat, $s.db, $pg, $exp)
  }
}
function Invoke-Check {
  $sw = [Diagnostics.Stopwatch]::StartNew()
  Say "CHECK ONLY - nothing is moved, deleted or changed."
  $p = Get-Plan $Rehash.IsPresent
  Show-Plan $p
  if (-not $p.problems.Count -and @($script:Cats).Count) {
    Say ''; Say 'Category pages (read-only):'
    $st = Get-CatState $script:Cats; Show-CatState $st $p
    if (-not $script:Sim) { $t = Test-Media 'content/__library_dupes_probe_missing__.mp4'; Say ("  a path that does not exist answers HTTP " + $t.code + " (removed files will answer the same)") }
  }
  $hb = Get-HeartbeatProblem; if ($hb) { Say "  note: $hb (-Apply will refuse until it is fresh)" }
  $rp = Get-ReportPath 'check'; Export-Plan $p $rp
  Say ''; Say "Report: $rp"
  Say ("Check took " + [int]$sw.Elapsed.TotalMinutes + " min " + $sw.Elapsed.Seconds + " s (hashing: " + $script:HashStats.hashed + " files, " + (Fmt-GB $script:HashStats.bytes) + ")")
  return $p
}
function Invoke-ApplyMain {
  $sw = [Diagnostics.Stopwatch]::StartNew()
  $hb = Get-HeartbeatProblem; if ($hb) { throw "refused, nothing changed: $hb" }
  $open = @(Get-Runs | Where-Object { $OpenStates -contains $_.state })
  if ($open.Count) { throw ("refused, nothing changed: run " + $open[-1].stamp + " is unfinished (state '" + $open[-1].state + "'). Use -Continue " + $open[-1].stamp + " or -Rollback " + $open[-1].stamp) }
  Say 'APPLY: checking everything again first ...'
  $p = Get-Plan $Rehash.IsPresent
  Show-Plan $p
  if ($p.problems.Count) { throw "refused, nothing changed: problems listed above" }
  $todo = @(Get-Todo $p); if ($todo.Count -eq 0) { Say 'Nothing to move.'; return }
  $before = @()
  if (@($script:Cats).Count) {
    Say ''; Say 'Category pages before:'
    $before = @(Get-CatState $script:Cats); Show-CatState $before $p
    if (-not $script:Sim) { foreach ($s in $before) { if ($s.code -ne '200' -or $s.page -ne $s.db) { throw "refused, nothing changed: $($s.cat) page HTTP $($s.code) shows $($s.page), catalog has $($s.db)" } } }
  }
  $stamp = Get-NewStamp
  $rp = Get-ReportPath 'apply'; Export-Plan $p $rp
  $bk = New-Run $p $stamp $before
  try { Backup-Rows $p $bk; Check-Fail 'after-backup'; Save-Journal $bk 'backed-up' ("rows backed up: " + @($p.rows).Count) }
  catch { Say "  FAILED before anything moved: $($_.Exception.Message)"; Invoke-AutoRollback $bk $_.Exception.Message; throw "apply failed before any change and was rolled back: $($_.Exception.Message)" }
  Invoke-Phases $bk
  Say ''; Say ("DONE. Run $stamp moved " + $todo.Count + " files (" + (Fmt-GB (Sum-Size $todo)) + ") to " + (Join-Path $script:QRoot "library-dupes-$stamp") + ", deleted " + @($p.rows).Count + " catalog rows. Took " + [int]$sw.Elapsed.TotalMinutes + " min.")
  Say "Backup and manifest: $bk"; Say "Plan CSV: $rp"
  Say "Undo everything: powershell -ExecutionPolicy Bypass -File `"$PSCommandPath`" -Rollback $stamp"
}
function Invoke-ContinueMain([string]$which) {
  $hb = Get-HeartbeatProblem; if ($hb) { throw "refused: $hb" }
  $r = Resolve-Run $which $ContinueStates
  $script:Log = Join-Path $r.dir 'run.log'
  Say "CONTINUE run $($r.stamp) from state '$($r.state)'"
  Invoke-Phases $r.dir
  Say "DONE. Run $($r.stamp) finished and verified."
}
function Invoke-RollbackMain([string]$which) {
  $r = Resolve-Run $which @('done', 'failed', 'rollback-failed', 'backed-up', 'moving', 'moved', 'rows-deleted', 'verifying', 'planned')
  $script:Log = Join-Path $r.dir 'run.log'
  Say "ROLLBACK run $($r.stamp) (state '$($r.state)')"
  Invoke-Undo $r.dir
}

# ---------------------------------------------------------------- self-test (throwaway copies + scratch table)
$script:Fails = 0
function Assert([bool]$cond, [string]$msg) { if ($cond) { Say "  ok    $msg" } else { Say "  FAIL  $msg"; $script:Fails++ } }
function Get-Snapshot([string]$root) {
  if (-not (Test-Path -LiteralPath $root)) { return '' }
  $l = @(); $n = $root.TrimEnd('\').Length
  foreach ($f in @(Get-ChildItem -LiteralPath $root -Recurse -File -Force)) { $l += ($f.FullName.Substring($n) + '|' + $f.Length + '|' + $f.LastWriteTimeUtc.Ticks + '|' + (Get-FileHash -LiteralPath $f.FullName -Algorithm SHA256).Hash) }
  foreach ($d in @(Get-ChildItem -LiteralPath $root -Recurse -Directory -Force)) { $l += ($d.FullName.Substring($n) + '|dir') }
  return ((@($l) | Sort-Object) -join "`n")
}
function Get-Dump { return (Get-RowsText (Invoke-Helper @{ mode = 'dump' })) }
function Get-Dec($p, [string]$rel) { $x = @($p.items.ToArray() | Where-Object { $_.rel -ceq $rel }); if ($x.Count) { return ($x[0].kind + '/' + $x[0].decision) }; return '(missing)' }
function Try-Run([scriptblock]$sb) { try { & $sb | Out-Null; return '' } catch { return $_.Exception.Message } }
function Get-State([string]$stamp) { return [string](Read-Json (Join-Path (Join-Path $script:BRoot $stamp) 'journal.json')).state }
function Invoke-SelfTest {
  $hex = [guid]::NewGuid().ToString('N').Substring(0, 8)
  $tmp = Join-Path $env:TEMP "ld-selftest-$hex"
  $script:Lib = Join-Path $tmp 'lib'; $script:BRoot = Join-Path $tmp 'backups'; $script:QRoot = Join-Path $tmp 'quarantine'
  $script:Cache = Join-Path $tmp 'hashes.tsv'; $script:R3 = Join-Path $tmp 'r3-cache.tsv'; $script:Report = $tmp; $script:Marks = Join-Path $tmp 'marks.csv'
  $script:Table = "ld_selftest_$hex"; $script:Sim = $true; $script:Heartbeat = Join-Path $tmp 'heartbeat.txt'; $script:FailAt = ''
  $script:Cats = @('CatA', 'CatB'); $script:ExNear = $false; $script:ExCross = $false
  Say "SELF-TEST in $tmp, scratch table $script:Table (live library and catalog are not touched)"
  function Bytes([int]$seed, [int]$len) { $b = New-Object byte[] $len; (New-Object Random $seed).NextBytes($b); return ,$b }
  function Put([string]$rel, [byte[]]$b) { $p = Join-Path $script:Lib $rel; $d = Split-Path -Parent $p; if (-not (Test-Path -LiteralPath $d)) { [void](New-Item -ItemType Directory -Path $d -Force) }; [IO.File]::WriteAllBytes($p, $b) }
  function Sha([byte[]]$b) { $s = [Security.Cryptography.SHA256]::Create(); try { return ([BitConverter]::ToString($s.ComputeHash($b)) -replace '-', '') } finally { $s.Dispose() } }
  $uName = 'Li' + [char]0x00E7 + [char]0x00E3 + 'o ' + [char]0x00FC + '.mp4'
  $X = Bytes 1 40000; $Y = Bytes 2 30000; $Z = Bytes 3 20000; $W = Bytes 4 25000; $W2 = [byte[]]$W.Clone(); $W2[5] = [byte](($W2[5] + 1) % 256)
  $V = Bytes 5 21000; $U = Bytes 6 22000; $S6 = Bytes 7 23000; $C = Bytes 8 18679; $R = Bytes 9 15000
  Put 'CatA\keep\v1.mp4' $X; Put 'CatA\dup\v1.mp4' $X; Put 'CatA\dup\v1.jpg' (Bytes 20 900); [void](New-Item -ItemType Directory -Path (Join-Path $script:Lib 'CatA\dup\thumbs') -Force)
  Put ('CatA\keep\' + $uName) $U; Put ('CatA\dup\' + $uName) $U
  Put 'CatA\keep\v2.mp4' $Y; Put 'CatA\mixed\v2.mp4' $Y
  Put 'CatA\keep\v3.mp4' $Z; Put 'CatA\mixed\v3.mp4' $Z
  Put 'CatA\keep\v4.mp4' $W; Put 'CatA\mixed\v4.mp4' $W2
  Put 'CatA\keep\v5.mp4' $V; Put 'CatB\x\v5.mp4' $V
  Put 'CatA\keep\rev.mp4' $R; Put 'CatA\mixed\rev.mp4' $R
  Put 'CatA\keep\v6.mp4' $S6; Put 'CatA\mixed\v6.mp4' $S6; Put 'CatA\mixed\v6.jpg' (Bytes 21 800)
  Put 'CatA\keep\n1.mp4' (Bytes 10 1000); Put 'CatA\near\n1.mp4' (Bytes 11 2000); Put 'CatA\near\n1.jpg' (Bytes 22 700)
  Put 'CatA\keep\n2.mp4' (Bytes 12 1000); Put 'CatA\mixed\n2.mp4' (Bytes 13 2000)
  Put 'CatA\keep\n3.mp4' (Bytes 14 1000); Put 'CatA\mixed\n3.mp4' (Bytes 15 2000)
  $K = Bytes 24 900; Put 'CatA\keep.jpg' $K; Put 'CatA\keep\keep.jpg' $K
  Put 'CatA\mixed\Movies.exe' (New-Object byte[] 0); Put 'CatA\keep\a.com' $C; Put 'CatB\y\a.com' $C
  [IO.File]::WriteAllText($script:Heartbeat, 'alive', $utf8)
  $lib = $script:Lib
  function M([string]$set, [string]$act, [string]$rel, [string]$sha, [string]$reason, [string]$ids) {
    $f = Join-Path $lib $rel; $mb = 0.0; if (Test-Path -LiteralPath $f) { $mb = [Math]::Round((Get-Item -LiteralPath $f).Length / 1MB, 2) }
    return [pscustomobject]([ordered]@{ SetId = $set; Action = $act; Reason = $reason; Path = $f; SizeMB = $mb.ToString([Globalization.CultureInfo]::InvariantCulture); SHA256 = $sha; 'CatalogId(s)' = $ids })
  }
  $hK = Sha $K; $hX = Sha $X; $hY = Sha $Y; $hZ = Sha $Z; $hW = Sha $W; $hV = Sha $V; $hU = Sha $U; $hS = Sha $S6; $hC = Sha $C; $hR = Sha $R
  $marks = @(
    (M 'D1' 'KEEP' 'CatA\keep\v1.mp4' $hX 'keeper' '1'), (M 'D1' 'QUARANTINE' 'CatA\dup\v1.mp4' $hX 'duplicate of CatA\keep\v1.mp4 (same folder name); HAS LIVE CATALOG ROW 2: x' '2'),
    (M 'D1' 'QUARANTINE' 'CatA\dup\v1.jpg' '' 'cover/thumbnail of quarantined CatA\dup\v1.mp4' ''),
    (M 'D2' 'KEEP' 'CatA\keep\v2.mp4' $hY 'keeper' ''), (M 'D2' 'QUARANTINE' 'CatA\mixed\v2.mp4' $hY 'duplicate of CatA\keep\v2.mp4 (x); HAS LIVE CATALOG ROW 5: x' '5'),
    (M 'D3' 'KEEP' 'CatA\keep\v3.mp4' $hZ 'keeper' '3'), (M 'D3' 'QUARANTINE' 'CatA\mixed\v3.mp4' $hZ 'duplicate of CatA\keep\v3.mp4 (x); HAS LIVE CATALOG ROW 4: x' '4'),
    (M 'D4' 'KEEP' 'CatA\keep\v4.mp4' $hW 'keeper' '16'), (M 'D4' 'QUARANTINE' 'CatA\mixed\v4.mp4' $hW 'duplicate of CatA\keep\v4.mp4 (x); HAS LIVE CATALOG ROW 17: x' '17'),
    (M 'D4' 'QUARANTINE' 'CatA\mixed\gone.mp4' $hW 'duplicate of CatA\keep\v4.mp4 (x)' ''),
    (M 'D5' 'KEEP' 'CatA\keep\v5.mp4' $hV 'keeper' '6'), (M 'D5' 'QUARANTINE' 'CatB\x\v5.mp4' $hV 'duplicate of CatA\keep\v5.mp4 (x); HAS LIVE CATALOG ROW 7: x' '7'),
    (M 'D6' 'KEEP' 'CatA\keep\rev.mp4' $hR 'keeper' ''), (M 'D6' 'REVIEW' 'CatA\mixed\rev.mp4' $hR 'two live rows' ''),
    (M 'D7' 'KEEP' 'CatA\keep\v6.mp4' $hS 'keeper' '12'), (M 'D7' 'QUARANTINE' 'CatA\mixed\v6.mp4' $hS 'duplicate of CatA\keep\v6.mp4 (x); HAS LIVE CATALOG ROW 13: x' '13'),
    (M 'D7' 'QUARANTINE' 'CatA\mixed\v6.jpg' '' 'cover/thumbnail of quarantined CatA\mixed\v6.mp4' ''),
    (M 'D8' 'KEEP' ('CatA\keep\' + $uName) $hU 'keeper' '14'), (M 'D8' 'QUARANTINE' ('CatA\dup\' + $uName) $hU ('duplicate of CatA\keep\' + $uName + ' (x); HAS LIVE CATALOG ROW 15: x') '15'),
    (M 'D9' 'KEEP' 'CatA\keep\a.com' $hC 'keeper' ''), (M 'D9' 'QUARANTINE' 'CatB\y\a.com' $hC 'duplicate of CatA\keep\a.com (x); HAS LIVE CATALOG ROW 11: x' '11'),
    (M 'N00001' 'KEEP' 'CatA\keep\n1.mp4' '' 'smaller' '8'), (M 'N00001' 'REMOVE-LARGER' 'CatA\near\n1.mp4' '' 'larger' '9'),
    (M 'N00237' 'KEEP' 'CatA\keep\n2.mp4' '' 'smaller' ''), (M 'N00237' 'REVIEW' 'CatA\mixed\n2.mp4' '' 'check' ''),
    (M 'N00003' 'KEEP' 'CatA\keep\n3.mp4' '' 'smaller' ''), (M 'N00003' 'REMOVE-LARGER' 'CatA\mixed\n3.mp4' '' 'larger' '10'),
    (M 'D10' 'KEEP' 'CatA\keep\keep.jpg' $hK 'keeper' ''), (M 'D10' 'QUARANTINE' 'CatA\keep.jpg' $hK 'duplicate of CatA\keep\keep.jpg (x)' '')
  )
  Start-Sleep -Milliseconds 1100
  $marks | Export-Csv -LiteralPath $script:Marks -NoTypeInformation -Encoding UTF8
  $fv = New-Object IO.FileInfo (Join-Path $lib 'CatA\dup\v1.mp4')
  [IO.File]::WriteAllText($script:R3, ("F`tcata\dup\v1.mp4|" + $fv.Length + '|' + $fv.LastWriteTimeUtc.Ticks + "`t" + $hX + "`r`n"), $utf8)
  function Row([int]$id, [string]$rel, [string]$thumb) { $fp = To-FilePath $rel; $t = ''; if ($thumb) { $t = To-FilePath $thumb }; return @{ id = $id; file_path = $fp; title = [IO.Path]::GetFileNameWithoutExtension($rel); category = (Get-Top $rel); thumb = $t } }
  $seed = @((Row 1 'CatA\keep\v1.mp4' ''), (Row 2 'CatA\dup\v1.mp4' 'CatA\dup\v1.jpg'), (Row 3 'CatA\keep\v3.mp4' ''), (Row 4 'CatA\mixed\v3.mp4' ''), (Row 5 'CatA\mixed\v2.mp4' ''),
    (Row 6 'CatA\keep\v5.mp4' ''), (Row 7 'CatB\x\v5.mp4' ''), (Row 8 'CatA\keep\n1.mp4' ''), (Row 9 'CatA\near\n1.mp4' 'CatA\near\n1.jpg'), (Row 10 'CatA\mixed\n3.mp4' ''),
    (Row 11 'CatB\y\a.com' ''), (Row 12 'CatA\keep\v6.mp4' 'CatA\mixed\v6.jpg'), (Row 13 'CatA\mixed\v6.mp4' ''), (Row 14 ('CatA\keep\' + $uName) ''), (Row 15 ('CatA\dup\' + $uName) ''),
    (Row 16 'CatA\keep\v4.mp4' ''), (Row 17 'CatA\mixed\v4.mp4' ''))
  $pass = $false
  try {
    [void](Invoke-Helper @{ mode = 'create' })
    [void](Invoke-Helper @{ mode = 'seed'; items = $seed })
    $dump0 = Get-Dump; $snap0 = Get-Snapshot $script:Lib
    Assert (@(From-JsonArray $dump0).Count -eq 17) 'scratch table seeded with 17 rows'

    Say '[1] decisions (check only)'
    $p = Get-Plan $false
    $exp = [ordered]@{ 'CatA\dup\v1.mp4' = 'exact/MOVE'; 'CatA\dup\v1.jpg' = 'cover/MOVE'; 'CatA\mixed\v2.mp4' = 'exact/SKIP'; 'CatA\mixed\v3.mp4' = 'exact/MOVE'
      'CatA\mixed\v4.mp4' = 'exact/SKIP'; 'CatA\mixed\gone.mp4' = 'exact/SKIP'; 'CatB\x\v5.mp4' = 'exact/REPORT'; 'CatA\mixed\rev.mp4' = 'exact/EXCLUDED'
      'CatA\mixed\v6.mp4' = 'exact/MOVE'; 'CatA\mixed\v6.jpg' = 'cover/SKIP'; 'CatB\y\a.com' = 'exe-com/MOVE'; 'CatA\keep\a.com' = 'exe-com/MOVE'; 'CatA\mixed\Movies.exe' = 'exe-com/MOVE'
      'CatA\near\n1.mp4' = 'near/MOVE'; 'CatA\near\n1.jpg' = 'near-cover/MOVE'; 'CatA\mixed\n2.mp4' = 'near/EXCLUDED'; 'CatA\mixed\n3.mp4' = 'near/SKIP' }
    $exp[('CatA\dup\' + $uName)] = 'exact/MOVE'
    $exp['CatA\keep.jpg'] = 'exact/SKIP'
    foreach ($k in $exp.Keys) { $d = Get-Dec $p $k; $why = @($p.items.ToArray() | Where-Object { $_.rel -ceq $k } | ForEach-Object { $_.reason }) -join ''; Assert ($d -eq $exp[$k]) "$k -> $d $why" }
    Assert ($p.items.ToArray().Count -eq $exp.Count) "no other candidates ($($p.items.ToArray().Count))"
    $fr = (@($p.folders | ForEach-Object { $_.rel } | Sort-Object) -join ',')
    Assert ($fr -eq 'CatA\dup,CatA\near,CatB\y') "folders that empty out: $fr"
    Assert ((@($p.folders | Where-Object { $_.rel -eq 'CatA\dup' })[0].subdirs) -eq 1) 'CatA\dup moves with its empty thumbs subfolder'
    $ids = (@($p.rows | ForEach-Object { $_.id } | Sort-Object) -join ',')
    Assert ($ids -eq '2,4,9,11,13,15') "rows to delete: $ids"
    Assert ((@($p.keeperRowIds) -join ',') -eq '1,3,8,12,14') "keeper rows that must stay: $(@($p.keeperRowIds) -join ',')"
    Assert ([int]$p.catDelta['CatA'] -eq 5 -and [int]$p.catDelta['CatB'] -eq 1) 'category deltas CatA -5, CatB -1'
    Assert ((@($p.items | Where-Object { $_.rel -eq 'CatA\dup\v1.mp4' })[0].check) -like 'saved hash*') 'r3b hash cache used when size+mtime match'
    $csv = Get-ReportPath 'check'; Export-Plan $p $csv
    Assert ((Test-Path -LiteralPath $csv) -and @(Import-Csv -LiteralPath $csv).Count -eq ($exp.Count + 3)) 'report CSV written (items + folders)'
    $p2 = Get-Plan $false
    Assert ($script:HashStats.hashed -eq 0) "second check reuses saved hashes ($($script:HashStats.hashed) hashed)"
    Assert ((Get-Snapshot $script:Lib) -eq $snap0 -and (Get-Dump) -eq $dump0) 'check-only changed nothing'

    Say '[2] -ExcludeNearDuplicates'
    $script:ExNear = $true; $p3 = Get-Plan $false; $script:ExNear = $false
    Assert ((Get-Dec $p3 'CatA\near\n1.mp4') -eq 'near/EXCLUDED' -and (Get-Dec $p3 'CatA\near\n1.jpg') -eq '(missing)') 'near-duplicate and its cover left out'
    Assert ((@($p3.folders | ForEach-Object { $_.rel } | Sort-Object) -join ',') -eq 'CatA\dup,CatB\y' -and @($p3.rows).Count -eq 5) 'CatA\near stays, 5 rows to delete'

    Say '[3] stale heartbeat'
    (Get-Item -LiteralPath $script:Heartbeat).LastWriteTime = (Get-Date).AddMinutes(-10)
    $e = Try-Run { Invoke-ApplyMain }
    Assert ($e -like 'refused*' -and @(Get-Runs).Count -eq 0 -and (Get-Snapshot $script:Lib) -eq $snap0) "apply refused, nothing changed ($e)"
    (Get-Item -LiteralPath $script:Heartbeat).LastWriteTime = Get-Date

    Say '[4] apply + rollback latest'
    $e = Try-Run { Invoke-ApplyMain }
    $run = @(Get-Runs)[-1]; $q = Join-Path $script:QRoot ('library-dupes-' + $run.stamp)
    Assert ($e -eq '' -and $run.state -eq 'done') "apply succeeded, state $($run.state) $e"
    Assert ((Test-Path -LiteralPath (Join-Path $q 'CatA\dup\thumbs')) -and (Test-Path -LiteralPath (Join-Path $q ('CatA\dup\' + $uName))) -and (Test-Path -LiteralPath (Join-Path $q 'CatA\mixed\v3.mp4')) -and (Test-Path -LiteralPath (Join-Path $q 'CatB\y\a.com'))) 'quarantine keeps the folder layout'
    Assert (-not (Test-Path -LiteralPath (Join-Path $script:Lib 'CatA\dup')) -and -not (Test-Path -LiteralPath (Join-Path $script:Lib 'CatB\y')) -and (Test-Path -LiteralPath (Join-Path $script:Lib 'CatA\mixed\v2.mp4')) -and (Test-Path -LiteralPath (Join-Path $script:Lib 'CatB\x\v5.mp4'))) 'emptied folders gone; SKIP/REPORT files stay'
    Assert (@(From-JsonArray (Get-Dump)).Count -eq 11) '6 rows deleted (11 left)'
    Assert ((Test-Path -LiteralPath (Join-Path $script:BRoot ($run.stamp + '\manifest.csv'))) -and (Test-Path -LiteralPath (Join-Path $script:BRoot ($run.stamp + '\rows.csv')))) 'manifest.csv and rows.csv written'
    $e = Try-Run { Invoke-RollbackMain 'latest' }
    Assert ($e -eq '') "rollback latest succeeded $e"
    Assert ((Get-Snapshot $script:Lib) -eq $snap0) 'files and folders identical to before'
    Assert ((Get-Dump) -eq $dump0) 'catalog rows identical to before'
    Assert (-not (Test-Path -LiteralPath $q)) 'empty quarantine folder removed'

    Say '[5] injected failures -> automatic rollback'
    foreach ($at in @('after-backup', 'mid-move', 'after-move', 'after-rows', 'verify')) {
      $script:FailAt = $at; $e = Try-Run { Invoke-ApplyMain }; $script:FailAt = ''
      $st = @(Get-Runs)[-1].state
      Assert ($e -like '*rolled back*' -and $st -eq 'rolled-back') "failure at $at -> state $st"
      Assert ((Get-Snapshot $script:Lib) -eq $snap0 -and (Get-Dump) -eq $dump0) "  files and rows identical after $at"
    }

    Say '[6] crash mid-move, then -Continue, then rollback'
    $script:FailAt = 'crash-mid-move'; $e = Try-Run { Invoke-ApplyMain }; $script:FailAt = ''
    Assert (@(Get-Runs)[-1].state -eq 'moving') "crash leaves state moving ($(@(Get-Runs)[-1].state))"
    $e2 = Try-Run { Invoke-ApplyMain }
    Assert ($e2 -like 'refused*unfinished*') 'a new -Apply refuses while a run is unfinished'
    $e = Try-Run { Invoke-ContinueMain 'latest' }
    Assert ($e -eq '' -and @(Get-Runs)[-1].state -eq 'done' -and @(From-JsonArray (Get-Dump)).Count -eq 11) "-Continue finished the run $e"
    $e = Try-Run { Invoke-RollbackMain 'latest' }
    Assert ($e -eq '' -and (Get-Snapshot $script:Lib) -eq $snap0 -and (Get-Dump) -eq $dump0) "rollback after continue: identical $e"

    Say '[7] collision on rollback'
    $e = Try-Run { Invoke-ApplyMain }; Assert ($e -eq '') 'apply succeeded'
    $coll = Join-Path $script:Lib 'CatA\mixed\v3.mp4'; [IO.File]::WriteAllBytes($coll, (Bytes 99 500))
    $s1 = (Get-Snapshot $script:Lib) + '##' + (Get-Snapshot $script:QRoot); $d1 = Get-Dump
    $e = Try-Run { Invoke-RollbackMain 'latest' }
    Assert ($e -like 'rollback refused*' -and $script:LastUndoRefused) 'rollback refused because v3.mp4 exists in both places'
    Assert (((Get-Snapshot $script:Lib) + '##' + (Get-Snapshot $script:QRoot)) -eq $s1 -and (Get-Dump) -eq $d1) '  nothing changed by the refused rollback'
    Remove-Item -LiteralPath $coll -Force
    $e = Try-Run { Invoke-RollbackMain 'latest' }
    Assert ($e -eq '' -and (Get-Snapshot $script:Lib) -eq $snap0 -and (Get-Dump) -eq $dump0) "rollback after removing the collider: identical $e"

    Say '[8] nothing left to roll back'
    $e = Try-Run { Invoke-RollbackMain 'latest' }
    Assert ($e -like '*nothing to do*') 'second rollback latest finds nothing to roll back'
    $pass = ($script:Fails -eq 0)
  } catch {
    Say "SELF-TEST ERROR: $($_.Exception.Message) $($_.InvocationInfo.PositionMessage)"; $pass = $false
  } finally {
    $script:Log = ''
    try { [void](Invoke-Helper @{ mode = 'drop' }) } catch { Say "  could not drop $script:Table : $($_.Exception.Message)" }
    if ($pass) { Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue } else { Say "  test files kept for inspection: $tmp" }
  }
  if ($pass) { Say 'SELF-TEST PASSED' } else { Say "SELF-TEST FAILED ($script:Fails failed check(s))" }
  return $pass
}

# ---------------------------------------------------------------- main
$code = 0
try {
  Say "Move-LibraryDuplicates  $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') (local time)"
  [void](Invoke-Helper @{ mode = 'ping' })
  if ($SelfTest) { if (-not (Invoke-SelfTest)) { $code = 1 } }
  elseif ($Rollback) { Invoke-RollbackMain $Rollback }
  elseif ($Continue) { Invoke-ContinueMain $Continue }
  elseif ($Apply) { Invoke-ApplyMain }
  else { [void](Invoke-Check); Say 'Check only - nothing was changed. To quarantine the MOVE rows: run again with -Apply' }
} catch {
  Say "ERROR: $($_.Exception.Message)"; $code = 2
} finally {
  if ($script:Jar -and (Test-Path -LiteralPath $script:Jar)) { Remove-Item -LiteralPath $script:Jar -Force -ErrorAction SilentlyContinue }
}
exit $code
