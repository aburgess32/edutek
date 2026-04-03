<?php if (!ob_get_level()) { ob_start(); } ?>
  <link href="css/tiles.css" rel="stylesheet">
<?php
    include_once "navbar.php";
    include_once "includes/tiles.php";

    $hostname = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost', ENT_QUOTES, 'UTF-8');
    $segments = getSegments();
    $allContent = getAllContent();

    // Build content-per-segment map for directory sections
    $segmentContent = [];
    foreach ($segments as $segKey => $seg) {
        $topics = $seg['topics'] ?? [];
        $segmentContent[$segKey] = [
            'label' => $seg['label'] ?? $segKey,
            'icon' => $seg['icon'] ?? '',
            'color' => $seg['color'] ?? '#333',
            'items' => [],
        ];
        foreach ($topics as $topic) {
            $segmentContent[$segKey]['items'][] = [
                'label' => $topic['label'] ?? '',
                'icon' => $topic['icon'] ?? '',
                'type' => $topic['type'] ?? 'video',
                'href' => buildTopicHref($topic, $_SERVER['HTTP_HOST'] ?? 'localhost', $segKey),
            ];
        }
    }
?>

<div class="tiles-page">

    <!-- Anchor Nav Bar -->
    <div class="dir-anchor-nav">
        <?php foreach ($segments as $segKey => $seg): ?>
        <a href="#dir-<?php echo htmlspecialchars($segKey, ENT_QUOTES, 'UTF-8'); ?>"
           class="dir-anchor-link">
            <?php echo htmlspecialchars($seg['label'] ?? $segKey, ENT_QUOTES, 'UTF-8'); ?>
        </a>
        <?php endforeach; ?>
        <a href="#dir-all" class="dir-anchor-link">All</a>
    </div>

    <!-- Search -->
    <div class="dir-search">
        <input type="text"
               id="dir-search"
               class="dir-search-input"
               placeholder="Search content..."
               autocomplete="off">
    </div>

    <div id="dir-no-results" class="dir-no-results">No matching content found.</div>

    <!-- Segment Sections -->
    <?php foreach ($segmentContent as $segKey => $segData): ?>
    <?php if (!empty($segData['items'])): ?>
    <div class="dir-section" id="dir-<?php echo htmlspecialchars($segKey, ENT_QUOTES, 'UTF-8'); ?>">
        <h3 class="dir-section-title">
            <?php echo $segData['icon']; ?>
            <?php echo htmlspecialchars($segData['label'], ENT_QUOTES, 'UTF-8'); ?>
        </h3>
        <ul class="dir-list">
            <?php foreach ($segData['items'] as $item):
                $badge = getContentTypeBadge($item['type']);
            ?>
            <li class="dir-item" data-label="<?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>">
                <a href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>" class="dir-item-link">
                    <span class="dir-item-icon"><?php echo $item['icon']; ?></span>
                    <span class="dir-item-label"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="type-badge" style="background:<?php echo htmlspecialchars($badge['color'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>

    <!-- All Content (alphabetical) -->
    <div class="dir-section" id="dir-all">
        <h3 class="dir-section-title">All Content</h3>
        <ul class="dir-list">
            <?php foreach ($allContent as $item):
                $badge = getContentTypeBadge($item['type'] ?? 'video');
                $itemIcon = $item['icon'] ?? '';
                if ($itemIcon === '') {
                    switch ($item['type'] ?? 'video') {
                        case 'audio': $itemIcon = "\xF0\x9F\x8E\xA7"; break;
                        case 'book': $itemIcon = "\xF0\x9F\x93\x9A"; break;
                        case 'service': $itemIcon = "\xE2\x9A\xA1"; break;
                        default: $itemIcon = "\xF0\x9F\x8E\xAC"; break;
                    }
                }
            ?>
            <li class="dir-item" data-label="<?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>">
                <a href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>" class="dir-item-link">
                    <span class="dir-item-icon"><?php echo $itemIcon; ?></span>
                    <span class="dir-item-label"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="type-badge" style="background:<?php echo htmlspecialchars($badge['color'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

</div>

<script src="js/tiles.js"></script>

<?php
    // FRE-12: Breadcrumb — single "Directory" crumb
    require_once 'includes/breadcrumb.php';
    $crumbs = [
        ['label' => 'Home', 'color' => null, 'href' => 'index.php'],
        ['label' => 'Directory', 'color' => null, 'href' => null],
    ];
    renderBreadcrumb($crumbs);

    include_once "footer.php";
?>
