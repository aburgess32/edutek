<?php

/**
 * Student-Facing Playlist Page
 *
 * Displays a published lesson plan as a playlist of content items.
 * No teacher login required — this is a public/student page.
 *
 * Usage: playlist.php?plan=<id>
 *
 * @package EduPak
 */

include_once "includes/auth.php";
include_once "includes/tiles.php";

$planId = (int) ($_GET['plan'] ?? 0);

// ---------- Load plan from DB ----------

$plan = null;
$items = [];

if ($planId > 0) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT lp.id, lp.title, lp.icon, lp.color, lp.description,
                   lp.content_ids, lp.published_segments, lp.teacher_id,
                   u.display_name AS teacher_name
            FROM lesson_plans lp
            LEFT JOIN users u ON u.id = lp.teacher_id
            WHERE lp.id = ?
              AND lp.published_segments IS NOT NULL
              AND JSON_LENGTH(lp.published_segments) > 0
        ");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $plan = null;
    }
}

// ---------- 404 if not found or not published ----------

if (!$plan) {
    http_response_code(404);
?>
  <link href="css/tiles.css" rel="stylesheet">
  <link href="css/playlist.css" rel="stylesheet">
<?php include_once "navbar.php"; ?>
<div class="tiles-page">
    <div class="playlist-404">
        <div class="playlist-404__icon">📭</div>
        <h1 class="playlist-404__title">Playlist Not Found</h1>
        <p class="playlist-404__text">This playlist may have been removed or is no longer published.</p>
        <a href="index.php" class="playlist-404__btn">Back to Home</a>
    </div>
</div>
<?php
    require_once 'includes/breadcrumb.php';
    renderBreadcrumb([]);
    include_once "footer.php";
    exit;
}

// ---------- Parse content items ----------

$contentIds = json_decode($plan['content_ids'], true) ?: [];

// Load content metadata for thumbnails/duration if available
$contentMeta = [];
if (!empty($contentIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($contentIds), '?'));
        $metaStmt = $pdo->prepare("
            SELECT content_id, content_title, thumbnail_path, duration_seconds
            FROM content_meta
            WHERE content_id IN ({$placeholders})
        ");
        $metaStmt->execute($contentIds);
        foreach ($metaStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $contentMeta[$row['content_id']] = $row;
        }
    } catch (PDOException $e) {
        // content_meta may not exist yet — graceful degradation
        $contentMeta = [];
    }
}

// Build encrypted param keys for watch.php links
$encKeyVideolink  = tileEncrypt('videolink');
$encKeyVideoname  = tileEncrypt('videoname');
$encKeyVideolink1 = tileEncrypt('videolink1');
$encKeyVideoname1 = tileEncrypt('videoname1');

$planTitle = htmlspecialchars($plan['title'], ENT_QUOTES, 'UTF-8');
$planDesc  = htmlspecialchars($plan['description'] ?? '', ENT_QUOTES, 'UTF-8');
$planIcon  = $plan['icon'] ?? '📚';
$planColor = htmlspecialchars($plan['color'] ?? '#4ECDC4', ENT_QUOTES, 'UTF-8');
$teacherName = htmlspecialchars($plan['teacher_name'] ?? 'Teacher', ENT_QUOTES, 'UTF-8');
?>
  <link href="css/tiles.css" rel="stylesheet">
  <link href="css/playlist.css" rel="stylesheet">
<?php include_once "navbar.php"; ?>

