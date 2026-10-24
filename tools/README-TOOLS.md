# Edutek tools

Updated 
2026-10-24
 from the Edutek1 server.

* `bulk-importer/` - the keyboard-only bulk importer (separate Docker Compose project `edutek-bulk-fresh-v05`,
  http://127.0.0.1:8092/bulk-import-guided.php). `app/` is mounted read-only into the containers;
  `compose.yaml` + `compose.override.yaml` define them. Runtime data (`data/`), `.env`, backups and test media
  are NOT in git.
* `bulk-importer/patch-4p/` - the Patch 4p installer that is live now, with its unit / end-to-end tests and
  keyboard walk (`Install-Patch4p.ps1` = check only, `-E2E`, `-Apply`, `-Rollback latest`).
* `bulk-importer/import-analysis/` - import plan / analysis helpers.
* `maintenance/` - one-off library maintenance scripts (latest revision of each). Each one checks first and
  only changes things with its own -Apply switch; read the header before running.
* `system/startup-index.ps1` - the start-up indexer task.

Never commit `.env`, `operator-auth.json`, Wi-Fi settings, `data/`, backups or media.
