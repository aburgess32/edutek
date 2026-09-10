<?php
// Directory is the destination for the home-page "Watch Videos" card.
// It intentionally displays only video resources. Books, audio, music,
// and learning tools have their own dedicated home-page destinations.

if (!ob_get_level()) {
    ob_start();
}
?>
<link href="css/tiles.css" rel="stylesheet">

<?php
include_once "navbar.php";
include_once "includes/tiles.php";

$allContent = getAllContent();

/*
 * Keep only video items. The shared tile configuration is also used by
 * other pages, so filter locally instead of changing includes/tiles.php.
 */
$videoContent = array_values(array_filter($allContent, function ($item) {
    return strtolower((string) ($item['type'] ?? '')) === 'video';
}));

/*
 * Sort by the same label that the visitor sees. strcasecmp provides
 * case-insensitive alphabetical ordering.
 */
usort($videoContent, function ($a, $b) {
    return strcasecmp(
        (string) ($a['label'] ?? ''),
        (string) ($b['label'] ?? '')
    );
});
?>

<div class="tiles-page directory-video-page">

    <!-- Local Directory filter: filters only the video categories below. -->
    <div class="dir-search">
        <input type="text"
               id="dir-search"
               class="dir-search-input"
               placeholder="Search video categories..."
               autocomplete="off">
    </div>

    <div id="dir-no-results" class="dir-no-results">No matching video category found.</div>

    <div class="dir-section directory-video-section" id="dir-watch-videos">
        <h3 class="dir-section-title">🎬 Watch Videos</h3>

        <?php if (!empty($videoContent)): ?>
            <ul class="dir-list directory-video-list">
                <?php foreach ($videoContent as $item):
                    $badge = getContentTypeBadge('video');
                    $itemIcon = $item['icon'] ?? '🎬';
                ?>
                    <li class="dir-item"
                        data-label="<?php echo htmlspecialchars($item['label'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <a href="<?php echo htmlspecialchars($item['href'] ?? '#', ENT_QUOTES, 'UTF-8'); ?>"
                           class="dir-item-link">
                            <span class="dir-item-icon"><?php echo $itemIcon; ?></span>
                            <span class="dir-item-label">
                                <?php echo htmlspecialchars($item['label'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="type-badge"
                                  style="background:<?php echo htmlspecialchars($badge['color'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="directory-video-empty">
                No video categories are currently available.
            </p>
        <?php endif; ?>
    </div>

</div>

<script src="js/tiles.js"></script>

<?php
require_once 'includes/breadcrumb.php';

$crumbs = [
    ['label' => 'Home', 'color' => null, 'href' => 'index.php'],
    ['label' => 'Watch Videos', 'color' => null, 'href' => null],
];

renderBreadcrumb($crumbs);

include_once "footer.php";
?>