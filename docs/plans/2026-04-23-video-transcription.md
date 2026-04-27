# Video Transcription & Search Enhancement Implementation Plan

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** Extract the first 30 seconds of audio from every video on the Beelink, transcribe it locally with whisper.cpp, store the text in MySQL, and make it searchable through the existing search waterfall.

**Architecture:** A resumable PHP CLI batch worker (`scripts/transcribe-videos.php`) shells out to `ffmpeg` and `whisper-cli`. An admin web page (`admin/transcription.php`) is visible **only when the Beelink has internet** (pre-deployment) and shows progress pulled from the DB. The search API includes the new `transcript_snippet` column in its existing FULLTEXT and LIKE fallbacks.

**Tech Stack:** PHP 8.1 CLI, whisper.cpp (C++ binary), ffmpeg, MySQL 8.0 FULLTEXT, Docker multi-stage build.

---

## Task 1: Database Migration — Add Transcript Columns

**Objective:** Extend `content_meta` to store transcript snippets and track processing status.

**Files:**
- Create: `db/migrations/0016_add_transcript_snippet.sql`
- Modify: `db/schema.sql` (keep in sync)

**Step 1: Write migration UP**

```sql
-- UP
ALTER TABLE content_meta
    ADD COLUMN transcript_snippet TEXT DEFAULT NULL AFTER description,
    ADD COLUMN transcript_status ENUM('pending','processing','done','failed') DEFAULT NULL AFTER transcript_snippet,
    ADD COLUMN transcript_updated_at TIMESTAMP NULL DEFAULT NULL AFTER transcript_status,
    DROP INDEX ft_search,
    ADD FULLTEXT INDEX ft_search (title, description, category, subcategory, source, transcript_snippet);

UPDATE content_meta SET transcript_status = 'pending' WHERE content_type = 'video';
```

**Step 2: Write migration DOWN**

```sql
-- DOWN
ALTER TABLE content_meta
    DROP COLUMN transcript_snippet,
    DROP COLUMN transcript_status,
    DROP COLUMN transcript_updated_at,
    DROP INDEX ft_search,
    ADD FULLTEXT INDEX ft_search (title, category, subcategory, source);
```

**Step 3: Update schema.sql**

Add the three new columns to the `content_meta` CREATE TABLE and update the `ft_search` definition.

**Step 4: Verify**

Run: `php scripts/migrate.php up`
Expected: `Applied: 0016_add_transcript_snippet.sql`

**Step 5: Commit**

```bash
git add db/migrations/0016_add_transcript_snippet.sql db/schema.sql
git commit -m "feat(db): add transcript_snippet and status columns for video transcription"
```

---

## Task 2: Dockerfile — Add whisper.cpp Binary

**Objective:** Compile whisper.cpp during Docker build and copy the binary into the PHP image.

**Files:**
- Modify: `docker/Dockerfile`

**Step 1: Add multi-stage whisper build**

Insert BEFORE the existing `FROM php:8.1-apache` line:

```dockerfile
# ── whisper.cpp builder stage ─────────────────────────────────────────────────
FROM debian:bookworm-slim AS whisper-builder
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential git \
    && rm -rf /var/lib/apt/lists/*
RUN git clone --depth 1 https://github.com/ggerganov/whisper.cpp.git /tmp/whisper.cpp \
    && cd /tmp/whisper.cpp && make \
    && cp main /usr/local/bin/whisper-cli
```

**Step 2: Copy binary into final image**

In the `FROM php:8.1-apache` stage, after the `a2enmod rewrite` line, add:

```dockerfile
# Copy whisper.cpp binary from builder
COPY --from=whisper-builder /usr/local/bin/whisper-cli /usr/local/bin/whisper-cli
# Create persistent model directory (mounted volume in production)
RUN mkdir -p /var/lib/whisper/models && chmod 755 /var/lib/whisper/models
```

**Step 3: Rebuild and verify**

Run: `docker compose build app`
Then: `docker compose run --rm app whisper-cli --version`
Expected: whisper.cpp version output (non-error)

**Step 4: Commit**

```bash
git add docker/Dockerfile
git commit -m "feat(docker): add whisper.cpp binary via multi-stage build"
```

---

## Task 3: PHP Helper Functions — Transcription Engine

**Objective:** Add reusable PHP functions to extract 30s audio and run whisper.cpp.

**Files:**
- Modify: `htdocs/includes/content-indexer.php`

**Step 1: Add helper functions**

Append to `content-indexer.php`:

