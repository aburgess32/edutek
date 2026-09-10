<?php
ob_start();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tiles.php';

$config = getTileConfig();
$services = $config['services'] ?? [];

/*
 * Hide Kiwix Khmer from the user-facing EduTek Tools page.
 * The configured service remains intact so it can be restored later.
 */
$services = array_filter(
    $services,
    static function (array $service): bool {
        $label = strtolower(trim((string)($service['label'] ?? '')));

        return $label !== 'kiwix khmer';
    }
);

/*
 * Display overrides for existing configured services.
 *
 * These change only visible card text and icons. Their configured URLs,
 * ports, and underlying local applications remain unchanged.
 */
$toolOverridesByLabel = [
    'kiwix khan' => [
        'label' => 'Khan Academy',
        'description' => 'Videos and exercises.',
        'icon' => 'fa-graduation-cap',
    ],
    'kiwix medical' => [
        'label' => 'MDWiki (Medical)',
        'description' => 'Identification guides for common health topics, conditions, and treatments.',
        'icon' => 'fa-medkit',
    ],
    'kiwix wiki' => [
        'label' => 'Wikipedia',
        'description' => 'An offline English Wikipedia catalogue for browsing and learning.',
        'icon' => 'fa-globe',
    ],
    'ai tools' => [
        'label' => 'AI Tools',
        'description' => 'Chat with an AI tool and customize your own AI.',
        'icon' => 'fa-cogs',
    ],
    'ai image generation' => [
        'label' => 'AI Image Generation',
        'description' => 'Type a prompt and create an image.',
        'icon' => 'fa-image',
    ],
    'khan interactive' => [
        'label' => 'Khan Interactive',
        'description' => 'Videos and interactive exercises.',
        'icon' => 'fa-superscript',
    ],
];

/*
 * Apply the display overrides while preserving the original service data.
 */
foreach ($services as $key => $service) {
    $originalLabel = strtolower(trim((string)($service['label'] ?? $key)));

    if (!isset($toolOverridesByLabel[$originalLabel])) {
        continue;
    }

    foreach ($toolOverridesByLabel[$originalLabel] as $property => $value) {
        $services[$key][$property] = $value;
    }
}

/*
 * Wiki for Schools is separate from Kiwix Wiki/Wikipedia.
 * It is served locally by the Docker app container from:
 * D:/xampp/htdocs/Edutek/Wiki
 *
 * Local URL:
 * http://localhost:8080/wiki-for-schools/index.html
 */
$services['wiki_for_schools'] = [
    'label' => 'Wiki for Schools',
    'href' => '/wiki-for-schools/index.html',
    'description' => 'Browse a curated offline encyclopedia by subject.',
    'icon' => 'fa-book',
    'local_same_tab' => true,
];

$hostname = htmlspecialchars(
    $_SERVER['HTTP_HOST'] ?? 'localhost:8080',
    ENT_QUOTES,
    'UTF-8'
);

function toolHref(array $service, string $hostname): string
{
    $label = strtolower(trim((string)($service['label'] ?? '')));
    $href = trim((string)($service['href'] ?? ''));

    /*
     * Khan Interactive already has a working launcher that opens
     * its local service on port 9061 in the preferred named window.
     *
     * This check intentionally accepts the renamed visible label too.
     */
    if ($label === 'khan interactive') {
        return 'launch-khan.php';
    }

    /*
     * Remove the app port, such as :8080, before creating a URL
     * for a different local service port.
     */
    $hostOnly = preg_replace('/:\d+$/', '', $hostname);

    if ($hostOnly === '' || $hostOnly === null) {
        $hostOnly = 'localhost';
    }

    if ($href !== '') {
        if (strpos($href, 'PORT') === 0) {
            $port = preg_replace('/\D+/', '', $href);

            if ($port !== '') {
                return 'http://' . $hostOnly . ':' . $port . '/';
            }
        }

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        return $href;
    }

    $port = trim((string)($service['port'] ?? ''));

    if ($port !== '') {
        return 'http://' . $hostOnly . ':' . $port . '/';
    }

    return '#';
}

function toolIcon(array $service): string
{
    $icon = trim((string)($service['icon'] ?? ''));

    if ($icon !== '') {
        return $icon;
    }

    return 'fa-wrench';
}
?>
<link href="css/index.css" rel="stylesheet">

<?php include_once __DIR__ . '/navhome.php'; ?>

<main class="home-page" id="main-content">
    <section class="home-hero home-hero--compact" aria-labelledby="tools-title">
        <div class="home-hero__content">
            <p class="home-hero__eyebrow">Offline learning</p>
            <h1 class="home-hero__title" id="tools-title">Learning Tools</h1>
            <p class="home-hero__subtitle">Open interactive learning, reference libraries, and educational applications available on this device.</p>
        </div>
    </section>

    <section class="resource-types" aria-labelledby="tools-list-title">
        <div class="home-section-heading">
            <p class="home-section-heading__eyebrow">Available tools</p>
            <h2 id="tools-list-title">Explore Offline Applications</h2>
            <p>These tools open locally and may work even when there is no internet connection.</p>
        </div>

        <div class="resource-types__grid tools-grid">
            <?php foreach ($services as $key => $service): ?>
                <?php
                $label = trim((string)($service['label'] ?? $key));
                $href = toolHref($service, $_SERVER['HTTP_HOST'] ?? 'localhost:8080');
                $icon = toolIcon($service);
                $description = trim((string)(
                    $service['description'] ?? 'Open this offline learning tool.'
                ));
                $openSameTab = !empty($service['local_same_tab']);
                ?>
                <a
                    class="resource-card resource-card--tools"
                    href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"
                    <?php if (strtolower($label) !== 'khan interactive' && !$openSameTab): ?>
                        target="_blank"
                        rel="noopener noreferrer"
                    <?php endif; ?>
                    aria-label="Open <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>"
                >
                    <span class="resource-card__icon" aria-hidden="true">
                        <i class="fa <?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>"></i>
                    </span>

                    <span class="resource-card__content">
                        <span class="resource-card__title">
                            <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                        </span>

                        <span class="resource-card__description">
                            <?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </span>

                    <span class="resource-card__arrow" aria-hidden="true">&rarr;</span>
                </a>
            <?php endforeach; ?>

            <?php if (empty($services)): ?>
                <div class="home-empty-state">
                    No learning tools are configured yet.
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="home-next-steps">
        <div class="home-next-steps__copy">
            <p class="home-section-heading__eyebrow">Return to learning</p>
            <h2>Explore the media library</h2>
            <p>Use videos, audiobooks, books, and music to continue learning offline.</p>
        </div>

        <div class="home-next-steps__actions">
            <a class="home-button home-button--primary" href="index.php">
                <i class="fa fa-home" aria-hidden="true"></i>
                Home
            </a>

            <a class="home-button home-button--secondary" href="directory.php">
                <i class="fa fa-list" aria-hidden="true"></i>
                Browse All Topics
            </a>
        </div>
    </section>
</main>

<?php
require_once __DIR__ . '/includes/breadcrumb.php';

renderBreadcrumb([
    ['label' => 'Home', 'color' => null, 'href' => 'index.php'],
    ['label' => 'Learning Tools', 'color' => null, 'href' => null],
]);

include_once __DIR__ . '/footer.php';
?>