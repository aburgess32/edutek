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
