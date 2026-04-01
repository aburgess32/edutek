<?php
    include_once "includes/auth.php";
    include_once "includes/tiles.php";
?>
  <link href="css/index.css" rel="stylesheet">
  <link href="css/tiles.css" rel="stylesheet">
  <link href="css/login.css" rel="stylesheet">
<?php
    include_once "navhome.php";

    $hostname = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost', ENT_QUOTES, 'UTF-8');
    $segments = getSegments();
    $allContent = getAllContent();

    // Light-colored segments need dark text
    $lightSegments = ['educators'];
?>

<div class="tiles-page">

    <?php if (isGuest()): ?>
    <div class="guest-banner">
        Browsing as guest &mdash; <a href="login.php">Get Started</a> to save your progress
    </div>
    <?php endif; ?>

    <?php
    // FRE-10: Continue Watching row (server-side rendered)
    if (isLoggedIn() && !isGuest()):
        try {
            $cwPdo = getDbConnection();
            $cwStmt = $cwPdo->prepare("
                SELECT content_id, content_title, thumbnail_path,
                       progress_seconds, duration_seconds
                FROM watch_history
                WHERE user_id = :uid AND duration_seconds > 0
                ORDER BY last_watched DESC
                LIMIT 10
            ");
            $cwStmt->execute([':uid' => (int) $_SESSION['user_id']]);
            $cwRows = $cwStmt->fetchAll(PDO::FETCH_ASSOC);

            $cwItems = [];
            foreach ($cwRows as $cwRow) {
                $cwPct = round(($cwRow['progress_seconds'] / $cwRow['duration_seconds']) * 100);
                if ($cwPct >= 95) continue;
                $cwRow['progress_pct'] = (int) $cwPct;

                // Check thumbnail exists
                $cwThumbFile = __DIR__ . '/' . ltrim($cwRow['thumbnail_path'], '/');
                $cwRow['thumb_ok'] = file_exists($cwThumbFile);

                $cwItems[] = $cwRow;
                if (count($cwItems) >= 5) break;
            }
        } catch (PDOException $e) {
            $cwItems = [];
        }

        if (!empty($cwItems)):
            // Pre-compute encrypted param keys for watch.php links
            $cwKeyVideolink  = tileEncrypt('videolink');
            $cwKeyVideoname  = tileEncrypt('videoname');
            $cwKeyVideolink1 = tileEncrypt('videolink1');
            $cwKeyVideoname1 = tileEncrypt('videoname1');
    ?>
    <section class="continue-row" aria-label="Continue Watching">
        <h2 class="continue-row__heading">Continue Watching</h2>
        <div class="continue-row__scroll" role="list">
            <?php foreach ($cwItems as $cwItem):
                // Reconstruct watch.php URL params from content_id
                $cwContentId  = $cwItem['content_id'];
                $cwFolderPath = dirname($cwContentId) . '/';
                $cwFolderName = basename(dirname($cwContentId));
                $cwFileName   = basename($cwContentId);

                $cwEncLink  = tileEncrypt($cwFolderPath);
                $cwEncName  = tileEncrypt($cwFolderName);
                $cwEncLink1 = tileEncrypt($cwContentId);
                $cwEncName1 = tileEncrypt($cwFileName);

                $cwHref = 'watch.php?&' . $cwKeyVideolink . '=' . $cwEncLink
                        . '&' . $cwKeyVideoname . '=' . $cwEncName
                        . '&' . $cwKeyVideolink1 . '=' . $cwEncLink1
                        . '&' . $cwKeyVideoname1 . '=' . $cwEncName1;

                $cwTitle = htmlspecialchars($cwItem['content_title'] ?: $cwFileName, ENT_QUOTES, 'UTF-8');
                $cwPct   = $cwItem['progress_pct'];
            ?>
            <a class="continue-card" href="<?php echo $cwHref; ?>" role="listitem"
               aria-label="Continue: <?php echo $cwTitle; ?> &ndash; <?php echo $cwPct; ?>% watched">
                <div class="continue-card__thumb">
                    <?php if ($cwItem['thumb_ok']): ?>
                    <img src="<?php echo htmlspecialchars($cwItem['thumbnail_path'], ENT_QUOTES, 'UTF-8'); ?>"
                         alt="<?php echo $cwTitle; ?> thumbnail"
                         loading="lazy" decoding="async">
                    <?php else: ?>
                    <div class="continue-card__placeholder">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="11" stroke="#fff" stroke-width="1.5" opacity="0.6"/>
                            <polygon points="10,7 18,12 10,17" fill="#fff" opacity="0.8"/>
                        </svg>
                    </div>
                    <?php endif; ?>
                    <div class="continue-card__progress" style="--pct: <?php echo $cwPct; ?>%"></div>
                </div>
                <p class="continue-card__title"><?php echo $cwTitle; ?></p>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
        endif; // !empty($cwItems)
    endif; // isLoggedIn && !isGuest
    ?>

    <!-- Choose Your Path: Segment Tiles -->
    <div class="tiles-section-header">
        <h2 class="tiles-section-title">Choose Your Path</h2>
        <a href="directory.php" class="tiles-section-link">View Directory</a>
    </div>

    <div class="seg-grid">
        <?php foreach ($segments as $segKey => $seg):
            $isLight = in_array($segKey, $lightSegments, true);
            $gradient = htmlspecialchars($seg['gradient'] ?? '', ENT_QUOTES, 'UTF-8');
            $color = htmlspecialchars($seg['color'] ?? '#333', ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars($seg['label'] ?? $segKey, ENT_QUOTES, 'UTF-8');
            $desc = htmlspecialchars($seg['description'] ?? '', ENT_QUOTES, 'UTF-8');
            $icon = $seg['icon'] ?? '';
        ?>
        <?php
            $segImage = $seg['image'] ?? '';
            $segImageJpg = str_replace('.webp', '.jpg', $segImage);
            $hasImage = ($segImage !== '' && file_exists(__DIR__ . '/' . $segImage));
        ?>
        <a href="browse.php?seg=<?php echo htmlspecialchars($segKey, ENT_QUOTES, 'UTF-8'); ?>"
           class="seg-tile<?php echo $isLight ? ' seg-tile--light' : ''; ?><?php echo $hasImage ? ' seg-tile--has-image' : ''; ?>"
           style="background: <?php echo $gradient ?: $color; ?>;"
           title="<?php echo $label; ?>">
            <?php if ($hasImage): ?>
            <div class="seg-tile-bg">
                <picture>
                    <source srcset="<?php echo htmlspecialchars($segImage, ENT_QUOTES, 'UTF-8'); ?>" type="image/webp">
                    <img src="<?php echo htmlspecialchars($segImageJpg, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy" decoding="async">
                </picture>
            </div>
            <?php else: ?>
            <div class="seg-tile-fallback">
                <img src="assets/img/edutek-logo.jpg" alt="">
            </div>
            <?php endif; ?>
            <div class="seg-tile-inner">
                <p class="seg-tile-label"><?php echo $label; ?></p>
                <p class="seg-tile-desc"><?php echo $desc; ?></p>

            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- All Content -->
    <div class="tiles-section-header">
        <h2 class="tiles-section-title">All Content</h2>
        <a href="directory.php" class="tiles-section-link">Full Directory</a>
    </div>

    <div class="all-content-grid">
        <?php foreach ($allContent as $item):
            $badge = getContentTypeBadge($item['type'] ?? 'video');
            $itemLabel = htmlspecialchars($item['label'] ?? '', ENT_QUOTES, 'UTF-8');
            $itemHref = htmlspecialchars($item['href'] ?? '#', ENT_QUOTES, 'UTF-8');
            $itemIcon = $item['icon'] ?? '';
            if ($itemIcon === '') {
                // Assign default icon based on type
                switch ($item['type'] ?? 'video') {
                    case 'audio': $itemIcon = "\xF0\x9F\x8E\xA7"; break;
                    case 'book': $itemIcon = "\xF0\x9F\x93\x9A"; break;
                    case 'service': $itemIcon = "\xE2\x9A\xA1"; break;
                    default: $itemIcon = "\xF0\x9F\x8E\xAC"; break;
                }
            }
        ?>
        <a href="<?php echo $itemHref; ?>" class="all-content-card" title="<?php echo $itemLabel; ?>">
            <span class="all-content-card-icon"><?php echo $itemIcon; ?></span>
            <span class="all-content-card-label"><?php echo $itemLabel; ?></span>
            <span class="type-badge" style="background:<?php echo htmlspecialchars($badge['color'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </a>
        <?php endforeach; ?>

        <?php if (empty($allContent)): ?>
        <div class="tiles-empty">
            <img src="assets/img/edutek-logo.jpg" alt="" class="tiles-empty-logo">
            <p class="tiles-empty-text">No content available yet.</p>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php
    // FRE-14: Render breadcrumb shell (toggle + mode icon) on homepage
    // No crumbs — just the controls so mode toggle is always accessible
    require_once 'includes/breadcrumb.php';
    renderBreadcrumb([]);

    include_once "footer.php";
?>