```php
/**
 * Check whether whisper-cli is available on this system.
 */
function isWhisperAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    $output = [];
    $code = -1;
    @exec('whisper-cli --version 2>&1', $output, $code);
    $available = ($code === 0);
    return $available;
}

/**
 * Resolve the path to the whisper model file.
 */
function getWhisperModelPath(): string
{
    return '/var/lib/whisper/models/ggml-base.bin';
}

/**
 * Extract the first 30 seconds of audio from a video and transcribe it.
 *
 * @param string $videoPath Absolute path to the video file.
 * @param string $modelPath Absolute path to the ggml model file.
 * @param int    $timeout   Max seconds for the full pipeline.
 * @return string|null Transcript text or null on failure.
 */
function transcribeVideoSnippet(string $videoPath, string $modelPath, int $timeout = 60): ?string
{
    if (!isFfmpegAvailable() || !isWhisperAvailable()) {
        return null;
    }
    if (!file_exists($videoPath) || !is_readable($videoPath)) {
        return null;
    }
    if (!file_exists($modelPath)) {
        return null;
    }

    $tmpWav = sys_get_temp_dir() . '/whisper_' . uniqid() . '.wav';

    // Extract 30s of mono 16kHz audio
    $ffmpegCmd = sprintf(
        'ffmpeg -i %s -t 30 -ar 16000 -ac 1 -vn %s -y 2>/dev/null',
        escapeshellarg($videoPath),
        escapeshellarg($tmpWav)
    );
    @exec($ffmpegCmd, $_, $ffmpegCode);
    if ($ffmpegCode !== 0 || !file_exists($tmpWav)) {
        @unlink($tmpWav);
        return null;
    }

    // Run whisper.cpp without timestamps
    $whisperCmd = sprintf(
        'whisper-cli -m %s -f %s --no-timestamps -l auto 2>/dev/null',
        escapeshellarg($modelPath),
        escapeshellarg($tmpWav)
    );

    $output = [];
    $code = -1;
    @exec($whisperCmd, $output, $code);
    @unlink($tmpWav);

    if ($code !== 0 || empty($output)) {
        return null;
    }

    $text = trim(implode(' ', $output));
    // Collapse whitespace and limit length
    $text = preg_replace('/\s+/', ' ', $text);
    return mb_substr($text, 0, 2000) ?: null;
}
```

**Step 2: Verify syntax**

Run: `php -l htdocs/includes/content-indexer.php`
Expected: `No syntax errors`

**Step 3: Commit**

```bash
git add htdocs/includes/content-indexer.php
git commit -m "feat(content): add whisper.cpp transcription helpers"
```

---

## Task 4: Model Download API

**Objective:** Provide a backend endpoint that downloads the ggml-base.bin model when internet is available.

**Files:**
- Create: `htdocs/api/download-whisper-model.php`

**Step 1: Write the endpoint**

```php
<?php
/**
 * Download Whisper Model API
 *
 * POST — downloads ggml-base.bin from Hugging Face.
 * Requires admin authentication.
 */

include_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require teacher/admin role
if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? 'guest') !== 'teacher') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$modelPath = '/var/lib/whisper/models/ggml-base.bin';

if (file_exists($modelPath) && filesize($modelPath) > 100000000) {
    echo json_encode(['success' => true, 'message' => 'Model already exists']);
    exit;
}

$url = 'https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-base.bin';

$dir = dirname($modelPath);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$tempPath = $modelPath . '.tmp';
$ch = curl_init($url);
$fp = fopen($tempPath, 'wb');
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 600);
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
fclose($fp);

if ($httpCode !== 200 || !file_exists($tempPath) || filesize($tempPath) < 100000000) {
    @unlink($tempPath);
    http_response_code(500);
    echo json_encode(['error' => 'Download failed', 'details' => $err]);
    exit;
}

rename($tempPath, $modelPath);
chmod($modelPath, 0644);

echo json_encode(['success' => true, 'message' => 'Model downloaded']);
```

**Step 2: Verify syntax**

Run: `php -l htdocs/api/download-whisper-model.php`

**Step 3: Commit**

```bash
git add htdocs/api/download-whisper-model.php
git commit -m "feat(api): add whisper model download endpoint"
```

---

## Task 5: Transcription Status API

**Objective:** Expose progress stats for the admin UI to poll.

**Files:**
- Create: `htdocs/api/transcription-status.php`

**Step 1: Write the endpoint**

