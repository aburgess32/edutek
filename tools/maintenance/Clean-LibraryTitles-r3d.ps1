<#
Clean-LibraryTitles-r3d.ps1  (Edutek, 2026-10-22, revision 3d)

r3d changes: -Rollback copes with a quarantine that was emptied by hand (2026-10-22 14:51). A quarantined junk file
that is gone from the quarantine AND from the library, and is listed in <BackupRoot>\..\quarantine-purge-*.csv, is
reported as purged (it cannot come back) instead of failing the rollback; rows, names and titles are still rolled
back exactly. A missing file that is NOT in a purge list still makes the rollback INCOMPLETE. -CheckBackup counts
purged files separately (never "unchanged"). The self-test covers this.

r3c changes: -SelfTest no longer samples dirty rows from the live catalog (after the cleanup there are none, so the
r3 self-test had nothing to test). It clones template rows into the scratch table and rewrites them there into
synthetic fixtures (junk rows, held rows, '..' names with thumbnails, legacy titles); content_meta is only read.

r3 changes: fixes the Patch 7 check reporting "NOT installed: container cannot read the Patch 7 files" although
Patch 7 is installed (the function parameter $container shadowed the script's -Container name, so it ran
"docker exec True ..."); the container check now names the docker error and compares all three files; the
self-test runs the container check for real; the scan's closing hint says to install Patch 7 first when needed.

r2 changes: fixes the PS 5.1 crash "The property 'Count' cannot be found on this object" when a scope has 0 or 1
rows (e.g. -Category Agriculture has no title-only rows); the journal is written before anything else and any error
after that point rolls back automatically; -Rollback handles a backup folder that has no journal; -CheckBackup
compares a backup with the live state (read-only); -Apply refuses unless Patch 7 (indexer title guard) is installed
(override: -AllowWithoutPatch7).

Cleans up existing library content whose titles start with a dot, contain stray dots, or contain dots plus
underscores, and the thumbnails that belong to them. It never runs an index (full or partial), never runs or takes
part in the held 8,876-row catalog cleanup (rows whose file is missing are only reported), never deletes a library
file, and never touches .env or guide_config.

Scopes (-Scope, default: all four)
  junk-files  macOS/Windows junk files on disk (._*, .DS_Store, Thumbs.db, desktop.ini) are MOVED (never deleted) to
              <QuarantineRoot>\<stamp>\<same relative path>. A file that is locked is skipped and reported.
  junk-rows   catalog rows of those junk files (the "._01 - ..." cards with a dot title and the sample thumbnail) are
              backed up, then deleted (by id AND path). Only rows whose file is still on disk; rows whose file is gone
              belong to the held stale-row cleanup and are left alone.
  names       videos / books / audio whose file name ends in a dot before the extension ("Peppers..mp4") are renamed
              with the shared rule edutek_safe_name v1 (same as the importer), together with their same-name
              thumbnails (.jpg .jpeg .png .webp). Exactly those catalog rows get the new path, the new thumbnail path
              and the clean title. Collisions, transcoded copies, files the app cannot see and rows used elsewhere
              are skipped and reported.
  titles      rows whose stored title still has the dot/underscore artifacts get the clean title (edutek_title v1,
              the same function Patch 7 puts into the indexer). Titles edited by hand are only reported.

Modes
  .\Clean-LibraryTitles.ps1                     check only (default, same as -Scan): read-only plan, CSV on the Desktop
  .\Clean-LibraryTitles.ps1 -Apply [-Scope names] [-Category Agriculture] [-Max 200]
                                                backup + manifest, then the changes; automatic rollback on failure
  .\Clean-LibraryTitles.ps1 -Rollback latest    (or -Rollback <stamp>) undoes a run from its backup folder; a folder
                                                without journal.json (run stopped before its first change) is verified
                                                against the live state and closed, nothing is changed
  .\Clean-LibraryTitles.ps1 -CheckBackup latest (or <stamp>) read-only: are the backed-up rows/files still as before?
  .\Clean-LibraryTitles.ps1 -SelfTest           every scope, rollback, injected failures and refusals on THROWAWAY
                                                copies (temp folder + scratch table clt_selftest_xxxxxxxx, dropped)
