<?php
/* Edutek importer patch 4b: catalog cleanup after a publish or undo (CLI only, outside the main app's code).
   Run-ImportIndexer.ps1 copies this file to /tmp in edupak-app and runs it there after a successful partial index.
   Same rule as api/local-catalog-cleanup-start.php: a content_meta row is stale when its file no longer exists on disk.
   Never touches files, never indexes. Default: report only. --execute deletes, but only when the guards pass:
     - the library mount is healthy: CONTENT_PATH is a directory with at least --min-files PDF/MP4 files and at least 95% of --expect-files;
     - stale rows <= --max-delete and <= --max-fraction of the rows checked;
     - with --expect-stale=N, the stale count found now must still be N (the count the report pass saw).
   Prints one JSON line. Audit CSV (same columns as the app's cleanup report) goes to INDEX_STATUS_DIR. Exit 0 = ok/held, 1 = error. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$opt = getopt('', ['execute', 'min-files:', 'expect-files:', 'max-delete:', 'max-fraction:', 'expect-stale:', 'tag:', 'app:']);
$app = rtrim((string)($opt['app'] ?? '/var/www/html'), '/');
function cc_out(array $o, int $code): void { echo json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n"; exit($code); }
try {
  require_once $app . '/includes/config.php';
  require_once $app . '/includes/auth.php';
} catch (Throwable $e) { cc_out(['state' => 'error', 'message' => 'Could not load the app configuration: ' . $e->getMessage()], 1); }
$execute = isset($opt['execute']);
$minFiles = (int)($opt['min-files'] ?? 30000); $expectFiles = (int)($opt['expect-files'] ?? 0);
$maxDelete = (int)($opt['max-delete'] ?? 25); $maxFraction = (float)($opt['max-fraction'] ?? 0.005);
$expectStale = isset($opt['expect-stale']) ? (int)$opt['expect-stale'] : null;
$tag = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($opt['tag'] ?? '')) ?? '';
$contentRoot = rtrim(str_replace('\\', '/', (string)CONTENT_PATH), '/');
$statusDir = rtrim((string)(getenv('INDEX_STATUS_DIR') ?: '/system/index-status'), '/');
$res = ['state' => '', 'mode' => $execute ? 'execute' : 'report', 'tag' => $tag, 'content_root' => $contentRoot, 'library_files' => 0, 'expect_files' => $expectFiles,
  'checked' => 0, 'stale' => 0, 'removed' => 0, 'unresolved' => 0, 'errors' => 0, 'audit' => '', 'message' => '', 'stale_paths' => []];
// 1. mount health
if ($contentRoot === '' || !is_dir($contentRoot)) { $res['state'] = 'held'; $res['message'] = "The library folder $contentRoot is not available in edupak-app. Nothing was deleted."; cc_out($res, 0); }
$n = 0;
try {
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($contentRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
  foreach ($it as $f) { if ($f->isFile()) { $e = strtolower($f->getExtension()); if ($e === 'pdf' || $e === 'mp4') $n++; } }
} catch (Throwable $e) { $res['state'] = 'held'; $res['message'] = 'Could not list the library: ' . $e->getMessage() . ' Nothing was deleted.'; cc_out($res, 0); }
$res['library_files'] = $n;
if ($n < $minFiles || ($expectFiles > 0 && $n < (int)floor($expectFiles * 0.95))) {
  $res['state'] = 'held'; $res['message'] = "The library mount looks incomplete ($n PDF/MP4 files; need at least $minFiles" . ($expectFiles > 0 ? " and 95% of the $expectFiles in the device list" : '') . '). Nothing was deleted.'; cc_out($res, 0);
}
// 2. find stale rows (same path rule as the app)
function cc_abs(string $webPath, string $contentRoot, string $app): ?string {
  $webPath = str_replace('\\', '/', ltrim($webPath, '/')); $prefix = basename($contentRoot) . '/';
  if ($prefix !== '/' && strpos($webPath, $prefix) === 0) return $contentRoot . '/' . substr($webPath, strlen($prefix));
  $ht = rtrim(str_replace('\\', '/', (string)realpath($app)), '/');
  if (strpos($webPath, 'videos/') === 0 && $ht !== '') return $ht . '/' . $webPath;
  return null;
}
function cc_csv(string $v): string { return ($v !== '' && preg_match('/^[=+\-@]/', $v)) ? "'" . $v : $v; }
$lock = @fopen($statusDir . '/local-catalog-cleanup.lock', 'c');
if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB)) { $res['state'] = 'held'; $res['message'] = 'Another catalog cleanup is running. Nothing was deleted.'; cc_out($res, 0); }
try {
  $pdo = getDbConnection();
  $sel = $pdo->prepare('SELECT content_id, file_path, content_type, category, subcategory, title, thumbnail_path FROM content_meta WHERE content_id > :last ORDER BY content_id LIMIT :n');
  $sel->bindValue(':n', 500, PDO::PARAM_INT); $last = ''; $stale = [];
  while (true) {
    $sel->bindValue(':last', $last, PDO::PARAM_STR); $sel->execute(); $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) break;
    foreach ($rows as $row) {
      $cid = (string)($row['content_id'] ?? ''); $last = $cid; $res['checked']++;
      if ($cid === '') { $res['errors']++; continue; }
      $abs = cc_abs((string)($row['file_path'] ?? $cid), $contentRoot, $app);
      if ($abs === null) { $res['unresolved']++; continue; }
      if (!is_file($abs)) $stale[] = $row + ['_abs' => $abs];
    }
    if (count($rows) < 500) break;
  }
  $res['stale'] = count($stale); $res['stale_paths'] = array_slice(array_map(fn($r) => (string)$r['file_path'], $stale), 0, 50);
  $runId = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
  $held = $res['stale'] > $maxDelete || ($res['checked'] > 0 && $res['stale'] > $res['checked'] * $maxFraction);
  $mismatch = $execute && $expectStale !== null && $res['stale'] !== $expectStale;
  $csvName = ($execute && !$held && !$mismatch && $stale) ? 'local-catalog-cleanup-' . $runId . '.csv' : ($stale ? 'catalog-cleanup-' . ($held ? 'held' : 'report') . '-' . $runId . '.csv' : '');
  $h = null;
  if ($csvName !== '') { $h = @fopen($statusDir . '/' . $csvName, 'wb'); if (!is_resource($h)) throw new RuntimeException('Could not write the audit CSV in ' . $statusDir); fputcsv($h, ['timestamp', 'content_id', 'file_path', 'content_type', 'category', 'subcategory', 'title', 'thumbnail_path', 'message']); $res['audit'] = $csvName; }
  $row2csv = function (array $r, string $msg) use (&$h): void { fputcsv($h, array_map('cc_csv', [gmdate(DATE_ATOM), (string)$r['content_id'], (string)$r['file_path'], (string)($r['content_type'] ?? ''), (string)($r['category'] ?? ''), (string)($r['subcategory'] ?? ''), (string)($r['title'] ?? ''), (string)($r['thumbnail_path'] ?? ''), $msg])); };
  if ($held) {
    foreach ($stale as $r) $row2csv($r, 'Held: file missing, not deleted (more stale rows than the safety limit).');
    $res['state'] = 'held'; $res['message'] = $res['stale'] . ' catalog rows point to missing files, more than the safety limit (' . $maxDelete . ' rows or ' . ($maxFraction * 100) . '% of ' . $res['checked'] . '). Nothing was deleted; check the list and run the cleanup by hand if it is right.';
  } elseif ($mismatch) {
    foreach ($stale as $r) $row2csv($r, 'Not deleted: the stale count changed since the report pass.');
    $res['state'] = 'held'; $res['message'] = 'The number of stale rows changed between the report pass (' . $expectStale . ') and now (' . $res['stale'] . '). Nothing was deleted.';
  } elseif (!$execute) {
    foreach ($stale as $r) $row2csv($r, 'Report only: file missing.');
    $res['state'] = 'report'; $res['message'] = $res['stale'] . ' stale row(s) found (report only).';
  } else {
    $del = $pdo->prepare('DELETE FROM content_meta WHERE content_id = :id');
    foreach ($stale as $r) {
      if (is_file($r['_abs'])) { $row2csv($r, 'Kept: the file exists again.'); continue; }
      try { $del->execute([':id' => (string)$r['content_id']]); if ($del->rowCount() > 0) { $res['removed']++; $row2csv($r, 'Content file no longer exists on disk.'); } }
      catch (PDOException $e) { $res['errors']++; $row2csv($r, 'Could not remove stale catalog record: ' . $e->getMessage()); }
    }
    $res['state'] = 'done'; $res['message'] = $res['removed'] . ' stale catalog row(s) removed.';
  }
  if (is_resource($h)) fclose($h);
} catch (Throwable $e) { $res['state'] = 'error'; $res['message'] = 'Catalog cleanup failed: ' . $e->getMessage(); cc_out($res, 1); }
finally { if (is_resource($lock)) { @flock($lock, LOCK_UN); @fclose($lock); } }
cc_out($res, 0);
