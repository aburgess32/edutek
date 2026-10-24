<?php
/* Edutek: the old sandbox importer (maintenance key) was retired in patch 4b. Old bookmarks go straight to the bulk importer, which listens on 127.0.0.1:8092 only. */
header('Cache-Control: no-store');
header('Location: http://127.0.0.1:8092/bulk-import-guided.php', true, 302);
exit;
