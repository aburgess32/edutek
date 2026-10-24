<#
Move-HistoryDuplicates.ps1  -  Edutek library: quarantine loose History files that duplicate a LIVE copy in History\History

Default: CHECK ONLY. It hashes, checks and writes a report to the Desktop. Nothing is changed.
  -Resume                   reuse SHA256 values saved by an earlier (or interrupted) check
  -IncludeDifferingThumbs   also quarantine same-name .jpg/.png thumbnails whose size differs, but only
                            when the loose video they belong to is quarantined too (default: report only)
  -Apply                    re-checks everything, then: backs up the catalog rows, moves the duplicates into
                            D:\edutek-system\quarantine\history-dupes-<stamp>\, deletes exactly those rows,
                            verifies over HTTP and rolls back by itself if anything fails
  -Rehash                   with -Apply: ignore the hash cache and hash everything again
  -Rollback latest|<stamp>  moves the files back, restores the rows exactly, verifies
  -SelfTest                 runs everything on throwaway copies and a scratch table (live data untouched)

A loose file is QUARANTINE only when ALL hold:
  * a file with the same name exists in History\History, same size, same SHA256 (both sides hashed);
  * videos: the History\History copy has a catalog row (category History / subcategory History) and
    answers over HTTP (Range GET -> 206/200, video/*);
  * images: not a folder card image (History.jpg / thumbs.jpg), not the thumbnail of a loose video that
    stays, not the cover of a loose PDF, not used as thumbnail_path by any remaining catalog row.
PDFs and covers with no copy are never touched. The 'thumbs' subfolder is never touched.
Never runs an index, never touches the held catalog cleanup. Windows PowerShell 5.1.
#>
[CmdletBinding()]
param(
  [switch]$Apply,
  [string]$Rollback = '',
  [switch]$SelfTest,
  [switch]$Resume,
  [switch]$Rehash,
  [switch]$IncludeDifferingThumbs,
  [string]$Library = 'D:\xampp\htdocs\Edutek\videos',
  [string]$BackupRoot = 'D:\edutek-system\backups\history-dupes',
  [string]$QuarantineRoot = 'D:\edutek-system\quarantine',
  [string]$CacheFile = 'D:\edutek-system\scratch\history-dupes-hashes.tsv',
  [string]$ReportDir = '',
  [string]$BaseUrl = 'http://localhost:8080',
  [string]$Container = 'edupak-app',
  [string]$DockerContext = 'desktop-linux',
  [string]$DockerExe = '',
  [string]$TriggerDir = 'D:\edutek-system\bulk-loader-fresh-v05\data\index-trigger',
  [int]$HeartbeatMaxAgeSec = 90,
  [int]$CacheMaxHours = 72,
  [int]$Threads = 4,
  [string]$FailAt = ''
)
Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$utf8 = New-Object System.Text.UTF8Encoding($false)
$Cat = 'History'; $Sub = 'History'
$VideoExt = @('.mp4', '.m4v', '.webm', '.mov', '.mkv', '.avi')
$ImageExt = @('.jpg', '.jpeg', '.png', '.webp', '.gif')
if (-not $ReportDir) { $ReportDir = [Environment]::GetFolderPath('Desktop') }

$script:Lib = $Library; $script:BRoot = $BackupRoot; $script:QRoot = $QuarantineRoot; $script:Cache = $CacheFile
$script:Report = $ReportDir; $script:Table = 'content_meta'; $script:Sim = $false; $script:SimHttpFail = @{}
$script:IDT = $IncludeDifferingThumbs.IsPresent; $script:LastUndoRefused = $false
$script:Heartbeat = Join-Path $TriggerDir 'indexer-heartbeat.txt'; $script:FailAt = $FailAt; $script:Log = ''
$script:HashStats = [pscustomobject]@{ cached = 0; hashed = 0; bytes = [int64]0; seconds = 0.0 }
$script:Curl = Join-Path $env:SystemRoot 'System32\curl.exe'
if (-not (Test-Path -LiteralPath $script:Curl)) { $script:Curl = 'curl.exe' }

function Say([string]$m) { Write-Host $m; if ($script:Log) { try { [IO.File]::AppendAllText($script:Log, $m + "`r`n", $utf8) } catch { } } }
function From-JsonArray([string]$t) { return @(($t | ConvertFrom-Json) | ForEach-Object { $_ }) }
function Check-Fail([string]$at) { if ($script:FailAt -and $script:FailAt -eq $at) { throw "injected test failure at $at" } }
function Fmt-GB([double]$b) { return ('{0:N2} GB' -f ($b / 1GB)) }
function Sum-Size($items) { $s = [int64]0; foreach ($x in @($items)) { $s += [int64]$x.size }; return $s }

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
    if (!preg_match('/^(content_meta|hd_selftest_[0-9a-f]{8})$/', $t)) { hd_out(['state' => 'error', 'message' => 'table not allowed']); }
    $scratch = (strpos($t, 'hd_selftest_') === 0);
    $mode = (string)($in['mode'] ?? '');
    $c7 = "id, content_id, file_path, category, subcategory, content_type, thumbnail_path";

    if ($mode === 'ping') { hd_out(['state' => 'ok', 'count' => (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()]); }

    if ($mode === 'live') {   /* read-only */
        $folder = (string)$in['folder']; $esc = addcslashes($folder, '\\%_');
        $sel = $pdo->prepare("SELECT $c7 FROM `$t` WHERE file_path = ?");
        $loose = []; foreach ($in['loose'] as $p) { $sel->execute([(string)$p]); foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) { $loose[] = $r; } }
        $copies = []; foreach ($in['copies'] as $p) { $sel->execute([(string)$p]); foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) { $copies[] = $r; } }
        $th = $pdo->prepare("SELECT id, file_path, thumbnail_path FROM `$t` WHERE thumbnail_path = ?");
        $thumbs = []; foreach ($in['loose'] as $p) { $th->execute([(string)$p]); foreach ($th->fetchAll(PDO::FETCH_ASSOC) as $r) { $thumbs[] = $r; } }
        $all = $pdo->prepare("SELECT id, file_path FROM `$t` WHERE file_path LIKE ? AND file_path NOT LIKE ? ORDER BY id");
        $all->execute([$esc . '%', $esc . '%/%']); $folderRows = $all->fetchAll(PDO::FETCH_ASSOC);
        $cc = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE category = ? AND subcategory = ? AND file_path LIKE ?");
        $cc->execute([(string)$in['cat'], (string)$in['sub'], addcslashes((string)$in['copy_folder'], '\\%_') . '%']); $copyCount = (int)$cc->fetchColumn();
        $refs = [];
        if (!$scratch && $loose) {
            $keys = []; foreach ($loose as $r) { $keys[] = (string)$r['content_id']; $keys[] = (string)$r['file_path']; } $keys = array_values(array_unique($keys));
            $rt = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('content_id','file_path','content_path') AND DATA_TYPE IN ('char','varchar','text','mediumtext','longtext','tinytext') AND TABLE_NAME <> 'content_meta' AND TABLE_NAME NOT LIKE '%selftest%'")->fetchAll(PDO::FETCH_NUM);
            foreach ($rt as $c) { foreach (array_chunk($keys, 200) as $ch) {
                $q = $pdo->prepare("SELECT `{$c[1]}` k, COUNT(*) n FROM `{$c[0]}` WHERE `{$c[1]}` IN (" . implode(',', array_fill(0, count($ch), '?')) . ") GROUP BY `{$c[1]}`");
                $q->execute($ch); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) { $refs[] = ['table' => $c[0] . '.' . $c[1], 'key' => $x['k'], 'n' => (int)$x['n']]; } } }
        }
        $url = '';
        if (!$scratch) { require_once '/var/www/html/includes/tiles.php'; $url = 'tutorials.php?&' . tileEncrypt('course') . '=' . tileEncrypt((string)$in['cat']); }
        hd_out(['state' => 'ok', 'loose' => $loose, 'copies' => $copies, 'thumbs' => $thumbs, 'folder_rows' => $folderRows, 'copy_count' => $copyCount, 'refs' => $refs, 'url' => $url]);
    }

    if ($mode === 'select') {
        $rows = []; $s = $pdo->prepare("SELECT * FROM `$t` WHERE id = ?");
        foreach (($in['ids'] ?? []) as $id) { $s->execute([(int)$id]); $r = $s->fetch(PDO::FETCH_ASSOC); if ($r) { $rows[(int)$r['id']] = $r; } }
        ksort($rows); hd_out(hd_rows($rows));
    }

    if ($mode === 'delete') {   /* exact ids + path + content_id, all or nothing */
        $pdo->beginTransaction(); $n = 0;
        $d = $pdo->prepare("DELETE FROM `$t` WHERE id = ? AND file_path = ? AND content_id = ?");
        foreach ($in['items'] as $it) {
            $d->execute([(int)$it['id'], (string)$it['file_path'], (string)$it['content_id']]);
            if ($d->rowCount() !== 1) { $pdo->rollBack(); hd_out(['state' => 'error', 'message' => 'row ' . $it['id'] . ' not found with the expected path; nothing deleted']); }
            $n++;
        }
        $pdo->commit(); hd_out(['state' => 'ok', 'deleted' => $n]);
    }

    if ($mode === 'restore') {   /* exact backed-up rows, all or nothing */
        $rows = json_decode(base64_decode((string)$in['rows_b64']), true);
        if (!is_array($rows)) { hd_out(['state' => 'error', 'message' => 'bad backup rows']); }
        $pdo->beginTransaction(); $ins = 0; $same = 0;
        $byId = $pdo->prepare("SELECT * FROM `$t` WHERE id = ?");
        $byPath = $pdo->prepare("SELECT id FROM `$t` WHERE (file_path = ? OR content_id = ?) AND id <> ?");
        foreach ($rows as $r) {
            foreach (array_keys($r) as $k) { if (!preg_match('/^[a-z_0-9]+$/', $k)) { $pdo->rollBack(); hd_out(['state' => 'error', 'message' => 'bad column in backup']); } }
            $byId->execute([(int)$r['id']]); $ex = $byId->fetch(PDO::FETCH_ASSOC);
            if ($ex) {
                if ($ex['file_path'] === $r['file_path'] && $ex['content_id'] === $r['content_id']) { $same++; continue; }
                $pdo->rollBack(); hd_out(['state' => 'error', 'message' => 'id ' . $r['id'] . ' is now used by another row; nothing restored']);
            }
            $byPath->execute([$r['file_path'], $r['content_id'], (int)$r['id']]); $o = $byPath->fetchColumn();
            if ($o !== false) { $pdo->rollBack(); hd_out(['state' => 'error', 'message' => 'the path of row ' . $r['id'] . ' is now in row ' . $o . '; nothing restored']); }
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
        $same = 0; $changed = []; $missing = [];
        foreach ($rows as $r) {
            $id = (int)$r['id']; $g->execute([$id]); $c = $g->fetch(PDO::FETCH_ASSOC);
            if (!$c) { $missing[] = $id; continue; }
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
        $n = 0; foreach ($in['items'] as $it) { $s->execute([(int)$it['id'], (string)$it['file_path'], (string)$it['file_path'], (string)$it['title'], (string)$it['category'], (string)$it['subcategory'], (string)$it['content_type'], ((string)$it['thumb'] === '' ? null : (string)$it['thumb'])]); $n++; }
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
function Invoke-Helper([hashtable]$in) {
  $in['table'] = $script:Table
  $json = $in | ConvertTo-Json -Depth 8 -Compress
  $code = $HelperPhp.Replace('__HD_INPUT__', [Convert]::ToBase64String($utf8.GetBytes($json)))
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @($code | & $script:Docker @script:DockerArgs exec -i $Container php 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
  $line = @($o | Where-Object { $_.StartsWith('HDR:') }) | Select-Object -Last 1
  if (-not $line) { throw ("DB helper ($($in.mode)) gave no result: " + ((@($o) | Select-Object -Last 4) -join ' / ')) }
  $res = $utf8.GetString([Convert]::FromBase64String($line.Substring(4).Trim())) | ConvertFrom-Json
  if ($res.state -ne 'ok') { throw ("DB helper ($($in.mode)): " + $res.message) }
  return $res
}
function Get-RowsText($res) { return $utf8.GetString([Convert]::FromBase64String($res.rows_b64)) }

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
    $script:Jar = Join-Path $env:TEMP ('hd-jar-' + [guid]::NewGuid().ToString('N') + '.txt')
    [void](Invoke-Curl @('-s', '-g', '-o', 'NUL', '-c', $script:Jar, '-b', $script:Jar, "$BaseUrl/guest.php"))
  }
  return $script:Jar
}
function Get-Page([string]$rel) {
  $jar = Get-Jar
  $f = Join-Path $env:TEMP ('hd-page-' + [guid]::NewGuid().ToString('N') + '.html')
  $code = Invoke-Curl @('-s', '-g', '-b', $jar, '-c', $jar, '-o', $f, '-w', '%{http_code}', "$BaseUrl/$rel")
  $body = ''; if (Test-Path -LiteralPath $f) { $body = [IO.File]::ReadAllText($f, $utf8); Remove-Item -LiteralPath $f -Force }
  return [pscustomobject]@{ code = $code; body = $body }
}
function Encode-Path([string]$p) { return ((@($p -split '/') | ForEach-Object { [Uri]::EscapeDataString($_) }) -join '/') }
function Test-Media([string]$urlPath) {
  # urlPath like /content/History/History/<encoded name>; ranged GET of the first 1 KB
  if ($script:Sim) {
    $name = [Uri]::UnescapeDataString(($urlPath -split '/')[-1])
    if ($script:SimHttpFail.ContainsKey($name)) { return [pscustomobject]@{ ok = $false; info = '404|text/html (simulated)' } }
    return [pscustomobject]@{ ok = $true; info = '206|video/mp4 (simulated)' }
  }
  $jar = Get-Jar
  $r = Invoke-Curl @('-s', '-g', '-b', $jar, '-o', 'NUL', '-r', '0-1023', '-w', '%{http_code}|%{content_type}', "$BaseUrl$urlPath")
  $ok = ($r -match '^(206|200)\|video/')
  return [pscustomobject]@{ ok = $ok; info = $r }
}
function Get-HistoryPage([string]$url) {
  # returns the 'History' (History\History) card and the loose 'History' card of the History page
  $pg = Get-Page $url
  $res = [pscustomobject]@{ code = $pg.code; copy = $null; loose = $null; body = $pg.body }
  if ($pg.code -ne '200') { return $res }
  $parts = [regex]::Split($pg.body, '(?=<div class="tut-section[ "])')
  foreach ($s in $parts) {
    $m = [regex]::Match($s, 'tut-section-title">([^<]*)</p>\s*<div class="tut-section-count">(\d+)\s+(\w+)')
    if (-not $m.Success) { continue }
    $title = [Net.WebUtility]::HtmlDecode($m.Groups[1].Value).Trim()
    $links = @([regex]::Matches($s, 'href="(watch\.php[^"]*)"') | ForEach-Object { [Net.WebUtility]::HtmlDecode($_.Groups[1].Value) })
    $pdfs = @([regex]::Matches($s, 'href="readpdf\.php')).Count
    $o = [pscustomobject]@{ title = $title; count = [int]$m.Groups[2].Value; unit = $m.Groups[3].Value; links = $links; pdfs = $pdfs }
    if ($s -match 'class="tut-section tut-section--loose') { if ($title -eq $Cat) { $res.loose = $o } }
    elseif ($title -eq $Sub -and -not $res.copy) { $res.copy = $o }
  }
  return $res
}
function Test-WatchSamples($links, [int]$n) {
  $out = @()
  $list = @($links); if ($list.Count -eq 0) { return $out }
  $idx = @(0, [int]($list.Count / 2), ($list.Count - 1)) | Select-Object -Unique | Select-Object -First $n
  foreach ($i in $idx) {
    $w = Get-Page $list[$i]
    $m = [regex]::Match($w.body, 'id="wp-video"\s+src="([^"]+)"')
    if ($w.code -ne '200' -or -not $m.Success) { $out += [pscustomobject]@{ link = $list[$i]; ok = $false; info = "watch page $($w.code), no video tag" }; continue }
    $src = [Net.WebUtility]::HtmlDecode($m.Groups[1].Value); if (-not $src.StartsWith('/')) { $src = '/' + $src }
    $t = Test-Media $src
    $out += [pscustomobject]@{ link = $list[$i]; ok = $t.ok; info = $t.info }
  }
  return $out
}
function Get-PrePage([string]$url) {
  if ($script:Sim) { return [pscustomobject]@{ ok = $true; copyCount = 0; looseCount = 0; looseWatch = 0; loosePdf = 0; text = 'page check skipped (self-test)' } }
  $pg = Get-HistoryPage $url
  $ok = ($pg.code -eq '200' -and $pg.copy -and $pg.copy.count -gt 0)
  $r = [pscustomobject]@{ ok = $ok; copyCount = 0; looseCount = 0; looseWatch = 0; loosePdf = 0; text = "History page HTTP $($pg.code)" }
  if ($pg.copy) { $r.copyCount = $pg.copy.count; $r.text += "; History\History card: $($pg.copy.count) $($pg.copy.unit), $($pg.copy.links.Count) watch links" }
  else { $r.text += '; History\History card NOT found' }
  if ($pg.loose) { $r.looseCount = $pg.loose.count; $r.looseWatch = $pg.loose.links.Count; $r.loosePdf = $pg.loose.pdfs; $r.text += "; loose 'History' card: $($pg.loose.count) $($pg.loose.unit) ($($pg.loose.links.Count) videos, $($pg.loose.pdfs) PDFs)" }
  else { $r.text += "; no loose 'History' card" }
  return $r
}
function Test-PageState([string]$url, [int]$copyCount, [int]$looseCount, [int]$looseWatch, [int]$loosePdf) {
  # returns a list of problems (empty = fine)
  $f = @()
  if ($script:Sim) { Check-Fail 'verify'; return $f }
  $pg = Get-HistoryPage $url
  if ($pg.code -ne '200') { return @("History page answered HTTP $($pg.code)") }
  if (-not $pg.copy) { $f += 'History\History card is missing' }
  else {
    if ($pg.copy.count -ne $copyCount) { $f += "History\History card shows $($pg.copy.count), expected $copyCount" }
    if ($pg.copy.links.Count -ne $copyCount) { $f += "History\History card has $($pg.copy.links.Count) watch links, expected $copyCount" }
    $play = @(Test-WatchSamples $pg.copy.links 3)
    if ($play.Count -eq 0) { $f += 'no History\History video could be sampled' }
    foreach ($x in @($play | Where-Object { -not $_.ok })) { $f += "History\History video does not play: $($x.link) ($($x.info))" }
    if ($play.Count) { Say ("  playback samples: " + ((@($play) | ForEach-Object { $_.info }) -join ', ')) }
  }
  if ($looseCount -gt 0) {
    if (-not $pg.loose) { $f += "loose 'History' card is missing (expected $looseCount items)" }
    else {
      if ($pg.loose.count -ne $looseCount) { $f += "loose 'History' card shows $($pg.loose.count) items, expected $looseCount" }
      if ($pg.loose.links.Count -ne $looseWatch) { $f += "loose 'History' card has $($pg.loose.links.Count) video links, expected $looseWatch" }
      if ($pg.loose.pdfs -ne $loosePdf) { $f += "loose 'History' card has $($pg.loose.pdfs) PDF links, expected $loosePdf" }
    }
  } elseif ($pg.loose -and $pg.loose.count -gt 0) { $f += "loose 'History' card still shows $($pg.loose.count) items, expected none" }
  Check-Fail 'verify'
  return $f
}

# ---------------------------------------------------------------- SHA256 with progress, parallel, cached
$HashScript = {
  param([string]$path)
  try {
    $sha = New-Object System.Security.Cryptography.SHA256CryptoServiceProvider
    $fs = New-Object System.IO.FileStream($path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read, 4194304, [IO.FileOptions]::SequentialScan)
    try { $h = $sha.ComputeHash($fs) } finally { $fs.Dispose(); $sha.Dispose() }
    return ([BitConverter]::ToString($h) -replace '-', '')
  } catch { return ('ERROR: ' + $_.Exception.Message) }
}
function Read-HashCache([bool]$checkAge) {
  $c = @{}
  if (-not (Test-Path -LiteralPath $script:Cache)) { return $c }
  $minTicks = (Get-Date).ToUniversalTime().AddHours(-$CacheMaxHours).Ticks
  foreach ($l in [IO.File]::ReadAllLines($script:Cache, $utf8)) {
    $p = $l -split "`t", 5
    if ($p.Count -lt 5 -or $p[0] -notmatch '^[0-9A-F]{64}$') { continue }
    if ($checkAge -and [int64]$p[3] -lt $minTicks) { continue }
    $c[$p[4]] = [pscustomobject]@{ sha = $p[0]; size = [int64]$p[1]; mtime = [int64]$p[2] }
  }
  return $c
}
function Get-Hashes($files, [bool]$useCache, [bool]$checkAge) {
  # $files: FileInfo objects. returns hashtable FullName -> SHA256 (or 'ERROR: ...')
  $res = @{}; $todo = @()
  $cache = @{}; if ($useCache) { $cache = Read-HashCache $checkAge }
  foreach ($f in @($files)) {
    $k = $f.FullName; if ($res.ContainsKey($k)) { continue }
    if ($cache.ContainsKey($k) -and $cache[$k].size -eq $f.Length -and $cache[$k].mtime -eq $f.LastWriteTimeUtc.Ticks) { $res[$k] = $cache[$k].sha }
    else { $res[$k] = $null; $todo += $f }
  }
  $script:HashStats.cached = $res.Count - $todo.Count; $script:HashStats.hashed = $todo.Count
  $total = [int64]0; foreach ($f in $todo) { $total += $f.Length }
  $script:HashStats.bytes = $total
  Say ("  SHA256: " + $script:HashStats.cached + " from cache, " + $todo.Count + " to hash (" + (Fmt-GB $total) + ", $Threads threads)")
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
      Start-Sleep -Milliseconds 200
      foreach ($j in @($inflight | Where-Object { $_.h.IsCompleted })) {
        $o = $j.ps.EndInvoke($j.h); $j.ps.Dispose(); $inflight.Remove($j)
        $h = ''; if ($o.Count -gt 0) { $h = [string]$o[0] }
        if (-not $h) { $h = 'ERROR: no result' }
        $res[$j.f.FullName] = $h; $done += $j.f.Length; $n++
        if ($h -match '^[0-9A-F]{64}$') {
          [IO.File]::AppendAllText($script:Cache, ($h + "`t" + $j.f.Length + "`t" + $j.f.LastWriteTimeUtc.Ticks + "`t" + (Get-Date).ToUniversalTime().Ticks + "`t" + $j.f.FullName + "`r`n"), $utf8)
        }
      }
      $sec = $sw.Elapsed.TotalSeconds
      if ($total -gt 0) { Write-Progress -Activity 'SHA256' -Status ("$n / $($todo.Count) files, " + (Fmt-GB $done) + ' of ' + (Fmt-GB $total)) -PercentComplete ([Math]::Min(100, [int](100 * $done / $total))) }
      if ($sec - $lastSay -ge 15) {
        $lastSay = $sec; $rate = 0; if ($sec -gt 0) { $rate = $done / 1MB / $sec }
        $eta = ''; if ($rate -gt 0) { $eta = ', about ' + [int](($total - $done) / 1MB / $rate) + ' s left' }
        Say ("    hashed $n / $($todo.Count) files, " + (Fmt-GB $done) + ' of ' + (Fmt-GB $total) + (' ({0:N0} MB/s' -f $rate) + $eta + ')')
      }
    }
  } finally {
    foreach ($j in @($inflight)) { try { $j.ps.Stop(); $j.ps.Dispose() } catch { } }
    $pool.Close(); $pool.Dispose(); Write-Progress -Activity 'SHA256' -Completed
  }
  $script:HashStats.seconds = [Math]::Round($sw.Elapsed.TotalSeconds, 1)
  Say ("  SHA256 done: $n file(s), " + (Fmt-GB $total) + " in " + $script:HashStats.seconds + ' s')
  return $res
}