```php
<?php
/**
 * Transcription Status API
 *
 * GET — returns counts and running state.
 */

include_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? 'guest') !== 'teacher') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

try {
    $pdo = getDbConnection();

    $total = (int) $pdo->query("SELECT COUNT(*) FROM content_meta WHERE content_type = 'video'")->fetchColumn();
    $done  = (int) $pdo->query("SELECT COUNT(*) FROM content_meta WHERE content_type = 'video' AND transcript_status = 'done'")->fetchColumn();
    $failed = (int) $pdo->query("SELECT COUNT(*) FROM content_meta WHERE content_type = 'video' AND transcript_status = 'failed'")->fetchColumn();
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM content_meta WHERE content_type = 'video' AND transcript_status = 'pending'")->fetchColumn();
    $processing = (int) $pdo->query("SELECT COUNT(*) FROM content_meta WHERE content_type = 'video' AND transcript_status = 'processing'")->fetchColumn();

    $lockFile = sys_get_temp_dir() . '/edupak_transcription.lock';
    $isRunning = file_exists($lockFile) && (time() - filemtime($lockFile)) < 300;

    echo json_encode([
        'total'      => $total,
        'done'       => $done,
        'failed'     => $failed,
        'pending'    => $pending,
        'processing' => $processing,
        'percent'    => $total > 0 ? round(($done / $total) * 100, 1) : 0,
        'is_running' => $isRunning,
    ]);
} catch (PDOException $e) {
    error_log('Transcription status error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Status query failed']);
}
```

**Step 2: Verify syntax**

Run: `php -l htdocs/api/transcription-status.php`

**Step 3: Commit**

```bash
git add htdocs/api/transcription-status.php
git commit -m "feat(api): add transcription progress status endpoint"
```

---

## Task 6: CLI Batch Transcription Script

**Objective:** A resumable, lock-protected CLI script that processes un-transcribed videos in batches.

**Files:**
- Create: `scripts/transcribe-videos.php`

**Step 1: Write the script**

```php
#!/usr/bin/env php
<?php
/**
 * Video Transcription Batch Script
 *
 * Usage:
 *   php scripts/transcribe-videos.php [--limit=N] [--content-path=PATH]
 *
 * Processes videos where transcript_status = 'pending'.
 * Uses a lock file to prevent concurrent runs.
 */

if (php_sapi_name() !== 'cli') {
    echo "CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../htdocs/includes/config.php';
require_once __DIR__ . '/../htdocs/includes/content-indexer.php';

// ── Parse args ───────────────────────────────────────────────────────────────
$limit = 0;
$contentRoot = rtrim(CONTENT_PATH, '/');
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, 8);
    }
    if (str_starts_with($arg, '--content-path=')) {
        $contentRoot = rtrim(substr($arg, 15), '/');
    }
}

// ── Lock file ────────────────────────────────────────────────────────────────
$lockFile = sys_get_temp_dir() . '/edupak_transcription.lock';
if (file_exists($lockFile)) {
    $age = time() - filemtime($lockFile);
    if ($age < 300) {
        echo "Another transcription process is running (lock age: {$age}s).\n";
        exit(1);
    }
    echo "Stale lock found (age: {$age}s). Removing and continuing.\n";
    @unlink($lockFile);
}
touch($lockFile);

// ── Checks ───────────────────────────────────────────────────────────────────
if (!isFfmpegAvailable()) {
    fwrite(STDERR, "ffmpeg not available.\n");
    @unlink($lockFile);
    exit(1);
}
if (!isWhisperAvailable()) {
    fwrite(STDERR, "whisper-cli not available.\n");
    @unlink($lockFile);
    exit(1);
}
$modelPath = getWhisperModelPath();
if (!file_exists($modelPath)) {
    fwrite(STDERR, "Model not found: {$modelPath}\nRun download from admin panel first.\n");
    @unlink($lockFile);
    exit(1);
}

// ── Database ─────────────────────────────────────────────────────────────────
try {
    $pdo = getDbConnection();
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    @unlink($lockFile);
    exit(1);
}

// Reset stale processing rows (>1 hour)
$pdo->exec("UPDATE content_meta SET transcript_status = 'pending' WHERE transcript_status = 'processing' AND (transcript_updated_at IS NULL OR transcript_updated_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))");

// ── Query pending videos ─────────────────────────────────────────────────────
$sql = "SELECT content_id, file_path FROM content_meta WHERE content_type = 'video' AND transcript_status = 'pending' ORDER BY content_id ASC";
if ($limit > 0) {
    $sql .= " LIMIT {$limit}";
}
$stmt = $pdo->query($sql);
$videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($videos)) {
    echo "No pending videos.\n";
    @unlink($lockFile);
    exit(0);
}

echo "Processing " . count($videos) . " video(s)...\n\n";

$updateStmt = $pdo->prepare("
    UPDATE content_meta
    SET transcript_snippet = :snippet,
        transcript_status = :status,
        transcript_updated_at = NOW()
    WHERE content_id = :content_id
");

$done = 0;
$failed = 0;

foreach ($videos as $video) {
    $contentId = $video['content_id'];
    $filePath = $contentRoot . '/' . ltrim($video['file_path'], '/');

    if (!file_exists($filePath)) {
        echo "[SKIP] File not found: {$contentId}\n";
        $updateStmt->execute([':snippet' => null, ':status' => 'failed', ':content_id' => $contentId]);
        $failed++;
        continue;
    }

    // Mark as processing
    $updateStmt->execute([':snippet' => null, ':status' => 'processing', ':content_id' => $contentId]);

    echo "[PROCESS] {$contentId}\n";
    $snippet = transcribeVideoSnippet($filePath, $modelPath, 90);

    if ($snippet !== null && $snippet !== '') {
        $updateStmt->execute([':snippet' => $snippet, ':status' => 'done', ':content_id' => $contentId]);
        $preview = mb_substr($snippet, 0, 80);
        echo "  → {$preview}...\n";
        $done++;
    } else {
        $updateStmt->execute([':snippet' => null, ':status' => 'failed', ':content_id' => $contentId]);
        echo "  → FAILED\n";
        $failed++;
    }

    touch($lockFile); // keep lock alive
}

@unlink($lockFile);

echo "\nBatch complete. Done: {$done}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
```

