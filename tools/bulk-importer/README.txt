Edutek Fresh Sandbox v0.5 - isolated baseline, not production importer

Based on the actual uploaded 2026-10-20 source snapshot. Maintenance access and visual placements are included. No changes to D:\edutek, existing containers, production database, old sandbox or real media library. Source and test jobs live ONLY in D:\edutek-system\bulk-loader-fresh-v05.

Local URL: http://localhost:8092
Separate Docker Compose project: edutek-bulk-fresh-v05
Separate services: web and worker. restart: unless-stopped. Docker Desktop itself must be running; this does NOT enable Windows startup for Docker Desktop.
The existing Edutek PHP image is reused by its exact local image ID. No image build or download. No database service/network or real library mount.
Source code is mounted read-only. Data is mounted read-write. Port is bound to 127.0.0.1 only. Network is internal. PHP's development web server is used ONLY for this local test sandbox, not production hosting.

INSTALL
Extract the entire ZIP. Run Install-FreshSandbox.ps1 with Windows PowerShell. It refuses to overwrite an existing installation and verifies web/worker readiness. Save the newly displayed sandbox key privately. This temporary key is not the final administrator-unlock design.
Do not rerun installer after a partial failure. Diagnose retained files/logs instead.

TEST
Unlock new sandbox; create a disposable job; wait for awaiting_review; check only 02 New.pdf and 02 New.jpg in the visual editor; apply category Math, course Fresh Test; rename them individually to Introduction.pdf and Introduction.jpg if desired, keeping extensions. Validate; expect two selected, zero errors; approve with COPY SANDBOX; expect two copied_size_verified results in sandbox only. Existing source files remain. No indexing.

START / STOP
powershell.exe -NoProfile -ExecutionPolicy Bypass -File D:\edutek-system\bulk-loader-fresh-v05\Start-FreshSandbox.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File D:\edutek-system\bulk-loader-fresh-v05\Stop-FreshSandbox.ps1
Stopping does not delete data. Start again with Start-FreshSandbox.ps1. Existing jobs survive.

LOGS (PowerShell)
$root='D:\edutek-system\bulk-loader-fresh-v05'
docker compose --project-directory $root --env-file "$root\.env" -p edutek-bulk-fresh-v05 -f "$root\compose.yaml" logs --tail 60 web worker

BOUNDARIES
This is a clean installation of the current tested prototype, with managed worker lifecycle and isolated storage, not the completed accessible uploader. Fixtures are still hardcoded; PDF/video/image dummy contents are intentionally not valid playable media. No real USB selector, library inventory, actual thumbnail preview, PDF first-page rendering, audiobook-cover upload, companion grouping/renaming, production executor, indexing, cancellation/resume, autosaved visual drafts or final admin access design. Visual edits persist only after validation upload. Placement suggestions reflect fixture plan folders only. Renderer dependencies remain pending. Partial output handling remains prototype-grade.

VALIDATION
The uploaded source contained both maintenance-key access and linked visual editor. Local package archive and JavaScript syntax verified by assistant. PHP lint, Compose readiness and browser acceptance must run on the Beelink. A successful baseline does not mean every requested feature is implemented.

SECRETS
Only a SHA256 hash of the random 256-bit access key is stored in data/operator-auth.json. Do not upload this configuration, browser cookies, or key. All holders of this test key share sandbox-operator ownership. Final multi-admin access still needs design/testing.
