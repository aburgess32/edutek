<?php
/**
 * EduPak Admin Panel — Tile Segment Management
 *
 * Simple password-gated admin for assigning content to segments.
 */

declare(strict_types=1);

// Paths relative to admin/ subdirectory
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/tiles.php';

// ── Authentication ──────────────────────────────────────────────────────────
// File-based hash — auto-generated on first run with default password 'edupak_admin'
$hashFile = __DIR__ . '/../config/.admin_hash';
if (file_exists($hashFile)) {
    $adminHash = trim(file_get_contents($hashFile));
} else {
    // Generate hash file on first access
    $adminHash = password_hash('edupak_admin', PASSWORD_DEFAULT);
    @file_put_contents($hashFile, $adminHash, LOCK_EX);
}

$authenticated = false;
$loginError = '';

if (isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true) {
    $authenticated = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    if (!csrf_verify($_POST['_csrf_token'] ?? '')) {
        $loginError = 'Invalid request. Please try again.';
    } elseif (password_verify($_POST['admin_password'], $adminHash)) {
        $_SESSION['admin_auth'] = true;
        $authenticated = true;
    } else {
        $loginError = 'Incorrect password.';
    }
}

// ── Handle segment assignment save ──────────────────────────────────────────
$saveMessage = '';
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_assignments') {
    if (!csrf_verify($_POST['_csrf_token'] ?? '')) {
        $saveMessage = 'Invalid CSRF token.';
    } else {
        $assignments = $_POST['assignments'] ?? [];
        $jsonPath = __DIR__ . '/../config/tiles.json';

        if (file_exists($jsonPath)) {
            $config = json_decode(file_get_contents($jsonPath), true);
            if (is_array($config) && isset($config['segments'])) {
                // Process assignments: each is folder_name => segment_key
                foreach ($assignments as $folder => $targetSeg) {
                    $folder = trim($folder);
                    $targetSeg = trim($targetSeg);
                    if ($folder === '' || $targetSeg === '' || $targetSeg === 'none') {
                        continue;
                    }
                    if (!isset($config['segments'][$targetSeg])) {
                        continue;
                    }

                    // Check if already in target segment
                    $alreadyExists = false;
                    foreach ($config['segments'][$targetSeg]['topics'] ?? [] as $t) {
                        if (($t['content_path'] ?? '') === 'videos/' . $folder) {
                            $alreadyExists = true;
                            break;
                        }
                    }

                    if (!$alreadyExists) {
                        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $folder));
                        $config['segments'][$targetSeg]['topics'][] = [
                            'slug' => $slug,
                            'label' => $folder,
                            'icon' => "\xF0\x9F\x8E\xAC",
                            'color' => $config['segments'][$targetSeg]['color'] ?? '#333',
                            'type' => 'video',
                            'content_path' => 'videos/' . $folder,
                            'href' => null,
                        ];
                    }
                }

                // Write updated config
                $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (file_put_contents($jsonPath, $json, LOCK_EX) !== false) {
                    // Clear the file cache
                    $cachePath = sys_get_temp_dir() . '/edupak_tiles_cache.php';
                    if (file_exists($cachePath)) {
                        @unlink($cachePath);
                    }
                    $saveMessage = 'Assignments saved successfully.';
                } else {
                    $saveMessage = 'Error: Could not write tiles.json.';
                }
            }
        } else {
            $saveMessage = 'Error: tiles.json not found.';
        }
    }
}

$segments = getSegments();
$uncategorized = getUncategorizedContent();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Tile Management - Edutek</title>
    <link href="../css/tiles.css" rel="stylesheet">
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--color-bg); }
    </style>
</head>
<body>

<?php if (!$authenticated): ?>
<!-- Login Form -->
<div class="admin-login">
    <h2>Admin Login</h2>
    <?php if ($loginError !== ''): ?>
    <p class="admin-error"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <form method="post" action="">
        <?php echo csrf_field(); ?>
        <input type="password" name="admin_password" placeholder="Enter admin password" required autofocus>
        <button type="submit">Login</button>
    </form>
</div>

<?php else: ?>
<!-- Admin Panel -->
<div class="admin-container">

    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 0;">
        <h1 style="font-size:22px;margin:0;">Tile Management</h1>
        <a href="../index.php" class="admin-btn admin-btn--secondary">Back to Site</a>
    </div>

    <?php if ($saveMessage !== ''): ?>
    <div class="<?php echo strpos($saveMessage, 'Error') === 0 ? 'admin-alert' : 'admin-success'; ?>">
        <?php echo htmlspecialchars($saveMessage, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <!-- Uncategorized Content -->
    <?php if (!empty($uncategorized)): ?>
    <form method="post" action="">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_assignments">

        <div class="admin-section">
            <div class="admin-section-header">
                <span class="admin-section-color" style="background:#E63946;"></span>
                Uncategorized Content (<?php echo count($uncategorized); ?>)
            </div>
            <?php foreach ($uncategorized as $folder): ?>
            <div class="admin-item">
                <span class="admin-item-label"><?php echo htmlspecialchars($folder, ENT_QUOTES, 'UTF-8'); ?></span>
                <select name="assignments[<?php echo htmlspecialchars($folder, ENT_QUOTES, 'UTF-8'); ?>]">
                    <option value="none">-- Assign to --</option>
                    <?php foreach ($segments as $sKey => $sData): ?>
                    <option value="<?php echo htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($sData['label'] ?? $sKey, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="admin-actions">
            <button type="button" id="admin-classify-btn" class="admin-btn admin-btn--secondary" style="margin-right:8px;">
                Auto-Classify New Content
            </button>
            <button type="submit" class="admin-btn">Save Assignments</button>
        </div>
    </form>
    <?php else: ?>
    <div class="admin-success">All content is assigned to segments.</div>
    <?php endif; ?>

    <!-- Current Segments -->
    <?php foreach ($segments as $segKey => $seg): ?>
    <div class="admin-section">
        <div class="admin-section-header">
            <span class="admin-section-color" style="background:<?php echo htmlspecialchars($seg['color'] ?? '#333', ENT_QUOTES, 'UTF-8'); ?>;"></span>
            <?php echo $seg['icon'] ?? ''; ?>
            <?php echo htmlspecialchars($seg['label'] ?? $segKey, ENT_QUOTES, 'UTF-8'); ?>
            (<?php echo count($seg['topics'] ?? []); ?> topics)
        </div>
        <?php if (!empty($seg['topics'])): ?>
            <?php foreach ($seg['topics'] as $topic): ?>
            <div class="admin-item">
                <span><?php echo $topic['icon'] ?? ''; ?></span>
                <span class="admin-item-label"><?php echo htmlspecialchars($topic['label'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                <?php $badge = getContentTypeBadge($topic['type'] ?? 'video'); ?>
                <span class="type-badge" style="background:<?php echo htmlspecialchars($badge['color'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="admin-item" style="color:#999;font-style:italic;">No topics assigned</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

</div>

<?php echo csrf_field(); ?>
<script src="../js/tiles.js"></script>

<?php endif; ?>

</body>
</html>