**Step 2: Make executable**

Run: `chmod +x scripts/transcribe-videos.php`

**Step 3: Verify syntax**

Run: `php -l scripts/transcribe-videos.php`

**Step 4: Commit**

```bash
git add scripts/transcribe-videos.php
git commit -m "feat(scripts): add resumable video transcription batch worker"
```

---

## Task 7: Admin Transcription Page

**Objective:** A web UI visible only when the Beelink is online, used to download the model, start transcription, and monitor progress.

**Files:**
- Create: `htdocs/admin/transcription.php`

**Step 1: Write the page**

```php
<?php
/**
 * Admin Transcription Panel
 *
 * Visible only when the Beelink has internet connectivity.
 * Allows downloading the whisper model and starting the batch worker.
 */

require_once __DIR__ . '/../includes/auth.php';

// Require teacher/admin login
if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? 'guest') !== 'teacher') {
    header('Location: /teacher-login.php');
    exit;
}

// ── Internet connectivity check ──────────────────────────────────────────────
$hasInternet = false;
$checkHost = 'huggingface.co';
$connected = @fsockopen($checkHost, 443, $errno, $errstr, 3);
if ($connected) {
    fclose($connected);
    $hasInternet = true;
}

$modelPath = '/var/lib/whisper/models/ggml-base.bin';
$hasModel = file_exists($modelPath) && filesize($modelPath) > 100000000;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Video Transcription — EduPak Admin</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 700px; margin: 40px auto; padding: 0 20px; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .ok { background: #d4edda; color: #155724; }
        .warn { background: #fff3cd; color: #856404; }
        .bad { background: #f8d7da; color: #721c24; }
        button { padding: 10px 20px; font-size: 14px; cursor: pointer; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        #progress { font-size: 14px; margin-top: 10px; }
        pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Video Transcription</h1>

    <?php if (!$hasInternet): ?>
        <div class="card">
            <span class="badge bad">Offline</span>
            <p><strong>Transcription is unavailable.</strong></p>
            <p>This feature requires an internet connection to download the AI model. Once the model is downloaded and all videos are transcribed, this panel will be hidden after deployment.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <span class="badge ok">Online</span>
            <h2>1. Model Setup</h2>
            <?php if ($hasModel): ?>
                <p>Model is ready: <code><?php echo htmlspecialchars($modelPath); ?></code></p>
            <?php else: ?>
                <p>The whisper model (~150 MB) must be downloaded before transcription can begin.</p>
                <button id="btn-download" onclick="downloadModel()">Download Model</button>
                <div id="download-status"></div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>2. Transcription</h2>
            <?php if (!$hasModel): ?>
                <p class="warn">Download the model first.</p>
            <?php else: ?>
                <button id="btn-start" onclick="startTranscription()">Start Transcription</button>
                <div id="progress"></div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Progress</h2>
            <div id="stats">Loading...</div>
        </div>
    <?php endif; ?>

    <script>
        async function downloadModel() {
            const btn = document.getElementById('btn-download');
            const status = document.getElementById('download-status');
            btn.disabled = true;
            status.textContent = 'Downloading... this may take a few minutes.';
            try {
                const res = await fetch('/api/download-whisper-model.php', { method: 'POST' });
                const data = await res.json();
                if (data.success) {
                    status.textContent = 'Download complete. Refreshing...';
                    location.reload();
                } else {
                    status.textContent = 'Error: ' + (data.error || 'Unknown');
                    btn.disabled = false;
                }
            } catch (e) {
                status.textContent = 'Network error: ' + e.message;
                btn.disabled = false;
            }
        }

        async function startTranscription() {
            const btn = document.getElementById('btn-start');
            btn.disabled = true;
            document.getElementById('progress').textContent = 'Worker started in background. Polling progress...';
            // Trigger background process via a lightweight ping endpoint (see note below)
            // Since direct background exec from JS is not possible, the admin triggers
            // it via a separate API or accepts that the admin must run the CLI manually.
            // Alternative: implement a start API (Task 8).
        }

        async function loadStats() {
            try {
                const res = await fetch('/api/transcription-status.php');
                const data = await res.json();
                if (data.error) {
                    document.getElementById('stats').textContent = 'Error: ' + data.error;
                    return;
                }
                document.getElementById('stats').innerHTML = `
                    <p>Total videos: <strong>${data.total}</strong></p>
                    <p>Done: <strong>${data.done}</strong> (${data.percent}%)</p>
                    <p>Pending: <strong>${data.pending}</strong></p>
                    <p>Processing: <strong>${data.processing}</strong></p>
                    <p>Failed: <strong>${data.failed}</strong></p>
                    <p>Running: <strong>${data.is_running ? 'Yes' : 'No'}</strong></p>
                `;
            } catch (e) {
                document.getElementById('stats').textContent = 'Failed to load stats.';
            }
        }

        loadStats();
        setInterval(loadStats, 5000);
    </script>
</body>
</html>
```

