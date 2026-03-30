  <link href="css/tiles.css" rel="stylesheet">
<?php
    include_once "navbar.php";
    include_once "includes/tiles.php";

    $hostname = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost', ENT_QUOTES, 'UTF-8');
    $segments = getSegments();
    $segKey = isset($_GET['seg']) ? trim($_GET['seg']) : '';

    // Validate segment key
    if ($segKey === '' || !isset($segments[$segKey])) {
        header('Location: /');
        exit;
    }

    $seg = $segments[$segKey];
    $topics = getSegmentTopics($segKey);
    $label = htmlspecialchars($seg['label'] ?? $segKey, ENT_QUOTES, 'UTF-8');
    $desc = htmlspecialchars($seg['description'] ?? '', ENT_QUOTES, 'UTF-8');
    $icon = $seg['icon'] ?? '';
    $color = htmlspecialchars($seg['color'] ?? '#333', ENT_QUOTES, 'UTF-8');

    // Light-colored segments need dark text on topic tiles
    $lightColors = ['#F7C948', '#F5D76E', '#FFD700'];
?>

<div class="tiles-page">

    <!-- Browse Header -->
    <div class="browse-header">
        <a href="index.php" class="browse-back" title="Back to Home">
            <i class="fa fa-arrow-left"></i>
        </a>
        <span class="browse-seg-icon"><?php echo $icon; ?></span>
        <div>
            <h1 class="browse-seg-label"><?php echo $label; ?></h1>
            <p class="browse-seg-desc"><?php echo $desc; ?></p>
        </div>
    </div>

    <!-- Topic Tiles -->
    <?php if (!empty($topics)): ?>
    <div class="topic-grid">
        <?php foreach ($topics as $topic):
            $topicColor = $topic['color'] ?? $color;
            $topicLabel = htmlspecialchars($topic['label'] ?? '', ENT_QUOTES, 'UTF-8');
            $topicIcon = $topic['icon'] ?? '';
            $topicType = $topic['type'] ?? 'video';
            $topicHref = htmlspecialchars(buildTopicHref($topic, $_SERVER['HTTP_HOST'] ?? 'localhost'), ENT_QUOTES, 'UTF-8');
            $badge = getContentTypeBadge($topicType);

            // Determine if this is a light-colored tile
            $isLight = in_array($topicColor, $lightColors, true);
        ?>
        <a href="<?php echo $topicHref; ?>"
           class="topic-tile<?php echo $isLight ? ' topic-tile--light' : ''; ?>"
           style="background: <?php echo htmlspecialchars($topicColor, ENT_QUOTES, 'UTF-8'); ?>;"
           title="<?php echo $topicLabel; ?>">
            <div class="topic-tile-inner">
                <span class="topic-tile-icon"><?php echo $topicIcon; ?></span>
                <p class="topic-tile-label"><?php echo $topicLabel; ?></p>
                <span class="type-badge" style="background:<?php echo htmlspecialchars($badge['color'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <?php else: ?>
    <!-- Empty State -->
    <div class="tiles-empty">
        <img src="assets/img/edutek-logo.jpg" alt="" class="tiles-empty-logo">
        <?php if ($segKey === 'knowledge_power'): ?>
        <p class="tiles-empty-text">Your teacher will add priority content here.</p>
        <?php else: ?>
        <p class="tiles-empty-text">No content in this section yet.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<?php
    include_once "footer.php";
?>
