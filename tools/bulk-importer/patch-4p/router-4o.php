<?php
// Patch 4o test router (throwaway UI-walk container ONLY; never used by the real site).
// The importer only answers Host localhost:8092 / 127.0.0.1:8092. In the throwaway
// container the walk reaches it on 127.0.0.1:18492, so this router presents the
// expected Host to the page and leaves every other request to php -S.
$p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($p === '/' || $p === '/bulk-import-guided.php') {
    $_SERVER['HTTP_HOST'] = 'localhost:8092';
    $f = '/sandbox/public/bulk-import-guided.php';
    $_SERVER['SCRIPT_NAME'] = '/bulk-import-guided.php';
    $_SERVER['PHP_SELF'] = '/bulk-import-guided.php';
    $_SERVER['SCRIPT_FILENAME'] = $f;
    chdir(dirname($f));
    require $f;
    return true;
}
return false;