**Note on starting the worker from the web UI:** PHP `exec("nohup ... &")` from Apache works in the Docker container. We will add a dedicated start API in Task 8 so the JS button can actually trigger it.

**Step 2: Verify syntax**

Run: `php -l htdocs/admin/transcription.php`

**Step 3: Commit**

```bash
git add htdocs/admin/transcription.php
git commit -m "feat(admin): add transcription control panel (online-only)"
```

---

## Task 8: Start Transcription Worker API

**Objective:** An authenticated API endpoint that spawns the background CLI script.

**Files:**
- Create: `htdocs/api/start-transcription.php`

**Step 1: Write the endpoint**

```php
<?php
/**
 * Start Transcription Worker API
 *
 * POST — spawns scripts/transcribe-videos.php in the background.
 */

include_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? 'guest') !== 'teacher') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$lockFile = sys_get_temp_dir() . '/edupak_transcription.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
    echo json_encode(['success' => true, 'message' => 'Already running']);
    exit;
}

$scriptPath = realpath(__DIR__ . '/../../scripts/transcribe-videos.php');
$logPath = dirname(__DIR__, 2) . '/logs/transcription.log';

if (!file_exists($scriptPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Transcription script not found']);
    exit;
}

if (!is_dir(dirname($logPath))) {
    mkdir(dirname($logPath), 0755, true);
}

$cmd = sprintf(
    'nohup php %s >> %s 2>&1 &',
    escapeshellarg($scriptPath),
    escapeshellarg($logPath)
);
exec($cmd);

echo json_encode(['success' => true, 'message' => 'Transcription worker started']);
```

**Step 2: Update admin page JS**

In `htdocs/admin/transcription.php`, replace the `startTranscription()` placeholder with:

```javascript
async function startTranscription() {
    const btn = document.getElementById('btn-start');
    btn.disabled = true;
    document.getElementById('progress').textContent = 'Starting worker...';
    try {
        const res = await fetch('/api/start-transcription.php', { method: 'POST' });
        const data = await res.json();
        document.getElementById('progress').textContent = data.message || 'Started';
    } catch (e) {
        document.getElementById('progress').textContent = 'Failed to start: ' + e.message;
        btn.disabled = false;
    }
}
```