<div class="tiles-page">

    <!-- Playlist Header -->
    <div class="playlist-header" style="background: <?php echo $planColor; ?>;">
        <a href="index.php" class="playlist-header__back" title="Back to Home">
            <i class="fa fa-arrow-left"></i>
        </a>
        <div class="playlist-header__icon"><?php echo $planIcon; ?></div>
        <div class="playlist-header__info">
            <h1 class="playlist-header__title"><?php echo $planTitle; ?></h1>
            <?php if ($planDesc !== ''): ?>
            <p class="playlist-header__desc"><?php echo $planDesc; ?></p>
            <?php endif; ?>
            <p class="playlist-header__meta">
                <?php echo count($contentIds); ?> item<?php echo count($contentIds) !== 1 ? 's' : ''; ?>
                &middot; Created by <?php echo $teacherName; ?>
            </p>
        </div>
    </div>

    <!-- Content Items -->
    <?php if (empty($contentIds)): ?>
    <div class="tiles-empty">
        <p class="tiles-empty-text">This playlist has no content items.</p>
    </div>
    <?php else: ?>
    <div class="playlist-items">
        <?php foreach ($contentIds as $index => $contentId):
            $meta = $contentMeta[$contentId] ?? null;
            $available = ($meta !== null);

            // Parse path: videos/{Category}/{Subcategory}/{video.mp4}
            $parts = explode('/', $contentId);
            $folderPath = dirname($contentId) . '/';
            $folderName = basename(dirname($contentId));
            $fileName   = basename($contentId);
            $videoName  = pathinfo($fileName, PATHINFO_FILENAME);

            // Use content_meta title if available, else derive from filename
            $itemTitle = $available
                ? htmlspecialchars($meta['content_title'] ?? $videoName, ENT_QUOTES, 'UTF-8')
                : htmlspecialchars($videoName ?: $contentId, ENT_QUOTES, 'UTF-8');

            // Build watch.php link
            $watchHref = '#';
            if ($available) {
                $encLink  = tileEncrypt($folderPath);
                $encName  = tileEncrypt($folderName);
                $encLink1 = tileEncrypt($contentId);
                $encName1 = tileEncrypt($fileName);

                $watchHref = 'watch.php?&' . $encKeyVideolink . '=' . $encLink
                           . '&' . $encKeyVideoname . '=' . $encName
                           . '&' . $encKeyVideolink1 . '=' . $encLink1
                           . '&' . $encKeyVideoname1 . '=' . $encName1;
            }

            // Thumbnail
            $thumbPath = $meta['thumbnail_path'] ?? '';
            $hasThumb  = ($thumbPath !== '' && file_exists(__DIR__ . '/' . ltrim($thumbPath, '/')));

            // Duration
            $duration = (int) ($meta['duration_seconds'] ?? 0);
            $durationStr = '';
            if ($duration > 0) {
                $mins = (int) floor($duration / 60);
                $secs = $duration % 60;
                $durationStr = $mins . ':' . str_pad((string) $secs, 2, '0', STR_PAD_LEFT);
            }
        ?>
        <?php if ($available): ?>
        <a href="<?php echo $watchHref; ?>" class="playlist-item" data-index="<?php echo $index + 1; ?>">
        <?php else: ?>
        <div class="playlist-item playlist-item--unavailable" data-index="<?php echo $index + 1; ?>">
        <?php endif; ?>

            <div class="playlist-item__num"><?php echo $index + 1; ?></div>

            <div class="playlist-item__thumb">
                <?php if ($hasThumb): ?>
                <img src="<?php echo htmlspecialchars($thumbPath, ENT_QUOTES, 'UTF-8'); ?>"
                     alt="<?php echo $itemTitle; ?>" loading="lazy" decoding="async">
                <?php else: ?>
                <div class="playlist-item__placeholder">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="11" stroke="#fff" stroke-width="1.5" opacity="0.6"/>
                        <polygon points="10,7 18,12 10,17" fill="#fff" opacity="0.8"/>
                    </svg>
                </div>
                <?php endif; ?>
                <?php if ($durationStr !== ''): ?>
                <span class="playlist-item__duration"><?php echo $durationStr; ?></span>
                <?php endif; ?>
            </div>

            <div class="playlist-item__info">
                <?php if ($available): ?>
                <p class="playlist-item__title"><?php echo $itemTitle; ?></p>
                <?php else: ?>
                <p class="playlist-item__title playlist-item__title--strike">[Content unavailable]</p>
                <?php endif; ?>
            </div>

        <?php if ($available): ?>
        </a>
        <?php else: ?>
        </div>
        <?php endif; ?>

        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<?php
    require_once 'includes/breadcrumb.php';
    $crumbs = buildBreadcrumb($_GET);
    renderBreadcrumb($crumbs);

    include_once "footer.php";
?>