# ---------------------------------------------------------------- plan (read-only)
function New-OrdinalMap { return (New-Object System.Collections.Hashtable ([StringComparer]::Ordinal)) }
function Get-Kind([string]$name) {
  $e = [IO.Path]::GetExtension($name).ToLowerInvariant()
  if ($VideoExt -contains $e) { return 'video' }
  if ($ImageExt -contains $e) { return 'image' }
  if ($e -eq '.pdf') { return 'pdf' }
  return 'other'
}
function Get-Plan([bool]$useCache, [bool]$checkAge) {
  $ld = Join-Path $script:Lib $Cat; $cd = Join-Path $ld $Sub
  if (-not (Test-Path -LiteralPath $cd)) { throw "folder not found: $cd" }
  $prefix = "content/$Cat/"; $cprefix = "content/$Cat/$Sub/"
  $files = @(Get-ChildItem -LiteralPath $ld -File -Force | Sort-Object Name)
  $subdirs = @{}; foreach ($d in @(Get-ChildItem -LiteralPath $ld -Directory -Force)) { $subdirs[$d.Name.ToLowerInvariant()] = $true }
  $copyFiles = @{}; foreach ($f in @(Get-ChildItem -LiteralPath $cd -File -Force)) { $copyFiles[$f.Name] = $f }
  $items = @()
  foreach ($f in $files) {
    $c = $null; if ($copyFiles.ContainsKey($f.Name)) { $c = $copyFiles[$f.Name] }
    $items += [pscustomobject]@{ file = $f.Name; kind = (Get-Kind $f.Name); size = [int64]$f.Length; decision = ''; reason = ''
      sha256 = ''; copy_sha256 = ''; copy_size = $(if ($c) { [int64]$c.Length } else { '' }); loose_row_ids = ''; copy_row_id = ''; http = ''
      rel = ($prefix + $f.Name); copy_rel = ($cprefix + $f.Name); fi = $f; copy = $c; rows = @() }
  }
  $live = Invoke-Helper @{ mode = 'live'; loose = @($items | ForEach-Object { $_.rel }); copies = @($items | Where-Object { $_.copy } | ForEach-Object { $_.copy_rel })
    folder = $prefix; copy_folder = $cprefix; cat = $Cat; sub = $Sub }
  $looseRows = New-OrdinalMap
  foreach ($r in @($live.loose)) { $k = [string]$r.file_path; if (-not $looseRows.ContainsKey($k)) { $looseRows[$k] = @() }; $looseRows[$k] = @($looseRows[$k]) + $r }
  $copyRows = New-OrdinalMap
  foreach ($r in @($live.copies)) { if ([string]$r.category -ceq $Cat -and [string]$r.subcategory -ceq $Sub) { $copyRows[[string]$r.file_path] = $r } }
  $notes = New-Object System.Collections.ArrayList; $problems = New-Object System.Collections.ArrayList
  foreach ($it in $items) {
    if ($looseRows.ContainsKey($it.rel)) { $it.rows = @($looseRows[$it.rel]); $it.loose_row_ids = ((@($it.rows) | ForEach-Object { $_.id }) -join ' ') }
    if (@($it.rows).Count -gt 1) { [void]$notes.Add("$($it.file) has $(@($it.rows).Count) catalog rows ($($it.loose_row_ids))") }
  }
  # pass 1: names and sizes
  foreach ($it in $items) {
    $stem = [IO.Path]::GetFileNameWithoutExtension($it.file)
    if ($it.kind -eq 'image' -and $subdirs.ContainsKey($stem.ToLowerInvariant())) { $it.decision = 'KEEP'; $it.reason = 'folder card image (same name as a subfolder)' }
    elseif (-not $it.copy) { $it.decision = 'NEVER'; $it.reason = "no same-name file in $Cat\$Sub; never touched" }
    elseif ($it.kind -ne 'video' -and $it.kind -ne 'image') { $it.decision = 'KEEP'; $it.reason = 'only videos and images are handled' }
    elseif ($it.size -ne $it.copy.Length) {
      if ($it.kind -eq 'image') { $it.decision = 'REPORT'; $it.reason = 'same name, different size (report only)' }
      else { $it.decision = 'KEEP'; $it.reason = 'same name but different size' }
    } else { $it.decision = 'HASH' }
  }
  # pass 2: SHA256 on both sides
  $toHash = @(); foreach ($it in @($items | Where-Object { $_.decision -eq 'HASH' })) { $toHash += $it.fi; $toHash += $it.copy }
  $h = @{}; if ($toHash.Count) { $h = Get-Hashes $toHash $useCache $checkAge } else { $script:HashStats.cached = 0; $script:HashStats.hashed = 0 }
  foreach ($it in @($items | Where-Object { $_.decision -eq 'HASH' })) {
    $it.sha256 = [string]$h[$it.fi.FullName]; $it.copy_sha256 = [string]$h[$it.copy.FullName]
    if ($it.sha256 -notmatch '^[0-9A-F]{64}$' -or $it.copy_sha256 -notmatch '^[0-9A-F]{64}$') { $it.decision = 'KEEP'; $it.reason = 'could not hash: ' + $it.sha256 + ' ' + $it.copy_sha256 }
    elseif ($it.sha256 -ne $it.copy_sha256) { $it.decision = 'KEEP'; $it.reason = 'same name and size but SHA256 differs' }
    elseif ($it.kind -eq 'image') { $it.decision = 'IMG' }
    else { $it.decision = 'VID' }
  }
  # pass 3: videos need a live copy (catalog row + HTTP)
  foreach ($it in @($items | Where-Object { $_.decision -eq 'VID' })) {
    if (-not $copyRows.ContainsKey($it.copy_rel)) { $it.decision = 'KEEP'; $it.reason = "identical, but the $Cat\$Sub copy has no catalog row in $Cat/$Sub"; continue }
    $it.copy_row_id = [string]$copyRows[$it.copy_rel].id
    $t = Test-Media ('/' + (Encode-Path $it.copy_rel)); $it.http = $t.info
    if (-not $t.ok) { $it.decision = 'KEEP'; $it.reason = "identical, but the $Cat\$Sub copy does not play over HTTP"; continue }
    $it.decision = 'QUARANTINE'; $it.reason = "identical (SHA256) to the live $Cat\$Sub copy (catalog row + HTTP OK)"
    if (@($it.rows).Count -eq 0) { $it.reason += '; loose file has no catalog row' }
  }
  # pass 4: images
  $deleting = @{}; foreach ($it in @($items | Where-Object { $_.decision -eq 'QUARANTINE' })) { foreach ($r in @($it.rows)) { $deleting[[string]$r.id] = $true } }
  $thumbRefs = New-OrdinalMap
  foreach ($r in @($live.thumbs)) { if (-not $deleting.ContainsKey([string]$r.id)) { $k = [string]$r.thumbnail_path; if (-not $thumbRefs.ContainsKey($k)) { $thumbRefs[$k] = @() }; $thumbRefs[$k] = @($thumbRefs[$k]) + [string]$r.id } }
  $videoByStem = @{}; $docStems = @{}
  foreach ($it in $items) {
    $s = [IO.Path]::GetFileNameWithoutExtension($it.file).ToLowerInvariant()
    if ($it.kind -eq 'video') { $videoByStem[$s] = $it } elseif ($it.kind -ne 'image') { $docStems[$s] = $true }
  }
  foreach ($it in @($items | Where-Object { $_.decision -eq 'IMG' -or $_.decision -eq 'REPORT' })) {
    $s = [IO.Path]::GetFileNameWithoutExtension($it.file).ToLowerInvariant()
    $v = $null; if ($videoByStem.ContainsKey($s)) { $v = $videoByStem[$s] }
    if ($thumbRefs.ContainsKey($it.rel)) { $it.decision = 'KEEP'; $it.reason = 'used as thumbnail by remaining catalog row(s) ' + (@($thumbRefs[$it.rel]) -join ' ') }
    elseif ($docStems.ContainsKey($s)) { $it.decision = 'KEEP'; $it.reason = 'cover of a loose PDF/document' }
    elseif ($v -and $v.decision -ne 'QUARANTINE') { $it.decision = 'KEEP'; $it.reason = 'thumbnail of a loose video that stays' }
    elseif ($it.decision -eq 'REPORT') {
      if ($script:IDT -and $v -and $v.decision -eq 'QUARANTINE') { $it.decision = 'QUARANTINE'; $it.reason = 'same name, different size; thumbnail of a quarantined video (-IncludeDifferingThumbs)' }
    }
    else { $it.decision = 'QUARANTINE'; $it.reason = "identical (SHA256) to the $Cat\$Sub copy" }
  }
  foreach ($it in @($items | Where-Object { $_.decision -eq 'HASH' -or $_.decision -eq 'IMG' -or $_.decision -eq 'VID' -or -not $_.decision })) { $it.decision = 'KEEP'; $it.reason = 'internal: undecided' ; [void]$problems.Add("undecided: $($it.file)") }
  # notes
  $onDisk = New-OrdinalMap; foreach ($it in $items) { $onDisk[$it.rel] = $true }
  $orphan = @(@($live.folder_rows) | Where-Object { -not $onDisk.ContainsKey([string]$_.file_path) })
  if ($orphan.Count) { [void]$notes.Add("$($orphan.Count) catalog row(s) in $prefix have no file (e.g. id $($orphan[0].id) $($orphan[0].file_path)); not touched") }
  $noRow = @($items | Where-Object { $_.kind -eq 'video' -and @($_.rows).Count -eq 0 })
  if ($noRow.Count) { [void]$notes.Add("$($noRow.Count) loose video(s) have no catalog row (e.g. $($noRow[0].file))") }
  $refSum = @{}
  foreach ($x in @($live.refs)) { $t = [string]$x.table; if (-not $refSum.ContainsKey($t)) { $refSum[$t] = 0 }; $refSum[$t] += [int]$x.n }
  foreach ($t in @($refSum.Keys | Sort-Object)) { [void]$notes.Add("$t references loose History rows $($refSum[$t]) time(s) (history/log data; left as is)") }
  return [pscustomobject]@{ items = $items; notes = @($notes); problems = @($problems); copyCount = [int]$live.copy_count; url = [string]$live.url
    looseRowCount = @($live.folder_rows).Count; copyFileCount = $copyFiles.Count }
}
function Get-Todo($p) { return @($p.items | Where-Object { $_.decision -eq 'QUARANTINE' }) }
function Get-TodoRows($p) { $r = @(); foreach ($it in (Get-Todo $p)) { $r += @($it.rows) }; return $r }
function Show-Plan($p) {
  $all = @($p.items)
  Say ''
  Say ("Loose files in $Cat\: " + $all.Count + "   ($Cat\$Sub has " + $p.copyFileCount + " files, " + $p.copyCount + " catalog rows)")
  foreach ($d in @('QUARANTINE', 'REPORT', 'KEEP', 'NEVER')) {
    $g = @($all | Where-Object { $_.decision -eq $d }); if ($g.Count -eq 0) { continue }
    $kinds = (@($g | Group-Object kind | Sort-Object Name | ForEach-Object { "$($_.Count) $($_.Name)" }) -join ', ')
    Say (('  {0,-11} {1,4} files  {2,10}   ({3})' -f $d, $g.Count, (Fmt-GB (Sum-Size $g)), $kinds))
    foreach ($r in @($g | Group-Object reason | Sort-Object Count -Descending)) { Say (('                {0,4}  {1}' -f $r.Count, $r.Name)) }
  }
  $rows = @(Get-TodoRows $p)
  Say ("  catalog rows that -Apply would delete: " + $rows.Count + " (loose '$Cat' rows of the quarantined files; the $Cat\$Sub rows stay)")
  foreach ($n in @($p.notes)) { Say "  note: $n" }
  foreach ($n in @($p.problems)) { Say "  PROBLEM: $n" }
}
function Export-Plan($p, [string]$path) {
  @($p.items) | Select-Object file, kind, size, decision, reason, sha256, copy_sha256, copy_size, loose_row_ids, copy_row_id, http |
    Export-Csv -LiteralPath $path -NoTypeInformation -Encoding UTF8
}

