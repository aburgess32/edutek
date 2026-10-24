<#
Finish-LibraryCleanup.ps1  (r1b, 2026-10-24)  Edutek library: the remaining approved clean-up items in ONE script.
Windows PowerShell 5.1, StrictMode 2. ASCII only.

  (default)              CHECK ONLY for every section below. Writes a report CSV + summary TXT to the Desktop. Changes nothing.
  -Only <A,B,...>        limit to these sections (comma list), e.g.  -Only WmaToMp3   or   -Only "Lightroom,MissingRows"
  -Apply                 does it: backup + journal in D:\edutek-system\backups\finish-cleanup\<stamp>\, removed files go to
                         D:\edutek-system\quarantine\finish-cleanup-<stamp>\ (same folder layout), catalog rows are backed up
                         before any delete/update, then every section verifies itself.
  -Rollback latest|<stamp>   undoes one -Apply run exactly (newest first), using its journal.
  -Purge latest|<stamp>      shows what is in that run's quarantine folder. Add -Apply to delete it PERMANENTLY
                             (separate, explicit step; the backup folder and its manifests are kept).

Sections (default run = all, in this order):
  WmaToMp3                81 .wma under Music\ and Audiobooks\ -> .mp3 next to them (ffmpeg libmp3lame -q:a 4, tags kept, single
                          thread, in the importer previews container); duration checked (<= 1 s), wma -> quarantine, new rows added,
                          each mp3 must be served 206 audio/mpeg. An mp3 that already exists is skipped (reported).
  MislabeledFolders       Audiobooks\Discipleship, J Grisham - The Finisher, J Grisham - The Guilty: byte-identical copies of
                          7 Habits..., D Baldacci - The Finisher..., D Baldacci - The Guilty... -> mislabeled copy to quarantine, rows removed.
  RemoveBroken            Music\Misty Edwards (cover only, no audio) and the 4 KB unreadable "Guns Germs and Steel.mp3" -> quarantine, rows removed.
  MusicEducationToVideos  Music\Music Education video lessons (+ covers, rows) -> Videos category Music Lessons\Music Theory;
                          the then-empty folder and its Music tile cover -> quarantine.
  Placeholder             counts tiles that fall back to images/sample.png (missing), installs a neutral placeholder there (new file only).
  DiscardBatch            importer batch 84a8eab1 -> state "discarded" through its own batch.json + actions.lock (record kept).
  NameClashes             the 2 held name clashes of the Oct 22 title clean-up (identical -> quarantine "..", different -> "<name> (2)").
  HistoryPdfs             loose History\*.pdf: identical copy elsewhere (History\History or Books\History) -> quarantine the loose one;
                          otherwise move into History\History with its cover.
  Lightroom               Adobe Lightroom: 8 empty course folders + their covers + the 0-byte video #83 (row 826) -> quarantine.
  MissingRows             21 Audiobooks rows whose file is missing (13 pdf, 8 mp4) -> rows backed up, then deleted.

