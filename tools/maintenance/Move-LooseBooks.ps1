<#
  Move-LooseBooks.ps1  (Windows PowerShell 5.1)

  The Books page (books.php) only shows SUBFOLDERS of videos\Books that contain PDFs, so PDFs lying loose
  in videos\Books itself never get a card and cannot be opened. This script moves those loose PDFs (and
  their cover images) into videos\Books\Books, so they appear as one "Books" card.

    .\Move-LooseBooks.ps1                     check only (default) - changes nothing
    .\Move-LooseBooks.ps1 -Apply              backup + move + verify + partial index + catalog fix + web check
    .\Move-LooseBooks.ps1 -Rollback latest    undo the newest -Apply (or pass the backup folder path)
    .\Move-LooseBooks.ps1 -VerifyOnly         only the web check of the Books\Books card

  -Apply in detail:
    1. re-runs every check and stops if any fails
    2. new backup folder D:\edutek-system\backups\loose-books\<stamp>\ with manifest.json (path, size, SHA256
       of every file) and catalog-rows-before.json (the catalog rows of the loose PDFs, exactly as they are)
    3. same-volume Move-Item of each PDF and cover into Books\Books (never overwrites; a failure moves
       everything already moved straight back), then SHA256 re-check at the new place
    4. queues a PARTIAL index of only the moved PDFs through the Edutek Import Partial Indexer trigger folder,
       the same way Publish-Batch.ps1 does (request name loosebooks-<stamp>, so no catalog cleanup runs)
    5. removes ONLY the old catalog rows of the moved PDFs, and only when the old file is gone, the new file
       exists and its new row was indexed (all-or-nothing, in one transaction)
    6. checks over HTTP that the Books page lists the Books card with the right count, that the card's list
       shows every PDF, and that a sample PDF opens
  It never runs a full index or the catalog cleanup, and does not touch .env, the repo, batch records or audits.