# ---------------------------------------------------------------- journal / backup files
$script:StampOverride = ''
function New-Stamp { if ($script:StampOverride) { return $script:StampOverride }; return (Get-Date -Format 'yyyyMMdd-HHmmss') }
function Save-Journal([string]$bk, $J) { [IO.File]::WriteAllText((Join-Path $bk 'journal.json'), ($J | ConvertTo-Json -Depth 6), $utf8) }
function Read-Journal([string]$bk) { return ([IO.File]::ReadAllText((Join-Path $bk 'journal.json'), $utf8) | ConvertFrom-Json) }
function Read-Manifest([string]$bk) {
  $f = Join-Path $bk 'manifest.json'; if (-not (Test-Path -LiteralPath $f)) { return @() }
  return (From-JsonArray ([IO.File]::ReadAllText($f, $utf8)))
}
function Get-BackupRowsText([string]$bk) {
  $f = Join-Path $bk 'rows-history-loose.json'; if (-not (Test-Path -LiteralPath $f)) { return '' }
  return [IO.File]::ReadAllText($f, $utf8)
}
function Get-RunDirs {
  if (-not (Test-Path -LiteralPath $script:BRoot)) { return @() }
  return @(Get-ChildItem -LiteralPath $script:BRoot -Directory | Where-Object { $_.Name -match '^\d{8}-\d{6}$' -and (Test-Path -LiteralPath (Join-Path $_.FullName 'journal.json')) } | Sort-Object Name -Descending)
}
function Resolve-BackupDir([string]$which) {
  if ($which -eq 'latest') {
    foreach ($d in (Get-RunDirs)) { $s = [string](Read-Journal $d.FullName).state; if (@('rolled-back', 'auto-rolled-back', 'backing-up') -notcontains $s) { return $d.FullName } }
    return $null
  }
  $p = Join-Path $script:BRoot $which
  if ($which -match '^\d{8}-\d{6}$' -and (Test-Path -LiteralPath (Join-Path $p 'journal.json'))) { return $p }
  return $null
}