-Max caps each scope per run (default 200). -Category limits the run to one top-level library folder.
Backups: <BackupRoot>\<stamp>\ (manifest.csv, rows-*.json exact row dumps used by rollback, *.csv readable copies,
journal.json, log.txt).
#>
[CmdletBinding()]
param(
  [switch]$Scan,
  [switch]$Apply,
  [string]$Rollback = '',
  [switch]$SelfTest,
  [string]$CheckBackup = '',
  [switch]$AllowWithoutPatch7,
  [string]$Htdocs = 'D:\edutek\htdocs',
  [string[]]$Scope = @('junk-files', 'junk-rows', 'names', 'titles'),
  [string]$Category = '',
  [int]$Max = 200,
  [string]$Library = 'D:\xampp\htdocs\Edutek\videos',
  [string]$QuarantineRoot = 'D:\edutek-system\quarantine',
  [string]$BackupRoot = 'D:\edutek-system\backups\library-titles',
  [string]$ModuleDir = '',
  [string]$OutCsv = '',
  [string]$Container = 'edupak-app',
  [string]$DockerExe = '',
  [string]$DockerContext = 'desktop-linux',
  [string]$FailAt = ''          # test hook for -SelfTest: during-backup | after-moves | after-rows | after-renames | after-names-db | after-titles
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$utf8 = New-Object System.Text.UTF8Encoding($false)
$script:Table = 'content_meta'
$script:Sim = $false
$script:Log = ''
$AllScopes = @('junk-files', 'junk-rows', 'names', 'titles')
$JunkNames = @('.ds_store', 'thumbs.db', 'desktop.ini')
$ThumbExt = @('.jpg', '.jpeg', '.png', '.webp')
$Modules = @{ 'edutek-safe-name.php' = 'fbbfbf56baebe6f7c41093ebce088677c877e4dcaa52e04755932886cb4d3896'; 'edutek-title.php' = 'f17761e5d13b2d4835d42f1e376bf491bc8804e29b655bac674aade9b5da4b63' }
if (-not $ModuleDir) { $ModuleDir = Join-Path $PSScriptRoot 'Patch7-titles\files\includes' }
$Patch7IndexerSha = '8a1d5ecedefebc732ea6c77a36d2a054a44a5fb37aafed61f70ecf78d40dce28'
$script:ActiveBackup = ''

function Say([string]$m) { Write-Host $m; if ($script:Log) { try { [IO.File]::AppendAllText($script:Log, $m + "`r`n", $utf8) } catch { Write-Host "  (could not write the log: $($_.Exception.Message))" } } }
# NOTE: PowerShell unrolls a function's array output: 0 rows come back as $null and 1 row as a bare object, and
# Set-StrictMode then throws on .Count. ALWAYS call it as @(From-JsonArray ...).
function From-JsonArray([string]$t) { return @(($t | ConvertFrom-Json) | ForEach-Object { $_ }) }

# ---------------------------------------------------------------- DB / rule helper (runs inside the app container)
$HelperPhp = @'
<?php
if (PHP_SAPI !== 'cli') { exit(1); }
function clt_out($a) {
    $j = json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($j === false) { $j = json_encode(['state' => 'error', 'message' => 'json_encode failed: ' . json_last_error_msg()]); }
    echo "\nCLT:" . base64_encode($j) . "\n"; exit(0);
}
function clt_rows($rows) {
    $rows = array_values($rows); $j = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($j === false) { clt_out(['state' => 'error', 'message' => 'row json_encode failed: ' . json_last_error_msg()]); }
    return ['state' => 'ok', 'count' => count($rows), 'rows' => $rows, 'rows_b64' => base64_encode($j)];
}
try {
    $in = json_decode(base64_decode('__CLT_INPUT__'), true);
    if (!is_array($in)) { clt_out(['state' => 'error', 'message' => 'bad input']); }
    $mod = '/tmp/clt7';
    foreach (($in['modules'] ?? []) as $f => $h) {
        if (!preg_match('/^[a-z-]+\.php$/', $f) || !is_file("$mod/$f") || hash_file('sha256', "$mod/$f") !== strtolower($h)) { clt_out(['state' => 'error', 'message' => "module $f missing or changed in the container"]); }
    }
    require_once "$mod/edutek-title.php";
    if ((string)($in['mode'] ?? '') === 'rule') {
        $o = [];
        foreach ($in['names'] as $n) { $st = pathinfo($n, PATHINFO_FILENAME); $c = ''; try { $c = sn_filename($n); } catch (Throwable $e) { $c = 'ERROR ' . $e->getMessage(); }
            $o[] = ['name' => $n, 'clean' => $c, 'title' => edutek_display_title($st), 'legacy' => edutek_legacy_title($st), 'junk' => edutek_index_junk($n)]; }
        clt_out(['state' => 'ok', 'items' => $o]);
    }
    require_once '/var/www/html/includes/config.php';
    require_once '/var/www/html/includes/auth.php';
    $pdo = getDbConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $t = (string)($in['table'] ?? '');
    if (!preg_match('/^(content_meta|clt_selftest_[0-9a-f]{8})$/', $t)) { clt_out(['state' => 'error', 'message' => 'table not allowed']); }
    $scratch = (strpos($t, 'clt_selftest_') === 0);
    $mode = (string)($in['mode'] ?? '');
    $cols = "id, content_id, file_path, title, thumbnail_path, category, subcategory";

    if ($mode === 'ping') { clt_out(['state' => 'ok', 'count' => (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(), 'rule' => EDUTEK_TITLE_RULE . ' / ' . SN_RULE]); }

    if ($mode === 'scan') {
        $cat = (string)($in['category'] ?? ''); $fs = !empty($in['fs']);
        if ($cat !== '') { $s = $pdo->prepare("SELECT $cols FROM `$t` WHERE file_path LIKE ? ORDER BY file_path"); $s->execute([addcslashes('content/' . $cat . '/', '\\%_') . '%']); }
        else { $s = $pdo->query("SELECT $cols FROM `$t` ORDER BY file_path"); }
        $out = []; $total = 0; $removable = ['.ds_store', 'thumbs.db', 'desktop.ini'];
        while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
            $total++;
            $fp = (string)$r['file_path'];
            if (strncmp($fp, 'content/', 8) !== 0) { continue; }
            $rel = substr($fp, 8); $base = basename($rel); $stem = pathinfo($base, PATHINFO_FILENAME);
            $kind = ''; $reason = ''; $newBase = ''; $newTitle = '';
            $j = edutek_index_junk($rel, -1);
            if ($j !== '') {
                $kind = (strncmp($base, '._', 2) === 0 || in_array(strtolower($base), $removable, true)) ? 'junk' : 'junk-other';
                $reason = $j;
            } elseif (preg_match('/^\.|\.$/', $stem)) {
                $kind = 'name';
                try { $newBase = sn_filename($base); } catch (Throwable $e) { $newBase = ''; $reason = 'rule error: ' . $e->getMessage(); }
                $newTitle = edutek_display_title($newBase !== '' ? pathinfo($newBase, PATHINFO_FILENAME) : $stem);
            } else {
                $disp = edutek_display_title($stem);
                if ($disp !== (string)$r['title']) {
                    if ((string)$r['title'] === edutek_legacy_title($stem)) { $kind = 'title'; $newTitle = $disp; }
                    elseif (preg_match('/^[\s._]|\.\.|\s\.(\s|$)|\.$/', (string)$r['title'])) { $kind = 'title-curated'; $newTitle = $disp; $reason = 'title was edited by hand; left as is'; }
                }
            }
            if ($kind === '') { continue; }
            $r['kind'] = $kind; $r['reason'] = $reason; $r['new_base'] = $newBase; $r['new_title'] = $newTitle;
            $r['cx'] = $fs ? file_exists('/content/' . $rel) : null;
            $r['new_in_db'] = false;
            $out[] = $r;
        }
        /* names: is the new path already in the catalog, and do other tables refer to the old path? */
        $q = $pdo->prepare("SELECT id FROM `$t` WHERE file_path = ? OR content_id = ? LIMIT 1");
        $refTables = [];
        foreach ($pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('content_id','file_path','content_path') AND DATA_TYPE IN ('char','varchar','text','mediumtext','longtext','tinytext') AND TABLE_NAME <> 'content_meta' AND TABLE_NAME NOT LIKE 'clt\\_selftest\\_%' AND TABLE_NAME NOT LIKE 'fsc\\_selftest\\_%'")->fetchAll(PDO::FETCH_NUM) as $c) { $refTables[] = $c; }
        foreach ($out as &$r) {
            if ($r['kind'] !== 'name' || $r['new_base'] === '') { continue; }
            $np = 'content/' . (dirname(substr($r['file_path'], 8)) === '.' ? '' : dirname(substr($r['file_path'], 8)) . '/') . $r['new_base'];
            $r['new_path'] = $np;
            if ($np !== $r['file_path']) { $q->execute([$np, $np]); $r['new_in_db'] = ($q->fetchColumn() !== false); }
            $refs = [];
            foreach ($refTables as $c) { $x = $pdo->prepare("SELECT COUNT(*) FROM `{$c[0]}` WHERE `{$c[1]}` = ?"); $x->execute([$r['file_path']]); if ((int)$x->fetchColumn() > 0) { $refs[] = $c[0] . '.' . $c[1]; } }
            $r['refs'] = implode(',', $refs);
        }
        unset($r);
        clt_out(['state' => 'ok', 'total' => $total, 'items' => $out, 'ref_tables' => array_map(function ($c) { return $c[0] . '.' . $c[1]; }, $refTables)]);
    }

    if ($mode === 'select') {
        $rows = [];
        $s = $pdo->prepare("SELECT * FROM `$t` WHERE id = ?");
        foreach (($in['ids'] ?? []) as $id) { $s->execute([(int)$id]); $r = $s->fetch(PDO::FETCH_ASSOC); if ($r) { $rows[(int)$r['id']] = $r; } }
        $p = $pdo->prepare("SELECT * FROM `$t` WHERE file_path LIKE ? ORDER BY id LIMIT 60");
        foreach (($in['prefixes'] ?? []) as $px) { $p->execute([addcslashes($px, '\\%_') . '%']); foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[(int)$r['id']] = $r; } }
        ksort($rows);
        clt_out(clt_rows($rows));
    }

    if ($mode === 'delete') {
        $pdo->beginTransaction(); $n = 0;
        $d = $pdo->prepare("DELETE FROM `$t` WHERE id = ? AND file_path = ?");
        foreach ($in['items'] as $it) {
            $d->execute([(int)$it['id'], (string)$it['file_path']]);
            if ($d->rowCount() !== 1) { $pdo->rollBack(); clt_out(['state' => 'error', 'message' => 'row ' . $it['id'] . ' not found with the expected path; nothing deleted']); }
            $n++;
        }
        $pdo->commit(); clt_out(['state' => 'ok', 'deleted' => $n]);
    }

    if ($mode === 'restore') {
        $rows = json_decode(base64_decode((string)$in['rows_b64']), true);
        if (!is_array($rows)) { clt_out(['state' => 'error', 'message' => 'bad backup rows']); }
        $pdo->beginTransaction(); $ins = 0; $same = 0;
        $byId = $pdo->prepare("SELECT * FROM `$t` WHERE id = ?");
        $byPath = $pdo->prepare("SELECT id FROM `$t` WHERE (file_path = ? OR content_id = ?) AND id <> ?");
        foreach ($rows as $r) {
            foreach (array_keys($r) as $k) { if (!preg_match('/^[a-z_0-9]+$/', $k)) { $pdo->rollBack(); clt_out(['state' => 'error', 'message' => 'bad column in backup']); } }
            $byId->execute([(int)$r['id']]); $ex = $byId->fetch(PDO::FETCH_ASSOC);
            if ($ex) {
                if ($ex['file_path'] === $r['file_path']) { $same++; continue; }
                $pdo->rollBack(); clt_out(['state' => 'error', 'message' => 'id ' . $r['id'] . ' is now used by another row; nothing restored']);
            }
            $byPath->execute([$r['file_path'], $r['content_id'], (int)$r['id']]); $o = $byPath->fetchColumn();
            if ($o !== false) { $pdo->rollBack(); clt_out(['state' => 'error', 'message' => 'the path of row ' . $r['id'] . ' is now in row ' . $o . '; nothing restored']); }
            $k = array_keys($r);
            $pdo->prepare("INSERT INTO `$t` (`" . implode('`,`', $k) . "`) VALUES (" . implode(',', array_fill(0, count($k), '?')) . ")")->execute(array_values($r));
            $ins++;
        }
        $pdo->commit(); clt_out(['state' => 'ok', 'inserted' => $ins, 'already' => $same]);
    }

    if ($mode === 'rename') {   /* new path + title + thumbnail for exactly the renamed files */
        $fs = !empty($in['fs']);
        $pdo->beginTransaction(); $n = 0;
        $g = $pdo->prepare("SELECT id, content_id, file_path FROM `$t` WHERE id = ? FOR UPDATE");
        $taken = $pdo->prepare("SELECT id FROM `$t` WHERE (file_path = ? OR content_id = ?) AND id <> ? LIMIT 1");
        $u = $pdo->prepare("UPDATE `$t` SET content_id = ?, file_path = ?, title = ?, thumbnail_path = ? WHERE id = ? AND file_path = ? AND content_id = ?");
        foreach ($in['items'] as $it) {
            $id = (int)$it['id']; $old = (string)$it['old_path']; $new = (string)$it['new_path'];
            $g->execute([$id]); $r = $g->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['file_path'] !== $old || $r['content_id'] !== $old) { $pdo->rollBack(); clt_out(['state' => 'error', 'message' => "row $id no longer has the expected path; nothing changed"]); }
            $taken->execute([$new, $new, $id]); $o = $taken->fetchColumn();
            if ($o !== false) { $pdo->rollBack(); clt_out(['state' => 'error', 'message' => "the new path of row $id is already row $o; nothing changed"]); }
            if ($fs && (!file_exists('/content/' . substr($new, 8)) || ($old !== $new && strcasecmp($old, $new) !== 0 && file_exists('/content/' . substr($old, 8))))) { $pdo->rollBack(); clt_out(['state' => 'error', 'message' => "the app container does not see the renamed file of row $id; nothing changed"]); }
            $th = $it['thumb']; $th = ($th === null || $th === '') ? null : (string)$th;
            $u->execute([$new, $new, (string)$it['title'], $th, $id, $old, $old]);
            $n++;
        }
        $pdo->commit(); clt_out(['state' => 'ok', 'updated' => $n]);
    }

    if ($mode === 'settitle') {
        $pdo->beginTransaction(); $n = 0;
        $g = $pdo->prepare("SELECT title, file_path FROM `$t` WHERE id = ? FOR UPDATE");
        $u = $pdo->prepare("UPDATE `$t` SET title = ? WHERE id = ? AND file_path = ?");
        foreach ($in['items'] as $it) {
            $g->execute([(int)$it['id']]); $r = $g->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['file_path'] !== (string)$it['file_path'] || $r['title'] !== (string)$it['old_title']) { $pdo->rollBack(); clt_out(['state' => 'error', 'message' => 'row ' . $it['id'] . ' changed since the scan; nothing changed']); }
            $u->execute([(string)$it['title'], (int)$it['id'], (string)$it['file_path']]);
            $n++;
        }
        $pdo->commit(); clt_out(['state' => 'ok', 'updated' => $n]);
    }

    if ($mode === 'revert') {   /* put backed-up rows back in place (rename / title undo) */
        $rows = json_decode(base64_decode((string)$in['rows_b64']), true);
        if (!is_array($rows)) { clt_out(['state' => 'error', 'message' => 'bad backup rows']); }
        $allow = $in['allow'] ?? [];
        $pdo->beginTransaction(); $n = 0; $same = 0;
        $g = $pdo->prepare("SELECT * FROM `$t` WHERE id = ? FOR UPDATE");
        $taken = $pdo->prepare("SELECT id FROM `$t` WHERE (file_path = ? OR content_id = ?) AND id <> ? LIMIT 1");
        foreach ($rows as $r) {
            foreach (array_keys($r) as $k) { if (!preg_match('/^[a-z_0-9]+$/', $k)) { $pdo->rollBack(); clt_out(['state' => 'error', 'message' => 'bad column in backup']); } }
            $id = (int)$r['id']; $g->execute([$id]); $c = $g->fetch(PDO::FETCH_ASSOC);
            if (!$c) { $pdo->rollBack(); clt_out(['state' => 'error', 'message' => "row $id is gone; nothing reverted"]); }
            $eq = true; foreach ($r as $k => $v) { if (!array_key_exists($k, $c) || $c[$k] !== $v) { $eq = false; break; } }
            if ($eq) { $same++; continue; }
            $ok = array_map('strval', (array)($allow[(string)$id] ?? [$r['file_path']]));
            if (!in_array((string)$c['file_path'], $ok, true)) { $pdo->rollBack(); clt_out(['state' => 'error', 'message' => "row $id now has an unexpected path; nothing reverted"]); }
            $taken->execute([$r['file_path'], $r['content_id'], $id]); $o = $taken->fetchColumn();
            if ($o !== false) { $pdo->rollBack(); clt_out(['state' => 'error', 'message' => "the old path of row $id is now row $o; nothing reverted"]); }
            $k = array_values(array_filter(array_keys($r), function ($x) { return $x !== 'id'; }));
            $v = []; foreach ($k as $x) { $v[] = $r[$x]; } $v[] = $id;
            $pdo->prepare("UPDATE `$t` SET `" . implode('` = ?, `', $k) . "` = ? WHERE id = ?")->execute($v);
            $n++;
        }
        $pdo->commit(); clt_out(['state' => 'ok', 'reverted' => $n, 'already' => $same]);
    }

    if ($mode === 'compare') {   /* read-only: backed-up rows vs the table now */
        $rows = json_decode(base64_decode((string)$in['rows_b64']), true);
        if (!is_array($rows)) { clt_out(['state' => 'error', 'message' => 'bad backup rows']); }
        $g = $pdo->prepare("SELECT * FROM `$t` WHERE id = ?");
        $same = 0; $changed = []; $missing = [];
        foreach ($rows as $r) {
            $id = (int)$r['id']; $g->execute([$id]); $c = $g->fetch(PDO::FETCH_ASSOC);
            if (!$c) { $missing[] = $id; continue; }
            $diff = []; foreach ($r as $k => $v) { if (!array_key_exists($k, $c) || $c[$k] !== $v) { $diff[] = $k; } }
            if ($diff) { $changed[] = $id . ':' . implode('+', $diff); } else { $same++; }
        }
        clt_out(['state' => 'ok', 'rows' => count($rows), 'same' => $same, 'changed' => $changed, 'missing' => $missing]);
    }

    /* ---- scratch-table modes for -SelfTest only ---- */
    if (!$scratch) { clt_out(['state' => 'error', 'message' => "mode $mode is only allowed on a scratch table"]); }
    if ($mode === 'clone') {
        $ids = array_map('intval', $in['ids']); if (!$ids) { clt_out(['state' => 'error', 'message' => 'no ids']); }
        $pdo->exec("CREATE TABLE `$t` LIKE content_meta");
        $pdo->exec("INSERT INTO `$t` SELECT * FROM content_meta WHERE id IN (" . implode(',', $ids) . ")");
        clt_out(['state' => 'ok', 'count' => (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()]);
    }
    if ($mode === 'seed') {
        /* r3d: turn cloned scratch rows into synthetic dirty fixtures (scratch table only, never content_meta) */
        $u = $pdo->prepare("UPDATE `$t` SET file_path = ?, content_id = ?, title = ?, thumbnail_path = ? WHERE id = ?");
        $n = 0;
        foreach ($in['items'] as $it) { $u->execute([(string)$it['file_path'], (string)$it['file_path'], (string)$it['title'], (string)$it['thumb'], (int)$it['id']]); $n += $u->rowCount(); }
        clt_out(['state' => 'ok', 'count' => $n]);
    }
    if ($mode === 'dump') { clt_out(clt_rows($pdo->query("SELECT * FROM `$t` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC))); }
    if ($mode === 'poke') { $pdo->prepare("UPDATE `$t` SET title = ? WHERE id = ?")->execute([(string)$in['title'], (int)$in['id']]); clt_out(['state' => 'ok']); }
    if ($mode === 'drop') { $pdo->exec("DROP TABLE IF EXISTS `$t`"); clt_out(['state' => 'ok']); }
    clt_out(['state' => 'error', 'message' => 'unknown mode']);
} catch (Throwable $e) {
    try { if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable $e2) { }
    clt_out(['state' => 'error', 'message' => get_class($e) . ': ' . $e->getMessage()]);
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
function Invoke-Docker([string[]]$a) {
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @(& $script:Docker @script:DockerArgs @a 2>&1 | ForEach-Object { "$_" }); $c = $LASTEXITCODE } finally { $ErrorActionPreference = $old }
  return [pscustomobject]@{ code = $c; out = ($o -join "`n") }
}

function Install-Modules {
  foreach ($f in $Modules.Keys) {
    $p = Join-Path $ModuleDir $f
    if (-not (Test-Path -LiteralPath $p)) { throw "module not found: $p" }
    $h = (Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash
    if ($h -ne $Modules[$f].ToUpper()) { throw "module $p has SHA256 $h, expected $($Modules[$f].ToUpper())" }
  }
  $r = Invoke-Docker @('exec', $Container, 'mkdir', '-p', '/tmp/clt7')
  if ($r.code -ne 0) { throw "docker exec failed: $($r.out)" }
  foreach ($f in $Modules.Keys) {
    $r = Invoke-Docker @('cp', (Join-Path $ModuleDir $f), ($Container + ':/tmp/clt7/' + $f))
    if ($r.code -ne 0) { throw "docker cp $f failed: $($r.out)" }
  }
}
function Remove-Modules { [void](Invoke-Docker @('exec', $Container, 'rm', '-rf', '/tmp/clt7')) }

function Invoke-Helper([hashtable]$in, [string]$table = '') {
  if (-not $table) { $table = $script:Table }
  $in['table'] = $table
  $in['modules'] = $Modules
  $json = $in | ConvertTo-Json -Depth 8 -Compress
  $code = $HelperPhp.Replace('__CLT_INPUT__', [Convert]::ToBase64String($utf8.GetBytes($json)))
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @($code | & $script:Docker @script:DockerArgs exec -i $Container php 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
  $line = @($o | Where-Object { $_.StartsWith('CLT:') }) | Select-Object -Last 1
  if (-not $line) { throw ("helper ($($in.mode)) gave no result: " + ((@($o) | Select-Object -Last 4) -join ' / ')) }
  $text = $utf8.GetString([Convert]::FromBase64String($line.Substring(4).Trim()))
  $res = $text | ConvertFrom-Json
  if ($res.state -ne 'ok') { throw ("helper ($($in.mode)): " + $res.message) }
  return $res
}
function Get-RowsText($res) { return $utf8.GetString([Convert]::FromBase64String($res.rows_b64)) }

# ---------------------------------------------------------------- files
function Get-LibPath([string]$fp) {
  if ($fp -notmatch '^content/(.+)$') { return $null }
  return (Join-Path $Library ($Matches[1] -replace '/', '\'))
}
function Test-File([string]$p) { return ($p -and [IO.File]::Exists($p)) }
function Get-AsciiKey([string]$p) { return [Text.Encoding]::ASCII.GetString([Text.Encoding]::ASCII.GetBytes($p)) }
function Get-PurgedSet {
  # r3d: paths listed in <BackupRoot>\..\quarantine-purge-*.csv (column FullName). Those lists are written by PS 5.1
  # Export-Csv in ASCII, so non-ASCII letters became '?'; both sides are compared in that folded form.
  $h = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
  $root = Split-Path $BackupRoot
  foreach ($f in @(Get-ChildItem -LiteralPath $root -Filter 'quarantine-purge-*.csv' -File -ErrorAction SilentlyContinue)) {
    try { foreach ($r in @(Import-Csv -LiteralPath $f.FullName)) { if ($r.PSObject.Properties['FullName'] -and $r.FullName) { [void]$h.Add((Get-AsciiKey ([string]$r.FullName))) } } } catch { }
  }
  return ,$h
}
function Get-JunkFiles([string]$root) {
  $list = New-Object System.Collections.ArrayList
  if (-not [IO.Directory]::Exists($root)) { return @() }
  $stack = New-Object System.Collections.Stack; $stack.Push($root)
  while ($stack.Count) {
    $d = $stack.Pop()
    try { $files = [IO.Directory]::GetFiles($d); $dirs = [IO.Directory]::GetDirectories($d) } catch { continue }
    foreach ($f in $files) {
      $n = [IO.Path]::GetFileName($f)
      if ($n.StartsWith('._') -or $JunkNames -contains $n.ToLowerInvariant()) {
        $len = -1; try { $len = (New-Object IO.FileInfo $f).Length } catch { }
        [void]$list.Add([pscustomobject]@{ rel = $f.Substring($Library.TrimEnd('\').Length + 1); size = $len })
      }
    }
    foreach ($s in $dirs) {
      try { if (([IO.File]::GetAttributes($s) -band [IO.FileAttributes]::ReparsePoint) -ne 0) { continue } } catch { continue }
      $stack.Push($s)
    }
  }
  return @($list | Sort-Object rel)
}
function Get-Tree([string]$root) {
  $files = @{}; $dirs = @{}
  foreach ($i in @(Get-ChildItem -LiteralPath $root -Recurse -Force)) {
    $rel = $i.FullName.Substring($root.TrimEnd('\').Length).TrimStart('\')
    if ($i.PSIsContainer) { $dirs[$rel] = $true; continue }
    $files[$rel] = "$rel|$($i.Length)|" + (Get-FileHash -LiteralPath $i.FullName -Algorithm SHA256).Hash
  }
  return ((@($files.Keys | Sort-Object | ForEach-Object { $files[$_] }) + @($dirs.Keys | Sort-Object)) -join "`n")
}

# ---------------------------------------------------------------- plan (read-only)
function New-Item2($type, $r, [string]$action, [string]$reason) {
  $tp = ''; if ($r -and $r.PSObject.Properties['thumbnail_path'] -and $null -ne $r.thumbnail_path) { $tp = [string]$r.thumbnail_path }
  return [pscustomobject]@{ type = $type; action = $action; reason = $reason; id = $(if ($r) { [int]$r.id } else { '' })
    category = ''; old_file = ''; new_file = ''; old_thumb = $tp; new_thumb = $tp; old_title = $(if ($r) { [string]$r.title } else { '' }); new_title = ''
    row = $r; renames = @(); new_path = '' }
}
function Get-Plan {
  $p = [pscustomobject]@{ total = 0; items = (New-Object System.Collections.ArrayList); refTables = @(); problems = (New-Object System.Collections.ArrayList) }
  $res = Invoke-Helper @{ mode = 'scan'; category = $Category; fs = (-not $script:Sim) }
  $p.total = [int]$res.total; $p.refTables = @($res.ref_tables)
  $cap = @{}; foreach ($s in $AllScopes) { $cap[$s] = 0 }
  $targets = @{}
  foreach ($r in @($res.items)) {
    $fp = [string]$r.file_path; $rel = $fp.Substring(8); $win = Get-LibPath $fp
    $wx = Test-File $win
    $exists = $(if ($script:Sim) { $wx } else { [bool]$r.cx })
    $cat = ($rel -split '/')[0]
    $it = $null
    switch ([string]$r.kind) {
      'junk' {
        $it = New-Item2 'junk-row' $r '' ([string]$r.reason); $it.old_file = $rel
        if (-not $exists) { $it.action = 'held'; $it.reason = 'file is gone: part of the held stale-row cleanup, not touched' }
        else { $it.action = 'delete row' }
      }
      'junk-other' { $it = New-Item2 'junk-other' $r 'report only' ([string]$r.reason); $it.old_file = $rel }
      'title-curated' { $it = New-Item2 'title' $r 'report only' ([string]$r.reason); $it.old_file = $rel; $it.new_title = [string]$r.new_title }
      'title' {
        $it = New-Item2 'title' $r 'update title' ''; $it.old_file = $rel; $it.new_title = [string]$r.new_title
        if (-not $exists) { $it.action = 'held'; $it.reason = 'file is gone: part of the held stale-row cleanup, not touched' }
      }
      'name' {
        $it = New-Item2 'name' $r 'rename' ''; $it.old_file = $rel; $it.new_title = [string]$r.new_title
        $nb = [string]$r.new_base
        $dirRel = ''; $k = $rel.LastIndexOf('/'); if ($k -ge 0) { $dirRel = $rel.Substring(0, $k + 1) }
        $oldBase = $rel.Substring($dirRel.Length); $oldStem = [IO.Path]::GetFileNameWithoutExtension($oldBase)
        $it.new_file = $dirRel + $nb
        $why = ''
        if (-not $exists) { $why = 'file is gone: part of the held stale-row cleanup, not touched'; $it.action = 'held' }
        elseif (-not $nb) { $why = 'naming rule failed: ' + [string]$r.reason }
        elseif (-not $wx) { $why = 'the file name does not resolve on Windows (character mapping); left as is' }
        elseif ([string]$r.content_id -ne $fp) { $why = 'content_id differs from file_path' }
        elseif ($r.refs) { $why = 'referenced by ' + $r.refs }
        elseif ($r.new_in_db) { $why = 'collision: ' + $it.new_file + ' is already in the catalog' }
        if (-not $why) {
          $dirWin = Split-Path $win; $newStem = [IO.Path]::GetFileNameWithoutExtension($nb)
          if (Test-File (Join-Path $dirWin ($oldStem + '.h264.mp4'))) { $why = 'has a transcoded copy (' + $oldStem + '.h264.mp4)' }
          $ren = New-Object System.Collections.ArrayList
          [void]$ren.Add([pscustomobject]@{ old = $win; new = (Join-Path $dirWin $nb) })
          foreach ($e in $ThumbExt) {
            foreach ($cand in @(Get-ChildItem -LiteralPath $dirWin -Force -Filter ('*' + $e) -ErrorAction SilentlyContinue | Where-Object { -not $_.PSIsContainer -and $_.Extension -ieq $e -and [IO.Path]::GetFileNameWithoutExtension($_.Name) -ceq $oldStem })) {
              [void]$ren.Add([pscustomobject]@{ old = $cand.FullName; new = (Join-Path $dirWin ($newStem + $cand.Extension)) })
            }
          }
          foreach ($x in $ren) {
            if ((Test-Path -LiteralPath $x.new) -and $x.new -ne $x.old -and -not [string]::Equals($x.new, $x.old, [StringComparison]::OrdinalIgnoreCase)) { $why = 'collision: ' + [IO.Path]::GetFileName($x.new) + ' already exists' }
            $key = $x.new.ToLowerInvariant()
            if ($targets.ContainsKey($key)) { $why = 'collision: two files would get the name ' + [IO.Path]::GetFileName($x.new) }
          }
          if (-not $why) { foreach ($x in $ren) { $targets[$x.new.ToLowerInvariant()] = $true } }
          $it.renames = @($ren)
          $tp = $it.old_thumb
          $thumbs = @($ren | Select-Object -Skip 1)
          foreach ($x in $thumbs) {
            $oldWeb = 'content/' + $dirRel + [IO.Path]::GetFileName($x.old)
            if ($tp -ceq $oldWeb) { $it.new_thumb = 'content/' + $dirRel + [IO.Path]::GetFileName($x.new) }
          }
          $it.new_path = 'content/' + $it.new_file
        }
        if ($why) { if ($it.action -ne 'held') { $it.action = 'skip' }; $it.reason = $why; $it.renames = @() }
      }
    }
    if ($null -eq $it) { continue }
    $it.category = $cat
    [void]$p.items.Add($it)
  }
  # junk files on disk
  $root = $Library; if ($Category) { $root = Join-Path $Library $Category }
  foreach ($f in @(Get-JunkFiles $root)) {
    $it = New-Item2 'junk-file' $null 'quarantine' ''; $it.old_file = ($f.rel -replace '\\', '/'); $it.category = ($it.old_file -split '/')[0]
    $it.reason = "$($f.size) bytes"; [void]$p.items.Add($it)
  }
  # scope selection and -Max
  $map = @{ 'junk-file' = 'junk-files'; 'junk-row' = 'junk-rows'; 'name' = 'names'; 'title' = 'titles' }
  foreach ($it in $p.items) {
    if ($it.action -notin @('delete row', 'update title', 'rename', 'quarantine')) { continue }
    $s = $map[$it.type]
    if ($Scope -notcontains $s) { $it.action = 'not in -Scope'; continue }
    if ($cap[$s] -ge $Max) { $it.action = 'deferred (over -Max)'; continue }
    $cap[$s]++
  }
  return $p
}
function Get-Todo($p, [string]$type) {
  $a = @{ 'junk-file' = 'quarantine'; 'junk-row' = 'delete row'; 'name' = 'rename'; 'title' = 'update title' }[$type]
  return @($p.items | Where-Object { $_.type -eq $type -and $_.action -eq $a })
}
function Export-Plan($p, [string]$path) {
  @($p.items | Select-Object type, action, reason, id, category, old_file, new_file, old_thumb, new_thumb, old_title, new_title) |
    Export-Csv -LiteralPath $path -NoTypeInformation -Encoding UTF8
}
function Show-Plan($p) {
  Say ''
  Say ("Catalog rows scanned: {0}{1}   (rule: edutek_safe_name v1 + edutek_title v1)" -f $p.total, $(if ($Category) { "   category: $Category" } else { '' }))
  $g = @($p.items | Group-Object type, action | Sort-Object Name)
  foreach ($x in $g) { Say ("  {0,-48} {1,6}" -f $x.Name, $x.Count) }
  $sk = @($p.items | Where-Object { $_.action -eq 'skip' } | Group-Object { ($_.reason -split ':')[0] })
  if ($sk.Count) { Say '  skipped, by reason:'; foreach ($x in $sk) { Say ("    {0,-60} {1,6}" -f $x.Name, $x.Count) } }
  if (@($p.refTables).Count) { Say ('  other tables checked for references: ' + (@($p.refTables) -join ', ')) }
  Say ''
  Say 'Samples:'
  foreach ($t in @('name', 'title', 'junk-row', 'junk-file')) {
    foreach ($it in @(Get-Todo $p $t | Select-Object -First 4)) {
      if ($t -eq 'name') { Say ("  [rename] {0}`n           -> {1}   title '{2}'" -f $it.old_file, $it.new_file, $it.new_title) }
      elseif ($t -eq 'title') { Say ("  [title]  '{0}' -> '{1}'" -f $it.old_title, $it.new_title) }
      elseif ($t -eq 'junk-row') { Say ("  [row]    {0} '{1}' ({2})" -f $it.id, $it.old_title, $it.old_file) }
      else { Say ("  [file]   {0}" -f $it.old_file) }
    }
  }
}

# ---------------------------------------------------------------- journal
function Save-Journal($bk, $J) { [IO.File]::WriteAllText((Join-Path $bk 'journal.json'), ($J | ConvertTo-Json -Depth 6), $utf8) }
function Write-RowsBackup($bk, [string]$name, [string]$text) {
  [IO.File]::WriteAllText((Join-Path $bk "rows-$name.json"), $text, $utf8)
  $rows = @(From-JsonArray $text)
  if ($rows.Count) { $rows | Export-Csv -LiteralPath (Join-Path $bk "rows-$name.csv") -NoTypeInformation -Encoding UTF8 }
}
function Check-Fail([string]$at) { if ($FailAt -and $FailAt -eq $at) { throw "injected test failure at $at" } }
function Move-File([string]$a, [string]$b) {
  $d = Split-Path $b; if (-not [IO.Directory]::Exists($d)) { [void][IO.Directory]::CreateDirectory($d) }
  [IO.File]::Move($a, $b)
}

# ---------------------------------------------------------------- apply
function Invoke-Apply($plan, [string]$stamp) {
  $bk = Join-Path $BackupRoot $stamp
  if (Test-Path -LiteralPath $bk) { throw "backup folder $bk already exists" }
  [void](New-Item -ItemType Directory -Path $bk -Force)
  $script:Log = Join-Path $bk 'log.txt'
  $q = Join-Path $QuarantineRoot $stamp
  Say "Backup folder: $bk"
  $J = @{ stamp = $stamp; started = (Get-Date).ToString('s'); table = $script:Table; library = $Library; quarantine = $q; state = 'backing-up'
          moved = @(); rows_deleted = $false; renamed = @(); names = @(); names_db = $false; titles_db = $false; skipped = @(); version = 'r3d'; patch7 = $script:Patch7Note }
  # r2: the journal exists before anything else, and every step from here on is inside the try (auto-rollback)
  Save-Journal $bk $J
  $script:ActiveBackup = $bk
  try {
    $jf = @(Get-Todo $plan 'junk-file'); $jr = @(Get-Todo $plan 'junk-row'); $nm = @(Get-Todo $plan 'name'); $tt = @(Get-Todo $plan 'title')
    Export-Plan $plan (Join-Path $bk 'manifest.csv')
    foreach ($x in @(@('junk', $jr), @('names', $nm), @('titles', $tt))) {
      $ids = @($x[1] | ForEach-Object { [int]$_.id })
      $text = '[]'; if ($ids.Count) { $text = Get-RowsText (Invoke-Helper @{ mode = 'select'; ids = $ids }) }
      $n = @(From-JsonArray $text).Count
      if ($n -ne $ids.Count) { throw "backup of $($x[0]) rows has $n rows, expected $($ids.Count)" }
      Write-RowsBackup $bk $x[0] $text
    }
    Check-Fail 'during-backup'
    $J.state = 'started'; Save-Journal $bk $J
    # 1. junk files -> quarantine (moved, never deleted)
    $mv = 0
    foreach ($it in $jf) {
      $src = Join-Path $Library ($it.old_file -replace '/', '\'); $dst = Join-Path $q ($it.old_file -replace '/', '\')
      if (-not (Test-File $src)) { $J.skipped += ('file gone: ' + $it.old_file); continue }
      if (Test-Path -LiteralPath $dst) { throw "quarantine target exists: $dst" }
      try { Move-File $src $dst } catch { $J.skipped += ('could not move (' + $_.Exception.Message.Trim() + '): ' + $it.old_file); Say ("  [junk-files] skipped (in use?): " + $it.old_file); continue }
      $J.moved += @{ from = $src; to = $dst }; $mv++; Save-Journal $bk $J
    }
    if ($jf.Count) { Say ("[junk-files] moved {0} file(s) to {1}" -f $mv, $q) }
    Check-Fail 'after-moves'
    # 2. junk rows (backed up above)
    if ($jr.Count) {
      $r = Invoke-Helper @{ mode = 'delete'; items = @($jr | ForEach-Object { @{ id = [int]$_.id; file_path = [string]$_.row.file_path } }) }
      $J.rows_deleted = $true; Save-Journal $bk $J
      Say ("[junk-rows] deleted {0} row(s)" -f $r.deleted)
    }
    Check-Fail 'after-rows'
    # 3. names: rename file + thumbnails, then exactly those rows
    $done = New-Object System.Collections.ArrayList
    foreach ($it in $nm) {
      $mine = New-Object System.Collections.ArrayList; $bad = ''
      foreach ($x in $it.renames) {
        if ((Test-Path -LiteralPath $x.new) -and -not [string]::Equals($x.new, $x.old, [StringComparison]::OrdinalIgnoreCase)) { $bad = 'target appeared: ' + $x.new; break }
        try { [IO.File]::Move($x.old, $x.new) } catch { $bad = $_.Exception.Message.Trim(); break }
        [void]$mine.Add($x); $J.renamed += @{ old = $x.old; new = $x.new }; Save-Journal $bk $J
      }
      if ($bad) {
        for ($k = $mine.Count - 1; $k -ge 0; $k--) { $x = $mine[$k]; [IO.File]::Move($x.new, $x.old); $J.renamed += @{ old = $x.new; new = $x.old } }
        Save-Journal $bk $J; $J.skipped += ('rename skipped (' + $bad + '): ' + $it.old_file); Say ("  [names] skipped: {0} ({1})" -f $it.old_file, $bad); continue
      }
      [void]$done.Add($it)
    }
    Check-Fail 'after-renames'
    if ($done.Count) {
      $J.names = @($done | ForEach-Object { @{ id = [int]$_.id; old_path = [string]$_.row.file_path; new_path = $_.new_path } }); Save-Journal $bk $J
      $r = Invoke-Helper @{ mode = 'rename'; fs = (-not $script:Sim); items = @($done | ForEach-Object { @{ id = [int]$_.id; old_path = [string]$_.row.file_path; new_path = $_.new_path; title = $_.new_title; thumb = $_.new_thumb } }) }
      $J.names_db = $true; Save-Journal $bk $J
      Say ("[names] renamed {0} file(s) ({1} with thumbnails) and updated {2} row(s)" -f $done.Count, @($done | Where-Object { @($_.renames).Count -gt 1 }).Count, $r.updated)
    }
    Check-Fail 'after-names-db'
    # 4. titles
    if ($tt.Count) {
      $r = Invoke-Helper @{ mode = 'settitle'; items = @($tt | ForEach-Object { @{ id = [int]$_.id; file_path = [string]$_.row.file_path; old_title = [string]$_.old_title; title = $_.new_title } }) }
      $J.titles_db = $true; Save-Journal $bk $J
      Say ("[titles] updated {0} title(s)" -f $r.updated)
    }
    Check-Fail 'after-titles'
    $J.state = 'done'; $J.finished = (Get-Date).ToString('s'); Save-Journal $bk $J
    if (@($J.skipped).Count) { Say ("{0} item(s) skipped during the run (see journal.json):" -f @($J.skipped).Count); foreach ($s in @($J.skipped | Select-Object -First 10)) { Say "  - $s" } }
    $script:ActiveBackup = ''
    Say "DONE. Rollback: .\$($script:Me) -Rollback $stamp"
    return $true
  } catch {
    $msg = $_.Exception.Message; $where = ''; try { $where = ' (line ' + $_.InvocationInfo.ScriptLineNumber + ')' } catch { }
    Say ("ERROR: " + $msg + $where)
    $changed = (@($J.moved).Count -gt 0) -or $J.rows_deleted -or (@($J.renamed).Count -gt 0) -or $J.names_db -or $J.titles_db
    $J.error = $msg + $where; $J.state = $(if ($changed) { 'failed' } else { 'failed-before-changes' })
    try { Save-Journal $bk $J } catch { Say ("  (could not update journal.json: " + $_.Exception.Message + '; the rollback uses the last saved journal)') }
    $script:ActiveBackup = ''
    $ok = Invoke-AutoRollback $bk $changed
    return $false
  }
}

function Invoke-AutoRollback([string]$bk, [bool]$changed) {
  if (-not $changed) { Say 'The run stopped BEFORE its first change: no file was moved or renamed and no row was changed.' }
  Say 'Rolling back automatically ...'
  $ok = $false
  try { $ok = Invoke-Undo $bk } catch { Say ("  rollback error: " + $_.Exception.Message) }
  $v = $false
  try { $v = Test-BackupUnchanged $bk } catch { Say ("  verification error: " + $_.Exception.Message) }
  if ($ok -and $v) { Say 'Automatic rollback complete and verified: rows and files are as before.' }
  else { Say "Automatic rollback INCOMPLETE or not verified - nothing more will be done automatically. See $bk\log.txt and run: .\$($script:Me) -CheckBackup $(Split-Path $bk -Leaf)" }
  return ($ok -and $v)
}

# ---------------------------------------------------------------- undo (reverse order)
function Invoke-Undo([string]$bk) {
  $jp = Join-Path $bk 'journal.json'
  if (-not (Test-Path -LiteralPath $jp)) { Say "no journal in $bk"; return $false }
  $J = Get-Content -LiteralPath $jp -Raw -Encoding UTF8 | ConvertFrom-Json
  if ($J.table -ne $script:Table) { Say "journal is for table $($J.table), not $($script:Table)"; return $false }
  if (-not $script:Log) { $script:Log = Join-Path $bk 'log.txt' }
  $ok = $true
  # r3d: junk files that were purged from the quarantine cannot be moved back; say so before changing anything
  $script:PurgedCount = 0
  $purgedFrom = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
  $mv0 = @($J.moved)
  if ($mv0.Count) {
    $ps = Get-PurgedSet
    foreach ($m in $mv0) { if (-not (Test-File $m.to) -and -not (Test-File $m.from) -and $ps.Contains((Get-AsciiKey ([string]$m.to)))) { [void]$purgedFrom.Add([string]$m.from) } }
    if ($purgedFrom.Count) { Say ("  NOTE: {0} of the {1} junk file(s) this run quarantined were purged from the quarantine (listed in {2}\quarantine-purge-*.csv); they cannot be moved back. Rows, names and titles are still rolled back." -f $purgedFrom.Count, $mv0.Count, (Split-Path $BackupRoot)) }
  }
  if ($J.titles_db) {
    try { $r = Invoke-Helper @{ mode = 'revert'; rows_b64 = [Convert]::ToBase64String($utf8.GetBytes([IO.File]::ReadAllText((Join-Path $bk 'rows-titles.json'), $utf8))) }; Say ("  [titles] reverted {0} row(s), {1} already as before" -f $r.reverted, $r.already) }
    catch { $ok = $false; Say ("  [titles] revert FAILED: " + $_.Exception.Message) }
  }
  if ($J.names_db) {
    $allow = @{}
    foreach ($n in @($J.names)) { $allow[[string]$n.id] = @([string]$n.old_path, [string]$n.new_path) }
    try { $r = Invoke-Helper @{ mode = 'revert'; allow = $allow; rows_b64 = [Convert]::ToBase64String($utf8.GetBytes([IO.File]::ReadAllText((Join-Path $bk 'rows-names.json'), $utf8))) }; Say ("  [names] reverted {0} row(s), {1} already as before" -f $r.reverted, $r.already) }
    catch { $ok = $false; Say ("  [names] revert FAILED (files left renamed): " + $_.Exception.Message); return $false }
  }
  $ren = @($J.renamed)
  if ($ren.Count) {
    [array]::Reverse($ren); $n = 0
    foreach ($i in $ren) {
      if ((Test-File $i.new) -and ((-not (Test-File $i.old)) -or [string]::Equals($i.new, $i.old, [StringComparison]::OrdinalIgnoreCase))) { [IO.File]::Move($i.new, $i.old); $n++ }
      elseif (Test-File $i.old) { }
      else { $ok = $false; Say ("  [names] cannot rename back, neither name exists: " + $i.old) }
    }
    Say ("  [names] renamed back {0} file(s)" -f $n)
  }
  if ($J.rows_deleted) {
    try { $r = Invoke-Helper @{ mode = 'restore'; rows_b64 = [Convert]::ToBase64String($utf8.GetBytes([IO.File]::ReadAllText((Join-Path $bk 'rows-junk.json'), $utf8))) }; Say ("  [junk-rows] restored {0} row(s), {1} already present" -f $r.inserted, $r.already)
      if ($purgedFrom.Count) { $jr = @(From-JsonArray ([IO.File]::ReadAllText((Join-Path $bk 'rows-junk.json'), $utf8)) | Where-Object { $lp = Get-LibPath ([string]$_.file_path); $lp -and $purgedFrom.Contains($lp) }).Count; if ($jr) { Say ("  [junk-rows] {0} of the restored row(s) point to a purged file; a later -Scan lists them as 'junk-row, held'" -f $jr) } } }
    catch { $ok = $false; Say ("  [junk-rows] restore FAILED: " + $_.Exception.Message) }
  }
  $mv = @($J.moved)
  if ($mv.Count) {
    [array]::Reverse($mv); $n = 0
    foreach ($m in $mv) {
      if ((Test-File $m.to) -and -not (Test-Path -LiteralPath $m.from)) { Move-File $m.to $m.from; $n++ }
      elseif (Test-File $m.from) { }
      elseif ($purgedFrom.Contains([string]$m.from)) { $script:PurgedCount++; if ($script:PurgedCount -le 5) { Say ("  [junk-files] purged, not restored: " + $m.from) } }
      else { $ok = $false; Say ("  [junk-files] cannot move back (not in the quarantine and not in a purge list): " + $m.to) }
    }
    Say ("  [junk-files] moved back {0} file(s)" -f $n)
    if ($script:PurgedCount) { Say ("  [junk-files] {0} file(s) not restored: purged from the quarantine" -f $script:PurgedCount) }
    if (Test-Path -LiteralPath $J.quarantine) { if (-not @(Get-ChildItem -LiteralPath $J.quarantine -Recurse -Force | Where-Object { -not $_.PSIsContainer }).Count) { Remove-Item -LiteralPath $J.quarantine -Recurse -Force } }
  }
  $J2 = Get-Content -LiteralPath $jp -Raw -Encoding UTF8 | ConvertFrom-Json
  Add-Member -InputObject $J2 -NotePropertyName rolled_back -NotePropertyValue ((Get-Date).ToString('s') + $(if ($ok) { ' ok' } else { ' incomplete' }) + $(if ($script:PurgedCount) { " ($($script:PurgedCount) purged junk file(s) not restored)" } else { '' })) -Force
  [IO.File]::WriteAllText($jp, ($J2 | ConvertTo-Json -Depth 6), $utf8)
  return $ok
}

# ---------------------------------------------------------------- read-only verification of a backup folder
function Compare-RowsBackup([string]$bk) {
  # returns $true when every backed-up row (rows-*.json) is in the table exactly as backed up
  $all = $true; $any = $false
  foreach ($n in @('junk', 'names', 'titles')) {
    $f = Join-Path $bk "rows-$n.json"
    if (-not (Test-Path -LiteralPath $f)) { continue }
    $any = $true
    $r = Invoke-Helper @{ mode = 'compare'; rows_b64 = [Convert]::ToBase64String($utf8.GetBytes([IO.File]::ReadAllText($f, $utf8))) }
    $chg = @($r.changed); $mis = @($r.missing)
    Say ("  [rows-{0}] {1} backed-up row(s): {2} identical, {3} changed, {4} missing" -f $n, $r.rows, $r.same, $chg.Count, $mis.Count)
    foreach ($c in @($chg | Select-Object -First 10)) { Say "      changed $c" }
    foreach ($m in @($mis | Select-Object -First 10)) { Say "      missing id $m" }
    if ($chg.Count -or $mis.Count) { $all = $false }
  }
  if (-not $any) { Say '  (no row backups in this folder: the run stopped before backing up any row)' }
  return $all
}
function Compare-FilesManifest([string]$bk, [string]$lib, [bool]$allowPurged = $false) {
  # returns $true when every file the run planned to touch is still where it was (and no rename target exists)
  $m = Join-Path $bk 'manifest.csv'
  if (-not (Test-Path -LiteralPath $m)) { Say '  (no manifest: the run stopped before writing its plan)'; return $true }
  $rows = @(Import-Csv -LiteralPath $m)
  $bad = New-Object System.Collections.ArrayList; $nq = 0; $nr = 0; $np = 0
  $ps = Get-PurgedSet; $q0 = Join-Path $QuarantineRoot (Split-Path $bk -Leaf)
  foreach ($r in $rows) {
    if ($r.type -eq 'junk-file' -and $r.action -eq 'quarantine') {
      $nq++; $p = Join-Path $lib ($r.old_file -replace '/', '\')
      if (-not (Test-File $p)) { if ($ps.Contains((Get-AsciiKey (Join-Path $q0 ($r.old_file -replace '/', '\'))))) { $np++ } else { [void]$bad.Add('junk file not in place: ' + $r.old_file) } }
    } elseif ($r.type -eq 'name' -and $r.action -eq 'rename') {
      $nr++; $o = Join-Path $lib ($r.old_file -replace '/', '\'); $n = Join-Path $lib ($r.new_file -replace '/', '\')
      if (-not (Test-File $o)) { [void]$bad.Add('file not under its old name: ' + $r.old_file) }
      elseif (-not [string]::Equals($o, $n, [StringComparison]::OrdinalIgnoreCase) -and (Test-File $n)) { [void]$bad.Add('new name exists too: ' + $r.new_file) }
    }
  }
  $q = Join-Path $QuarantineRoot (Split-Path $bk -Leaf)
  $qn = 0; if (Test-Path -LiteralPath $q) { $qn = @(Get-ChildItem -LiteralPath $q -Recurse -Force | Where-Object { -not $_.PSIsContainer }).Count }
  Say ("  [files] {0} planned junk file(s) and {1} planned rename(s) checked: {2} problem(s); quarantine folder {3}" -f $nq, $nr, $bad.Count, $(if (Test-Path -LiteralPath $q) { "exists ($qn file(s))" } else { 'does not exist' }))
  if ($np) { Say ("  [files] {0} junk file(s) not in place because they were purged from the quarantine (cannot be restored)" -f $np) }
  foreach ($b in @($bad | Select-Object -First 10)) { Say "      $b" }
  return ($bad.Count -eq 0 -and $qn -eq 0 -and ($allowPurged -or $np -eq 0))
}
function Test-BackupUnchanged([string]$bk, [bool]$allowPurged = $false) {
  $lib = $Library
  $jp = Join-Path $bk 'journal.json'
  if (Test-Path -LiteralPath $jp) { try { $jl = [string](Get-Content -LiteralPath $jp -Raw -Encoding UTF8 | ConvertFrom-Json).library; if ($jl) { $lib = $jl } } catch { } }
  $a = Compare-RowsBackup $bk
  $b = Compare-FilesManifest $bk $lib $allowPurged
  return ($a -and $b)
}
function Invoke-NoJournal([string]$bk) {
  # a backup folder without journal.json: the run stopped before its first change (the journal is written before
  # any change in v2 and r2). Verify that against the live state, then close the folder. Nothing is changed.
  Say ("No journal.json in {0}." -f $bk)
  Say 'That run stopped while it was making its backup, before its first change. Verifying against the live state (read-only) ...'
  if (Test-BackupUnchanged $bk) {
    $J = @{ stamp = (Split-Path $bk -Leaf); state = 'aborted-before-changes'; table = $script:Table; library = $Library; quarantine = (Join-Path $QuarantineRoot (Split-Path $bk -Leaf))
            moved = @(); rows_deleted = $false; renamed = @(); names = @(); names_db = $false; titles_db = $false; skipped = @(); version = 'r3d'
            rolled_back = ((Get-Date).ToString('s') + ' not needed: verified unchanged') }
    Save-Journal $bk $J
    Say 'Verified: every backed-up row and every planned file is exactly as before. Nothing to roll back; journal.json written to close the folder.'
    return $true
  }
  Say 'NOT verified: the differences above are not from this run''s journal, so nothing is changed automatically. Please look at them by hand.'
  return $false
}
function Resolve-BackupDir([string]$name) {
  if ($name -eq 'latest') {
    $d = @(Get-ChildItem -LiteralPath $BackupRoot -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '^\d{8}-\d{6}$' } | Sort-Object Name)
    if ($d.Count) { return $d[-1].FullName }
    return ''
  }
  if ($name -notmatch '^[A-Za-z0-9._-]+$') { return '' }
  $p = Join-Path $BackupRoot $name
  if (Test-Path -LiteralPath $p -PathType Container) { return $p }
  return ''
}

# ---------------------------------------------------------------- Patch 7 gate
function Get-ContainerPatch7 {
  # reads the three Patch 7 files inside the container (read-only); reachable = docker exec worked at all
  $files = @('content-indexer.php', 'edutek-title.php', 'edutek-safe-name.php')
  $r = Invoke-Docker (@('exec', $script:Container, 'sha256sum') + @($files | ForEach-Object { '/var/www/html/includes/' + $_ }))
  $h = @{}
  foreach ($l in @($r.out -split "`n")) { if ($l -match '^([0-9a-f]{64})\s+/var/www/html/includes/(\S+)\s*$') { $h[$Matches[2]] = $Matches[1] } }
  $reach = ($r.code -eq 0 -or $h.Count -gt 0)
  $err = ''
  if (-not $reach) { $err = ((@($r.out -split "`n") | Where-Object { $_.Trim() }) | Select-Object -First 1); if (-not $err) { $err = "docker exit code $($r.code)" } }
  return [pscustomobject]@{ reachable = $reach; hashes = $h; error = [string]$err; code = $r.code }
}
function Get-Patch7State([string]$root, [bool]$checkContainer = $true) {
  # r3: the parameter used to be called $container - PowerShell names are case-insensitive, so it hid the
  # script's -Container ('edupak-app') and the check ran 'docker exec True ...', which always failed.
  $inc = Join-Path $root 'includes'
  $ix = Join-Path $inc 'content-indexer.php'
  $miss = @()
  foreach ($f in @('edutek-safe-name.php', 'edutek-title.php')) {
    $p = Join-Path $inc $f
    if (-not (Test-Path -LiteralPath $p)) { $miss += "$f missing" } elseif ((Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash -ne $Modules[$f].ToUpper()) { $miss += "$f differs from the Patch 7 file" }
  }
  if (-not (Test-Path -LiteralPath $ix)) { $miss += 'content-indexer.php missing' }
  else {
    $h = (Get-FileHash -LiteralPath $ix -Algorithm SHA256).Hash
    if ($h -ne $Patch7IndexerSha.ToUpper()) {
      $t = [IO.File]::ReadAllText($ix)
      if (-not ($t.Contains('edutek-title.php') -and $t.Contains('edutek_index_junk'))) { $miss += 'content-indexer.php is the old indexer (no Patch 7 title guard)' }
    }
  }
  if ($checkContainer -and -not $miss.Count) {
    $c = Get-ContainerPatch7
    if (-not $c.reachable) { $miss += ('container ' + $script:Container + ' cannot be checked: ' + $c.error) }
    else {
      foreach ($f in @('edutek-safe-name.php', 'edutek-title.php')) {
        if (-not $c.hashes.ContainsKey($f)) { $miss += "container does not see $f" }
        elseif ($c.hashes[$f] -ne $Modules[$f].ToLower()) { $miss += "container's $f differs from the Patch 7 file" }
      }
      $hostIx = (Get-FileHash -LiteralPath $ix -Algorithm SHA256).Hash.ToLower()
      if (-not $c.hashes.ContainsKey('content-indexer.php')) { $miss += 'container does not see content-indexer.php' }
      elseif ($c.hashes['content-indexer.php'] -ne $hostIx) { $miss += "container's content-indexer.php differs from $ix" }
    }
  }
  return [pscustomobject]@{ installed = (-not $miss.Count); detail = ($miss -join '; ') }
}

# ---------------------------------------------------------------- self-test on throwaway copies
function Assert([bool]$c, [string]$m) { if ($c) { Say "  PASS  $m" } else { Say "  FAIL  $m"; $script:stFails++ } }
function Invoke-SelfTest {
  $script:stFails = 0
  Say '--- self-test 0: shared rules (in the container)'
  $rule = Invoke-Helper @{ mode = 'rule'; names = @('Peppers..mp4', 'A STUDY IN SCARLET..pdf', '3 Wood working Tricks  Tips...mp4', 'Mango Milk Shake  Refreshing Summer Drink ..mp4', '._01 - Farmers Innovate.mp4', 'x.MP4') }
  $it = @($rule.items)
  Assert ($it[0].clean -ceq 'Peppers.mp4' -and $it[0].title -ceq 'Peppers') "'Peppers..mp4' -> 'Peppers.mp4' / 'Peppers' (got '$($it[0].clean)' / '$($it[0].title)')"
  Assert ($it[1].clean -ceq 'A STUDY IN SCARLET.pdf' -and $it[1].title -ceq 'A Study In Scarlet') "'A STUDY IN SCARLET..pdf' -> 'A STUDY IN SCARLET.pdf' / 'A Study In Scarlet'"
  Assert ($it[2].clean -ceq '3 Wood working Tricks Tips.mp4' -and $it[2].title -ceq 'Wood Working Tricks Tips') "'3 Wood working Tricks  Tips...mp4' -> '3 Wood working Tricks Tips.mp4' (got '$($it[2].clean)' / '$($it[2].title)')"
  Assert ($it[3].clean -ceq 'Mango Milk Shake Refreshing Summer Drink.mp4') "'... Summer Drink ..mp4' -> '... Summer Drink.mp4' (got '$($it[3].clean)')"
  Assert ($it[4].junk -ne '' -and $it[4].legacy -ceq '. 01 Farmers Innovate' -and $it[4].title -ceq 'Farmers Innovate') "'._01 - ...' is junk; old title '. 01 Farmers Innovate', new 'Farmers Innovate'"
  Assert ($it[5].clean -ceq 'x.mp4') "'x.MP4' -> 'x.mp4'"

  $live = $Library
  $tmp = Join-Path $env:TEMP ('clt-selftest-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
  $hex = [guid]::NewGuid().ToString('N').Substring(0, 8)
  $tbl = 'clt_selftest_' + $hex
  $created = $false
  try {
    Say ''; Say '--- building self-test fixtures (r3d: synthetic dirty rows; live catalog only read for template rows)'
    $sel = Invoke-Helper @{ mode = 'select'; ids = @(); prefixes = @('content/') } 'content_meta'
    $rows = @($sel.rows)
    $cA = 'zz-clt-selftest-A'; $cB = 'zz-clt-selftest-B'
    $titleCands = @('Tani . Kids Farm Song.mp4', 'Tani . . . Planting Day.mp4', 'Lesson 1.. Intro to Soil.mp4', 'Wait.... Watch the Seeds.mp4', 'Grow Beans . Part 2.mp4', 'Tani .  Harvest Time.mp4')
    $tr = @(@((Invoke-Helper @{ mode = 'rule'; names = $titleCands }).items) | Where-Object { $_.junk -eq '' -and [IO.Path]::GetFileNameWithoutExtension([string]$_.name) -notmatch '^\.|\.$' -and $_.legacy -cne $_.title } | Select-Object -First 4)
    if ($tr.Count -lt 4) { throw "self-test: only $($tr.Count) title fixtures differ between the old and the new title rule" }
    # cat, file name, thumbnail name ('' = none), create the file?
    $spec = @(
      @($cA, '._01 - Farmers Innovate.mp4', '', $true), @($cA, '._02 - Grow Melons.mp4', '', $true),
      @($cB, '._03 - Neem Oil Spray.mp4', '', $true), @($cB, '._04 - Compost Pile.mp4', '', $true),
      @($cA, '._08 - Gone clip.mp4', '', $false), @($cB, '._09 - Gone clip.mp4', '', $false),
      @($cA, 'Peppers..mp4', 'Peppers..jpg', $true), @($cA, 'My Two Weeks Old Compost Pile Is On Fire..mp4', 'My Two Weeks Old Compost Pile Is On Fire..jpg', $true),
      @($cB, 'Methods of composting step by step..mp4', 'Methods of composting step by step..jpg', $true),
      @($cA, 'Checking In..pdf', '', $true), @($cB, 'Betty Namaganda meme yange..mp3', '', $true),
      @($cA, [string]$tr[0].name, '', $true), @($cA, [string]$tr[1].name, '', $true), @($cB, [string]$tr[2].name, '', $true), @($cB, [string]$tr[3].name, '', $true))
    if ($rows.Count -lt ($spec.Count + 10)) { throw "self-test needs at least $($spec.Count + 10) catalog rows as templates, found $($rows.Count)" }
    $legacy = @{}; foreach ($x in @((Invoke-Helper @{ mode = 'rule'; names = @($spec | ForEach-Object { $_[1] }) }).items)) { $legacy[[string]$x.name] = [string]$x.legacy }
    $seed = New-Object System.Collections.ArrayList; $heldIds = @(); $seedIds = @{}
    for ($i = 0; $i -lt $spec.Count; $i++) {
      $s = $spec[$i]; $id = [int]$rows[$i].id; $seedIds[$id] = $true
      $th = $(if ($s[2]) { "content/$($s[0])/Sub One/$($s[2])" } else { '' })
      [void]$seed.Add(@{ id = $id; file_path = "content/$($s[0])/Sub One/$($s[1])"; title = $legacy[$s[1]]; thumb = $th; make = $s[3]; thumbName = $s[2]; cat = $s[0]; name = $s[1] })
      if (-not $s[3]) { $heldIds += $id }
    }
    Say ("{0} template rows: {1} become synthetic fixtures, {2} stay as untouched neighbours" -f $rows.Count, $seed.Count, ($rows.Count - $seed.Count))
    # throwaway library: tiny files for the neighbours (same names as live) and for the fixtures
    Set-Variable -Scope Script -Name Library -Value (Join-Path $tmp 'lib'); Set-Variable -Scope Script -Name QuarantineRoot -Value (Join-Path $tmp 'quarantine'); Set-Variable -Scope Script -Name BackupRoot -Value (Join-Path $tmp 'backups')
    $made = 0
    foreach ($r in $rows) {
      if ($seedIds.ContainsKey([int]$r.id)) { continue }
      $rel = ([string]$r.file_path).Substring(8) -replace '/', '\'
      $src = Join-Path $live $rel
      if (-not (Test-File $src)) { continue }
      $dst = Join-Path $Library $rel
      try {
        $d = Split-Path $dst; if (-not [IO.Directory]::Exists($d)) { [void][IO.Directory]::CreateDirectory($d) }
        [IO.File]::WriteAllText($dst, "selftest $rel", $utf8); $made++
        $stem = [IO.Path]::GetFileNameWithoutExtension($src)
        foreach ($e in $ThumbExt) { if (Test-File (Join-Path (Split-Path $src) ($stem + $e))) { [IO.File]::WriteAllText((Join-Path $d ($stem + $e)), "thumb $rel", $utf8) } }
      } catch { }
    }
    foreach ($f in $seed) {
      $d = Join-Path $Library "$($f.cat)\Sub One"; if (-not [IO.Directory]::Exists($d)) { [void][IO.Directory]::CreateDirectory($d) }
      if ($f.make) { [IO.File]::WriteAllText((Join-Path $d $f.name), "selftest $($f.name)", $utf8); $made++ }
      if ($f.thumbName) { [IO.File]::WriteAllText((Join-Path $d $f.thumbName), "thumb $($f.name)", $utf8) }
    }
    $firstDir = Join-Path $Library "$cA\Sub One"
    foreach ($j in @('.DS_Store', 'Thumbs.db', 'desktop.ini', '._extra clip.mp4')) { [IO.File]::WriteAllText((Join-Path $firstDir $j), 'junk', $utf8) }
    Say ("throwaway library: {0} ({1} files + their thumbnails + 4 extra junk files)" -f $Library, $made)
    $script:Table = $tbl
    $c = Invoke-Helper @{ mode = 'clone'; ids = @($rows | ForEach-Object { [int]$_.id }) }; $created = $true
    $sd = Invoke-Helper @{ mode = 'seed'; items = @($seed | ForEach-Object { @{ id = $_.id; file_path = $_.file_path; title = $_.title; thumb = $_.thumb } }) }
    Say ("scratch table {0}: {1} rows copied, {2} turned into fixtures" -f $tbl, $c.count, $sd.count)
    $script:Sim = $true
    Set-Variable -Scope Script -Name Category -Value ''
    $dbBefore = Get-RowsText (Invoke-Helper @{ mode = 'dump' })
    $fsBefore = Get-Tree $Library

    Say ''; Say '--- self-test 1: plan'
    $plan = Get-Plan; Show-Plan $plan
    $nJF = @(Get-Todo $plan 'junk-file').Count; $nJR = @(Get-Todo $plan 'junk-row').Count; $nN = @(Get-Todo $plan 'name').Count; $nT = @(Get-Todo $plan 'title').Count
    Assert ($nJR -ge 1 -and $nN -ge 1 -and $nT -ge 1 -and $nJF -ge 4) "plan has junk files ($nJF), junk rows ($nJR), renames ($nN), titles ($nT)"
    Assert (@($plan.items | Where-Object { $_.action -eq 'held' -and $heldIds -contains $_.id }).Count -eq $heldIds.Count) ("rows whose file is gone are held, not deleted ({0})" -f $heldIds.Count)

    Say ''; Say '--- self-test 2: apply'
    $ok = Invoke-Apply $plan 'st-apply'
    Assert $ok 'apply succeeded'
    $ar = @((Invoke-Helper @{ mode = 'dump' }).rows)
    $byId = @{}; foreach ($r in $ar) { $byId[[int]$r.id] = $r }
    Assert (@(Get-Todo $plan 'junk-row' | Where-Object { $byId.ContainsKey($_.id) }).Count -eq 0) 'junk rows deleted'
    Assert (@($heldIds | Where-Object { -not $byId.ContainsKey($_) }).Count -eq 0) 'held rows untouched'
    $okN = $true; foreach ($it in @(Get-Todo $plan 'name')) { $r = $byId[$it.id]; if ($r.file_path -cne $it.new_path -or $r.content_id -cne $it.new_path -or $r.title -cne $it.new_title -or [string]$r.thumbnail_path -cne $it.new_thumb) { $okN = $false; Say "    row $($it.id): $($r.file_path) | $($r.title) | $($r.thumbnail_path)" }; foreach ($x in $it.renames) { if (-not (Test-File $x.new) -or ((Test-File $x.old) -and $x.old -ne $x.new)) { $okN = $false; Say "    file $($x.new)" } } }
    Assert $okN 'renamed files, same-name thumbnails and exactly those rows (path, title, thumbnail)'
    $okT = $true; foreach ($it in @(Get-Todo $plan 'title')) { if ($byId[$it.id].title -cne $it.new_title -or $byId[$it.id].file_path -cne $it.row.file_path) { $okT = $false } }
    Assert $okT 'titles updated, paths unchanged'
    $touched = @{}; foreach ($it in $plan.items) { if ($it.id -ne '') { $touched[[int]$it.id] = $true } }
    $before = @(From-JsonArray $dbBefore); $same = $true
    foreach ($r in $before) { if (-not $touched.ContainsKey([int]$r.id)) { if (($r | ConvertTo-Json -Compress) -cne ($byId[[int]$r.id] | ConvertTo-Json -Compress)) { $same = $false } } }
    Assert $same ("the other {0} rows are unchanged" -f @($before | Where-Object { -not $touched.ContainsKey([int]$_.id) }).Count)
    Assert (@(Get-JunkFiles $Library).Count -eq 0 -and @(Get-ChildItem -LiteralPath (Join-Path $QuarantineRoot 'st-apply') -Recurse -Force | Where-Object { -not $_.PSIsContainer }).Count -eq $nJF) 'junk files moved to the quarantine with their folders'
    Assert (Test-Path -LiteralPath (Join-Path $BackupRoot 'st-apply\manifest.csv')) 'manifest written'

    Say ''; Say '--- self-test 3: rollback'
    $script:Log = ''
    $ok = Invoke-Undo (Join-Path $BackupRoot 'st-apply')
    Assert $ok 'rollback succeeded'
    Assert ((Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore) 'rows identical to before (exact JSON)'
    Assert ((Get-Tree $Library) -ceq $fsBefore) 'files identical to before (names, sizes, SHA256)'

    foreach ($fa in @('after-moves', 'after-rows', 'after-renames', 'after-names-db', 'after-titles')) {
      Say ''; Say "--- self-test: injected failure $fa -> automatic rollback"
      $script:Log = ''
      Set-Variable -Scope Script -Name FailAt -Value $fa
      $ok = Invoke-Apply (Get-Plan) ('st-' + $fa)
      Set-Variable -Scope Script -Name FailAt -Value ''
      Assert (-not $ok) "apply reported failure ($fa)"
      Assert ((Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore) "rows identical to before ($fa)"
      Assert ((Get-Tree $Library) -ceq $fsBefore) "files identical to before ($fa)"
    }

    $script:Log = ''
    Say ''; Say '--- self-test r3d: quarantine emptied by hand before -Rollback'
    $script:Log = ''
    $ok = Invoke-Apply (Get-Plan) 'st-purged'
    $jpu = Get-Content -LiteralPath (Join-Path $BackupRoot 'st-purged\journal.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    $pm = @(@($jpu.moved) | Where-Object { $_.from -like '*._01 - Farmers Innovate.mp4' -or $_.from -like '*\.DS_Store' })
    $stash = Join-Path $tmp 'stash'; [void](New-Item -ItemType Directory -Path $stash -Force); $k = 0
    foreach ($m in $pm) { $k++; Copy-Item -LiteralPath $m.to -Destination (Join-Path $stash "$k.bin"); Remove-Item -LiteralPath $m.to -Force }
    @($pm | ForEach-Object { [pscustomobject]@{ FullName = $_.to; Length = 4; LastWriteTime = '' } }) | Export-Csv -LiteralPath (Join-Path $tmp 'quarantine-purge-selftest.csv') -NoTypeInformation
    $script:Log = ''
    $okU = Invoke-Undo (Join-Path $BackupRoot 'st-purged')
    $pc = $script:PurgedCount
    Assert ($ok -and $pm.Count -eq 2 -and $okU -and $pc -eq 2 -and (Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore) "rollback with 2 purged quarantine files: succeeds, reports $pc purged, rows identical to before (incl. the junk row of a purged file)"
    $tu1 = Test-BackupUnchanged (Join-Path $BackupRoot 'st-purged') $true; $tu0 = Test-BackupUnchanged (Join-Path $BackupRoot 'st-purged') $false
    Assert ($tu1 -and -not $tu0) '-CheckBackup counts purged files separately: never reported as unchanged'
    $k = 0; foreach ($m in $pm) { $k++; Copy-Item -LiteralPath (Join-Path $stash "$k.bin") -Destination $m.from }
    Assert ((Get-Tree $Library) -ceq $fsBefore) 'all other files moved back exactly (after putting the 2 purged files back by hand)'
    Remove-Item -LiteralPath (Join-Path $tmp 'quarantine-purge-selftest.csv') -Force
    $script:Log = ''
    $ok = Invoke-Apply (Get-Plan) 'st-lost'
    $jl = Get-Content -LiteralPath (Join-Path $BackupRoot 'st-lost\journal.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    $lm = @(@($jl.moved) | Where-Object { $_.from -like '*\.DS_Store' })[0]
    Copy-Item -LiteralPath $lm.to -Destination (Join-Path $stash 'lost.bin'); Remove-Item -LiteralPath $lm.to -Force
    $script:Log = ''
    $okL = Invoke-Undo (Join-Path $BackupRoot 'st-lost')
    Assert ($ok -and -not $okL -and (Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore) 'a missing quarantine file that is NOT in a purge list: rollback reports INCOMPLETE (rows still restored)'
    Copy-Item -LiteralPath (Join-Path $stash 'lost.bin') -Destination $lm.from
    Assert ((Get-Tree $Library) -ceq $fsBefore) 'files identical to before (after putting that file back by hand)'
    Say ''; Say '--- self-test: refusals and limits'
    $n1 = @(Get-Todo $plan 'name')[0]
    $col = $n1.renames[0].new; [IO.File]::WriteAllText($col, 'x', $utf8)
    $p2 = Get-Plan; $x2 = @($p2.items | Where-Object { $_.id -eq $n1.id })[0]
    Assert ($x2.action -eq 'skip' -and $x2.reason -like 'collision*') "rename collision is skipped ($($x2.reason))"
    Remove-Item -LiteralPath $col -Force
    $h264 = Join-Path (Split-Path $n1.renames[0].old) ([IO.Path]::GetFileNameWithoutExtension($n1.renames[0].old) + '.h264.mp4'); [IO.File]::WriteAllText($h264, 'x', $utf8)
    $p3 = Get-Plan; $x3 = @($p3.items | Where-Object { $_.id -eq $n1.id })[0]
    Assert ($x3.action -eq 'skip' -and $x3.reason -like '*transcoded*') 'file with a transcoded copy is skipped'
    Remove-Item -LiteralPath $h264 -Force
    Set-Variable -Scope Script -Name Max -Value 1
    $p4 = Get-Plan
    Assert ((@(Get-Todo $p4 'junk-file').Count -le 1) -and (@(Get-Todo $p4 'name').Count -eq 1) -and (@(Get-Todo $p4 'title').Count -eq 1) -and @($p4.items | Where-Object { $_.action -like 'deferred*' }).Count -gt 0) '-Max 1 caps every scope'
    Set-Variable -Scope Script -Name Max -Value 200
    Set-Variable -Scope Script -Name Scope -Value @('titles')
    $p5 = Get-Plan
    Assert (@(Get-Todo $p5 'name').Count -eq 0 -and @(Get-Todo $p5 'junk-row').Count -eq 0 -and @(Get-Todo $p5 'junk-file').Count -eq 0 -and @(Get-Todo $p5 'title').Count -eq $nT) '-Scope titles only plans titles'
    Set-Variable -Scope Script -Name Scope -Value $AllScopes
    $cat1 = $n1.category
    Set-Variable -Scope Script -Name Category -Value $cat1
    $p6 = Get-Plan
    Assert (@($p6.items | Where-Object { $_.category -ne $cat1 }).Count -eq 0 -and @($p6.items).Count -gt 0) "-Category '$cat1' stays inside that folder"
    Set-Variable -Scope Script -Name Category -Value ''
    # r2: the path that crashed on 2026-10-22 14:28 - a run where one scope is empty and another has one row
    Say ''; Say '--- self-test r2: empty and single-row scopes (the -Category Agriculture crash)'
    $e0 = @(From-JsonArray '[]').Count; $e1 = @(From-JsonArray '[{"id":7}]').Count; $e2 = @(From-JsonArray '[{"id":7},{"id":8}]').Count
    Assert ($e0 -eq 0 -and $e1 -eq 1 -and $e2 -eq 2) "row-count helper under StrictMode: 0 / 1 / 2 rows -> $e0 / $e1 / $e2"
    Set-Variable -Scope Script -Name Max -Value 1
    Set-Variable -Scope Script -Name Scope -Value @('junk-rows', 'names')
    $p8 = Get-Plan
    Assert (@(Get-Todo $p8 'title').Count -eq 0 -and @(Get-Todo $p8 'junk-file').Count -eq 0 -and @(Get-Todo $p8 'name').Count -eq 1 -and @(Get-Todo $p8 'junk-row').Count -eq 1) 'plan: titles 0, junk files 0, names 1, junk rows 1'
    $script:Log = ''
    $ok = Invoke-Apply $p8 'st-single'
    Assert $ok 'apply with empty + single-row scopes succeeds'
    $script:Log = ''
    $ok = Invoke-Undo (Join-Path $BackupRoot 'st-single')
    Assert ($ok -and (Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore -and (Get-Tree $Library) -ceq $fsBefore) 'rollback of that run is exact'
    Set-Variable -Scope Script -Name Max -Value 200
    Set-Variable -Scope Script -Name Scope -Value $AllScopes
    Set-Variable -Scope Script -Name Category -Value $cat1
    $p9 = Get-Plan
    $script:Log = ''
    $ok = Invoke-Apply $p9 'st-category'
    Assert $ok ("apply -Category '$cat1' succeeds (titles {0}, names {1}, junk rows {2}, junk files {3})" -f @(Get-Todo $p9 'title').Count, @(Get-Todo $p9 'name').Count, @(Get-Todo $p9 'junk-row').Count, @(Get-Todo $p9 'junk-file').Count)
    $script:Log = ''
    $ok = Invoke-Undo (Join-Path $BackupRoot 'st-category')
    Assert ($ok -and (Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore -and (Get-Tree $Library) -ceq $fsBefore) 'rollback of the -Category run is exact'
    Set-Variable -Scope Script -Name Category -Value ''
    # a crash while backing up: journal exists, nothing changed, auto-rollback verifies
    $script:Log = ''
    Set-Variable -Scope Script -Name FailAt -Value 'during-backup'
    $ok = Invoke-Apply (Get-Plan) 'st-during-backup'
    Set-Variable -Scope Script -Name FailAt -Value ''
    $jb = Get-Content -LiteralPath (Join-Path $BackupRoot 'st-during-backup\journal.json') -Raw | ConvertFrom-Json
    Assert (-not $ok -and $jb.state -eq 'failed-before-changes' -and (Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore -and (Get-Tree $Library) -ceq $fsBefore) "a crash during the backup is caught; journal state '$($jb.state)'; nothing changed"
    # a backup folder WITHOUT journal (what v2 left behind at 14:28)
    $nj = Join-Path $BackupRoot '20990101-000000'
    [void](New-Item -ItemType Directory -Path $nj -Force)
    foreach ($f in @('manifest.csv', 'rows-junk.json', 'rows-junk.csv', 'rows-names.json', 'rows-names.csv')) { $s = Join-Path $BackupRoot "st-during-backup\$f"; if (Test-Path -LiteralPath $s) { Copy-Item -LiteralPath $s -Destination $nj } }
    $script:Log = ''
    Assert ((Resolve-BackupDir 'latest') -eq $nj) '-Rollback latest finds the newest folder even without a journal'
    $ok = Invoke-NoJournal $nj
    $jn = Get-Content -LiteralPath (Join-Path $nj 'journal.json') -Raw | ConvertFrom-Json
    Assert ($ok -and $jn.state -eq 'aborted-before-changes' -and (Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore -and (Get-Tree $Library) -ceq $fsBefore) 'no-journal folder: verified unchanged and closed, nothing changed'
    Remove-Item -LiteralPath (Join-Path $nj 'journal.json')
    $jx = @(Get-Todo $p8 'junk-row')[0]
    [void](Invoke-Helper @{ mode = 'poke'; id = $jx.id; title = 'changed meanwhile' })
    $ok = Invoke-NoJournal $nj
    [void](Invoke-Helper @{ mode = 'poke'; id = $jx.id; title = $jx.old_title })
    Assert (-not $ok -and -not (Test-Path -LiteralPath (Join-Path $nj 'journal.json'))) 'no-journal folder with a changed row: reported, nothing changed, folder left open'
    Remove-Item -LiteralPath $nj -Recurse -Force
    # Patch 7 gate
    $fh = Join-Path $tmp 'htdocs'; [void](New-Item -ItemType Directory -Path (Join-Path $fh 'includes') -Force)
    $g0 = Get-Patch7State $fh $false
    foreach ($f in @('edutek-safe-name.php', 'edutek-title.php', 'content-indexer.php')) { Copy-Item -LiteralPath (Join-Path $ModuleDir $f) -Destination (Join-Path $fh 'includes') }
    $g1 = Get-Patch7State $fh $false
    Assert ((-not $g0.installed) -and $g1.installed) "Patch 7 gate: empty htdocs -> not installed ($($g0.detail)); Patch 7 files -> installed"
    # r3: the container half of the gate, for real (r2 only ever tested it with the container check switched off)
    $cx = Get-ContainerPatch7
    Assert ($cx.reachable -and $cx.hashes.ContainsKey('content-indexer.php')) "Patch 7 gate: container '$script:Container' is read (content-indexer.php sha256 $(if ($cx.hashes.ContainsKey('content-indexer.php')) { $cx.hashes['content-indexer.php'].Substring(0, 8) } else { '-' }); error: '$($cx.error)')"
    $hostOnly = Get-Patch7State $Htdocs $false; $full = Get-Patch7State $Htdocs $true
    Assert ($hostOnly.installed -eq $full.installed) "Patch 7 gate: host and container agree for $Htdocs (host: $(if ($hostOnly.installed) { 'installed' } else { 'not installed' }); with container: $(if ($full.installed) { 'installed' } else { 'not installed: ' + $full.detail }))"
    $script:Log = ''
    # a row changed after the scan: the title update refuses the whole batch and the run rolls back
    $t1 = @(Get-Todo $plan 'title')[0]
    $p7 = Get-Plan
    [void](Invoke-Helper @{ mode = 'poke'; id = $t1.id; title = 'changed meanwhile' })
    $ok = Invoke-Apply $p7 'st-changed'
    [void](Invoke-Helper @{ mode = 'poke'; id = $t1.id; title = $t1.old_title })
    Assert (-not $ok -and (Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore -and (Get-Tree $Library) -ceq $fsBefore) 'a row changed after the scan is refused; everything rolled back'
    # a locked junk file is skipped, the rest goes through
    $lockF = Join-Path $firstDir 'Thumbs.db'
    $fsx = [IO.File]::Open($lockF, 'Open', 'Read', 'None')
    try { $ok = Invoke-Apply (Get-Plan) 'st-locked' } finally { $fsx.Close() }
    Assert ($ok -and (Test-File $lockF)) 'a locked junk file is skipped and the run completes'
    $script:Log = ''
    $ok = Invoke-Undo (Join-Path $BackupRoot 'st-locked')
    Assert ($ok -and (Get-RowsText (Invoke-Helper @{ mode = 'dump' })) -ceq $dbBefore -and (Get-Tree $Library) -ceq $fsBefore) 'rollback after the partial run is exact'
    $live2 = $true; try { [void](Invoke-Helper @{ mode = 'dump' } 'content_meta'); $live2 = $false } catch { }
    Assert $live2 'scratch-only modes are refused on content_meta'
  } finally {
    $script:Log = ''
    Set-Variable -Scope Script -Name FailAt -Value ''
    if ($created) { try { [void](Invoke-Helper @{ mode = 'drop' }); Say "scratch table $tbl dropped" } catch { Say "could not drop $tbl : $($_.Exception.Message)" } }
    $script:Table = 'content_meta'
    if ($tmp.StartsWith($env:TEMP) -and (Test-Path -LiteralPath $tmp)) { Remove-Item -LiteralPath $tmp -Recurse -Force; Say "throwaway folder removed" }
  }
  Say ''
  if ($script:stFails) { Say "SELF-TEST: $($script:stFails) FAILURE(S)"; return $false } else { Say 'SELF-TEST: all passed'; return $true }
}

# ---------------------------------------------------------------- main
$script:Me = Split-Path -Leaf $PSCommandPath
$script:Patch7Note = ''
foreach ($s in $Scope) { if ($AllScopes -notcontains $s) { Write-Host "Unknown -Scope '$s'. Use: $($AllScopes -join ', ')"; exit 1 } }
if ($Max -lt 1 -or $Max -gt 2000) { Write-Host '-Max must be 1..2000'; exit 1 }
if ($Category -and -not (Test-Path -LiteralPath (Join-Path $Library $Category) -PathType Container)) { Write-Host "No library folder '$Category'"; exit 1 }
Install-Modules
try {
  [void](Invoke-Helper @{ mode = 'ping' } 'content_meta')
  if ($SelfTest) { if (Invoke-SelfTest) { exit 0 } else { exit 1 } }
  if ($CheckBackup) {
    $bk = Resolve-BackupDir $CheckBackup
    if (-not $bk) { Write-Host "No backup folder found ($CheckBackup) under $BackupRoot"; exit 1 }
    Say ''; Say ("=== Read-only check of {0} ({1}) ===" -f (Split-Path $bk -Leaf), (Get-Date).ToString('s'))
    $jp = Join-Path $bk 'journal.json'
    if (Test-Path -LiteralPath $jp) { $j = Get-Content -LiteralPath $jp -Raw -Encoding UTF8 | ConvertFrom-Json; Say ("  journal: state '{0}'{1}" -f $j.state, $(if ($j.PSObject.Properties['rolled_back']) { ", rolled back " + $j.rolled_back } else { '' })) } else { Say '  journal: none (the run stopped before its first change)' }
    if (Test-BackupUnchanged $bk) { Say 'RESULT: unchanged - every backed-up row and every planned file is exactly as before the run.'; exit 0 }
    Say 'RESULT: differences found (listed above). Nothing was changed by this check.'; exit 3
  }
  if ($Rollback) {
    $bk = Resolve-BackupDir $Rollback
    if (-not $bk) { Write-Host "No run found to roll back ($Rollback) under $BackupRoot"; exit 1 }
    Say ''; Say ("=== Rollback of {0} ({1}) ===" -f (Split-Path $bk -Leaf), (Get-Date).ToString('s'))
    if (-not (Test-Path -LiteralPath (Join-Path $bk 'journal.json'))) { $script:Log = Join-Path $bk 'log.txt'; if (Invoke-NoJournal $bk) { exit 0 } else { exit 3 } }
    $script:Log = Join-Path $bk 'log.txt'
    if (Invoke-Undo $bk) { if ($script:PurgedCount) { Say ("Rollback complete, except {0} junk file(s) that were purged from the quarantine (rows restored, files cannot come back)." -f $script:PurgedCount) } else { Say 'Rollback complete.' }; [void](Test-BackupUnchanged $bk $true); exit 0 } else { Say 'Rollback INCOMPLETE - see messages above.'; exit 3 }
  }
  $p7 = Get-Patch7State $Htdocs $true
  if ($p7.installed) { $script:Patch7Note = 'installed' } else { $script:Patch7Note = 'NOT installed: ' + $p7.detail }
  $plan = Get-Plan
  Show-Plan $plan
  Say ("Patch 7 (indexer title guard): {0}" -f $script:Patch7Note)
  if (-not $Apply) {
    if (-not $OutCsv) { $OutCsv = Join-Path ([Environment]::GetFolderPath('Desktop')) ('Library-Titles-Scan-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.csv') }
    Export-Plan $plan $OutCsv
    Say ''; Say "Scan written to $OutCsv"
    if ($p7.installed) { Say 'Check only - nothing was changed. Run with -Apply to fix (add -Category <folder> to do one folder first).' }
    else { Say 'Check only - nothing was changed. Install Patch 7 first (.\Install-Patch7-titles.ps1 -Apply), then run with -Apply.' }
    exit 0
  }
  if (-not $p7.installed) {
    if (-not $AllowWithoutPatch7) {
      Say ''
      Say 'REFUSED: Patch 7 is not installed. Without it the indexer still writes the old dotted titles and re-adds the'
      Say '"._" junk cards the next time it indexes these folders, undoing this cleanup. Install it first:'
      Say '  powershell -ExecutionPolicy Bypass -File .\Install-Patch7-titles.ps1 -Apply'
      Say 'or, if you accept that risk, add -AllowWithoutPatch7. Nothing was changed.'
      exit 4
    }
    Say 'WARNING: applying without Patch 7 (-AllowWithoutPatch7). A later index run may write the old titles again.'
  }
  $todo = 0; foreach ($t in @('junk-file', 'junk-row', 'name', 'title')) { $todo += @(Get-Todo $plan $t).Count }
  if ($todo -eq 0) { Say 'Nothing to do - already clean.'; exit 0 }
  $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
  if (Invoke-Apply $plan $stamp) { exit 0 } else { exit 2 }
} catch {
  $m = $_.Exception.Message; $ln = ''; try { $ln = ' (line ' + $_.InvocationInfo.ScriptLineNumber + ')' } catch { }
  Say ("UNEXPECTED ERROR: " + $m + $ln)
  if ($script:ActiveBackup) {
    $bk = $script:ActiveBackup; $script:ActiveBackup = ''
    [void](Invoke-AutoRollback $bk $true)
  } else { Say 'No run was in progress: nothing was changed.' }
  exit 2
} finally { Remove-Modules }