#>
[CmdletBinding()]
param(
  [switch]$Apply,
  [string]$Rollback = '',
  [switch]$VerifyOnly,
  [string]$Library = 'D:\xampp\htdocs\Edutek\videos',
  [string]$Category = 'Books',
  [string]$Subfolder = '',
  [int]$ExpectCount = 27,
  [string]$BackupRoot = 'D:\edutek-system\backups\loose-books',
  [string]$TriggerDir = 'D:\edutek-system\bulk-loader-fresh-v05\data\index-trigger',
  [string]$Container = 'edupak-app',
  [string]$WebPrefix = 'content',
  [string]$BaseUrl = 'http://localhost:8080',
  [string]$CsvList = '',
  [int]$IndexTimeoutSec = 240,
  [switch]$NoThumbFix,
  [switch]$SkipDb,
  [switch]$SkipIndex,
  [switch]$SkipHttp,
  [switch]$DbSelfTest
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$utf8 = New-Object System.Text.UTF8Encoding($false)
if (-not $Subfolder) { $Subfolder = $Category }
if (-not $CsvList) { $CsvList = Join-Path ([Environment]::GetFolderPath('Desktop')) 'Loose files in categories.csv' }
$catDir  = Join-Path $Library $Category
$destDir = Join-Path $catDir $Subfolder
$script:problems = New-Object System.Collections.ArrayList
$script:warnings = New-Object System.Collections.ArrayList

# Covers whose file name does not match their PDF (found 2026-10-22). They are renamed to <pdf name>.jpg while
# moving so the cover shows. Use -NoThumbFix to move them under their old name instead.
$ThumbFix = @{
  'Ida in Love and in Trouble - Veronica Chambers' = 'Ida in Love and in Trouble - Veronice Chambers.jpg'
  'Mona Lisa Vanishes'                             = 'Mona List Vanishes.jpg'
  'Not the End of the World-Sustainable Hope'      = 'Not the End of the World-Sustainable Hope .jpg'
}

function Say([string]$m, [string]$c = 'Gray') { Write-Host $m -ForegroundColor $c }
function Problem([string]$m) { [void]$script:problems.Add($m); Write-Host ("  PROBLEM: " + $m) -ForegroundColor Red }
function Warn([string]$m) { [void]$script:warnings.Add($m); Write-Host ("  warning: " + $m) -ForegroundColor Yellow }
function Write-NewText([string]$path, [string]$text) {
  # write to a temp name, then move into place (same as the importer tooling)
  $tmp = $path + '.tmp-' + [guid]::NewGuid().ToString('N').Substring(0, 8)
  [IO.File]::WriteAllText($tmp, $text, $utf8)
  Move-Item -LiteralPath $tmp -Destination $path -Force
}
function Save-Manifest { if ($script:manPath) { Write-NewText $script:manPath ($script:man | ConvertTo-Json -Depth 8) } }
function Get-Sha([string]$p) { (Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash }
function Fmt-MB([double]$b) { '{0:N1} MB' -f ($b / 1MB) }

# ---------------------------------------------------------------- PHP helper (runs inside the app container)
$HelperPhp = @'
<?php
/* Move-LooseBooks helper. Copied to /tmp in the app container by Move-LooseBooks.ps1 and removed after use.
   Touches only the content_ids it is given. Never indexes, never touches media files. Prints one JSON line. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$opt = getopt('', ['mode:', 'input:', 'out:', 'root:', 'table:']);
function lb_out(array $o, int $c = 0): void {
    echo json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
    exit($c);
}
try {
    require_once '/var/www/html/includes/config.php';
    require_once '/var/www/html/includes/auth.php';
} catch (Throwable $e) { lb_out(['state' => 'error', 'message' => 'config: ' . $e->getMessage()], 1); }
$mode  = (string)($opt['mode'] ?? '');
$table = (string)($opt['table'] ?? 'content_meta');
if (!preg_match('/^[a-z_]+$/', $table)) { lb_out(['state' => 'error', 'message' => 'bad table name'], 1); }
$root = rtrim(str_replace('\\', '/', (string)($opt['root'] ?? CONTENT_PATH)), '/');
$in = [];
if (isset($opt['input'])) {
    $in = json_decode((string)@file_get_contents((string)$opt['input']), true);
    if (!is_array($in)) { lb_out(['state' => 'error', 'message' => 'cannot read input json'], 1); }
}
function lb_abs(string $cid, string $root): ?string {
    $p = basename($root) . '/';
    if (strpos($cid, $p) !== 0 || strpos($cid, '..') !== false) { return null; }
    return $root . '/' . substr($cid, strlen($p));
}
function lb_row(PDO $pdo, string $t, string $cid): ?array {
    $s = $pdo->prepare("SELECT * FROM `$t` WHERE content_id = :c");
    $s->execute([':c' => $cid]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
function lb_run(PDO $pdo, string $mode, string $t, string $root, array $pairs, array $rows, ?string $out): array {
    $res = ['state' => '', 'mode' => $mode, 'pairs' => count($pairs), 'message' => '', 'details' => []];
    $info = [];
    foreach ($pairs as $p) {
        $old = (string)($p['old'] ?? ''); $new = (string)($p['new'] ?? '');
        $oa = lb_abs($old, $root); $na = lb_abs($new, $root);
        if ($oa === null || $na === null) { $res['state'] = 'error'; $res['message'] = "bad content_id: $old / $new"; return $res; }
        $info[] = ['old' => $old, 'new' => $new, 'old_row' => lb_row($pdo, $t, $old), 'new_row' => lb_row($pdo, $t, $new),
                   'old_file' => is_file($oa), 'new_file' => is_file($na)];
    }
    foreach (['old_row', 'new_row', 'old_file', 'new_file'] as $k) {
        $res[$k . 's'] = count(array_filter($info, function ($i) use ($k) { return !empty($i[$k]); }));
    }
    if ($mode === 'check') {
        foreach ($info as $i) {
            $res['details'][] = ['old' => $i['old'], 'old_row' => $i['old_row'] !== null, 'new_row' => $i['new_row'] !== null,
                                 'old_file' => $i['old_file'], 'new_file' => $i['new_file']];
        }
        $res['state'] = 'ok'; return $res;
    }
    if ($mode === 'export') {
        if (!$out) { $res['state'] = 'error'; $res['message'] = '--out missing'; return $res; }
        $list = [];
        foreach ($info as $i) { if ($i['old_row'] !== null) { $list[] = $i['old_row']; } }
        $json = json_encode(['table' => $t, 'exported' => date(DATE_ATOM), 'rows' => $list],
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($out, $json) === false) { $res['state'] = 'error'; $res['message'] = 'cannot write export'; return $res; }
        $res['exported'] = count($list); $res['state'] = 'ok'; return $res;
    }
    if ($mode === 'remove-old') {
        $bad = [];
        foreach ($info as $i) {
            if ($i['old_row'] === null) { continue; }
            if ($i['old_file'])            { $bad[] = $i['old'] . ': old file still exists'; }
            elseif (!$i['new_file'])       { $bad[] = $i['new'] . ': new file missing'; }
            elseif ($i['new_row'] === null) { $bad[] = $i['new'] . ': new catalog row missing (not indexed yet)'; }
        }
        if ($bad) { $res['state'] = 'held'; $res['message'] = 'nothing removed'; $res['details'] = $bad; return $res; }
        $pdo->beginTransaction();
        $d = $pdo->prepare("DELETE FROM `$t` WHERE content_id = :c AND id = :id");
        $n = 0;
        foreach ($info as $i) {
            if ($i['old_row'] === null) { continue; }
            $d->execute([':c' => $i['old'], ':id' => $i['old_row']['id']]);
            if ($d->rowCount() !== 1) { $pdo->rollBack(); $res['state'] = 'error'; $res['message'] = 'unexpected delete count for ' . $i['old'] . ', nothing removed'; return $res; }
            $n++; $res['details'][] = $i['old'];
        }
        $pdo->commit();
        $res['removed'] = $n; $res['state'] = 'done'; return $res;
    }
    if ($mode === 'restore') {
        $cols = array_column($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $by = [];
        foreach ($rows as $r) { if (is_array($r) && isset($r['content_id'])) { $by[(string)$r['content_id']] = $r; } }
        $bad = [];
        foreach ($info as $i) { if (!$i['old_file']) { $bad[] = $i['old'] . ': old file is not back yet'; } }
        if ($bad) { $res['state'] = 'held'; $res['message'] = 'nothing restored'; $res['details'] = $bad; return $res; }
        $pdo->beginTransaction();
        $ins = 0; $del = 0;
        $idCheck = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE id = :id");
        $d = $pdo->prepare("DELETE FROM `$t` WHERE content_id = :c AND id = :id");
        foreach ($info as $i) {
            if ($i['new_row'] !== null && !$i['new_file']) {
                $d->execute([':c' => $i['new'], ':id' => $i['new_row']['id']]);
                $del += $d->rowCount();
            }
            if ($i['old_row'] === null && isset($by[$i['old']])) {
                $r = $by[$i['old']];
                $keys = array_values(array_intersect(array_keys($r), $cols));
                if (in_array('id', $keys, true)) {
                    $idCheck->execute([':id' => $r['id']]);
                    if ((int)$idCheck->fetchColumn() > 0) { $keys = array_values(array_diff($keys, ['id'])); }
                }
                $sql = "INSERT INTO `$t` (`" . implode('`,`', $keys) . "`) VALUES (" .
                       implode(',', array_map(function ($k) { return ':p' . $k; }, array_keys($keys))) . ")";
                $s = $pdo->prepare($sql);
                $vals = [];
                foreach ($keys as $n => $k) { $vals[':p' . $n] = $r[$k]; }
                $s->execute($vals);
                $ins++; $res['details'][] = $i['old'];
            }
        }
        $pdo->commit();
        $res['inserted'] = $ins; $res['deleted_new'] = $del; $res['state'] = 'done'; return $res;
    }
    $res['state'] = 'error'; $res['message'] = 'unknown mode'; return $res;
}
try {
    $pdo = getDbConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pairs = (isset($in['pairs']) && is_array($in['pairs'])) ? $in['pairs'] : [];
    if ($mode === 'selftest') {
        /* Throwaway test: a session-only TEMPORARY copy of the listed rows and dummy files under /tmp.
           The real table and the real library are only read. */
        $pairs = array_slice($pairs, 0, 3);
        $tr = '/tmp/lb-selftest-' . getmypid() . '/content';
        $ids = [];
        foreach ($pairs as $p) { $r = lb_row($pdo, 'content_meta', (string)$p['old']); if ($r === null) { lb_out(['state' => 'error', 'message' => 'no row for ' . $p['old']], 1); } $ids[] = (int)$r['id']; }
        if (!$ids) { lb_out(['state' => 'error', 'message' => 'no pairs'], 1); }
        $pdo->exec('CREATE TEMPORARY TABLE lb_selftest AS SELECT * FROM content_meta WHERE id IN (' . implode(',', $ids) . ')');
        $pdo->exec('ALTER TABLE lb_selftest ADD PRIMARY KEY (id), ADD UNIQUE KEY (content_id)');
        $orig = $pdo->query('SELECT * FROM lb_selftest ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pairs as $p) {
            foreach ([(string)$p['old'], (string)$p['new']] as $c) { $a = lb_abs($c, $tr); if (!is_dir(dirname($a))) { mkdir(dirname($a), 0777, true); } }
            file_put_contents(lb_abs((string)$p['old'], $tr), 'dummy');
        }
        $steps = [];
        $exp = '/tmp/lb-selftest-' . getmypid() . '/rows.json';
        $r = lb_run($pdo, 'export', 'lb_selftest', $tr, $pairs, [], $exp); $steps['export'] = $r['state'] . '/' . ($r['exported'] ?? '');
        $backup = json_decode((string)file_get_contents($exp), true)['rows'];
        $r = lb_run($pdo, 'remove-old', 'lb_selftest', $tr, $pairs, [], null); $steps['remove_before_move'] = $r['state'];
        foreach ($pairs as $p) { rename(lb_abs((string)$p['old'], $tr), lb_abs((string)$p['new'], $tr)); }
        $r = lb_run($pdo, 'remove-old', 'lb_selftest', $tr, $pairs, [], null); $steps['remove_before_index'] = $r['state'];
        /* simulate the partial index: a new row at the new path (MySQL cannot read and write one temporary table in one statement) */
        foreach ($pairs as $p) {
            $o = lb_row($pdo, 'lb_selftest', (string)$p['old']);
            $o['id'] = (int)$o['id'] + 900000000; $o['content_id'] = (string)$p['new']; $o['file_path'] = (string)$p['new']; $o['subcategory'] = 'Books';
            $k = array_keys($o);
            $pdo->prepare('INSERT INTO lb_selftest (`' . implode('`,`', $k) . '`) VALUES (' . implode(',', array_fill(0, count($k), '?')) . ')')->execute(array_values($o));
        }
        $r = lb_run($pdo, 'remove-old', 'lb_selftest', $tr, $pairs, [], null); $steps['remove_after_index'] = $r['state'] . '/' . ($r['removed'] ?? '');
        $r = lb_run($pdo, 'restore', 'lb_selftest', $tr, $pairs, $backup, null); $steps['restore_before_moveback'] = $r['state'];
        foreach ($pairs as $p) { rename(lb_abs((string)$p['new'], $tr), lb_abs((string)$p['old'], $tr)); }
        $r = lb_run($pdo, 'restore', 'lb_selftest', $tr, $pairs, $backup, null); $steps['restore'] = $r['state'] . '/ins ' . ($r['inserted'] ?? '') . '/del ' . ($r['deleted_new'] ?? '');
        $after = $pdo->query('SELECT * FROM lb_selftest ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $steps['identical_after_restore'] = ($after == $orig);
        $pdo->exec('DROP TEMPORARY TABLE lb_selftest');
        exec('rm -rf ' . escapeshellarg('/tmp/lb-selftest-' . getmypid()));
        $ok = $steps['export'] === 'ok/' . count($pairs) && $steps['remove_before_move'] === 'held' && $steps['remove_before_index'] === 'held'
           && $steps['remove_after_index'] === 'done/' . count($pairs) && $steps['restore_before_moveback'] === 'held'
           && $steps['restore'] === 'done/ins ' . count($pairs) . '/del ' . count($pairs) && $steps['identical_after_restore'] === true;
        lb_out(['state' => $ok ? 'ok' : 'failed', 'mode' => 'selftest', 'steps' => $steps], $ok ? 0 : 1);
    }
    $rows = (isset($in['rows']) && is_array($in['rows'])) ? $in['rows'] : [];
    $res = lb_run($pdo, $mode, $table, $root, $pairs, $rows, isset($opt['out']) ? (string)$opt['out'] : null);
    lb_out($res, $res['state'] === 'error' ? 1 : 0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
    lb_out(['state' => 'error', 'message' => $e->getMessage()], 1);
}
'@

function Invoke-Docker([string[]]$a) {
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = & docker @a 2>&1 | ForEach-Object { "$_" }; $code = $LASTEXITCODE } finally { $ErrorActionPreference = $old }
  [pscustomobject]@{ code = $code; out = (@($o) -join "`n") }
}
function Invoke-Helper([string]$mode, $inputObj, [string]$workDir, [string]$pullOutTo = '') {
  $tag = 'lb-' + [guid]::NewGuid().ToString('N').Substring(0, 10)
  $php = Join-Path $workDir ($tag + '.php'); $inp = Join-Path $workDir ($tag + '.json')
  [IO.File]::WriteAllText($php, $HelperPhp, $utf8)
  [IO.File]::WriteAllText($inp, ($inputObj | ConvertTo-Json -Depth 8 -Compress), $utf8)
  try {
    foreach ($f in @($php, $inp)) {
      $r = Invoke-Docker @('cp', $f, ($Container + ':/tmp/' + [IO.Path]::GetFileName($f)))
      if ($r.code -ne 0) { throw "docker cp failed: $($r.out)" }
    }
    $args2 = @('exec', $Container, 'php', "/tmp/$tag.php", "--mode=$mode", "--input=/tmp/$tag.json")
    if ($pullOutTo) { $args2 += "--out=/tmp/$tag-out.json" }
    $r = Invoke-Docker $args2
    $line = @($r.out -split "`n" | Where-Object { $_.Trim().StartsWith('{') }) | Select-Object -Last 1
    if (-not $line) { throw "helper ($mode) gave no result: $($r.out)" }
    $res = $line | ConvertFrom-Json
    if ($pullOutTo -and $res.state -eq 'ok') {
      $c = Invoke-Docker @('cp', ($Container + ":/tmp/$tag-out.json"), $pullOutTo)
      if ($c.code -ne 0) { throw "docker cp (export) failed: $($c.out)" }
    }
    return $res
  } finally {
    [void](Invoke-Docker @('exec', $Container, 'rm', '-f', "/tmp/$tag.php", "/tmp/$tag.json", "/tmp/$tag-out.json"))
    if ($workDir -eq $env:TEMP) { Remove-Item -LiteralPath $php, $inp -Force -ErrorAction SilentlyContinue }
  }
}

# ---------------------------------------------------------------- partial index through the trigger folder
function Request-Index([string]$name, [string[]]$files) {
  $req = [ordered]@{ batch = $name; files = @($files) }
  $tmp = Join-Path $TriggerDir ($name + '.request.tmp')
  $final = Join-Path $TriggerDir ($name + '.request.json')
  if ((Test-Path -LiteralPath $final) -or (Test-Path -LiteralPath (Join-Path $TriggerDir ($name + '.result.json')))) { throw "index request $name already exists" }
  [IO.File]::WriteAllText($tmp, ($req | ConvertTo-Json -Compress), $utf8)
  Move-Item -LiteralPath $tmp -Destination $final
  Say ("  queued partial index request $name (" + @($files).Count + " files)")
  $resPath = Join-Path $TriggerDir ($name + '.result.json')
  $deadline = (Get-Date).AddSeconds($IndexTimeoutSec)
  while ((Get-Date) -lt $deadline) {
    if (Test-Path -LiteralPath $resPath) {
      Start-Sleep -Milliseconds 500
      try { return (Get-Content -LiteralPath $resPath -Raw -Encoding UTF8 | ConvertFrom-Json) } catch { }
    }
    Start-Sleep -Seconds 3
  }
  return $null
}

function Test-IndexResult($r, [int]$n) {
  if ($null -eq $r) { return "no result within $IndexTimeoutSec s (the request stays queued and will run when the indexer picks it up)" }
  $state = [string]$r.state; $out = ''
  if ($r.PSObject.Properties['output']) { $out = [string]$r.output }
  $line = ($out -split "`r?`n" | Where-Object { $_ -match '^Indexed \d+ items' } | Select-Object -First 1)
  Say ("  indexer: state=$state  " + $line)
  if ($state -ne 'complete') { return "indexer state is '$state'" }
  if ($line -notmatch "^Indexed $n items") { return "expected 'Indexed $n items', got '$line'" }
  return ''
}

# ---------------------------------------------------------------- web check
function Get-Web([string]$url, [string]$method = 'Get') {
  try { return Invoke-WebRequest -Uri $url -Method $method -UseBasicParsing -TimeoutSec 120 -MaximumRedirection 0 -ErrorAction Stop }
  catch { $resp = $_.Exception.Response; if ($resp) { return [pscustomobject]@{ StatusCode = [int]$resp.StatusCode; Content = ''; Headers = @{} } } throw }
}
function Join-Url([string]$rel) { if ($rel -match '^https?://') { return $rel }; return ($BaseUrl.TrimEnd('/') + '/' + $rel.TrimStart('/')) }
function Encode-Path([string]$p) { (($p -split '/') | ForEach-Object { [Uri]::EscapeDataString($_) }) -join '/' }

function Test-BooksWeb([string]$sub, [int]$expect, [string]$sampleName) {
  $ok = $true
  $page = Get-Web (Join-Url 'books.php')
  if ($page.StatusCode -ne 200) { Problem "books.php returned $($page.StatusCode)"; return $false }
  $cards = [regex]::Matches($page.Content, '<a class="book-category-card__link" href="([^"]*)">.*?<b class="rtitle">([^<]*)</b>.*?\(([\d,]+) PDFs?\)', 'Singleline')
  $card = $null
  foreach ($c in $cards) { if ([Net.WebUtility]::HtmlDecode($c.Groups[2].Value).Trim() -eq $sub) { $card = $c; break } }
  Say ("  books.php: 200, " + $cards.Count + " cards")
  if (-not $card) { Problem "the Books page has no '$sub' card"; return $false }
  $n = [int]($card.Groups[3].Value -replace ',', '')
  Say ("  card '$sub' shows ($n PDFs)")
  if ($expect -gt 0 -and $n -ne $expect) { Problem "card '$sub' shows $n PDFs, expected $expect"; $ok = $false }
  $list = Get-Web (Join-Url ([Net.WebUtility]::HtmlDecode($card.Groups[1].Value)))
  if ($list.StatusCode -ne 200) { Problem "the '$sub' book list returned $($list.StatusCode)"; return $false }
  $links = [regex]::Matches($list.Content, '<a href="(readpdf\.php\?[^"]*)">\s*<p>\s*<b class="rtitle">([^<]*)</b>', 'Singleline')
  Say ("  book list: " + $links.Count + " books")
  if ($expect -gt 0 -and $links.Count -ne $expect) { Problem "book list shows $($links.Count) books, expected $expect"; $ok = $false }
  if ($links.Count -eq 0) { return $false }
  $pick = $links[0]
  if ($sampleName) { foreach ($l in $links) { if ([Net.WebUtility]::HtmlDecode($l.Groups[2].Value).Trim() -eq $sampleName) { $pick = $l; break } } }
  $pname = [Net.WebUtility]::HtmlDecode($pick.Groups[2].Value).Trim()
  $rp = Get-Web (Join-Url ([Net.WebUtility]::HtmlDecode($pick.Groups[1].Value)))
  if ($rp.StatusCode -ne 200 -or $rp.Content -match 'PDF not found') { Problem "opening '$pname' failed (HTTP $($rp.StatusCode), 'PDF not found' shown: $($rp.Content -match 'PDF not found'))"; return $false }
  $emb = [regex]::Match($rp.Content, '<(?:embed|iframe|object)[^>]+(?:src|data)="([^"]+\.pdf[^"]*)"', 'IgnoreCase')
  if ($emb.Success) {
    $pdfUrl = Join-Url ([Net.WebUtility]::HtmlDecode($emb.Groups[1].Value))
    if ($pdfUrl -match ' ') { $u = [Uri]$pdfUrl; $pdfUrl = $u.AbsoluteUri }
    $h = Get-Web $pdfUrl 'Head'
    $ct = ''; try { $ct = [string]$h.Headers['Content-Type'] } catch { }
    Say ("  sample '$pname': reader page 200, PDF HTTP " + $h.StatusCode + " " + $ct)
    if ($h.StatusCode -ne 200) { Problem "sample PDF returned HTTP $($h.StatusCode)"; $ok = $false }
  } else {
    Say ("  sample '$pname': reader page 200 (no embedded PDF url found to HEAD)")
    Warn "reader page for '$pname' has no recognisable PDF url"
  }
  return $ok
}

# ---------------------------------------------------------------- plan
function Get-Plan {
  $items = New-Object System.Collections.ArrayList
  $pdfs = @(Get-ChildItem -LiteralPath $catDir -File -Filter '*.pdf' | Where-Object { $_.Extension -eq '.pdf' } | Sort-Object Name)
  $taken = @{}
  foreach ($p in $pdfs) {
    $base = [IO.Path]::GetFileNameWithoutExtension($p.Name)
    [void]$items.Add([ordered]@{ kind = 'pdf'; src = $p.FullName; dst = (Join-Path $destDir $p.Name); size = $p.Length
      old_rel = ($Category + '/' + $p.Name); new_rel = ($Category + '/' + $Subfolder + '/' + $p.Name); sha256 = ''; state = 'planned' })
    $thumbs = @()
    foreach ($e in '.jpg', '.jpeg', '.png', '.webp') { $t = Join-Path $catDir ($base + $e); if (Test-Path -LiteralPath $t -PathType Leaf) { $thumbs += , @($t, (Join-Path $destDir ($base + $e))) } }
    if ($thumbs.Count -eq 0 -and -not $NoThumbFix -and $ThumbFix.ContainsKey($base)) {
      $t = Join-Path $catDir $ThumbFix[$base]
      if (Test-Path -LiteralPath $t -PathType Leaf) { $thumbs += , @($t, (Join-Path $destDir ($base + [IO.Path]::GetExtension($t).ToLower()))) }
    }
    foreach ($t in $thumbs) {
      if ($taken.ContainsKey($t[0])) { continue }; $taken[$t[0]] = 1
      $fi = Get-Item -LiteralPath $t[0]
      [void]$items.Add([ordered]@{ kind = 'thumb'; src = $fi.FullName; dst = $t[1]; size = $fi.Length; old_rel = ''; new_rel = ''; sha256 = ''; state = 'planned' })
    }
  }
  return , $items
}

function Invoke-Checks($items) {
  $pdfItems = @($items | Where-Object { $_.kind -eq 'pdf' })
  $thumbItems = @($items | Where-Object { $_.kind -eq 'thumb' })
  Say ""; Say "Loose PDFs in $catDir -> $destDir" 'Cyan'
  foreach ($i in $pdfItems) {
    $base = [IO.Path]::GetFileNameWithoutExtension($i.src)
    $th = @($thumbItems | Where-Object { [IO.Path]::GetFileNameWithoutExtension($_.dst) -eq $base })
    $tl = if ($th.Count) { (($th | ForEach-Object { $s = [IO.Path]::GetFileName($_.src); $d = [IO.Path]::GetFileName($_.dst); if ($s -ne $d) { "$s  (renamed to $d)" } else { $s } }) -join ', ') } else { 'NO COVER' }
    Say ("  " + [IO.Path]::GetFileName($i.src) + "  [" + (Fmt-MB $i.size) + "]  cover: " + $tl)
  }
  $tot = 0; foreach ($i in $items) { $tot += $i.size }
  Say ("  " + $pdfItems.Count + " PDFs + " + $thumbItems.Count + " covers, " + (Fmt-MB $tot))
  Say ""; Say "Checks" 'Cyan'
  if ($ExpectCount -gt 0 -and $pdfItems.Count -ne $ExpectCount) { Problem "found $($pdfItems.Count) loose PDFs, expected $ExpectCount (pass -ExpectCount $($pdfItems.Count) if that is right)" }
  if ($pdfItems.Count -eq 0) { Problem "no loose PDFs to move" }
  if (Test-Path -LiteralPath $destDir) {
    if (-not (Test-Path -LiteralPath $destDir -PathType Container)) { Problem "$destDir exists and is not a folder" }
    else { Say ("  $destDir already exists (" + @(Get-ChildItem -LiteralPath $destDir -File).Count + " files) - files are added, nothing replaced") }
  } else { Say "  $destDir will be created" }
  $coll = 0
  foreach ($i in $items) { if (Test-Path -LiteralPath $i.dst) { Problem ("name already taken in destination: " + [IO.Path]::GetFileName($i.dst)); $coll++ } }
  if ($coll -eq 0) { Say "  no name collisions in the destination" }
  foreach ($i in $thumbItems) {
    $b = [IO.Path]::GetFileNameWithoutExtension($i.src)
    if (Test-Path -LiteralPath (Join-Path $catDir $b) -PathType Container) { Problem ("cover " + [IO.Path]::GetFileName($i.src) + " is also the card image of folder '$b'") }
  }
  $cardImg = @('.jpg', '.jpeg', '.png' | Where-Object { Test-Path -LiteralPath (Join-Path $catDir ($Subfolder + $_)) })
  if ($cardImg.Count) { Say ("  card image for the new '$Subfolder' card: $Subfolder" + $cardImg[0] + " (left in place)") } else { Warn "no $Subfolder.jpg in $catDir - the new card will have no picture" }
  if ([IO.Path]::GetPathRoot($catDir) -ne [IO.Path]::GetPathRoot($destDir)) { Problem "source and destination are on different volumes" }
  try {
    $drv = New-Object IO.DriveInfo ([IO.Path]::GetPathRoot((Resolve-Path -LiteralPath $catDir).ProviderPath))
    $bdrv = New-Object IO.DriveInfo ([IO.Path]::GetPathRoot($BackupRoot))
    Say ("  free space: " + $drv.Name + " " + (Fmt-MB $drv.AvailableFreeSpace) + "; backup drive " + $bdrv.Name + " " + (Fmt-MB $bdrv.AvailableFreeSpace) + " (same-volume move needs no extra space)")
    if ($bdrv.AvailableFreeSpace -lt 200MB) { Problem "less than 200 MB free on the backup drive" }
  } catch { Warn "could not read free space: $($_.Exception.Message)" }
  if (Test-Path -LiteralPath $CsvList) {
    try {
      $csv = @(Import-Csv -LiteralPath $CsvList | Where-Object { $_.Category -eq $Category } | ForEach-Object { $_.File })
      $have = @($pdfItems | ForEach-Object { [IO.Path]::GetFileName($_.src) })
      $miss = @($csv | Where-Object { $have -notcontains $_ }); $extra = @($have | Where-Object { $csv -notcontains $_ })
      if ($miss.Count -or $extra.Count) { Warn ("differs from $CsvList - not on disk: " + ($miss -join '; ') + " | not in list: " + ($extra -join '; ')) }
      else { Say ("  matches the " + $csv.Count + " $Category rows in " + [IO.Path]::GetFileName($CsvList)) }
    } catch { Warn "could not read $CsvList" }
  }
  if (-not $SkipIndex) {
    if (-not (Test-Path -LiteralPath $TriggerDir -PathType Container)) { Problem "index trigger folder $TriggerDir not found" }
    else {
      $hb = Join-Path $TriggerDir 'indexer-heartbeat.txt'
      if (Test-Path -LiteralPath $hb) {
        $age = ((Get-Date) - (Get-Item -LiteralPath $hb).LastWriteTime).TotalSeconds
        if ($age -gt 90) { Problem ("Edutek Import Partial Indexer heartbeat is " + [int]$age + " s old - it does not seem to be running") } else { Say ("  partial indexer is running (heartbeat " + [int]$age + " s ago)") }
      } else { Problem "no indexer heartbeat file in $TriggerDir" }
      $pend = @(Get-ChildItem -LiteralPath $TriggerDir -Filter '*.request.json' -File -ErrorAction SilentlyContinue)
      if ($pend.Count) { Warn ("other index requests are waiting: " + (($pend | ForEach-Object { $_.Name }) -join ', ')) }
    }
  }
  if (-not $SkipDb -or -not $SkipIndex) {
    $r = Invoke-Docker @('inspect', '-f', '{{.State.Running}}', $Container)
    if ($r.out.Trim() -ne 'true') { Problem "container $Container is not running" } else { Say "  container $Container is running" }
  }
  if (-not $SkipDb -and $script:problems.Count -eq 0) {
    $pairs = @($pdfItems | ForEach-Object { [ordered]@{ old = ($WebPrefix + '/' + $_.old_rel); new = ($WebPrefix + '/' + $_.new_rel) } })
    $c = Invoke-Helper 'check' ([ordered]@{ pairs = $pairs }) $env:TEMP
    if ($c.state -ne 'ok') { Problem ("catalog check failed: " + $c.message) }
    else {
      Say ("  catalog: " + $c.old_rows + " rows for the current paths, " + $c.new_rows + " rows already at the new paths; files seen by the app: " + $c.old_files + " at old paths")
      if ($c.old_files -ne $pdfItems.Count) { Problem "the app container sees $($c.old_files) of $($pdfItems.Count) PDFs under /$WebPrefix - wrong library or mount?" }
      if ($c.old_rows -ne $pdfItems.Count) { Warn "$($pdfItems.Count - $c.old_rows) PDFs have no catalog row yet (the partial index will add them)" }
    }
  }
  if (-not $SkipHttp) {
    try { $p = Get-Web (Join-Url 'books.php'); if ($p.StatusCode -ne 200) { Problem "books.php returned $($p.StatusCode)" } else { Say "  books.php answers 200" } }
    catch { Problem "books.php not reachable: $($_.Exception.Message)" }
  }
}

function Restore-Files {
  $back = 0
  $list = @($script:man.items); [array]::Reverse($list)
  foreach ($i in $list) {
    if ($i.state -ne 'moved' -and $i.state -ne 'verified') { continue }
    $dstThere = Test-Path -LiteralPath $i.dst; $srcThere = Test-Path -LiteralPath $i.src
    if ($srcThere -and -not $dstThere) { $i.state = 'restored'; continue }
    if (-not $dstThere) { Problem ("missing, cannot move back: " + $i.dst); continue }
    if ($srcThere) { Problem ("original place is taken again, left in Books\" + $Subfolder + ": " + $i.src); continue }
    if ($i.sha256 -and (Get-Sha $i.dst) -ne $i.sha256) { Problem ("content changed since the move, left in place: " + $i.dst); continue }
    Move-Item -LiteralPath $i.dst -Destination $i.src
    $i.state = 'restored'; $back++; Save-Manifest
  }
  Say "  moved back $back files"
  if ($script:man.dest_created -and (Test-Path -LiteralPath $script:man.dest_dir)) {
    if (@(Get-ChildItem -LiteralPath $script:man.dest_dir -Force).Count -eq 0) { Remove-Item -LiteralPath $script:man.dest_dir; Say "  removed the empty folder $($script:man.dest_dir)" }
    else { Warn "$($script:man.dest_dir) is not empty, left in place" }
  }
}

# ================================================================= main
if (($Apply -and $Rollback) -or ($VerifyOnly -and ($Apply -or $Rollback)) -or ($DbSelfTest -and ($Apply -or $Rollback -or $VerifyOnly))) { throw "use only one of -Apply, -Rollback, -VerifyOnly, -DbSelfTest" }

if ($VerifyOnly) {
  Say "Web check of the '$Subfolder' card on the $Category page" 'Cyan'
  $n = 0; if (Test-Path -LiteralPath $destDir) { $n = @(Get-ChildItem -LiteralPath $destDir -File -Filter '*.pdf' | Where-Object { $_.Extension -eq '.pdf' }).Count }
  if (Test-BooksWeb $Subfolder $n '') { Say "WEB CHECK PASSED" 'Green'; exit 0 } else { Say "WEB CHECK FAILED" 'Red'; exit 1 }
}

if ($DbSelfTest) {
  $items = Get-Plan
  $pairs = @($items | Where-Object { $_.kind -eq 'pdf' } | ForEach-Object { [ordered]@{ old = ($WebPrefix + '/' + $_.old_rel); new = ($WebPrefix + '/' + $_.new_rel) } })
  $r = Invoke-Helper 'selftest' ([ordered]@{ pairs = $pairs }) $env:TEMP
  $r | ConvertTo-Json -Depth 5 | Write-Host
  if ($r.state -eq 'ok') { Say "DB SELFTEST PASSED (temporary table only)" 'Green'; exit 0 } else { Say "DB SELFTEST FAILED" 'Red'; exit 1 }
}

if ($Rollback) {
  if ($Rollback -eq 'latest') {
    # newest -Apply that actually moved files and was not rolled back yet
    $d = Get-ChildItem -LiteralPath $BackupRoot -Directory -ErrorAction SilentlyContinue | Sort-Object Name -Descending | Where-Object {
      $m = Join-Path $_.FullName 'manifest.json'
      (Test-Path -LiteralPath $m) -and (@('planned', 'moved-back', 'rolled-back') -notcontains [string](Get-Content -LiteralPath $m -Raw -Encoding UTF8 | ConvertFrom-Json).state) } | Select-Object -First 1
    if (-not $d) { throw "no -Apply under $BackupRoot that moved files and is not rolled back yet (pass the backup folder path to force)" }
    $bdir = $d.FullName
  } else { $bdir = (Resolve-Path -LiteralPath $Rollback).ProviderPath }
  $script:manPath = Join-Path $bdir 'manifest.json'
  if (-not (Test-Path -LiteralPath $script:manPath)) { throw "no manifest.json in $bdir" }
  $script:man = Get-Content -LiteralPath $script:manPath -Raw -Encoding UTF8 | ConvertFrom-Json
  $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
  Start-Transcript -LiteralPath (Join-Path $bdir "rollback-$stamp.log") | Out-Null
  try {
    Say "ROLLBACK of $bdir" 'Cyan'
    Restore-Files
    $pdfItems = @($script:man.items | Where-Object { $_.kind -eq 'pdf' })
    $pairs = @($pdfItems | ForEach-Object { [ordered]@{ old = ($WebPrefix + '/' + $_.old_rel); new = ($WebPrefix + '/' + $_.new_rel) } })
    $rowsFile = Join-Path $bdir 'catalog-rows-before.json'
    if (-not $SkipDb -and (Test-Path -LiteralPath $rowsFile)) {
      $rows = (Get-Content -LiteralPath $rowsFile -Raw -Encoding UTF8 | ConvertFrom-Json).rows
      $r = Invoke-Helper 'restore' ([ordered]@{ pairs = $pairs; rows = @($rows) }) $bdir
      Write-NewText (Join-Path $bdir "catalog-restore-$stamp.json") ($r | ConvertTo-Json -Depth 6)
      if ($r.state -eq 'done') { Say ("  catalog: restored " + $r.inserted + " old rows, removed " + $r.deleted_new + " rows of the Books\$Subfolder paths") }
      else { Problem ("catalog restore " + $r.state + ": " + $r.message + " " + (@($r.details) -join '; ')) }
    }
    if (-not $SkipIndex -and $script:problems.Count -eq 0) {
      $back = @($pdfItems | Where-Object { Test-Path -LiteralPath $_.src } | ForEach-Object { $_.old_rel })
      if ($back.Count) { $e = Test-IndexResult (Request-Index "loosebooks-rollback-$stamp" $back) $back.Count; if ($e) { Warn "re-index of the old paths: $e" } }
    }
    if ($script:problems.Count -eq 0) { $script:man.state = 'rolled-back' }
    $script:man | Add-Member -NotePropertyName rolled_back -NotePropertyValue (Get-Date).ToString('s') -Force; Save-Manifest
    if (-not $SkipHttp) { try { $p = Get-Web (Join-Url 'books.php'); Say ("  books.php: " + $p.StatusCode) } catch { Warn "books.php not reachable" } }
  } finally { Stop-Transcript | Out-Null }
  if ($script:problems.Count) { Say "ROLLBACK FINISHED WITH PROBLEMS (see above). Log: $bdir" 'Red'; exit 1 }
  Say "ROLLBACK COMPLETE. Log and records: $bdir" 'Green'; exit 0
}

# ---------------------------------------------------------------- check (default) and -Apply
if (-not (Test-Path -LiteralPath $catDir -PathType Container)) { throw "$catDir not found" }
$items = Get-Plan
Invoke-Checks $items
if ($script:problems.Count) { Say ""; Say ("CHECKS FAILED (" + $script:problems.Count + " problem(s)) - nothing was changed.") 'Red'; exit 1 }
if (-not $Apply) {
  Say ""; Say ("ALL CHECKS PASSED" + $(if ($script:warnings.Count) { " (with $($script:warnings.Count) warning(s))" } else { '' }) + ". Nothing was changed. Run again with -Apply to move the files.") 'Green'
  exit 0
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$bdir = Join-Path $BackupRoot $stamp
if (Test-Path -LiteralPath $bdir) { throw "$bdir already exists" }
New-Item -ItemType Directory -Path $bdir | Out-Null
Start-Transcript -LiteralPath (Join-Path $bdir 'apply.log') | Out-Null
$exitCode = 0
$script:man = $null
try {
  Say ""; Say "APPLY - backup and records in $bdir" 'Cyan'
  Say "  hashing files..."
  foreach ($i in $items) { $i.sha256 = Get-Sha $i.src }
  $script:manPath = Join-Path $bdir 'manifest.json'
  $script:man = [pscustomobject][ordered]@{ version = 1; stamp = $stamp; created = (Get-Date).ToString('s'); computer = $env:COMPUTERNAME
    library = $Library; category = $Category; subfolder = $Subfolder; cat_dir = $catDir; dest_dir = $destDir; dest_created = $false
    state = 'planned'; items = @($items | ForEach-Object { [pscustomobject]$_ }); index_request = ''; index = $null; catalog = $null; web_ok = $null }
  Save-Manifest
  Say ("  manifest: " + $script:man.items.Count + " files with SHA256")
  $pdfItems = @($script:man.items | Where-Object { $_.kind -eq 'pdf' })
  $pairs = @($pdfItems | ForEach-Object { [ordered]@{ old = ($WebPrefix + '/' + $_.old_rel); new = ($WebPrefix + '/' + $_.new_rel) } })
  if (-not $SkipDb) {
    $rowsFile = Join-Path $bdir 'catalog-rows-before.json'
    $r = Invoke-Helper 'export' ([ordered]@{ pairs = $pairs }) $bdir $rowsFile
    if ($r.state -ne 'ok' -or -not (Test-Path -LiteralPath $rowsFile)) { throw "catalog backup failed: $($r.message)" }
    Say ("  catalog backup: " + $r.exported + " rows -> catalog-rows-before.json")
  }
  # move
  if (-not (Test-Path -LiteralPath $destDir)) { New-Item -ItemType Directory -Path $destDir | Out-Null; $script:man.dest_created = $true }
  $script:man.state = 'moving'; Save-Manifest
  try {
    foreach ($i in $script:man.items) {
      if (Test-Path -LiteralPath $i.dst) { throw ("destination appeared meanwhile: " + $i.dst) }
      Move-Item -LiteralPath $i.src -Destination $i.dst
      $i.state = 'moved'; Save-Manifest
    }
    foreach ($i in $script:man.items) {
      if ((Get-Sha $i.dst) -ne $i.sha256) { throw ("SHA256 differs after the move: " + $i.dst) }
      $i.state = 'verified'
    }
  } catch {
    Problem ("move stopped: " + $_.Exception.Message + " - moving everything back")
    Restore-Files
    $script:man.state = 'moved-back'; Save-Manifest
    throw "nothing changed in the end (files moved back)"
  }
  $script:man.state = 'moved'; Save-Manifest
  Say ("  moved and verified " + $script:man.items.Count + " files (" + $pdfItems.Count + " PDFs) into $destDir") 'Green'
  # partial index of the new paths
  $indexed = $false
  if (-not $SkipIndex) {
    $script:man.index_request = "loosebooks-$stamp"; Save-Manifest
    $res = Request-Index $script:man.index_request @($pdfItems | ForEach-Object { $_.new_rel })
    $script:man.index = $res; Save-Manifest
    $e = Test-IndexResult $res $pdfItems.Count
    if ($e) { Problem "partial index: $e. The old catalog rows were kept." } else { $indexed = $true }
  }
  # old catalog rows
  if (-not $SkipDb -and $indexed) {
    $r = Invoke-Helper 'remove-old' ([ordered]@{ pairs = $pairs }) $bdir
    $script:man.catalog = $r; Save-Manifest
    Write-NewText (Join-Path $bdir 'catalog-remove-old.json') ($r | ConvertTo-Json -Depth 6)
    if ($r.state -eq 'done') { Say ("  catalog: removed " + $r.removed + " old rows (" + $Category + "/<file>.pdf); the new rows are " + $Category + "/" + $Subfolder + "/<file>.pdf") }
    else { Problem ("old catalog rows " + $r.state + ": " + $r.message + " " + (@($r.details) -join '; ')) }
  }
  # web check
  if (-not $SkipHttp) {
    $small = $pdfItems | Sort-Object size | Select-Object -First 1
    $n = @(Get-ChildItem -LiteralPath $destDir -File -Filter '*.pdf' | Where-Object { $_.Extension -eq '.pdf' }).Count
    $script:man.web_ok = (Test-BooksWeb $Subfolder $n ([IO.Path]::GetFileName($small.dst))); Save-Manifest
  }
  $script:man.state = $(if ($script:problems.Count) { 'moved-with-problems' } else { 'done' }); $script:man | Add-Member -NotePropertyName finished -NotePropertyValue (Get-Date).ToString('s') -Force; Save-Manifest
} catch {
  Problem $_.Exception.Message
  $exitCode = 1
} finally { Stop-Transcript | Out-Null }
Say ""
$movedNow = 0
if ($script:man) { $movedNow = @($script:man.items | Where-Object { $_.state -eq 'moved' -or $_.state -eq 'verified' }).Count }
if ($script:problems.Count -and $movedNow -eq 0) {
  Say ("APPLY STOPPED (see above). No file was moved or is moved back; the library is unchanged. Records: $bdir") 'Red'
  exit 1
}
if ($script:problems.Count) {
  Say ("APPLY FINISHED WITH PROBLEMS (see above). The files ARE in their new place. Records: $bdir") 'Red'
  Say ("To undo: powershell -ExecutionPolicy Bypass -File `"$PSCommandPath`" -Rollback `"$bdir`"") 'Yellow'
  exit 1
}
Say ("DONE. The " + $pdfItems.Count + " books are in $destDir and show as the '$Subfolder' card on the $Category page. Records: $bdir") 'Green'
Say ("To undo: powershell -ExecutionPolicy Bypass -File `"$PSCommandPath`" -Rollback `"$bdir`"")
exit $exitCode