# ---------------------------------------------------------------- apply
function Invoke-Apply {
  $hb = Get-HeartbeatProblem
  if ($hb) { Say "REFUSED: $hb. Start the partial indexer (or wait until it is running) and try again. Nothing was changed."; return $false }
  foreach ($d in (Get-RunDirs)) {
    $s = [string](Read-Journal $d.FullName).state
    if (@('moving', 'moved', 'rows-deleted', 'verifying', 'rolling-back', 'failed', 'auto-rollback-FAILED', 'rollback-FAILED') -contains $s) {
      Say "REFUSED: earlier run $($d.Name) is in state '$s'. Run -Rollback $($d.Name) first. Nothing was changed."; return $false
    }
  }
  Say 'Re-checking everything before changing anything ...'
  $p = Get-Plan (-not $Rehash.IsPresent) $true
  if (@($p.problems).Count) { Show-Plan $p; Say 'REFUSED: the check found problems (see above). Nothing was changed.'; return $false }
  $todo = @(Get-Todo $p)
  if ($todo.Count -eq 0) { Show-Plan $p; Say 'Nothing to do - no loose duplicates qualify for quarantine.'; return $true }
  $pre = Get-PrePage $p.url
  Say ("  page before: " + $pre.text)
  if (-not $pre.ok) { Say 'REFUSED: the History page / History\History card does not look right before the change. Nothing was changed.'; return $false }

  $stamp = New-Stamp; $bk = Join-Path $script:BRoot $stamp; $qd = Join-Path $script:QRoot ("history-dupes-$stamp")
  if (Test-Path -LiteralPath $bk) { throw "backup folder already exists: $bk" }
  if (Test-Path -LiteralPath $qd) { throw "quarantine folder already exists: $qd" }
  [void](New-Item -ItemType Directory -Path $bk -Force)
  $script:Log = Join-Path $bk 'log.txt'
  Say "Apply $stamp  ($(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') local time)"
  Show-Plan $p
  Export-Plan $p (Join-Path $bk 'decisions.csv')
  $rows = @(Get-TodoRows $p); $ids = @($rows | ForEach-Object { [int]$_.id })
  $vidRows = 0; foreach ($it in $todo) { if ($it.kind -eq 'video') { $vidRows += @($it.rows).Count } }
  $man = @(); foreach ($it in $todo) {
    $man += [pscustomobject]([ordered]@{ file = $it.file; kind = $it.kind; src = $it.fi.FullName; dst = (Join-Path $qd $it.file); size = [int64]$it.size
      mtime = [int64]$it.fi.LastWriteTimeUtc.Ticks; sha256 = $it.sha256; row_ids = $it.loose_row_ids })
  }
  [IO.File]::WriteAllText((Join-Path $bk 'manifest.json'), (ConvertTo-Json -InputObject @($man) -Depth 4), $utf8)
  $man | Export-Csv -LiteralPath (Join-Path $bk 'manifest.csv') -NoTypeInformation -Encoding UTF8
  $J = [ordered]@{ stamp = $stamp; state = 'backing-up'; table = $script:Table; library = $script:Lib; quarantine = $qd; url = $p.url
    before = [ordered]@{ copyCount = $p.copyCount; pageCopy = $pre.copyCount; looseCount = $pre.looseCount; looseWatch = $pre.looseWatch; loosePdf = $pre.loosePdf }
    files = $man.Count; rows = $ids.Count; moved = @(); rows_deleted = $false; started = (Get-Date).ToString('s'); finished = ''; error = '' }
  Save-Journal $bk $J
  try {
    if ($ids.Count) {
      $sel = Invoke-Helper @{ mode = 'select'; ids = $ids }
      if ([int]$sel.count -ne $ids.Count) { throw "row backup found $($sel.count) of $($ids.Count) rows" }
      $txt = Get-RowsText $sel
      [IO.File]::WriteAllText((Join-Path $bk 'rows-history-loose.json'), $txt, $utf8)
      (From-JsonArray $txt) | Export-Csv -LiteralPath (Join-Path $bk 'rows-history-loose.csv') -NoTypeInformation -Encoding UTF8
    } else { [IO.File]::WriteAllText((Join-Path $bk 'rows-history-loose.json'), '[]', $utf8) }
    Say ("  backed up " + $ids.Count + " catalog row(s) and the list of " + $man.Count + " file(s) to $bk")
    Check-Fail 'after-backup'

    $J.state = 'moving'; Save-Journal $bk $J
    [void](New-Item -ItemType Directory -Path $qd -Force)
    $i = 0; $half = [int][Math]::Floor($man.Count / 2)
    foreach ($m in $man) {
      $fi = Get-Item -LiteralPath $m.src
      if ($fi.Length -ne $m.size -or $fi.LastWriteTimeUtc.Ticks -ne $m.mtime) { throw "file changed since the check: $($m.file)" }
      if (Test-Path -LiteralPath $m.dst) { throw "already in quarantine: $($m.dst)" }
      [IO.File]::Move($m.src, $m.dst)
      $J.moved = @($J.moved) + $m.file; $i++
      if ($i % 25 -eq 0 -or $i -eq $man.Count) { Save-Journal $bk $J; Say "  moved $i / $($man.Count)" }
      if ($i -eq $half) { Check-Fail 'mid-move' }
    }
    $J.state = 'moved'; Save-Journal $bk $J
    Check-Fail 'after-move'

    if ($ids.Count) {
      $items = @($rows | ForEach-Object { @{ id = [int]$_.id; file_path = [string]$_.file_path; content_id = [string]$_.content_id } })
      $del = Invoke-Helper @{ mode = 'delete'; items = $items }
      $J.rows_deleted = $true; $J.state = 'rows-deleted'; Save-Journal $bk $J
      Say "  deleted $($del.deleted) catalog row(s)"
    }
    Check-Fail 'after-rows'

    $J.state = 'verifying'; Save-Journal $bk $J
    $bad = @()
    foreach ($m in $man) {
      if (Test-Path -LiteralPath $m.src) { $bad += "still in the library: $($m.file)" }
      if (-not (Test-Path -LiteralPath $m.dst)) { $bad += "missing in quarantine: $($m.file)" }
      elseif ((Get-Item -LiteralPath $m.dst).Length -ne $m.size) { $bad += "size changed in quarantine: $($m.file)" }
    }
    if ($ids.Count) {
      $cmp = Invoke-Helper @{ mode = 'compare'; rows_b64 = [Convert]::ToBase64String($utf8.GetBytes((Get-BackupRowsText $bk))) }
      if (@($cmp.missing).Count -ne $ids.Count) { $bad += "only $(@($cmp.missing).Count) of $($ids.Count) rows are gone" }
    }
    $lv = Invoke-Helper @{ mode = 'live'; loose = @(); copies = @(); folder = "content/$Cat/"; copy_folder = "content/$Cat/$Sub/"; cat = $Cat; sub = $Sub }
    if ([int]$lv.copy_count -ne $p.copyCount) { $bad += "History\History catalog rows: $($lv.copy_count), expected $($p.copyCount)" }
    $bad += @(Test-PageState $p.url $pre.copyCount ($pre.looseCount - $ids.Count) ($pre.looseWatch - $vidRows) $pre.loosePdf)
    if ($bad.Count) { throw ("verification failed: " + ($bad -join '; ')) }
    $J.state = 'done'; $J.finished = (Get-Date).ToString('s'); Save-Journal $bk $J
    Say ''
    Say ("DONE: moved " + $man.Count + " file(s) (" + (Fmt-GB (Sum-Size $man)) + ") to $qd and deleted " + $ids.Count + " catalog row(s).")
    Say "  backup + journal: $bk"
    Say "  undo: powershell -ExecutionPolicy Bypass -File `"$PSCommandPath`" -Rollback $stamp"
    return $true
  } catch {
    $msg = "$($_.Exception.Message)"
    Say "FAILED: $msg"
    $J.error = $msg; $J.state = 'failed'; Save-Journal $bk $J
    Invoke-AutoRollback $bk
    return $false
  }
}
function Invoke-AutoRollback([string]$bk) {
  Say 'Rolling back automatically ...'
  $ok = $false
  try { $ok = Invoke-Undo $bk } catch { Say "  rollback error: $($_.Exception.Message)" }
  $J = Read-Journal $bk
  if ($ok) { $J.state = 'auto-rolled-back'; Say 'Automatic rollback complete: files and catalog rows are back as before.' }
  else { $J.state = 'auto-rollback-FAILED'; Say "AUTOMATIC ROLLBACK DID NOT FINISH. Run -Rollback $(Split-Path -Leaf $bk) and check $bk\log.txt" }
  Save-Journal $bk $J
}

# ---------------------------------------------------------------- undo (idempotent)
function Test-BackupUnchanged([string]$bk, $man) {
  $bad = @()
  foreach ($m in @($man)) {
    if (-not (Test-Path -LiteralPath $m.src)) { $bad += "not back in the library: $($m.file)" }
    elseif ((Get-Item -LiteralPath $m.src).Length -ne [int64]$m.size) { $bad += "size differs: $($m.file)" }
  }
  $txt = Get-BackupRowsText $bk
  $n = 0; if ($txt) { $n = @(From-JsonArray $txt).Count }
  if ($n -gt 0) {
    $cmp = Invoke-Helper @{ mode = 'compare'; rows_b64 = [Convert]::ToBase64String($utf8.GetBytes($txt)) }
    if ([int]$cmp.same -ne $n) { $bad += "catalog rows identical: $($cmp.same) of $n (changed: $(@($cmp.changed) -join ','); missing: $(@($cmp.missing) -join ','))" }
  }
  foreach ($b in $bad) { Say "  PROBLEM: $b" }
  if ($bad.Count -eq 0) { Say ("  verified: " + @($man).Count + " file(s) back in place, " + $n + " catalog row(s) identical to the backup") }
  return ($bad.Count -eq 0)
}
function Invoke-Undo([string]$bk) {
  $script:LastUndoRefused = $false
  $J = Read-Journal $bk; $script:Table = [string]$J.table
  $man = @(Read-Manifest $bk)
  $coll = @($man | Where-Object { (Test-Path -LiteralPath $_.src) -and (Test-Path -LiteralPath $_.dst) })
  if ($coll.Count) {
    Say "REFUSED: $($coll.Count) file(s) exist BOTH in the library and in quarantine; nothing was changed:"
    foreach ($c in $coll) { Say "    $($c.src)" }
    Say '  Decide which copy to keep, remove or rename the other one, then run -Rollback again.'
    $script:LastUndoRefused = $true; return $false
  }
  $J.state = 'rolling-back'; Save-Journal $bk $J
  $txt = Get-BackupRowsText $bk
  if (-not $txt -and $J.rows_deleted) { throw 'catalog rows were deleted but the row backup file is missing' }
  if ($txt -and @(From-JsonArray $txt).Count -gt 0) {
    $r = Invoke-Helper @{ mode = 'restore'; rows_b64 = [Convert]::ToBase64String($utf8.GetBytes($txt)) }
    Say "  catalog rows: $($r.inserted) restored, $($r.already) were already there"
  }
  $back = 0; $lost = @()
  foreach ($m in $man) {
    if (Test-Path -LiteralPath $m.dst) { [IO.File]::Move($m.dst, $m.src); $back++ }
    elseif (-not (Test-Path -LiteralPath $m.src)) { $lost += $m.file }
  }
  Say "  files moved back: $back (others were already in place)"
  foreach ($l in $lost) { Say "  PROBLEM: neither in the library nor in quarantine: $l" }
  $qd = [string]$J.quarantine
  if ($qd -and (Test-Path -LiteralPath $qd) -and @(Get-ChildItem -LiteralPath $qd -Force).Count -eq 0) { Remove-Item -LiteralPath $qd -Force }
  $ok = Test-BackupUnchanged $bk $man
  return ($ok -and $lost.Count -eq 0)
}
function Invoke-Rollback([string]$which) {
  $hb = Get-HeartbeatProblem; if ($hb) { Say "warning: $hb (rollback continues)" }
  $bk = Resolve-BackupDir $which
  if (-not $bk) { throw "no applied run found to roll back ('$which') in $script:BRoot" }
  $script:Log = Join-Path $bk 'log.txt'
  Say "Rollback of $(Split-Path -Leaf $bk)  ($(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') local time)"
  $ok = Invoke-Undo $bk
  if ($script:LastUndoRefused) { return $false }
  $J = Read-Journal $bk
  if ($ok -and -not $script:Sim -and $J.url) {
    $b = $J.before
    $bad = @(Test-PageState ([string]$J.url) ([int]$b.pageCopy) ([int]$b.looseCount) ([int]$b.looseWatch) ([int]$b.loosePdf))
    foreach ($x in $bad) { Say "  PROBLEM: $x" }
    if ($bad.Count) { $ok = $false } else { Say '  History page looks as before the change' }
  }
  if ($ok) { $J.state = 'rolled-back'; Say 'ROLLBACK COMPLETE.' } else { $J.state = 'rollback-FAILED'; Say "ROLLBACK DID NOT FINISH CLEANLY - see $bk\log.txt" }
  $J.finished = (Get-Date).ToString('s'); Save-Journal $bk $J
  return $ok
}

# ---------------------------------------------------------------- self-test (throwaway copies + scratch table)
$script:Fails = 0
function Assert([bool]$cond, [string]$msg) { if ($cond) { Say "  ok    $msg" } else { Say "  FAIL  $msg"; $script:Fails++ } }
function Get-Snapshot([string]$root) {
  if (-not (Test-Path -LiteralPath $root)) { return '' }
  $l = @(); $n = $root.TrimEnd('\').Length
  foreach ($f in @(Get-ChildItem -LiteralPath $root -Recurse -File -Force)) { $l += ($f.FullName.Substring($n) + '|' + $f.Length + '|' + (Get-FileHash -LiteralPath $f.FullName -Algorithm SHA256).Hash) }
  foreach ($d in @(Get-ChildItem -LiteralPath $root -Recurse -Directory -Force)) { $l += ($d.FullName.Substring($n) + '|dir') }
  return ((@($l) | Sort-Object) -join "`n")
}
function Get-Dump { return (Get-RowsText (Invoke-Helper @{ mode = 'dump' })) }
function Get-Decision($p, [string]$name) { $x = @(@($p.items) | Where-Object { $_.file -ceq $name }); if ($x.Count) { return $x[0].decision }; return '(missing)' }
function Invoke-SelfTest {
  $hex = [guid]::NewGuid().ToString('N').Substring(0, 8)
  $tmp = Join-Path $env:TEMP "hd-selftest-$hex"
  $script:Lib = Join-Path $tmp 'lib'; $script:BRoot = Join-Path $tmp 'backups'; $script:QRoot = Join-Path $tmp 'quarantine'
  $script:Cache = Join-Path $tmp 'hashes.tsv'; $script:Report = $tmp; $script:Table = "hd_selftest_$hex"; $script:Sim = $true
  $script:SimHttpFail = @{ 'g.mp4' = $true }; $script:Heartbeat = Join-Path $tmp 'heartbeat.txt'; $script:IDT = $false; $script:FailAt = ''
  Say "SELF-TEST in $tmp, scratch table $script:Table (live library and catalog are not touched)"
  $ld = Join-Path $script:Lib $Cat; $cd = Join-Path $ld $Sub
  foreach ($d in @($ld, $cd, (Join-Path $ld 'thumbs'))) { [void](New-Item -ItemType Directory -Path $d -Force) }
  function Bytes([int]$seed, [int]$len) { $b = New-Object byte[] $len; (New-Object Random $seed).NextBytes($b); return ,$b }
  function Put([string]$path, [byte[]]$b) { [IO.File]::WriteAllBytes($path, $b) }
  function Both([string]$name, [int]$seed, [int]$len) { $b = Bytes $seed $len; Put (Join-Path $ld $name) $b; Put (Join-Path $cd $name) $b }
  $uName = ([string][char]0x00E4) + ' #1.mp4'
  Both 'a.mp4' 1 40000
  Put (Join-Path $ld 'a.jpg') (Bytes 2 1000); Put (Join-Path $cd 'a.jpg') (Bytes 2 1200)
  $b = Bytes 3 30000; Put (Join-Path $ld 'b.mp4') $b; $b2 = [byte[]]$b.Clone(); $b2[10] = [byte](($b2[10] + 1) % 256); Put (Join-Path $cd 'b.mp4') $b2
  Both 'b.jpg' 4 2000
  Both 'c.mp4' 5 20000
  Both 'g.mp4' 6 20000
  Both 'd.png' 7 3000
  Both 'e.mp4' 8 25000
  Put (Join-Path $ld 'f.pdf') (Bytes 9 5000); Put (Join-Path $ld 'f.jpg') (Bytes 10 900)
  Put (Join-Path $ld 'History.jpg') (Bytes 11 800)
  Both 't.jpg' 12 700
  Put (Join-Path $ld 'thumbs\x.jpg') (Bytes 13 600)
  Both $uName 14 22000
  [IO.File]::WriteAllText($script:Heartbeat, 'alive', $utf8)
  $L = "content/$Cat/"; $C = "content/$Cat/$Sub/"
  function Row([int]$id, [string]$fp, [string]$sub, [string]$type, [string]$thumb) { return @{ id = $id; file_path = $fp; title = [IO.Path]::GetFileNameWithoutExtension($fp); category = $Cat; subcategory = $sub; content_type = $type; thumb = $thumb } }
  $seed = @(
    (Row 1001 ($L + 'a.mp4') '' 'video' ($L + 'a.jpg')), (Row 1002 ($L + 'b.mp4') '' 'video' ($L + 'b.jpg')), (Row 1003 ($L + 'c.mp4') '' 'video' ''),
    (Row 1004 ($L + 'g.mp4') '' 'video' ''), (Row 1005 ($L + 'f.pdf') '' 'pdf' ($L + 'f.jpg')), (Row 1006 ($L + $uName) '' 'video' ''),
    (Row 2001 ($C + 'a.mp4') $Sub 'video' ($C + 'a.jpg')), (Row 2002 ($C + 'b.mp4') $Sub 'video' ($C + 'b.jpg')), (Row 2003 ($C + 'c.mp4') 'Other' 'video' ''),
    (Row 2004 ($C + 'g.mp4') $Sub 'video' ''), (Row 2005 ($C + 'e.mp4') $Sub 'video' ''), (Row 2006 ($C + $uName) $Sub 'video' ($L + 't.jpg')),
    (Row 2007 ($C + 'd.png') $Sub 'image' ''), (Row 2008 ($C + 'b.jpg') $Sub 'image' '')
  )
  $pass = $false
  try {
    [void](Invoke-Helper @{ mode = 'create' })
    [void](Invoke-Helper @{ mode = 'seed'; items = $seed })
    $dump0 = Get-Dump; $snap0 = Get-Snapshot $script:Lib
    $n0 = @(From-JsonArray $dump0).Count
    Assert ($n0 -eq 14) "scratch table seeded with 14 rows ($n0)"

    Say '[1] decisions'
    $p = Get-Plan $false $false
    $exp = [ordered]@{ 'a.mp4' = 'QUARANTINE'; 'a.jpg' = 'REPORT'; 'b.mp4' = 'KEEP'; 'b.jpg' = 'KEEP'; 'c.mp4' = 'KEEP'; 'g.mp4' = 'KEEP'; 'd.png' = 'QUARANTINE'
      'e.mp4' = 'QUARANTINE'; 'f.pdf' = 'NEVER'; 'f.jpg' = 'NEVER'; 'History.jpg' = 'KEEP'; 't.jpg' = 'KEEP' }
    $exp[$uName] = 'QUARANTINE'
    foreach ($k in $exp.Keys) { $d = Get-Decision $p $k; $it = @(@($p.items) | Where-Object { $_.file -ceq $k }); $why = ''; if ($it.Count) { $why = $it[0].reason }; Assert ($d -eq $exp[$k]) "$k -> $d ($why)" }
    Assert (@($p.items).Count -eq $exp.Count) "thumbs subfolder not listed ($(@($p.items).Count) loose files)"
    Assert ($script:HashStats.hashed -gt 0) "hashed $($script:HashStats.hashed) files on the first pass"
    Assert (@(Get-TodoRows $p).Count -eq 2) "rows to delete: 1001 and 1006 ($((@(Get-TodoRows $p) | ForEach-Object { $_.id }) -join ','))"
    $csv = Join-Path $tmp 'report.csv'; Export-Plan $p $csv
    Assert ((Test-Path -LiteralPath $csv) -and (@(Import-Csv -LiteralPath $csv).Count -eq $exp.Count)) 'report CSV written'
    $script:IDT = $true; $p2 = Get-Plan $true $false; $script:IDT = $false
    Assert ((Get-Decision $p2 'a.jpg') -eq 'QUARANTINE') "-IncludeDifferingThumbs: a.jpg -> $(Get-Decision $p2 'a.jpg')"
    Assert ($script:HashStats.hashed -eq 0 -and $script:HashStats.cached -gt 0) "-Resume uses the cache ($($script:HashStats.cached) cached, $($script:HashStats.hashed) hashed)"
    Assert ((Get-Snapshot $script:Lib) -eq $snap0) 'check-only changed nothing'

    Say '[2] stale heartbeat'
    (Get-Item -LiteralPath $script:Heartbeat).LastWriteTime = (Get-Date).AddMinutes(-10)
    $r = Invoke-Apply
    Assert ((-not $r) -and @(Get-RunDirs).Count -eq 0 -and (Get-Snapshot $script:Lib) -eq $snap0) 'apply refused, nothing changed'
    (Get-Item -LiteralPath $script:Heartbeat).LastWriteTime = Get-Date

    Say '[3] apply + rollback latest'
    $script:StampOverride = '20000101-000002'
    $r = Invoke-Apply
    $q = Join-Path $script:QRoot 'history-dupes-20000101-000002'
    Assert ($r) 'apply succeeded'
    Assert (@(Get-ChildItem -LiteralPath $q -File -ErrorAction SilentlyContinue).Count -eq 4) '4 files in quarantine'
    Assert (@(From-JsonArray (Get-Dump)).Count -eq 12) '2 rows deleted (12 left)'
    Assert ((Test-Path -LiteralPath (Join-Path $ld 'thumbs\x.jpg')) -and (Test-Path -LiteralPath (Join-Path $ld 'a.jpg')) -and (Test-Path -LiteralPath (Join-Path $ld 'f.pdf'))) 'thumbs, REPORT and NEVER files untouched'
    $r = Invoke-Rollback 'latest'
    Assert ($r) 'rollback latest succeeded'
    Assert ((Get-Snapshot $script:Lib) -eq $snap0) 'files identical to before'
    Assert ((Get-Dump) -eq $dump0) 'catalog rows identical to before'
    Assert (-not (Test-Path -LiteralPath $q)) 'empty quarantine folder removed'
    $threw = $false; try { [void](Invoke-Rollback 'latest') } catch { $threw = $true }
    Assert $threw 'second rollback latest finds nothing to roll back'

    Say '[4] injected failures -> automatic rollback'
    $n = 3
    foreach ($at in @('after-backup', 'mid-move', 'after-move', 'after-rows', 'verify')) {
      $script:StampOverride = '20000101-00001' + $n; $script:FailAt = $at
      $r = Invoke-Apply; $script:FailAt = ''
      $st = [string](Read-Journal (Join-Path $script:BRoot $script:StampOverride)).state
      Assert ((-not $r) -and $st -eq 'auto-rolled-back') "failure at $at -> state $st"
      Assert ((Get-Snapshot $script:Lib) -eq $snap0 -and (Get-Dump) -eq $dump0) "  files and rows identical after $at"
      $n++
    }

    Say '[5] collision on rollback'
    $script:StampOverride = '20000101-000030'
    $r = Invoke-Apply; Assert ($r) 'apply succeeded'
    $coll = Join-Path $ld 'a.mp4'; Put $coll (Bytes 99 500)
    $s1 = (Get-Snapshot $script:Lib) + '##' + (Get-Snapshot $script:QRoot); $d1 = Get-Dump
    $r = Invoke-Rollback 'latest'
    Assert ((-not $r) -and $script:LastUndoRefused) 'rollback refused because a.mp4 exists in both places'
    Assert (((Get-Snapshot $script:Lib) + '##' + (Get-Snapshot $script:QRoot)) -eq $s1 -and (Get-Dump) -eq $d1) '  nothing changed by the refused rollback'
    Remove-Item -LiteralPath $coll -Force
    $r = Invoke-Rollback 'latest'
    Assert ($r) 'rollback after removing the collider succeeded'
    Assert ((Get-Snapshot $script:Lib) -eq $snap0 -and (Get-Dump) -eq $dump0) '  files and rows identical to before'
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
$sw = [Diagnostics.Stopwatch]::StartNew()
$code = 0
try {
  Say "Move-HistoryDuplicates  $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') (local time)"
  [void](Invoke-Helper @{ mode = 'ping' })
  if ($SelfTest) { if (-not (Invoke-SelfTest)) { $code = 1 } }
  elseif ($Rollback) { if (-not (Invoke-Rollback $Rollback)) { $code = 1 } }
  elseif ($Apply) { if (-not (Invoke-Apply)) { $code = 1 } }
  else {
    $hb = Get-HeartbeatProblem; if ($hb) { Say "note: $hb (-Apply would refuse right now)" }
    Say ("CHECK ONLY - nothing will be changed" + $(if ($Resume) { ' (reusing saved hashes)' } else { '' }))
    $p = Get-Plan $Resume.IsPresent $false
    $pre = Get-PrePage $p.url
    Say ("  page: " + $pre.text)
    Show-Plan $p
    $csv = Join-Path $script:Report ("History duplicates check " + (Get-Date -Format 'yyyyMMdd-HHmmss') + ".csv")
    Export-Plan $p $csv
    Say ''
    Say "Report: $csv"
    Say ("Check only - nothing was changed. Took " + [int]$sw.Elapsed.TotalSeconds + " s (hashing " + $script:HashStats.seconds + " s; " + $script:HashStats.cached + " cached, " + $script:HashStats.hashed + " hashed).")
    Say 'To quarantine the QUARANTINE rows: run again with -Apply'
  }
} catch {
  Say "ERROR: $($_.Exception.Message) $($_.InvocationInfo.PositionMessage)"; $code = 2
} finally {
  if ($script:Jar -and (Test-Path -LiteralPath $script:Jar)) { Remove-Item -LiteralPath $script:Jar -Force -ErrorAction SilentlyContinue }
}
exit $code