**Step 3: Verify syntax**

Run: `php -l htdocs/api/start-transcription.php`

**Step 4: Commit**

```bash
git add htdocs/api/start-transcription.php htdocs/admin/transcription.php
git commit -m "feat(api): add background transcription worker trigger"
```

---

## Task 9: Search API Integration

**Objective:** Include `transcript_snippet` in the existing search waterfall.

**Files:**
- Modify: `htdocs/api/search.php`

**Step 1: Update FULLTEXT match (line 86-89)**

Change:
```php
MATCH(cm.title, cm.category, cm.subcategory, cm.source)
```
to:
```php
MATCH(cm.title, cm.description, cm.category, cm.subcategory, cm.source, cm.transcript_snippet)
```

Do this in all three places inside the `$sql` block (score, match, title_relevance).

**Step 2: Update LIKE fallback (line 149-153)**

Add after `OR cm.source LIKE :like_source`:
```php
OR cm.transcript_snippet LIKE :like_transcript
```

Add to `$likeParams`:
```php
':like_transcript' => $likeParam,
```

**Step 3: Update alias search conditions (line 315)**

In `searchByAliases`, change the condition builder from:
```php
$conditions[] = "(cm.title LIKE {$paramName} OR cm.category LIKE {$paramName} OR cm.subcategory LIKE {$paramName} OR cm.source LIKE {$paramName})";
```
to:
```php
$conditions[] = "(cm.title LIKE {$paramName} OR cm.category LIKE {$paramName} OR cm.subcategory LIKE {$paramName} OR cm.source LIKE {$paramName} OR cm.transcript_snippet LIKE {$paramName})";
```

**Step 4: Update Levenshtein candidate fetch (line 382-386)**

Add after `OR cm.subcategory LIKE :lev_subcat`:
```php
OR cm.transcript_snippet LIKE :lev_transcript
```

Add to `$params`:
```php
':lev_transcript' => $likeVal,
```

**Step 5: Verify syntax**

Run: `php -l htdocs/api/search.php`

**Step 6: Commit**

```bash
git add htdocs/api/search.php
git commit -m "feat(search): include transcript_snippet in FULLTEXT and fallback searches"
```

---

## Task 10: Testing & Verification

**Objective:** Prove the pipeline works end-to-end on one video before running the full batch.

**Files:** None (manual verification)

**Step 1: Rebuild Docker image**

Run: `docker compose build app && docker compose up -d`

**Step 2: Run migration**

Run: `docker compose exec app php scripts/migrate.php up`

**Step 3: Test model download**

1. Log in as teacher in the browser
2. Visit `/admin/transcription.php`
3. Click "Download Model"
4. Verify `ggml-base.bin` appears in `/var/lib/whisper/models/` inside the container

**Step 4: Test single transcription**

Pick one video file path from the DB and run:
```bash
docker compose exec app php -r "
require 'htdocs/includes/config.php';
require 'htdocs/includes/content-indexer.php';
\$model = getWhisperModelPath();
\$video = '/content/videos/Science/Physics/intro.mp4'; // adjust to real path
echo transcribeVideoSnippet(\$video, \$model);
"
```

Expected: Plain text transcript printed to stdout.

**Step 5: Test batch script**

Run: `docker compose exec app php scripts/transcribe-videos.php --limit=5`
Expected: Processes 5 videos, updates DB, shows preview snippets.

**Step 6: Test search**

Search for a word that appears in a transcript but not in the title:
```bash
curl "http://localhost:8080/api/search.php?q=gravity"
```
Expected: Video appears in results with `match_type: 'exact'`.

**Step 7: Test admin progress UI**

Visit `/admin/transcription.php` and confirm stats update after the batch run.

**Step 8: Commit any fixes**

---

## Rollout Notes for the Beelink

1. **Before deployment** (while Beelink has internet):
   - `docker compose build app`
   - `docker compose up -d`
   - Run migration
   - Visit `/admin/transcription.php` → Download Model → Start Transcription
   - Let it run overnight (or repeatedly until `pending = 0`)

2. **After deployment** (offline field use):
   - The admin page will show the "Offline" badge and disable controls
   - Search continues to work using the already-populated `transcript_snippet` column
   - No whisper model or binary is required in the field for day-to-day operation

3. **Performance estimate** (Beelink-class CPU, ~5-10s per 30s audio with base model):
   - 1,000 videos ≈ 2-3 hours
   - 5,000 videos ≈ 10-15 hours
   - Run in batches and monitor via the admin panel