Never deletes library files (only -Purge -Apply does, on the quarantine). Never overwrites a file. Never runs an index.
Does not touch the held catalog clean-up, guide_config, covers outside the listed items, or other quarantine folders.
#>
[CmdletBinding()]
param(
  [switch]$Apply,
  [string]$Rollback = '',
  [string]$Purge = '',
  [string]$Only = '',
  [string]$Library = 'D:\xampp\htdocs\Edutek\videos',
  [string]$Htdocs = 'D:\edutek\htdocs',
  [string]$BackupRoot = 'D:\edutek-system\backups\finish-cleanup',
  [string]$QuarantineRoot = 'D:\edutek-system\quarantine',
  [string]$ImporterData = 'D:\edutek-system\bulk-loader-fresh-v05\data',
  [string]$BatchId = '84a8eab11fe1d4eaa0cfd74aedea7012',
  [string]$MusicEduTarget = 'Music Lessons\Music Theory',
  [string]$ReportDir = '',
  [string]$Container = 'edupak-app',
  [string]$Previews = 'edutek-bulk-fresh-v05-previews-1',
  [string]$Web = 'edutek-bulk-fresh-v05-web-1',
  [string]$DockerContext = 'desktop-linux',
  [string]$DockerExe = ''
)
Set-StrictMode -Version 2
$ErrorActionPreference = 'Stop'
$utf8 = New-Object System.Text.UTF8Encoding($false)
$script:T0 = Get-Date
$Lib = $Library.TrimEnd('\')
if (-not $ReportDir) { $ReportDir = [Environment]::GetFolderPath('Desktop') }
$AllSections = @('WmaToMp3', 'MislabeledFolders', 'RemoveBroken', 'MusicEducationToVideos', 'Placeholder', 'DiscardBatch', 'NameClashes', 'HistoryPdfs', 'Lightroom', 'MissingRows')
$AudioExt = @('.mp3', '.m4a', '.m4b', '.wma', '.wav', '.flac', '.ogg')
$VideoExt = @('.mp4', '.m4v', '.mkv', '.avi', '.mov', '.webm', '.mpeg', '.mpg')
$ImgExt = @('.jpg', '.jpeg', '.png', '.webp')
$MissingRowIds = @(2978, 3072, 19658, 20100, 20282, 20638, 20730, 21148, 22350, 24938, 26944, 27266, 27428, 4117, 4118, 4119, 4120, 15034, 15036, 15038, 15040)
$PlaceholderB64 = 'iVBORw0KGgoAAAANSUhEUgAAAeAAAAEOCAIAAADe+FMwAAAEZElEQVR42u3dTW6CUBiGUTEuyg04dbFO3cBdByOGLMGZMQ21VP7eK+dM2wp+JA83QrFpu/4AQJ6jEQAINAACDSDQAAg0gEADINAACDSAQAMg0AACDYBAAyDQAAINgEADCDQAAg0g0AAINAACDSDQAAg0gEADINAACDSAQAMg0AACDYBAAwg0AAINgEADCDQAAg0g0AAINAACDSDQAAg0gEADINAAAg2AQAMg0AACDYBAAwg0AAINgEADCDQAAg0g0AAINAACDSDQAAg0gEADINAAAg2AQAMg0AACDYBAAwg0AAINgEADCDQAAg0g0AAINIBAAyDQAAg0gEADINAAAg2AQAMg0AACDYBAAwg0AAININAACDQAo52MgB9u92IIW7lezobAU9N2vSkgzTJNIB9xoM6OBQKNIuCIINBogeOCQKMCODosxV0cDHCRSo6xgiaxCOocdV5UbYEGdTZ/BBoAgQYQaAAEGkCgAdiW+6BhmHsQEWioI82vP5Jp1uEjDhhb53/9Dgg0rF1njUagIbfOGo1AQ26dNRqBBhBosHxe/W9BoAEEGgCBBhBoAAQaAIEGEGiowJSHH3lwEgININBgEW35jEBD1Y1WZwQaEhutzgg0JDZanVmHr7yC4f76TkIEGipeSsOifMQBINAACDSAQAMg0AACDYBAw998KysCDbl11mgEGnLXzhqNQENinTUagYb6qg0CDSkh1mgEGnKXyRqNQENinTUagYbcOms0Ag25ddZoBBpy66zRCDTk1lmjEWj48sTrPgIN1XTQM0AQaNS5hL+URiPQqHPKC3oGCAKNOpfAl/3tzzUagUadt3xxFUagUecSuAkPAEGgUecSuCEPAEGgIe5kcLsXDwBBoGGDur3f4mf7o9EINOq87Han7I9GI9Co81Jbn74/Go1Ao87z78Nc+6PRCDTqPOeeqCoCDYlrzMx/LgeBhl2chBBoUC6NRqDRLO8XgQa18q4RaHTKeweBRqFMAIEGbTIHBBpVAoEGnK4QaPTITECgUSKTQaDRIPMxHwQa9TElBBp0x6wQaBTHxBBo0BoQaHBiA4FGZUwPgUZfMEMEGmUxSXbkZASMdL2cDQGsoAEQaACBBkCg+ZzrV+aPQBNh8LqfRkTV2bXZPXMXB9ZxYAVNPYtoHB0EGhXAcUGg0QJHBIFGEXAsmEXTdr0p8MoVQmlGoAF4x0ccAAINgEADCDQAAg0g0AAINAACDSDQAAg0gEADINAACDSAQAMg0AACDYBAAwg0AAINgEADCDQAAg0g0AAINAACDSDQAAg0gEADINAAAg2AQAMg0AACDYBAAwg0AAINgEADCDQAAg0g0AAINIBAGwGAQAMg0AACDYBAAwg0AAINgEADCDQAAg0g0AAINAACDSDQAAg0gEADINAAAg2AQAMg0AACDYBAAwg0AAINgEADCDQAAg0g0AAINIBAAyDQAAg0gEADINAAAg2AQAMg0AACDYBAAwg0AAININAARHoA0ZNl5mOqvogAAAAASUVORK5CYII='

$script:Rep = New-Object System.Collections.Generic.List[object]
$script:Sum = New-Object System.Collections.Generic.List[string]
$script:Bk = ''; $script:Q = ''; $script:Stamp = ''; $script:NoJournal = $false; $script:JsonN = 0; $script:Problems = 0

function Say([string]$m) { Write-Host $m; if ($script:Bk) { try { [IO.File]::AppendAllText((Join-Path $script:Bk 'journal.log'), ((Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $m + "`r`n"), $utf8) } catch { } } }
function Sum([string]$m) { $script:Sum.Add($m); Say $m }
function Add-Rep([string]$sec, [string]$act, [string]$st, [string]$path, [string]$target = '', $bytes = '', [string]$rows = '', [string]$note = '') {
  $script:Rep.Add([pscustomobject]@{ Section = $sec; Action = $act; Status = $st; Path = $path; Target = $target; Bytes = $bytes; RowIds = $rows; Note = $note })
}
function Test-File([string]$p) { try { return [IO.File]::Exists($p) } catch { return $false } }
function Test-Dir([string]$p) { try { return [IO.Directory]::Exists($p) } catch { return $false } }
function Get-Len([string]$p) { try { return [int64](New-Object IO.FileInfo($p)).Length } catch { return [int64]-1 } }
function Parent-Of([string]$p) { $i = $p.LastIndexOf('\'); if ($i -le 2) { return $p.Substring(0, 3) }; return $p.Substring(0, $i) }
function Leaf-Of([string]$p) { return $p.Substring($p.LastIndexOf('\') + 1) }
function Ext-Of([string]$n) { $i = $n.LastIndexOf('.'); if ($i -lt 0) { return '' }; return $n.Substring($i).ToLowerInvariant() }
function Stem-Of([string]$n) { $i = $n.LastIndexOf('.'); if ($i -lt 1) { return $n }; return $n.Substring(0, $i) }
function Rel([string]$full) { if ($full.StartsWith($Lib + '\', [StringComparison]::OrdinalIgnoreCase)) { return $full.Substring($Lib.Length + 1) }; throw "not in the library: $full" }
function Full([string]$rel) { return ($Lib + '\' + $rel) }
function To-FP([string]$rel) { return ('content/' + $rel.Replace('\', '/')) }
function To-CPath([string]$full) { if ($full -notmatch '^[Dd]:\\') { throw "not on D: : $full" }; return ('/host/d/' + $full.Substring(3).Replace('\', '/')) }
function Get-Sha([string]$p) { return (Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash }
function Fmt-MB($b) { return ('{0:N1} MB' -f ([double]$b / 1MB)) }
function Get-Covers([string]$dir, [string]$stem) {
  $out = @(); foreach ($e in $ImgExt) { $p = $dir + '\' + $stem + $e; if (Test-File $p) { $out += $p } }; return $out
}

# ------------------------------------------------------------------ docker
function Resolve-Docker { if ($DockerExe) { return $DockerExe }; $p = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe'; if (Test-Path -LiteralPath $p) { return $p }; return 'docker' }
$script:Docker = Resolve-Docker
$script:DockerArgs = @(); if ($DockerContext) { $script:DockerArgs = @('--context', $DockerContext) }
function Invoke-Native([string]$ctr, [string[]]$a) {
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @(& $script:Docker @script:DockerArgs exec $ctr @a 2>&1 | ForEach-Object { "$_" }); $code = $LASTEXITCODE } finally { $ErrorActionPreference = $old }
  return @{ code = $code; out = $o }
}
function Invoke-Php([string]$ctr, [string]$code, [hashtable]$in) {
  $json = ConvertTo-Json -InputObject $in -Depth 10 -Compress
  $c = $code.Replace('__FC_INPUT__', [Convert]::ToBase64String($utf8.GetBytes($json)))
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @($c | & $script:Docker @script:DockerArgs exec -i $ctr php 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
  $line = @($o | Where-Object { $_.StartsWith('ARR:') }) | Select-Object -Last 1
  if (-not $line) { throw ("helper ($($in.mode)) in $ctr gave no result: " + ((@($o) | Select-Object -Last 4) -join ' / ')) }
  $res = $utf8.GetString([Convert]::FromBase64String($line.Substring(4).Trim())) | ConvertFrom-Json
  if ($res.state -ne 'ok') { throw ("helper ($($in.mode)): " + $res.message) }
  return $res
}
function App([hashtable]$in) { return (Invoke-Php $Container $AppPhp $in) }
function Probe([string]$cpath) {
  $r = Invoke-Native $Previews @('ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $cpath)
  $d = -1.0; $line = @($r.out | Where-Object { $_ -match '^\s*[0-9]+(\.[0-9]+)?\s*$' }) | Select-Object -First 1
  if ($r.code -eq 0 -and $line) { $d = [double]::Parse($line.Trim(), [Globalization.CultureInfo]::InvariantCulture) }
  return @{ dur = $d; code = $r.code; msg = ((@($r.out) | Select-Object -First 3) -join ' / ') }
}

# ------------------------------------------------------------------ helper in edupak-app (catalog + web checks). Read-only modes first.
$AppPhp = @'
<?php
if (PHP_SAPI !== 'cli') { exit(1); }
function fc_out($a) { echo "\nARR:" . base64_encode(json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n"; exit(0); }
function fc_url($rel) { $s = array_values(array_filter(explode('/', str_replace('\\', '/', $rel)), function ($x) { return $x !== ''; })); if ($s && $s[0] === 'content') { array_shift($s); } return '/content/' . implode('/', array_map('rawurlencode', $s)); }
function fc_fs($fp) { $fp = str_replace('\\', '/', (string)$fp); if (strpos($fp, 'content/') === 0) { $fp = substr($fp, 8); } return '/content/' . ltrim($fp, '/'); }
try {
  $in = json_decode(base64_decode('__FC_INPUT__'), true);
  if (!is_array($in)) { fc_out(['state' => 'error', 'message' => 'bad input']); }
  $mode = (string)($in['mode'] ?? '');
  if ($mode === 'serve') {   /* GET first KB the way a browser player does */
    $out = [];
    foreach ($in['items'] as $it) {
      $u = isset($it['url']) ? $it['url'] : fc_url($it['rel']);
      $h = curl_init('http://localhost' . $u); curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_RANGE => '0-1023', CURLOPT_TIMEOUT => 60]); curl_exec($h);
      $out[] = ['u' => $u, 'code' => curl_getinfo($h, CURLINFO_HTTP_CODE), 'type' => (string)curl_getinfo($h, CURLINFO_CONTENT_TYPE)]; curl_close($h);
    }
    fc_out(['state' => 'ok', 'items' => $out]);
  }
  if ($mode === 'listen') {   /* what listen.php shows for an Audiobooks/Music folder */
    $c = "BF-CBC"; $iv = "91011121"; $k = "hfjfydjnvhbjfi";
    $e = function ($s) use ($c, $iv, $k) { return str_replace('=', '[equal]', base64_encode(openssl_encrypt($s, $c, $k, 0, $iv))); };
    $out = [];
    foreach ($in['folders'] as $f) {
      $p = explode('/', $f, 2); $web = 'videos/' . $p[0] . '/' . rawurlencode($p[1]) . '/';
      $u = 'http://localhost/listen.php?&' . $e('videolink1') . '=&' . $e('videoname1') . '=&' . $e('videolink') . '=' . $e($web) . '&' . $e('videoname') . '=' . $e($p[1]);
      $h = curl_init($u); curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 60]); $b = (string)curl_exec($h); $st = curl_getinfo($h, CURLINFO_HTTP_CODE); curl_close($h);
      $src = ''; if (preg_match('#<audio id="audio-player" src="([^"]*)"#', $b, $m)) { $src = html_entity_decode($m[1]); }
      $as = 0; $ct = '';
      if ($src !== '') { $h = curl_init('http://localhost/' . $src); curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_RANGE => '0-1023', CURLOPT_TIMEOUT => 60]); curl_exec($h); $as = curl_getinfo($h, CURLINFO_HTTP_CODE); $ct = (string)curl_getinfo($h, CURLINFO_CONTENT_TYPE); curl_close($h); }
      $out[] = ['folder' => $f, 'page' => $st, 'noplay' => (strpos($b, 'No playable audio found') !== false), 'first' => $as, 'type' => $ct];
    }
    fc_out(['state' => 'ok', 'folders' => $out]);
  }
  if ($mode === 'meta') {   /* the indexer's own row values for new files, WITHOUT running the indexer */
    require_once '/var/www/html/includes/content-indexer.php';
    $root = '/content'; $out = [];
    foreach ($in['rels'] as $rel) {
      $rel = str_replace('\\', '/', $rel); $full = $root . '/' . $rel;
      if (!is_file($full)) { fc_out(['state' => 'error', 'message' => 'meta: file not found: ' . $rel]); }
      $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION)); $parts = explode('/', $rel);
      $type = CONTENT_EXTENSION_MAP[$ext] ?? null; if ($type === null) { fc_out(['state' => 'error', 'message' => 'meta: not an indexed type: ' . $rel]); }
      $th = findThumbnail($full, $root);
      $out[] = ['content_id' => 'content/' . $rel, 'title' => titleFromFilename(pathinfo(basename($full), PATHINFO_FILENAME)), 'content_type' => $type,
        'category' => $parts[0] ?? null, 'subcategory' => (count($parts) >= 3 ? $parts[1] : null), 'source' => detectSource($rel), 'file_path' => 'content/' . $rel,
        'thumbnail_path' => ($th ? 'content/' . $th : null)];
    }
    fc_out(['state' => 'ok', 'rows' => $out]);
  }
  require_once '/var/www/html/includes/config.php';
  require_once '/var/www/html/includes/auth.php';
  $pdo = getDbConnection(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $t = 'content_meta';
  $byId = $pdo->prepare("SELECT * FROM `$t` WHERE id = ?");
  if ($mode === 'rowsById') { $r = []; foreach ($in['ids'] as $id) { $byId->execute([(int)$id]); $x = $byId->fetch(PDO::FETCH_ASSOC); if ($x) { $x['_exists'] = is_file(fc_fs($x['file_path'])); $r[] = $x; } } fc_out(['state' => 'ok', 'rows' => $r]); }
  if ($mode === 'rowsByPrefix' || $mode === 'rowsByPath') {   /* exact (binary) match in PHP after a case-insensitive SQL match */
    $rows = []; $th = [];
    $s = $pdo->prepare("SELECT * FROM `$t` WHERE LEFT(file_path, CHAR_LENGTH(?)) = ? OR LEFT(content_id, CHAR_LENGTH(?)) = ?");
    $s2 = $pdo->prepare("SELECT id, file_path, thumbnail_path FROM `$t` WHERE LEFT(thumbnail_path, CHAR_LENGTH(?)) = ?");
    foreach ($in['items'] as $p) {
      $ok = function ($v) use ($p, $mode) { $v = (string)$v; return $mode === 'rowsByPath' ? ($v === $p) : (strncmp($v, $p, strlen($p)) === 0); };
      $s->execute([$p, $p, $p, $p]); foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { if ($ok($r['file_path']) || $ok($r['content_id'])) { $rows[$r['id']] = $r; } }
      $s2->execute([$p, $p]); foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r) { if ($ok($r['thumbnail_path'])) { $th[$r['id']] = $r; } }
    }
    fc_out(['state' => 'ok', 'rows' => array_values($rows), 'thumbs' => array_values($th)]);
  }
  if ($mode === 'catRows') { $s = $pdo->prepare("SELECT id, content_id, file_path, thumbnail_path, subcategory FROM `$t` WHERE category = ?"); $s->execute([$in['category']]); fc_out(['state' => 'ok', 'rows' => $s->fetchAll(PDO::FETCH_ASSOC)]); }
  if ($mode === 'missingInCats') {
    $out = []; $s = $pdo->prepare("SELECT id, content_type, file_path FROM `$t` WHERE category = ?");
    foreach ($in['cats'] as $c) { $s->execute([$c]); foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { if (!is_file(fc_fs($r['file_path']))) { $out[] = $r; } } }
    fc_out(['state' => 'ok', 'rows' => $out]);
  }
  if ($mode === 'tiles') {   /* which tiles fall back to images/sample.png (same rules as tutorials.php / music.php) */
    $root = '/content'; $cats = [];
    $exists = function ($tp) { return $tp !== null && $tp !== '' && is_file(fc_fs($tp)); };
    $add = function ($c, $k, $n = 1) use (&$cats) { if (!isset($cats[$c])) { $cats[$c] = ['sections' => 0, 'section_placeholder' => 0, 'section_dead_thumb' => 0, 'loose_placeholder' => 0, 'video_items_no_thumb' => 0, 'music_folder_no_cover' => 0]; } $cats[$c][$k] += $n; };
    $q = $pdo->query("SELECT category, subcategory, title, thumbnail_path, content_type FROM `$t` ORDER BY category ASC, subcategory ASC, title ASC");
    $last = null; $looseDone = [];
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
      $c = (string)$r['category']; $s = (string)($r['subcategory'] ?? '');
      if ($r['content_type'] === 'video' && ($r['thumbnail_path'] === null || $r['thumbnail_path'] === '')) { $add($c, 'video_items_no_thumb'); }
      if ($s === '') { if (!isset($looseDone[$c])) { $looseDone[$c] = 0; } if ($r['thumbnail_path'] !== null && $r['thumbnail_path'] !== '') { $looseDone[$c] = 1; } continue; }
      $key = $c . "\0" . $s; if ($key === $last) { continue; } $last = $key;
      $add($c, 'sections');
      $hasTile = false; foreach (['jpg', 'jpeg', 'png', 'webp'] as $e) { if (is_file("$root/$c/$s.$e")) { $hasTile = true; break; } }
      if ($hasTile) { continue; }
      if ($r['thumbnail_path'] === null || $r['thumbnail_path'] === '') { $add($c, 'section_placeholder'); } elseif (!$exists($r['thumbnail_path'])) { $add($c, 'section_dead_thumb'); }
    }
    foreach ($looseDone as $c => $v) { if (!$v) { $add($c, 'loose_placeholder'); } }
    foreach (glob("$root/Music/*", GLOB_ONLYDIR) as $d) { $n = basename($d); $has = false; foreach (["$n.jpg", "$n.png", 'cover.jpg', 'cover.png', 'folder.jpg', 'folder.png'] as $f) { if (file_exists("$d/$f")) { $has = true; break; } } if (!$has) { $add('Music', 'music_folder_no_cover'); } }
    ksort($cats);
    fc_out(['state' => 'ok', 'cats' => $cats, 'sample_exists' => is_file('/var/www/html/images/sample.png')]);
  }
  /* ---------------- write modes: each one is a single transaction and checks the rows first ---------------- */
  $byPath = $pdo->prepare("SELECT id, file_path, content_id FROM `$t` WHERE (file_path = ? OR content_id = ?) AND id <> ?");
  $col = function ($kk) use ($pdo) { if (!preg_match('/^[a-z_0-9]+$/', $kk)) { throw new RuntimeException('bad column ' . $kk); } };
  if ($mode === 'insert') {   /* rows WITH their original id (rollback of a delete) */
    $pdo->beginTransaction(); $ins = []; $same = 0;
    foreach ($in['rows'] as $r) {
      unset($r['_exists']); foreach (array_keys($r) as $kk) { $col($kk); }
      $byId->execute([(int)$r['id']]); $x = $byId->fetch(PDO::FETCH_ASSOC);
      if ($x) { if ($x['file_path'] === $r['file_path'] && $x['content_id'] === $r['content_id']) { $same++; continue; } throw new RuntimeException('id ' . $r['id'] . ' is used by another row; nothing inserted'); }
      $byPath->execute([$r['file_path'], $r['content_id'], (int)$r['id']]); $o = $byPath->fetch(PDO::FETCH_ASSOC);
      if ($o && ($o['file_path'] === $r['file_path'] || $o['content_id'] === $r['content_id'])) { throw new RuntimeException('path of row ' . $r['id'] . ' is already in row ' . $o['id'] . '; nothing inserted'); }
      $kk = array_keys($r);
      $pdo->prepare("INSERT INTO `$t` (`" . implode('`,`', $kk) . "`) VALUES (" . implode(',', array_fill(0, count($kk), '?')) . ")")->execute(array_values($r));
      $ins[] = (int)$r['id'];
    }
    $pdo->commit(); fc_out(['state' => 'ok', 'inserted' => $ins, 'already' => $same]);
  }
  if ($mode === 'insertNew') {   /* new rows, new ids; refuses if the path is already in the catalog */
    $pdo->beginTransaction(); $ins = [];
    foreach ($in['rows'] as $r) {
      foreach (array_keys($r) as $kk) { $col($kk); }
      $byPath->execute([$r['file_path'], $r['content_id'], 0]); $o = $byPath->fetch(PDO::FETCH_ASSOC);
      if ($o && ($o['file_path'] === $r['file_path'] || $o['content_id'] === $r['content_id'])) { throw new RuntimeException($r['file_path'] . ' is already in row ' . $o['id'] . '; nothing inserted'); }
      $kk = array_keys($r);
      $pdo->prepare("INSERT INTO `$t` (`" . implode('`,`', $kk) . "`) VALUES (" . implode(',', array_fill(0, count($kk), '?')) . ")")->execute(array_values($r));
      $id = (int)$pdo->lastInsertId(); $byId->execute([$id]); $ins[] = $byId->fetch(PDO::FETCH_ASSOC);
    }
    $pdo->commit(); fc_out(['state' => 'ok', 'rows' => $ins]);
  }
  if ($mode === 'delete') {   /* only rows whose id AND both paths still match */
    $pdo->beginTransaction(); $n = 0; $gone = 0;
    $del = $pdo->prepare("DELETE FROM `$t` WHERE id = ? AND content_id = ? AND file_path = ?");
    foreach ($in['rows'] as $r) {
      $byId->execute([(int)$r['id']]); $x = $byId->fetch(PDO::FETCH_ASSOC);
      if (!$x) { $gone++; continue; }
      if ($x['file_path'] !== $r['file_path'] || $x['content_id'] !== $r['content_id']) { throw new RuntimeException('row ' . $r['id'] . ' changed; nothing deleted'); }
      $del->execute([(int)$r['id'], $r['content_id'], $r['file_path']]); $n += $del->rowCount();
    }
    $pdo->commit(); fc_out(['state' => 'ok', 'deleted' => $n, 'gone' => $gone]);
  }
  if ($mode === 'update') {   /* items: {id, expect:{content_id,file_path}, set:{col:val}} */
    $pdo->beginTransaction(); $n = 0;
    foreach ($in['items'] as $it) {
      $byId->execute([(int)$it['id']]); $x = $byId->fetch(PDO::FETCH_ASSOC);
      if (!$x) { throw new RuntimeException('row ' . $it['id'] . ' is gone; nothing updated'); }
      if ($x['file_path'] !== $it['expect']['file_path'] || $x['content_id'] !== $it['expect']['content_id']) { throw new RuntimeException('row ' . $it['id'] . ' changed; nothing updated'); }
      $set = $it['set']; foreach (array_keys($set) as $kk) { $col($kk); }
      $nf = $set['file_path'] ?? $x['file_path']; $nc = $set['content_id'] ?? $x['content_id'];
      $byPath->execute([$nf, $nc, (int)$it['id']]); $o = $byPath->fetch(PDO::FETCH_ASSOC);
      if ($o && ($o['file_path'] === $nf || $o['content_id'] === $nc)) { throw new RuntimeException('new path of row ' . $it['id'] . ' is already in row ' . $o['id'] . '; nothing updated'); }
      $sql = "UPDATE `$t` SET " . implode(', ', array_map(function ($k) { return "`$k` = ?"; }, array_keys($set))) . " WHERE id = ? AND content_id = ? AND file_path = ?";
      $st = $pdo->prepare($sql); $st->execute(array_merge(array_values($set), [(int)$it['id'], $x['content_id'], $x['file_path']])); $n += $st->rowCount();
    }
    $pdo->commit(); fc_out(['state' => 'ok', 'updated' => $n]);
  }
  fc_out(['state' => 'error', 'message' => 'unknown mode ' . $mode]);
} catch (Throwable $ex) {
  try { if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable $e2) { }
  fc_out(['state' => 'error', 'message' => get_class($ex) . ': ' . $ex->getMessage()]);
}
'@

# ------------------------------------------------------------------ helper in the importer web container (batch state, under the importer's own lock)
$WebPhp = @'
<?php
if (PHP_SAPI !== 'cli') { exit(1); }
function fc_out($a) { echo "\nARR:" . base64_encode(json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n"; exit(0); }
function fc_write($path, $raw) { $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp'; if (file_put_contents($tmp, $raw) === false || !rename($tmp, $path)) { throw new RuntimeException('State write failed'); } }
try {
  $in = json_decode(base64_decode('__FC_INPUT__'), true);
  $mode = (string)($in['mode'] ?? ''); $id = (string)($in['id'] ?? '');
  if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new RuntimeException('bad batch id'); }
  $root = '/sandbox-data/guided-v08'; $dir = "$root/$id"; $bp = "$dir/batch.json";
  if (!is_file($bp)) { throw new RuntimeException('batch.json not found'); }
  $info = function ($raw) use ($dir, $id) {
    $b = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); $ops = $b['operations'] ?? []; $copied = 0;
    foreach ($ops as $o) { if (is_array($o) && in_array((string)($o['state'] ?? ''), ['copied', 'published', 'done', 'complete'], true)) { $copied++; } }
    $files = 0; foreach (['files', 'recovery'] as $sd) { if (is_dir("$dir/$sd")) { $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$dir/$sd", FilesystemIterator::SKIP_DOTS)); foreach ($it as $f) { if ($f->isFile()) { $files++; } } } }
    $refs = []; foreach (glob('/sandbox-data/*/' . $id . '*') ?: [] as $g) { if (strpos($g, $dir) !== 0) { $refs[] = $g; } }
    return ['state_v' => (string)($b['state'] ?? ''), 'previous_state' => (string)($b['previous_state'] ?? ''), 'screen' => (string)($b['screen'] ?? ''),
      'rows' => count($b['rows'] ?? []), 'ops' => count($ops), 'ops_done' => $copied, 'files' => $files, 'events' => count($b['events'] ?? []),
      'created' => (string)($b['created'] ?? ''), 'source_key' => (string)($b['source_key'] ?? ''), 'sha' => hash('sha256', $raw), 'bytes' => strlen($raw), 'refs' => $refs, 'b' => $b];
  };
  if ($mode === 'info') { $i = $info((string)file_get_contents($bp)); unset($i['b']); $i['state'] = 'ok'; fc_out($i); }
  $l = fopen("$root/actions.lock", 'c'); if (!$l || !flock($l, LOCK_EX | LOCK_NB)) { throw new RuntimeException('the importer is busy (actions.lock held); try again when it is idle'); }
  $raw = (string)file_get_contents($bp); $i = $info($raw);
  if ($mode === 'discard') {
    if ($i['sha'] !== $in['expect_sha']) { throw new RuntimeException('batch.json changed since the check; nothing written'); }
    if (!in_array($i['state_v'], ['draft', 'approved'], true)) { throw new RuntimeException('state is ' . $i['state_v'] . ', not draft/approved; nothing written'); }
    if ($i['ops'] > 0 || $i['files'] > 0) { throw new RuntimeException('batch has operations or staged files; nothing written'); }
    $b = $i['b']; $now = gmdate('Y-m-d\TH:i:s\Z');
    $b['previous_state'] = $b['state']; $b['state'] = 'discarded'; $b['discarded_at'] = $now; $b['discarded_by'] = 'Finish-LibraryCleanup.ps1';
    if (!isset($b['events']) || !is_array($b['events'])) { $b['events'] = []; }
    $b['events'][] = ['at' => $now, 'event' => 'discarded', 'from' => $b['previous_state'], 'by' => 'Finish-LibraryCleanup.ps1', 'note' => 'old staged batch discarded by the user; record kept, rollback restores the previous file'];
    $new = json_encode($b, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    fc_write($bp, $new); fc_out(['state' => 'ok', 'after_sha' => hash('sha256', $new)]);
  }
  if ($mode === 'restore') {
    if ($i['sha'] !== $in['after_sha']) { throw new RuntimeException('batch.json changed after the discard; not restored'); }
    $old = (string)file_get_contents($in['backup']); if (hash('sha256', $old) !== $in['before_sha']) { throw new RuntimeException('backup copy does not match its recorded SHA256'); }
    fc_write($bp, $old); fc_out(['state' => 'ok', 'sha' => hash('sha256', $old)]);
  }
  throw new RuntimeException('unknown mode ' . $mode);
} catch (Throwable $ex) { fc_out(['state' => 'error', 'message' => get_class($ex) . ': ' . $ex->getMessage()]); }
'@
function WebPhp([hashtable]$in) { return (Invoke-Php $Web $WebPhp $in) }

# ------------------------------------------------------------------ journal + reversible primitives (apply only)
function J-Add([string[]]$p) { if ($script:NoJournal) { return }; [IO.File]::AppendAllText((Join-Path $script:Bk 'journal.tsv'), (($p -join "`t") + "`r`n"), $utf8) }
function Ensure-Dir([string]$d) {
  if (Test-Dir $d) { return }
  $parent = Parent-Of $d; if ($parent -and -not (Test-Dir $parent)) { Ensure-Dir $parent }
  [void][IO.Directory]::CreateDirectory($d); J-Add @('MKDIR', $d)
}
function Q-Of([string]$full) { if ($full.StartsWith($Lib + '\', [StringComparison]::OrdinalIgnoreCase)) { return (Join-Path $script:Q (Rel $full)) }; return (Join-Path $script:Q ('_other\' + $full.Substring(3))) }
function Do-Move([string]$src, [string]$dst) {
  if (-not (Test-File $src)) { throw "missing: $src" }
  if ((Test-File $dst) -or (Test-Dir $dst)) { throw "target exists (never overwritten): $dst" }
  $len = Get-Len $src; Ensure-Dir (Parent-Of $dst); [IO.File]::Move($src, $dst); J-Add @('MOVE', $src, $dst, [string]$len)
}
function Do-MoveDir([string]$src, [string]$dst) {
  if (-not (Test-Dir $src)) { throw "missing folder: $src" }
  if ((Test-File $dst) -or (Test-Dir $dst)) { throw "target exists (never overwritten): $dst" }
  Ensure-Dir (Parent-Of $dst); [IO.Directory]::Move($src, $dst); J-Add @('DMOVE', $src, $dst)
}
function Do-Quarantine([string]$src) { if (Test-Dir $src) { Do-MoveDir $src (Q-Of $src) } else { Do-Move $src (Q-Of $src) } }
function Save-JsonFile([string]$tag, $obj) {
  $script:JsonN++; $p = Join-Path $script:Bk ('{0:D3}-{1}.json' -f $script:JsonN, $tag)
  $fs = New-Object IO.FileStream($p, [IO.FileMode]::CreateNew); try { $b = $utf8.GetBytes((ConvertTo-Json -InputObject $obj -Depth 10)); $fs.Write($b, 0, $b.Length) } finally { $fs.Close() }
  return $p
}
function Read-JsonFile([string]$p) { return ([IO.File]::ReadAllText($p, $utf8) | ConvertFrom-Json) }
function Row-Hash($r) { $h = [ordered]@{}; foreach ($pp in $r.PSObject.Properties) { if ($pp.Name -ne '_exists') { $h[$pp.Name] = $pp.Value } }; return $h }
function Do-RowDelete([string]$tag, $rows) {
  $rows = @($rows); if (-not $rows.Count) { return 0 }
  $full = @($rows | ForEach-Object { Row-Hash $_ })
  $p = Save-JsonFile "rows-deleted-$tag" $full
  $res = App @{ mode = 'delete'; rows = @($full | ForEach-Object { @{ id = $_['id']; content_id = $_['content_id']; file_path = $_['file_path'] } }) }
  J-Add @('ROWDEL', $p); return [int]$res.deleted
}
function Do-RowUpdate([string]$tag, $items) {   # items: @{ row = <full row>; set = @{...} }
  $items = @($items); if (-not $items.Count) { return 0 }
  $rec = @(); foreach ($it in $items) { $b = [ordered]@{}; foreach ($k in $it.set.Keys) { $b[$k] = $it.row.$k }; $b['content_id'] = $it.row.content_id; $b['file_path'] = $it.row.file_path
    $a = [ordered]@{}; foreach ($k in $it.set.Keys) { $a[$k] = $it.set[$k] }; if (-not $a.Contains('content_id')) { $a['content_id'] = $it.row.content_id }; if (-not $a.Contains('file_path')) { $a['file_path'] = $it.row.file_path }
    $rec += [ordered]@{ id = $it.row.id; before = $b; after = $a; full_before = (Row-Hash $it.row) } }
  $p = Save-JsonFile "rows-updated-$tag" $rec
  $res = App @{ mode = 'update'; items = @($items | ForEach-Object { @{ id = $_.row.id; expect = @{ content_id = $_.row.content_id; file_path = $_.row.file_path }; set = $_.set } }) }
  J-Add @('ROWUPD', $p); return [int]$res.updated
}
function Do-RowInsertNew([string]$tag, $rows) {
  $rows = @($rows); if (-not $rows.Count) { return @() }
  $res = App @{ mode = 'insertNew'; rows = $rows }
  $got = @($res.rows); $p = Save-JsonFile "rows-inserted-$tag" @($got | ForEach-Object { Row-Hash $_ })
  J-Add @('ROWINS', $p); return $got
}
function Do-NewFile([string]$path, [byte[]]$bytes) {
  Ensure-Dir (Parent-Of $path)
  $fs = New-Object IO.FileStream($path, [IO.FileMode]::CreateNew); try { $fs.Write($bytes, 0, $bytes.Length) } finally { $fs.Close() }
  J-Add @('NEWFILE', $path, (Get-Sha $path))
}

# ------------------------------------------------------------------ rollback / purge
function Find-Run([string]$which) {
  if (-not (Test-Dir $BackupRoot)) { throw "no runs in $BackupRoot" }
  if ($which -eq 'latest') {
    $d = @(Get-ChildItem -LiteralPath $BackupRoot -Directory | Where-Object { (Test-File (Join-Path $_.FullName 'run.json')) } | Sort-Object Name -Descending)
    if (-not $d.Count) { throw "no -Apply run found in $BackupRoot" }; return $d[0].FullName
  }
  $p = Join-Path $BackupRoot $which; if (-not (Test-File (Join-Path $p 'run.json'))) { throw "run $which not found in $BackupRoot" }; return $p
}
function Do-Rollback([string]$which) {
  $dir = Find-Run $which; $run = Read-JsonFile (Join-Path $dir 'run.json')
  if (Test-File (Join-Path $dir 'ROLLED-BACK.txt')) { throw "run $($run.stamp) was already rolled back" }
  $script:Bk = $dir; $script:Q = [string]$run.quarantine; $script:Stamp = [string]$run.stamp; $script:NoJournal = $true
  $jf = Join-Path $dir 'journal.tsv'; $lines = @(); if (Test-File $jf) { $lines = @([IO.File]::ReadAllLines($jf, $utf8) | Where-Object { $_ }) }
  Say "ROLLBACK of run $($run.stamp): $($lines.Count) journal steps, newest first"
  $bad = 0; $done = 0
  for ($i = $lines.Count - 1; $i -ge 0; $i--) {
    $p = $lines[$i].Split("`t")
    try {
      switch ($p[0]) {
        'MOVE' { if ((Test-File $p[2]) -and -not (Test-File $p[1]) -and ((Get-Len $p[2]) -eq [int64]$p[3])) { $pd = Parent-Of $p[1]; if (-not (Test-Dir $pd)) { [void][IO.Directory]::CreateDirectory($pd) }; [IO.File]::Move($p[2], $p[1]); $done++ } elseif (Test-File $p[1]) { Say "  already back: $($p[1])" } else { throw "cannot move back $($p[2]) (missing or size changed)" } }
        'DMOVE' { if ((Test-Dir $p[2]) -and -not (Test-Dir $p[1])) { $pd = Parent-Of $p[1]; if (-not (Test-Dir $pd)) { [void][IO.Directory]::CreateDirectory($pd) }; [IO.Directory]::Move($p[2], $p[1]); $done++ } elseif (Test-Dir $p[1]) { Say "  already back: $($p[1])" } else { throw "cannot move folder back: $($p[2])" } }
        'MKDIR' { if ((Test-Dir $p[1]) -and -not @(Get-ChildItem -LiteralPath $p[1] -Force).Count) { [IO.Directory]::Delete($p[1]); $done++ } }
        'NEWFILE' { if (Test-File $p[1]) { if ((Get-Sha $p[1]) -ne $p[2]) { throw "changed since it was created, left in place: $($p[1])" }; $t = Join-Path $script:Q ('_rolled-back\' + $p[1].Substring(3)); $pd = Parent-Of $t; if (-not (Test-Dir $pd)) { [void][IO.Directory]::CreateDirectory($pd) }; [IO.File]::Move($p[1], $t); $done++ } }
        'ROWDEL' { $rows = @(Read-JsonFile $p[1]); $h = @($rows | ForEach-Object { Row-Hash $_ }); $r = App @{ mode = 'insert'; rows = $h }; Say "  rows restored: $(@($r.inserted).Count) (already there $($r.already))"; $done++ }
        'ROWINS' { $rows = @(Read-JsonFile $p[1]); $r = App @{ mode = 'delete'; rows = @($rows | ForEach-Object { @{ id = $_.id; content_id = $_.content_id; file_path = $_.file_path } }) }; Say "  added rows removed: $($r.deleted)"; $done++ }
        'ROWUPD' { $rec = @(Read-JsonFile $p[1]); $items = @(); foreach ($x in $rec) { $set = @{}; foreach ($pp in $x.before.PSObject.Properties) { $set[$pp.Name] = $pp.Value }; $items += @{ id = $x.id; expect = @{ content_id = $x.after.content_id; file_path = $x.after.file_path }; set = $set } }; $r = App @{ mode = 'update'; items = $items }; Say "  rows changed back: $($r.updated)"; $done++ }
        'BATCH' { $r = WebPhp @{ mode = 'restore'; id = $p[1]; backup = (To-CPath $p[2]); before_sha = $p[3]; after_sha = $p[4] }; Say "  batch $($p[1]) restored ($($r.sha))"; $done++ }
        default { throw "unknown journal step $($p[0])" }
      }
    } catch { $bad++; Say ("  PROBLEM: " + $lines[$i] + "  ->  " + $_.Exception.Message) }
  }
  [IO.File]::WriteAllText((Join-Path $dir 'ROLLED-BACK.txt'), ("rolled back " + (Get-Date -Format s) + "; steps undone $done; problems $bad`r`n"), $utf8)
  Sum "Rollback finished: $done steps undone, $bad problem(s). Quarantine leftovers (if any): $($script:Q)"
  if ($bad) { $script:Problems += $bad }
}
function Do-Purge([string]$which) {
  $dir = Find-Run $which; $run = Read-JsonFile (Join-Path $dir 'run.json'); $q = [string]$run.quarantine
  if (Test-File (Join-Path $dir 'PURGED.txt')) { Say "run $($run.stamp) was already purged"; return }
  if (-not (Test-Dir $q)) { Say "nothing to purge: $q does not exist"; return }
  $files = @(Get-ChildItem -LiteralPath $q -Recurse -File -Force); $bytes = [int64]0; foreach ($f in $files) { $bytes += $f.Length }
  Sum ("Quarantine of run $($run.stamp): $q  -  $($files.Count) files, " + (Fmt-MB $bytes))
  foreach ($f in $files) { Add-Rep 'Purge' 'DELETE-PERMANENTLY' $(if ($Apply) { 'PLANNED' } else { 'CHECK' }) $f.FullName '' $f.Length }
  if (-not $Apply) { Sum 'CHECK ONLY: nothing deleted. Add -Apply to delete this quarantine folder permanently (rollback of that run is then no longer possible for these files).'; return }
  $rec = Join-Path $dir 'purged.csv'
  $files | Select-Object FullName, Length, LastWriteTime | Export-Csv -LiteralPath $rec -NoTypeInformation -Encoding UTF8
  Remove-Item -LiteralPath $q -Recurse -Force
  [IO.File]::WriteAllText((Join-Path $dir 'PURGED.txt'), ("purged " + (Get-Date -Format s) + "; $($files.Count) files; list in purged.csv`r`n"), $utf8)
  Sum "PURGED: $($files.Count) files deleted permanently; the list is kept in $rec"
}

# ------------------------------------------------------------------ shared lookups
function Rows-ByPath([string[]]$fps) { if (-not @($fps).Count) { return @{ rows = @(); thumbs = @() } }; $r = App @{ mode = 'rowsByPath'; items = @($fps) }; return @{ rows = @($r.rows); thumbs = @($r.thumbs) } }
function Rows-ByPrefix([string[]]$pf) { if (-not @($pf).Count) { return @{ rows = @(); thumbs = @() } }; $r = App @{ mode = 'rowsByPrefix'; items = @($pf) }; return @{ rows = @($r.rows); thumbs = @($r.thumbs) } }
function Ids($rows) { return ((@($rows) | ForEach-Object { [string]$_.id }) -join ' ') }
# covers that may go with an item: not used as thumbnail by a row that stays
function Free-Covers($covers, $deletedIds) {
  $out = @(); foreach ($c in @($covers | Where-Object { $_ })) { $refs = @((Rows-ByPath @(To-FP (Rel $c))).thumbs | Where-Object { @($deletedIds) -notcontains [string]$_.id }); if (-not $refs.Count) { $out += $c } }; return $out
}
function Block([string]$S, [string]$path, [string]$why) { Add-Rep $S 'BLOCKED' 'BLOCKED' $path '' '' '' $why; Sum "  BLOCKED: $why  ($path)"; $script:Problems++ }
function Listen-Check([string]$S, [string[]]$folders, [string]$when) {
  if (-not @($folders).Count) { return }
  $r = App @{ mode = 'listen'; folders = @($folders) }
  foreach ($f in @($r.folders)) {
    $ok = ($f.page -eq 200 -and -not $f.noplay -and $f.first -eq 206)
    Add-Rep $S "PLAYER-$when" $(if ($ok) { 'OK' } else { 'NOT-PLAYABLE' }) $f.folder '' '' '' ("page $($f.page), first track $($f.first) $($f.type)" + $(if ($f.noplay) { ', page says No playable audio found' } else { '' }))
    if ($when -eq 'after' -and -not $ok) { Sum "  VERIFY FAILED: player for $($f.folder): page $($f.page), first track $($f.first)"; $script:Problems++ }
  }
}
function Serve-Check([string]$S, $rels, [string]$wantType) {
  $rels = @($rels); if (-not $rels.Count) { return 0 }
  $bad = 0; $r = App @{ mode = 'serve'; items = @($rels | ForEach-Object { @{ rel = $_.Replace('\', '/') } }) }
  foreach ($x in @($r.items)) { if (-not (($x.code -eq 206 -or $x.code -eq 200) -and $x.type -like "$wantType*")) { $bad++; Sum "  VERIFY FAILED: $($x.u) -> $($x.code) $($x.type)"; Add-Rep $S 'VERIFY' 'FAILED' $x.u '' '' '' "$($x.code) $($x.type)" } }
  if (-not $bad) { Sum "  verify: $($rels.Count) file(s) served $wantType (206)" }
  $script:Problems += $bad; return $bad
}

# ================================================================== 1 WmaToMp3
function Sec-WmaToMp3([bool]$ap) {
  $S = 'WmaToMp3'; $todo = @(); $skip = 0; $bytes = [int64]0
  $wma = @(); foreach ($top in @('Music', 'Audiobooks')) { $d = Full $top; if (Test-Dir $d) { $wma += @(Get-ChildItem -LiteralPath $d -Recurse -File -Filter '*.wma' | Where-Object { $_.Extension -eq '.wma' }) } }
  foreach ($f in $wma) {
    $rel = Rel $f.FullName; $mp3 = $f.DirectoryName + '\' + $f.BaseName + '.mp3'
    if ($f.Name.Contains('"')) { Add-Rep $S 'CONVERT' 'SKIP' $rel '' $f.Length '' 'name contains a double quote'; $skip++; continue }
    if (Test-File $mp3) { Add-Rep $S 'CONVERT' 'SKIP' $rel (Rel $mp3) $f.Length '' 'an mp3 with this name already exists (kept, wma left in place)'; $skip++; continue }
    $todo += @{ f = $f; rel = $rel; mp3 = $mp3; mrel = (Rel $mp3) }; $bytes += $f.Length
  }
  $stale = (Rows-ByPath @($todo | ForEach-Object { To-FP $_.mrel })).rows
  if (@($stale).Count) { foreach ($r in $stale) { Block $S $r.file_path "catalog row $($r.id) already points at this mp3 name" }; return }
  $wrows = @((Rows-ByPath @($todo | ForEach-Object { To-FP $_.rel })).rows)
  $groups = @($todo | Group-Object { $p = $_.rel.Split('\'); $p[0] + '\' + $p[1] } | Sort-Object Name)
  Sum ("WmaToMp3: $($wma.Count) wma found, $($todo.Count) to convert (" + (Fmt-MB $bytes) + "), $skip skipped; wma rows in catalog: $($wrows.Count)")
  foreach ($g in $groups) { Sum ("  {0,-45} {1,3} files" -f $g.Name, $g.Count) }
  foreach ($t in $todo) { Add-Rep $S 'CONVERT' $(if ($ap) { 'PLANNED' } else { 'CHECK' }) $t.rel $t.mrel $t.f.Length '' 'mp3 next to it, wma -> quarantine, new catalog row' }
  $folders = @($groups | ForEach-Object { $_.Name.Replace('\', '/') })
  $enc = Invoke-Native $Previews @('ffmpeg', '-hide_banner', '-encoders')
  if (-not (@($enc.out) -match 'libmp3lame').Count) { Block $S $Previews 'ffmpeg in the previews container has no libmp3lame'; return }
  if (-not $todo.Count) { return }
  if (-not $ap) {
    Listen-Check $S $folders 'now'
    $t = $todo | Sort-Object { $_.f.Length } | Select-Object -First 1
    $sw = [Diagnostics.Stopwatch]::StartNew()
    $r = Invoke-Native $Previews @('ffmpeg', '-nostdin', '-hide_banner', '-v', 'error', '-threads', '1', '-i', (To-CPath $t.f.FullName), '-map', '0:a:0', '-c:a', 'libmp3lame', '-q:a', '4', '-threads', '1', '-f', 'null', '-')
    $sw.Stop()
    if ($r.code -ne 0) { Block $S $t.rel ('test encode failed: ' + ((@($r.out) | Select-Object -First 2) -join ' / ')); return }
    $est = $sw.Elapsed.TotalSeconds * $bytes / [Math]::Max(1, $t.f.Length)
    Sum ("  test encode (to nowhere) of the smallest file: {0:N1} s for {1}; estimated conversion time ~{2:N0} min (+ ~2 s per file for checks)" -f $sw.Elapsed.TotalSeconds, (Fmt-MB $t.f.Length), (($est + 2 * $todo.Count) / 60))
    return
  }
  $tmpH = Join-Path $ImporterData ("finish-cleanup-tmp\" + $script:Stamp); $tmpC = '/sandbox-data/finish-cleanup-tmp/' + $script:Stamp
  Ensure-Dir $tmpH
  $done = @(); $n = 0; $fail = 0
  foreach ($t in $todo) {
    $n++; $outName = ('{0:D3}.mp3' -f $n); $outH = Join-Path $tmpH $outName; $inC = To-CPath $t.f.FullName
    Say ("  [{0}/{1}] {2}" -f $n, $todo.Count, $t.rel)
    $r = Invoke-Native $Previews @('ffmpeg', '-nostdin', '-hide_banner', '-v', 'error', '-threads', '1', '-n', '-i', $inC, '-map', '0:a:0', '-map_metadata', '0', '-c:a', 'libmp3lame', '-q:a', '4', '-id3v2_version', '3', '-threads', '1', "$tmpC/$outName")
    if ($r.code -ne 0 -or -not (Test-File $outH)) { $fail++; Add-Rep $S 'CONVERT' 'FAILED' $t.rel '' '' '' ('ffmpeg: ' + ((@($r.out) | Select-Object -First 2) -join ' / ')); continue }
    $d1 = Probe $inC; $d2 = Probe "$tmpC/$outName"
    if ($d2.dur -le 0 -or [Math]::Abs($d1.dur - $d2.dur) -gt 1.0) { $fail++; Add-Rep $S 'CONVERT' 'FAILED' $t.rel '' '' '' ("duration wma {0:N1} s vs mp3 {1:N1} s (tmp file left in {2})" -f $d1.dur, $d2.dur, $outH); continue }
    if (Test-File $t.mp3) { Add-Rep $S 'CONVERT' 'SKIP' $t.rel '' '' '' 'mp3 appeared meanwhile'; continue }
    [IO.File]::Move($outH, $t.mp3); J-Add @('NEWFILE', $t.mp3, (Get-Sha $t.mp3))
    Do-Move $t.f.FullName (Q-Of $t.f.FullName)
    $done += $t; Add-Rep $S 'CONVERT' 'DONE' $t.rel $t.mrel (Get-Len $t.mp3) '' ("{0:N1} s" -f $d2.dur)
  }
  if (@($wrows).Count) { [void](Do-RowDelete 'wma' $wrows) }
  if ($done.Count) {
    $meta = @((App @{ mode = 'meta'; rels = @($done | ForEach-Object { $_.mrel.Replace('\', '/') }) }).rows)
    $ins = Do-RowInsertNew 'mp3' @($meta | ForEach-Object { Row-Hash $_ })
    Sum "  converted $($done.Count), failed $fail; catalog rows added: $(@($ins).Count) (ids $((@($ins) | ForEach-Object { $_.id } | Measure-Object -Minimum -Maximum | ForEach-Object { "$($_.Minimum)-$($_.Maximum)" })))"
    [void](Serve-Check $S @($done | ForEach-Object { $_.mrel }) 'audio/mpeg')
    Listen-Check $S $folders 'after'
  }
  if ($fail) { $script:Problems += $fail; Sum "  $fail file(s) not converted (see report); their wma stays in place" }
  if ((Test-Dir $tmpH) -and -not @(Get-ChildItem -LiteralPath $tmpH -Force).Count) { [IO.Directory]::Delete($tmpH) }
}

# ================================================================== 2 MislabeledFolders
function Sec-MislabeledFolders([bool]$ap) {
  $S = 'MislabeledFolders'
  $pairs = @(@('Audiobooks\Discipleship', 'Audiobooks\7 Habits of Highly Effective Families'),
    @('Audiobooks\J Grisham - The Finisher', 'Audiobooks\D Baldacci - The Finisher V Janes Series 1'),
    @('Audiobooks\J Grisham - The Guilty', 'Audiobooks\D Baldacci - The Guilty W Robie Series 4'))
  $plan = @()
  foreach ($p in $pairs) {
    $bad = Full $p[0]; $good = Full $p[1]
    if (-not (Test-Dir $bad)) { Add-Rep $S 'QUARANTINE-FOLDER' 'ALREADY-DONE' $p[0]; Sum "MislabeledFolders: $($p[0]) is already gone"; continue }
    if (-not (Test-Dir $good)) { Block $S $p[1] 'correctly named folder not found (would need a rename instead)'; continue }
    $bf = @(Get-ChildItem -LiteralPath $bad -Recurse -File -Force); $gf = @(Get-ChildItem -LiteralPath $good -Recurse -File -Force | Where-Object { $AudioExt -contains $_.Extension.ToLowerInvariant() })
    $ba = @($bf | Where-Object { $AudioExt -contains $_.Extension.ToLowerInvariant() }); $ok = $true
    foreach ($a in $ba) {
      $c = @($gf | Where-Object { $_.Length -eq $a.Length }); $h = Get-Sha $a.FullName; $m = $null
      foreach ($x in $c) { if ((Get-Sha $x.FullName) -eq $h) { $m = $x; break } }
      if (-not $m) { $ok = $false; Block $S (Rel $a.FullName) "no byte-identical copy in $($p[1])"; break }
    }
    if (-not $ok) { continue }
    if (-not $ba.Count) { Block $S $p[0] 'no audio inside (unexpected)'; continue }
    $rr = Rows-ByPrefix @((To-FP $p[0]) + '/'); $ids = @($rr.rows | ForEach-Object { [string]$_.id })
    $outside = @($rr.thumbs | Where-Object { $ids -notcontains [string]$_.id }); if ($outside.Count) { Block $S $p[0] "a cover inside is used by row(s) $(Ids $outside)"; continue }
    $gr = @((Rows-ByPrefix @((To-FP $p[1]) + '/')).rows)
    $covers = @(Free-Covers (Get-Covers (Parent-Of $bad) (Leaf-Of $bad)) $ids)
    $sz = [int64]0; foreach ($f in $bf) { $sz += $f.Length }
    Sum ("MislabeledFolders: {0} -> quarantine: {1} audio files all byte-identical to {2} (which keeps {3} rows); rows to delete: {4}; covers beside it: {5}; {6}" -f $p[0], $ba.Count, $p[1], $gr.Count, $ids.Count, $covers.Count, (Fmt-MB $sz))
    Add-Rep $S 'QUARANTINE-FOLDER' $(if ($ap) { 'PLANNED' } else { 'CHECK' }) $p[0] $p[1] $sz (Ids $rr.rows) "$($ba.Count) audio parts identical (SHA256) to the kept folder"
    foreach ($c in $covers) { Add-Rep $S 'QUARANTINE-COVER' $(if ($ap) { 'PLANNED' } else { 'CHECK' }) (Rel $c) '' (Get-Len $c) }
    $plan += @{ bad = $bad; good = $p[1]; rows = @($rr.rows); covers = $covers }
  }
  if (-not $ap) { Listen-Check $S @($pairs | ForEach-Object { $_[1].Replace('\', '/') }) 'now'; return }
  foreach ($x in $plan) {
    [void](Do-RowDelete 'mislabeled' $x.rows); Do-Quarantine $x.bad; foreach ($c in $x.covers) { Do-Quarantine $c }
    Add-Rep $S 'QUARANTINE-FOLDER' 'DONE' (Rel $x.bad) (Q-Of $x.bad) '' (Ids $x.rows)
  }
  if ($plan.Count) { Listen-Check $S @($plan | ForEach-Object { $_.good.Replace('\', '/') }) 'after' }
}

# ================================================================== 3 RemoveBroken
function Sec-RemoveBroken([bool]$ap) {
  $S = 'RemoveBroken'; $st = $(if ($ap) { 'PLANNED' } else { 'CHECK' }); $plan = @()
  $m = Full 'Music\Misty Edwards'
  if (-not (Test-Dir $m)) { Add-Rep $S 'QUARANTINE-FOLDER' 'ALREADY-DONE' 'Music\Misty Edwards'; Sum 'RemoveBroken: Music\Misty Edwards already gone' }
  else {
    $f = @(Get-ChildItem -LiteralPath $m -Recurse -File -Force); $media = @($f | Where-Object { ($AudioExt + $VideoExt) -contains $_.Extension.ToLowerInvariant() })
    if ($media.Count) { Block $S 'Music\Misty Edwards' "has $($media.Count) audio/video file(s) now; not removed" }
    else {
      $rr = Rows-ByPrefix @('content/Music/Misty Edwards/'); $ids = @($rr.rows | ForEach-Object { [string]$_.id }); $cv = @(Free-Covers (Get-Covers (Full 'Music') 'Misty Edwards') $ids)
      Sum ("RemoveBroken: Music\Misty Edwards -> quarantine ({0} file(s): {1}; no audio); rows {2}; covers beside it {3}" -f $f.Count, (($f | ForEach-Object { $_.Name }) -join ', '), $ids.Count, $cv.Count)
      Add-Rep $S 'QUARANTINE-FOLDER' $st 'Music\Misty Edwards' '' '' (Ids $rr.rows) 'empty music folder (cover only)'
      $plan += @{ path = $m; rows = @($rr.rows); covers = $cv }
    }
  }
  $grel = 'Audiobooks\Guns, Germs and Steel - Sociology and History\Guns Germs and Steel.mp3'; $g = Full $grel
  if (-not (Test-File $g)) {
    $rr = Rows-ByPath @(To-FP $grel)
    if (@($rr.rows).Count) { Sum "RemoveBroken: Guns Germs and Steel.mp3 is no longer on disk (not in any quarantine folder of these scripts); its catalog row $(Ids $rr.rows) is removed"; Add-Rep $S 'ROW-DELETE' $st $grel '' '' (Ids $rr.rows) 'file already gone from disk'; $plan += @{ path = ''; rows = @($rr.rows); covers = @() } }
    else { Add-Rep $S 'QUARANTINE-FILE' 'ALREADY-DONE' $grel '' '' '' 'file and row already gone'; Sum 'RemoveBroken: Guns Germs and Steel.mp3 is already gone from disk and catalog (the book folder plays tracks 1-64)' }
  }
  else {
    $len = Get-Len $g; $pr = Probe (To-CPath $g)
    if ($len -ge 1MB -or $pr.dur -ge 1) { Block $S $grel ("ffprobe reads it ({0:N1} s, {1} bytes): not broken, not removed" -f $pr.dur, $len) }
    else {
      $rr = Rows-ByPath @(To-FP $grel); $ids = @($rr.rows | ForEach-Object { [string]$_.id }); $cv = @(Free-Covers (Get-Covers (Parent-Of $g) 'Guns Germs and Steel') $ids)
      $left = @(Get-ChildItem -LiteralPath (Parent-Of $g) -File | Where-Object { $AudioExt -contains $_.Extension.ToLowerInvariant() -and $_.FullName -ne $g }).Count
      $why = $(if ($pr.dur -lt 0) { 'ffprobe: ' + $pr.msg } else { "duration $($pr.dur) s" })
      Sum ("RemoveBroken: {0} ({1} bytes) confirmed broken ({2}) -> quarantine; rows {3}; covers {4}; the book folder keeps {5} other audio file(s)" -f $grel, $len, $why, (Ids $rr.rows), $cv.Count, $left)
      Add-Rep $S 'QUARANTINE-FILE' $st $grel '' $len (Ids $rr.rows) $why
      $plan += @{ path = $g; rows = @($rr.rows); covers = $cv }
    }
  }
  if (-not $ap) { return }
  foreach ($x in $plan) { [void](Do-RowDelete 'broken' $x.rows); if (-not $x.path) { continue }; Do-Quarantine $x.path; foreach ($c in $x.covers) { Do-Quarantine $c }; Add-Rep $S 'QUARANTINE' 'DONE' (Rel $x.path) (Q-Of $x.path) '' (Ids $x.rows) }
}

# ================================================================== 4 MusicEducationToVideos
function Sec-MusicEducationToVideos([bool]$ap) {
  $S = 'MusicEducationToVideos'; $st = $(if ($ap) { 'PLANNED' } else { 'CHECK' })
  $srcRel = 'Music\Music Education'; $src = Full $srcRel; $dst = Full $MusicEduTarget; $tseg = $MusicEduTarget.Split('\')
  if (-not (Test-Dir $src)) { Add-Rep $S 'MOVE' 'ALREADY-DONE' $srcRel; Sum "MusicEducationToVideos: $srcRel is already gone"; return }
  if ($tseg.Count -ne 2) { Block $S $MusicEduTarget 'target must be <Category>\<Subcategory>'; return }
  if (-not (Test-Dir (Full $tseg[0]))) { Block $S $tseg[0] 'target category folder not found'; return }
  $files = @(Get-ChildItem -LiteralPath $src -File -Force); $sub = @(Get-ChildItem -LiteralPath $src -Directory -Force)
  if ($sub.Count) { Block $S $srcRel "has sub-folders: $(($sub | ForEach-Object { $_.Name }) -join ', ')"; return }
  $vids = @($files | Where-Object { $VideoExt -contains $_.Extension.ToLowerInvariant() })
  $stems = @($vids | ForEach-Object { $_.BaseName })
  $imgs = @($files | Where-Object { ($ImgExt -contains $_.Extension.ToLowerInvariant()) -and ($stems -contains $_.BaseName) })
  $junk = @($files | Where-Object { @('thumbs.db', 'desktop.ini') -contains $_.Name.ToLowerInvariant() })
  $other = @($files | Where-Object { ($vids -notcontains $_) -and ($imgs -notcontains $_) -and ($junk -notcontains $_) })
  Sum "MusicEducationToVideos: $srcRel holds $($files.Count) file(s); destination (Videos section) = $MusicEduTarget"
  foreach ($f in $files) { Sum ("  - {0}  ({1})" -f $f.Name, (Fmt-MB $f.Length)) }
  if ($other.Count) { Block $S $srcRel ("other files that are not a video or its cover: " + (($other | ForEach-Object { $_.Name }) -join ', ')); return }
  if (-not $vids.Count) { Block $S $srcRel 'no video lessons inside'; return }
  $mv = @($vids) + @($imgs); $clash = @($mv | Where-Object { (Test-File (Join-Path $dst $_.Name)) -or (Test-Dir (Join-Path $dst $_.Name)) })
  if ($clash.Count) { Block $S $MusicEduTarget ("name already used in the destination: " + (($clash | ForEach-Object { $_.Name }) -join ', ')); return }
  $rr = Rows-ByPrefix @('content/Music/Music Education/'); $ids = @($rr.rows | ForEach-Object { [string]$_.id })
  $outside = @($rr.thumbs | Where-Object { $ids -notcontains [string]$_.id }); if ($outside.Count) { Block $S $srcRel "a cover is used by other row(s) $(Ids $outside)"; return }
  $upd = @()
  foreach ($r in $rr.rows) {
    $name = ([string]$r.file_path).Substring(([string]'content/Music/Music Education/').Length)
    $v = @($vids | Where-Object { $_.Name -ceq $name }); if (-not $v.Count) { Block $S $r.file_path "row $($r.id) points at a file that is not one of the videos"; return }
    $nrel = $MusicEduTarget + '\' + $name; $th = $null
    foreach ($e in $ImgExt) { $c = @($imgs | Where-Object { $_.Name -ceq ($v[0].BaseName + $e) }); if ($c.Count) { $th = To-FP ($MusicEduTarget + '\' + $c[0].Name); break } }
    $upd += @{ row = $r; set = [ordered]@{ content_id = (To-FP $nrel); file_path = (To-FP $nrel); category = $tseg[0]; subcategory = $tseg[1]; thumbnail_path = $th } }
  }
  $noRow = @($vids | Where-Object { $n = $_.Name; -not @($rr.rows | Where-Object { ([string]$_.file_path).EndsWith('/' + $n) }).Count })
  $tc = @(); if (Test-Dir $dst) { $tc = @(Get-ChildItem -LiteralPath $dst -File | Where-Object { $VideoExt -contains $_.Extension.ToLowerInvariant() }) }
  Sum ("  destination {0} {1}: {2} video(s) there now -> {3} after the move" -f $MusicEduTarget, $(if (Test-Dir $dst) { 'exists' } else { 'will be created' }), $tc.Count, ($tc.Count + $vids.Count))
  foreach ($x in ($tc | Select-Object -First 15)) { Sum "    = $($x.Name)" }
  if ($tc.Count -gt 15) { Sum "    = ... and $($tc.Count - 15) more" }
  Sum "  rows moved with them: $(Ids $rr.rows) (category -> $($tseg[0]), subcategory -> $($tseg[1]))$(if ($noRow.Count) { "; videos without a row: $($noRow.Count)" })"
  $tile = @(Free-Covers (Get-Covers (Full 'Music') 'Music Education') $ids)
  Sum "  afterwards the emptied folder$(if ($tile.Count) { ' and its Music tile image ' + (($tile | ForEach-Object { Leaf-Of $_ }) -join ', ') }) go to quarantine (no dead Music tile)"
  foreach ($f in $mv) { Add-Rep $S 'MOVE' $st (Rel $f.FullName) ($MusicEduTarget + '\' + $f.Name) $f.Length (Ids @($rr.rows | Where-Object { ([string]$_.file_path).EndsWith('/' + $f.Name) })) "into the Videos category $MusicEduTarget" }
  Add-Rep $S 'QUARANTINE-FOLDER' $st $srcRel '' '' '' 'emptied after the move'
  foreach ($c in $tile) { Add-Rep $S 'QUARANTINE-COVER' $st (Rel $c) '' (Get-Len $c) }
  if (-not $ap) { return }
  foreach ($f in $mv) { Do-Move $f.FullName (Join-Path $dst $f.Name) }
  [void](Do-RowUpdate 'music-education' $upd)
  if (-not @(Get-ChildItem -LiteralPath $src -Force | Where-Object { $junk -notcontains $_ -and @('thumbs.db', 'desktop.ini') -notcontains $_.Name.ToLowerInvariant() }).Count) { Do-Quarantine $src }
  foreach ($c in $tile) { Do-Quarantine $c }
  Add-Rep $S 'MOVE' 'DONE' $srcRel $MusicEduTarget '' (Ids $rr.rows)
  [void](Serve-Check $S @($vids | ForEach-Object { $MusicEduTarget + '\' + $_.Name }) 'video/mp4')
}

# ================================================================== 5 Placeholder
function Sec-Placeholder([bool]$ap) {
  $S = 'Placeholder'; $t = App @{ mode = 'tiles' }; $tot = @{ section_placeholder = 0; section_dead_thumb = 0; loose_placeholder = 0; video_items_no_thumb = 0; music_folder_no_cover = 0 }
  $lines = @()
  foreach ($pp in $t.cats.PSObject.Properties) {
    $c = $pp.Value; foreach ($k in @($tot.Keys)) { $tot[$k] += [int]$c.$k }
    $tiles = [int]$c.section_placeholder + [int]$c.section_dead_thumb + [int]$c.loose_placeholder + [int]$c.music_folder_no_cover
    if ($tiles -or [int]$c.video_items_no_thumb) { $lines += [pscustomobject]@{ cat = $pp.Name; tiles = $tiles; items = [int]$c.video_items_no_thumb; c = $c }
      Add-Rep $S 'COUNT' 'INFO' $pp.Name '' '' '' ("section tiles without picture {0} (no thumb {1}, dead thumb {2}), loose-section tile {3}, music folders without cover {4}, video items without thumbnail {5} (of {6} sections)" -f $tiles, $c.section_placeholder, $c.section_dead_thumb, $c.loose_placeholder, $c.music_folder_no_cover, $c.video_items_no_thumb, $c.sections) }
  }
  $tt = $tot.section_placeholder + $tot.section_dead_thumb + $tot.loose_placeholder + $tot.music_folder_no_cover
  Sum ("Placeholder: images/sample.png exists now: {0}. Tiles that show it: {1} (sections with no picture {2}, sections whose first item's thumbnail file is gone {3}, loose-item tiles {4}, Music folders without cover {5}); video items without a thumbnail (lazy thumb first shows it): {6}; total picture spots {7}" -f $t.sample_exists, $tt, $tot.section_placeholder, $tot.section_dead_thumb, $tot.loose_placeholder, $tot.music_folder_no_cover, $tot.video_items_no_thumb, ($tt + $tot.video_items_no_thumb))
  foreach ($l in ($lines | Sort-Object { $_.tiles + $_.items } -Descending | Select-Object -First 15)) { Sum ("  {0,-42} tiles {1,4}   video items {2,5}" -f $l.cat, $l.tiles, $l.items) }
  if ($lines.Count -gt 15) { Sum "  ... $($lines.Count - 15) more categories (all in the report CSV)" }
  Sum '  Option A (this script): one neutral placeholder picture at images/sample.png - fixes every broken tile at once, nothing else changes.'
  Sum '  Option B (proposal only, not built): per-folder covers generated from the first video frame / first PDF page, written as <Category>\<Sub>.jpg.'
  $dst = Join-Path $Htdocs 'images\sample.png'
  if (Test-File $dst) { Add-Rep $S 'INSTALL' 'SKIP' $dst '' (Get-Len $dst) '' 'already there: kept as it is'; Sum "  $dst already exists: left alone"; return }
  $bytes = [Convert]::FromBase64String($PlaceholderB64)
  Add-Rep $S 'INSTALL' $(if ($ap) { 'PLANNED' } else { 'CHECK' }) $dst '' $bytes.Length '' 'neutral 480x270 light-grey picture placeholder (new file, new folder if needed)'
  Sum "  install: $dst ($($bytes.Length) bytes, new file$(if (-not (Test-Dir (Parent-Of $dst))) { ', folder images\ is created' }))"
  if (-not $ap) { return }
  Do-NewFile $dst $bytes
  $r = App @{ mode = 'serve'; items = @(@{ url = '/images/sample.png' }) }; $x = @($r.items)[0]
  if (($x.code -eq 200 -or $x.code -eq 206) -and $x.type -like 'image/png*') { Sum "  verify: /images/sample.png served $($x.code) $($x.type)"; Add-Rep $S 'INSTALL' 'DONE' $dst } else { Sum "  VERIFY FAILED: /images/sample.png -> $($x.code) $($x.type)"; $script:Problems++ }
}

# ================================================================== 6 DiscardBatch
function Sec-DiscardBatch([bool]$ap) {
  $S = 'DiscardBatch'; $bp = Join-Path $ImporterData ("guided-v08\$BatchId\batch.json")
  $i = WebPhp @{ mode = 'info'; id = $BatchId }
  $desc = "state '$($i.state_v)', created $($i.created), screen '$($i.screen)', $($i.rows) planned rows, $($i.ops) operations ($($i.ops_done) done), $($i.files) staged files, $($i.events) events, $($i.bytes) bytes"
  if ($i.state_v -eq 'discarded') { Add-Rep $S 'DISCARD' 'ALREADY-DONE' $bp '' $i.bytes '' $desc; Sum "DiscardBatch: $BatchId is already discarded"; return }
  if (@('draft', 'approved') -notcontains $i.state_v) { Block $S $bp "state is '$($i.state_v)' (only draft/approved are discarded)"; return }
  if ([int]$i.ops -gt 0 -or [int]$i.files -gt 0) { Block $S $bp "batch has $($i.ops) operations / $($i.files) staged files: not discarded"; return }
  if (@($i.refs).Count) { Block $S $bp ("other importer data refers to it: " + (@($i.refs) -join ', ')); return }
  Sum "DiscardBatch: $BatchId ($desc) -> state 'discarded' (record and folder kept, nothing published or copied; SHA256 now $($i.sha))"
  Add-Rep $S 'DISCARD' $(if ($ap) { 'PLANNED' } else { 'CHECK' }) $bp '' $i.bytes '' $desc
  if (-not $ap) { return }
  $copy = Join-Path $script:Bk "batch-$BatchId-before.json"
  [IO.File]::Copy($bp, $copy, $false)
  if ((Get-Sha $copy) -ne ([string]$i.sha).ToUpperInvariant()) { throw 'backup copy of batch.json does not match the checked SHA256; nothing written' }
  $r = WebPhp @{ mode = 'discard'; id = $BatchId; expect_sha = [string]$i.sha }
  J-Add @('BATCH', $BatchId, $copy, [string]$i.sha, [string]$r.after_sha)
  $v = WebPhp @{ mode = 'info'; id = $BatchId }
  if ($v.state_v -ne 'discarded') { Sum "  VERIFY FAILED: state is $($v.state_v)"; $script:Problems++ } else { Sum "  verify: state 'discarded' (previous '$($v.previous_state)'), backup $copy"; Add-Rep $S 'DISCARD' 'DONE' $bp $copy }
}

# ================================================================== 7 NameClashes
function Sec-NameClashes([bool]$ap) {
  $S = 'NameClashes'; $st = $(if ($ap) { 'PLANNED' } else { 'CHECK' }); $plan = @()
  foreach ($fr in @('Construction Technology\Concrete Calculations, Info and Tips', 'Music\African Music')) {
    $dir = Full $fr; if (-not (Test-Dir $dir)) { Sum "NameClashes: $fr not found"; continue }
    $hits = @(Get-ChildItem -LiteralPath $dir -File | Where-Object { ($AudioExt + $VideoExt + @('.pdf')) -contains $_.Extension.ToLowerInvariant() -and $_.BaseName.EndsWith('.') })
    if (-not $hits.Count) { Add-Rep $S 'CLASH' 'ALREADY-DONE' $fr '' '' '' 'no "..." name left in this folder'; Sum "NameClashes: $fr - no double-dot name left (already settled)"; continue }
    foreach ($h in $hits) {
      $clean = $h.BaseName.TrimEnd('.'); $cp = Join-Path $dir ($clean + $h.Extension); $rel = Rel $h.FullName
      $rr = Rows-ByPath @(To-FP $rel); $ids = @($rr.rows | ForEach-Object { [string]$_.id })
      if (-not (Test-File $cp)) { Add-Rep $S 'CLASH' 'SKIP' $rel '' $h.Length (Ids $rr.rows) 'no clean-named file next to it: not a clash, left alone'; Sum "NameClashes: $rel has no clean-named twin: left alone"; continue }
      $same = ((Get-Len $cp) -eq $h.Length) -and ((Get-Sha $cp) -eq (Get-Sha $h.FullName))
      $cv = @(Get-Covers $dir $h.BaseName)
      if ($same) {
        $kr = @((Rows-ByPath @(To-FP (Rel $cp))).rows); if (-not $kr.Count) { Block $S (Rel $cp) 'identical twin has no catalog row; not removed'; continue }
        $fcv = @(Free-Covers $cv $ids)
        Sum "NameClashes: $rel is byte-identical to $($clean + $h.Extension) (row $(Ids $kr)) -> quarantine, rows $(Ids $rr.rows) removed, covers $($fcv.Count)"
        Add-Rep $S 'QUARANTINE-DUPLICATE' $st $rel (Rel $cp) $h.Length (Ids $rr.rows) 'identical (SHA256)'
        $plan += @{ kind = 'dup'; f = $h; rows = @($rr.rows); covers = $fcv }
      } else {
        $nstem = $clean + ' (2)'; $np = Join-Path $dir ($nstem + $h.Extension)
        if (Test-File $np) { Block $S $rel "$nstem$($h.Extension) already exists"; continue }
        $moves = @(@{ s = $h.FullName; d = $np }); foreach ($c in $cv) { $d = Join-Path $dir ($nstem + (Ext-Of $c)); if (Test-File $d) { Block $S (Rel $c) 'renamed cover name already used'; $moves = $null; break }; $moves += @{ s = $c; d = $d } }
        if ($null -eq $moves) { continue }
        Sum ("NameClashes: {0} ({1:N0} bytes) differs from {2} ({3:N0} bytes) -> renamed to {4}{5} (+{6} cover), row {7} follows" -f $rel, $h.Length, ($clean + $h.Extension), (Get-Len $cp), $nstem, $h.Extension, $cv.Count, (Ids $rr.rows))
        Add-Rep $S 'RENAME' $st $rel (Rel $np) $h.Length (Ids $rr.rows) 'different content, both kept'
        $plan += @{ kind = 'ren'; moves = $moves; rows = @($rr.rows); np = $np }
      }
    }
  }
  if (-not $ap) { return }
  foreach ($x in $plan) {
    if ($x.kind -eq 'dup') { [void](Do-RowDelete 'clash-dup' $x.rows); Do-Quarantine $x.f.FullName; foreach ($c in $x.covers) { Do-Quarantine $c }; Add-Rep $S 'QUARANTINE-DUPLICATE' 'DONE' (Rel $x.f.FullName) (Q-Of $x.f.FullName) '' (Ids $x.rows); continue }
    foreach ($m in $x.moves) { Do-Move $m.s $m.d }
    $nrel = Rel $x.np; $meta = @((App @{ mode = 'meta'; rels = @($nrel.Replace('\', '/')) }).rows)[0]
    $upd = @($x.rows | ForEach-Object { @{ row = $_; set = [ordered]@{ content_id = $meta.content_id; file_path = $meta.file_path; title = $meta.title; thumbnail_path = $meta.thumbnail_path } } })
    [void](Do-RowUpdate 'clash-rename' $upd)
    if (-not @($x.rows).Count) { [void](Do-RowInsertNew 'clash-rename' @(Row-Hash $meta)) }
    Add-Rep $S 'RENAME' 'DONE' $nrel '' '' (Ids $x.rows) $meta.title
    $ty = $(if ((Ext-Of $x.np) -eq '.mp3') { 'audio/mpeg' } else { 'video/mp4' }); [void](Serve-Check $S @($nrel) $ty)
  }
}

# ================================================================== 8 HistoryPdfs
function Sec-HistoryPdfs([bool]$ap) {
  $S = 'HistoryPdfs'; $st = $(if ($ap) { 'PLANNED' } else { 'CHECK' }); $plan = @()
  $hdir = Full 'History'; $into = 'History\History'
  $loose = @(Get-ChildItem -LiteralPath $hdir -File -Filter '*.pdf' | Where-Object { $_.Extension -eq '.pdf' })
  $pool = @(); foreach ($k in @('History\History', 'Books\History')) { $d = Full $k; if (Test-Dir $d) { $pool += @(Get-ChildItem -LiteralPath $d -Recurse -File -Filter '*.pdf') } }
  $nDup = 0; $nMove = 0
  foreach ($f in $loose) {
    $rel = Rel $f.FullName; $rr = Rows-ByPath @(To-FP $rel); $ids = @($rr.rows | ForEach-Object { [string]$_.id })
    $h = $null; $keep = $null
    foreach ($c in @($pool | Where-Object { $_.Length -eq $f.Length })) { if (-not $h) { $h = Get-Sha $f.FullName }; if ((Get-Sha $c.FullName) -eq $h) { $keep = $c; break } }
    $cv = @(Get-Covers $hdir $f.BaseName)
    if ($keep) {
      $krel = Rel $keep.FullName; $kr = @((Rows-ByPath @(To-FP $krel)).rows)
      if (-not $kr.Count) { Block $S $rel "identical copy $krel has no catalog row; loose copy kept"; continue }
      $kcv = @(Get-Covers $keep.DirectoryName $keep.BaseName); $give = $null; $cvOut = @(Free-Covers $cv $ids)
      if (-not $kcv.Count -and $cvOut.Count) { $give = $cvOut[0]; $cvOut = @($cvOut | Select-Object -Skip 1) }
      $nDup++
      Add-Rep $S 'QUARANTINE-DUPLICATE' $st $rel $krel $f.Length (Ids $rr.rows) ("identical (SHA256) to the kept copy (row $(Ids $kr))" + $(if ($give) { '; its cover moves next to the kept copy' } else { '' }))
      $plan += @{ kind = 'dup'; f = $f; rows = @($rr.rows); covers = $cvOut; give = $give; keep = $keep; krow = $kr[0] }
    } else {
      $np = Join-Path (Full $into) $f.Name; $n = 2
      while ((Test-File $np) -or @($cv | Where-Object { Test-File (Join-Path (Full $into) ((Stem-Of (Leaf-Of $np)) + (Ext-Of $_))) }).Count) { $np = Join-Path (Full $into) ("{0} ({1}).pdf" -f $f.BaseName, $n); $n++ }
      $nMove++
      Add-Rep $S 'MOVE' $st $rel (Rel $np) $f.Length (Ids $rr.rows) "no identical copy; moved into $into with $($cv.Count) cover(s)"
      $plan += @{ kind = 'move'; f = $f; rows = @($rr.rows); covers = $cv; np = $np }
    }
  }
  $orph = @(Get-ChildItem -LiteralPath $hdir -File | Where-Object { ($ImgExt -contains $_.Extension.ToLowerInvariant()) -and $_.Name -ne 'History.jpg' } | Where-Object { $b = $_.BaseName; -not @(Get-ChildItem -LiteralPath $hdir -File | Where-Object { $_.BaseName -eq $b -and $ImgExt -notcontains $_.Extension.ToLowerInvariant() }).Count })
  Sum "HistoryPdfs: $($loose.Count) loose PDFs in History\ : $nDup identical to a copy in History\History or Books\History -> quarantine; $nMove unique -> moved into $into. (Left alone: History.jpg and $($orph.Count) picture(s) with no file next to them.)"
  if (-not $ap) { return }
  foreach ($x in $plan) {
    if ($x.kind -eq 'dup') {
      [void](Do-RowDelete 'history-dup' $x.rows); Do-Quarantine $x.f.FullName
      if ($x.give) { $d = Join-Path $x.keep.DirectoryName ($x.keep.BaseName + (Ext-Of $x.give)); Do-Move $x.give $d
        if (-not [string]$x.krow.thumbnail_path) { [void](Do-RowUpdate 'history-keeper-cover' @(@{ row = $x.krow; set = [ordered]@{ thumbnail_path = (To-FP (Rel $d)) } })) } }
      foreach ($c in $x.covers) { Do-Quarantine $c }
      continue
    }
    Do-Move $x.f.FullName $x.np; $ns = Stem-Of (Leaf-Of $x.np); foreach ($c in $x.covers) { Do-Move $c (Join-Path (Parent-Of $x.np) ($ns + (Ext-Of $c))) }
    $meta = @((App @{ mode = 'meta'; rels = @((Rel $x.np).Replace('\', '/')) }).rows)[0]
    if (@($x.rows).Count) { [void](Do-RowUpdate 'history-move' @($x.rows | ForEach-Object { @{ row = $_; set = [ordered]@{ content_id = $meta.content_id; file_path = $meta.file_path; subcategory = $meta.subcategory; title = $meta.title; thumbnail_path = $meta.thumbnail_path } } })) }
    else { [void](Do-RowInsertNew 'history-move' @(Row-Hash $meta)) }
  }
  $mv = @($plan | Where-Object { $_.kind -eq 'move' } | ForEach-Object { Rel $_.np }); if ($mv.Count) { [void](Serve-Check $S $mv 'application/pdf') }
  Sum "  done: $nDup quarantined, $nMove moved"
}

# ================================================================== 9 Lightroom
function Sec-Lightroom([bool]$ap) {
  $S = 'Lightroom'; $st = $(if ($ap) { 'PLANNED' } else { 'CHECK' })
  $cat = 'Adobe Lightroom'; $keepC = 'Lightroom Classic CC Essential Training'; $drop = "$cat\$keepC\83 - Creating a collection and selecting a template.mp4"
  $courses = @('Enhancing a Landscape Photo with Lightroom', 'How To Get Started With Lightroom 5', 'Lightroom 5 In Depth - Books and Print', 'Lightroom CC First Look',
    'Lightroom Classic CC 2015 and  Lightroom Mobile- Raw Workflows', 'Lightroom Classic CC 2015 and Photoshop Workflows- Start-to-Finish Studies', 'Using Lightroom and Photoshop Together', 'Using Wacom Tablets with Lightroom Classic CC 2015')
  $cr = @((App @{ mode = 'catRows'; category = $cat }).rows); $other = @($cr | Where-Object { @('825', '826') -notcontains [string]$_.id })
  if ($other.Count) { Block $S $cat "category has other rows now: $(Ids $other)"; return }
  $items = @()
  foreach ($c in $courses) {
    $d = Full "$cat\$c"
    if (Test-Dir $d) { $fl = @(Get-ChildItem -LiteralPath $d -Recurse -File -Force | Where-Object { @('thumbs.db', 'desktop.ini') -notcontains $_.Name.ToLowerInvariant() }); if ($fl.Count) { Block $S "$cat\$c" "course folder is not empty ($($fl.Count) files)"; return }; $items += "$cat\$c" }
    foreach ($e in $ImgExt) { $p = "$cat\$c$e"; if (Test-File (Full $p)) { $items += $p } }
  }
  $r826 = @($cr | Where-Object { [string]$_.id -eq '826' })
  $df = Full $drop; $dOk = Test-File $df
  if ($dOk -and (Get-Len $df) -ne 0) { Block $S $drop "video #83 is not 0 bytes any more ($((Get-Len $df)) bytes)"; return }
  if ($r826.Count -and [string]$r826[0].file_path -cne (To-FP $drop)) { Block $S $drop "row 826 points elsewhere: $($r826[0].file_path)"; return }
  if ($dOk) { $items += $drop }
  $nd = @($items | Where-Object { Test-Dir (Full $_) }).Count
  Sum "Lightroom: $nd empty course folders + $($items.Count - $nd - [int]$dOk) covers + $(if ($dOk) { 'the 0-byte video #83' } else { 'no #83 file (already gone)' }) -> quarantine; row 826 $(if ($r826.Count) { 'deleted' } else { 'already gone' }). Kept: $keepC (row 825), Lightroom Tutorials.jpg, ightroom Tutorials.jpg, $keepC.jpg"
  foreach ($p in $items) { Add-Rep $S 'QUARANTINE' $st $p '' $(if (Test-File (Full $p)) { Get-Len (Full $p) } else { '' }) $(if ($p -eq $drop -and $r826.Count) { '826' } else { '' }) }
  if ($r826.Count -and -not $dOk) { Add-Rep $S 'ROW-DELETE' $st $drop '' '' '826' 'file already gone' }
  if (-not $ap) { return }
  if ($r826.Count) { $full = @((App @{ mode = 'rowsById'; ids = @(826) }).rows); [void](Do-RowDelete 'lightroom' $full) }
  foreach ($p in $items) { Do-Quarantine (Full $p) }
  $left = @((App @{ mode = 'catRows'; category = $cat }).rows); Sum "  verify: category $cat now has row(s) $(Ids $left)"
  [void](Serve-Check $S @($left | ForEach-Object { ([string]$_.file_path).Substring(8).Replace('/', '\') }) 'video/mp4')
}

# ================================================================== 10 MissingRows
function Sec-MissingRows([bool]$ap) {
  $S = 'MissingRows'; $st = $(if ($ap) { 'PLANNED' } else { 'CHECK' })
  $rows = @((App @{ mode = 'rowsById'; ids = @($MissingRowIds) }).rows)
  $gone = $MissingRowIds.Count - $rows.Count; $back = @($rows | Where-Object { $_._exists }); $del = @($rows | Where-Object { -not $_._exists })
  foreach ($r in $back) { Add-Rep $S 'ROW-DELETE' 'SKIP' $r.file_path '' '' ([string]$r.id) 'file exists again: row kept' }
  foreach ($r in $del) { Add-Rep $S 'ROW-DELETE' $st $r.file_path '' '' ([string]$r.id) ("$($r.content_type), file missing") }
  $all = @((App @{ mode = 'missingInCats'; cats = @('Audiobooks') }).rows); $more = @($all | Where-Object { $MissingRowIds -notcontains [int]$_.id })
  Sum "MissingRows: of the 21 rows, $($del.Count) still point at a missing file -> backed up and deleted; $($back.Count) have their file again (kept); $gone already gone. (Info: Audiobooks has $($more.Count) other row(s) with a missing file - not touched.)"
  foreach ($m in ($more | Select-Object -First 10)) { Add-Rep $S 'INFO-OTHER-MISSING' 'INFO' $m.file_path '' '' ([string]$m.id) 'not in the approved list: not touched' }
  if (-not $ap -or -not $del.Count) { return }
  $n = Do-RowDelete 'missing' $del; Sum "  deleted $n row(s)"
}

# ================================================================== main
$mode = 'check'; if ($Rollback) { $mode = 'rollback' } elseif ($Purge) { $mode = 'purge' } elseif ($Apply) { $mode = 'apply' }
if ($Rollback -and ($Purge -or $Apply -or $Only)) { throw '-Rollback runs on its own (no -Apply, -Purge or -Only)' }
if ($Purge -and $Only) { throw '-Purge takes no -Only' }
$sel = @($AllSections)
if ($Only) {
  $sel = @()
  foreach ($w in $Only.Split(',')) { $w = $w.Trim(); if (-not $w) { continue }; $m = @($AllSections | Where-Object { $_ -ieq $w }); if (-not $m.Count) { throw "unknown section '$w'. Sections: $($AllSections -join ', ')" }; $sel += $m[0] }
  $sel = @($AllSections | Where-Object { $sel -contains $_ })
}
$stampNow = Get-Date -Format 'yyyyMMdd-HHmmss'
Say "Finish-LibraryCleanup r1b - mode $mode - $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') - library $Lib"
if (-not (Test-Dir $Lib)) { throw "library not found: $Lib" }
$times = @()
if ($mode -eq 'rollback') { Do-Rollback $Rollback }
elseif ($mode -eq 'purge') { Do-Purge $Purge }
else {
  $ap = ($mode -eq 'apply')
  if ($ap) {
    $script:Stamp = $stampNow; $bk = Join-Path $BackupRoot $script:Stamp; $q = Join-Path $QuarantineRoot ("finish-cleanup-" + $script:Stamp)
    if ((Test-Dir $bk) -or (Test-Dir $q)) { throw "run folder already exists: $bk" }
    [void][IO.Directory]::CreateDirectory($bk); $script:Bk = $bk; $script:Q = $q
    $run = [ordered]@{ stamp = $script:Stamp; quarantine = $q; sections = $sel; library = $Lib; started = (Get-Date -Format s); script = $PSCommandPath }
    $fs = New-Object IO.FileStream((Join-Path $bk 'run.json'), [IO.FileMode]::CreateNew); try { $b = $utf8.GetBytes((ConvertTo-Json -InputObject $run -Depth 5)); $fs.Write($b, 0, $b.Length) } finally { $fs.Close() }
    Say "APPLY run $($script:Stamp): backup + journal $bk ; quarantine $q"
  } else { Say 'CHECK ONLY: nothing is changed. Add -Apply to do it.' }
  foreach ($s in $sel) {
    Say ''; Say "=== $s ==="; $sw = [Diagnostics.Stopwatch]::StartNew(); $p0 = $script:Problems
    try { & ("Sec-" + $s) $ap } catch { $script:Problems++; Sum "  SECTION $s STOPPED: $($_.Exception.Message)"; Add-Rep $s 'ERROR' 'FAILED' '' '' '' $_.Exception.Message; if ($ap) { Sum "  (finished steps of $s are in the journal; -Rollback $($script:Stamp) undoes the whole run)" } }
    $sw.Stop(); $times += ("{0,-24} {1,7:N1} s  {2}" -f $s, $sw.Elapsed.TotalSeconds, $(if ($script:Problems -gt $p0) { 'PROBLEMS' } else { 'ok' }))
  }
}
$el = (Get-Date) - $script:T0
$tag = $(if ($script:Stamp) { $script:Stamp } else { $stampNow })
$base = Join-Path $ReportDir ("Finish cleanup $mode $tag"); $csv = "$base.csv"; $txt = "$base.txt"; $n = 2
while ((Test-Path -LiteralPath $csv) -or (Test-Path -LiteralPath $txt)) { $csv = "$base ($n).csv"; $txt = "$base ($n).txt"; $n++ }
$script:Rep | Export-Csv -LiteralPath $csv -NoTypeInformation -Encoding UTF8
$hdr = @("Finish-LibraryCleanup r1b - mode $mode - $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') - sections: $($sel -join ', ')", ("elapsed {0:N1} min; problems/blocked: {1}" -f $el.TotalMinutes, $script:Problems), '') + @($times) + @('')
if ($script:Bk) { $hdr += "backup/journal: $($script:Bk)"; $hdr += "quarantine:     $($script:Q)"; $hdr += '' }
[IO.File]::WriteAllLines($txt, [string[]]($hdr + @($script:Sum)), $utf8)
if ($script:Bk -and $mode -eq 'apply') { Copy-Item -LiteralPath $csv -Destination (Join-Path $script:Bk 'report.csv'); Copy-Item -LiteralPath $txt -Destination (Join-Path $script:Bk 'report.txt') }
Say ''; foreach ($t in $times) { Say $t }
Say ("Done in {0:N1} min. Problems/blocked: {1}. Report: {2}" -f $el.TotalMinutes, $script:Problems, $txt)
if ($script:Problems) { exit 1 }
