<?php

/**
 * EduPak Tile Configuration Helper
 *
 * Loads, caches, and queries tile configuration from tiles.json.
 * Provides helpers for segment/topic lookups, href generation, and content indexing.
 *
 * @package EduPak
 * @version 1.0.0
 */

declare(strict_types=1);

// BF-CBC encryption constants (matches index.php / tutorials.php)
define('TILE_CIPHER', 'BF-CBC');
define('TILE_IV', '91011121');
define('TILE_ENC_KEY', 'hfjfydjnvhbjfi');

/**
 * Hardcoded fallback segments when tiles.json is missing or corrupt.
 */
function getFallbackSegments(): array
{
    return [
        'early_learners' => [
            'label' => 'Early Learners',
            'description' => 'Foundation skills, stories, and play',
            'icon' => "\xF0\x9F\x8C\xB1",
            'color' => '#FF6B35',
            'gradient' => 'linear-gradient(135deg, #FF6B35 0%, #FF8F65 100%)',
            'image' => '',
            'sort_order' => 1,
            'topics' => [],
        ],
        'explorers' => [
            'label' => 'Explorers',
            'description' => 'Curiosity-driven, broad topics',
            'icon' => "\xF0\x9F\xA7\xAD",
            'color' => '#4ECDC4',
            'gradient' => 'linear-gradient(135deg, #4ECDC4 0%, #44B3AA 100%)',
            'image' => '',
            'sort_order' => 2,
            'topics' => [],
        ],
        'advanced' => [
            'label' => 'Advanced',
            'description' => 'Vocational, professional, deep learning',
            'icon' => "\xE2\x9A\x99\xEF\xB8\x8F",
            'color' => '#1A535C',
            'gradient' => 'linear-gradient(135deg, #1A535C 0%, #2D7A86 100%)',
            'image' => '',
            'sort_order' => 3,
            'topics' => [],
        ],
        'educators' => [
            'label' => 'Educators',
            'description' => 'Teaching tools and lesson resources',
            'icon' => "\xF0\x9F\x93\x8B",
            'color' => '#F7C948',
            'gradient' => 'linear-gradient(135deg, #F7C948 0%, #F5D76E 100%)',
            'image' => '',
            'sort_order' => 4,
            'topics' => [],
        ],
    ];
}

/**
 * Load tile configuration with filemtime-based file cache.
 *
 * @return array Parsed tiles.json contents.
 */
function getTileConfig(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $jsonPath = __DIR__ . '/../config/tiles.json';
    $cachePath = sys_get_temp_dir() . '/edupak_tiles_cache.php';

    if (!file_exists($jsonPath)) {
        $cached = [
            'segments' => getFallbackSegments(),
            'services' => [],
            'special_routes' => [],
            'type_badges' => [],
        ];
        return $cached;
    }

    $jsonMtime = filemtime($jsonPath);

    // Check file cache
    if (file_exists($cachePath)) {
        $cacheData = @include $cachePath;
        if (is_array($cacheData) && isset($cacheData['_mtime']) && $cacheData['_mtime'] === $jsonMtime) {
            unset($cacheData['_mtime']);
            $cached = $cacheData;
            return $cached;
        }
    }

    // Parse fresh
    $raw = file_get_contents($jsonPath);
    $data = json_decode($raw, true);

    if (!is_array($data) || empty($data['segments'])) {
        $cached = [
            'segments' => getFallbackSegments(),
            'services' => [],
            'special_routes' => [],
            'type_badges' => [],
        ];
        return $cached;
    }

    $cached = $data;

    // Write file cache
    $cacheContent = $data;
    $cacheContent['_mtime'] = $jsonMtime;
    $export = '<?php return ' . var_export($cacheContent, true) . ";\n";
    $tmpFile = $cachePath . '.tmp';
    if (@file_put_contents($tmpFile, $export, LOCK_EX) !== false) {
        @rename($tmpFile, $cachePath);
    }

    return $cached;
}

/**
 * Get all segments sorted by sort_order, with published lesson plans injected.
 *
 * Published plans (lesson_plans with non-empty published_segments JSON)
 * appear as playlist-type topics in their target segments.
 *
 * @return array Associative array of segment_key => segment_data.
 */
function getSegments(): array
{
    $config = getTileConfig();
    $segments = $config['segments'] ?? getFallbackSegments();

    uasort($segments, function ($a, $b) {
        return ($a['sort_order'] ?? 99) - ($b['sort_order'] ?? 99);
    });

    // Inject published lesson plans into their target segments
    try {
        $pdo = getDbConnection();
        $publishedPlans = $pdo->query("
            SELECT id, title, icon, color, content_ids, published_segments, sort_order
            FROM lesson_plans
            WHERE published_segments IS NOT NULL
              AND JSON_LENGTH(published_segments) > 0
            ORDER BY sort_order ASC
        ")->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        $publishedPlans = []; // graceful degradation pre-migration
    }

    $segPlanCount = [];
    foreach ($publishedPlans as $plan) {
        $segKeys = json_decode($plan['published_segments'], true) ?: [];
        $items = json_decode($plan['content_ids'], true) ?: [];
        if (count($items) === 0) {
            continue;
        }

        foreach ($segKeys as $segKey) {
            if (!isset($segments[$segKey])) {
                continue;
            }
            // Cap at 30 published plans per segment on home page
            if (!isset($segPlanCount[$segKey])) {
                $segPlanCount[$segKey] = 0;
            }
            if ($segPlanCount[$segKey] >= 30) {
                continue;
            }
            $segPlanCount[$segKey]++;
            $segments[$segKey]['topics'][] = [
                'slug'         => 'playlist-' . $plan['id'],
                'label'        => $plan['title'],
                'icon'         => $plan['icon'] ?? 'ðŸ“š',
                'color'        => $plan['color'] ?? '#4ECDC4',
                'type'         => 'playlist',
                'content_path' => null,
                'href'         => 'playlist.php?plan=' . $plan['id'],
                'item_count'   => count($items),
            ];
        }
    }

    return $segments;
}

/**
 * Get topics for a specific segment.
 *
 * @param  string $segKey Segment key (e.g. 'early_learners').
 * @return array  List of topic arrays, or empty array if segment not found.
 */
function getSegmentTopics(string $segKey): array
{
    $segments = getSegments();
    if (!isset($segments[$segKey])) {
        return [];
    }
    return $segments[$segKey]['topics'] ?? [];
}

/**
 * Encrypt a value using the BF-CBC cipher (matches existing pattern).
 *
 * @param  string $value Plain text to encrypt.
 * @return string        Base64-encoded encrypted string with '=' replaced by '[equal]'.
 */
function tileEncrypt(string $value): string
{
    $encrypted = openssl_encrypt($value, TILE_CIPHER, TILE_ENC_KEY, 0, TILE_IV);
    return str_replace('=', '[equal]', base64_encode($encrypted));
}

/**
 * Build the correct href for a topic.
 *
 * @param  array  $topic    Topic data from tiles.json.
 * @param  string $hostname Current HTTP_HOST value.
 * @param  string $segKey   Segment key for breadcrumb context (FRE-12).
 * @param  string $topicSlug Topic slug for breadcrumb context (FRE-12).
 * @return string            URL to navigate to.
 */
function buildTopicHref(array $topic, string $hostname, string $segKey = '', string $topicSlug = ''): string
{
    $href = $topic['href'] ?? null;
    $label = $topic['label'] ?? '';
    $type = $topic['type'] ?? 'video';

    // Build breadcrumb context query string (FRE-12)
    $bcParams = '';
    if ($segKey !== '') {
        $bcParams .= '&seg=' . urlencode($segKey);
    }
    if ($topicSlug === '' && isset($topic['slug'])) {
        $topicSlug = $topic['slug'];
    }
    if ($topicSlug !== '') {
        $bcParams .= '&topic=' . urlencode($topicSlug);
    }

    // Port-based service links (no breadcrumb context â€” external service)
    if ($href !== null && strpos($href, '__PORT_') === 0) {
        $port = str_replace(['__PORT_', '__'], '', $href);
        return 'http://' . $hostname . ':' . $port . '/';
    }

    // Explicit href (direct link) â€” append breadcrumb context if it's an internal PHP page
    if ($href !== null && $href !== '') {
        if ($bcParams !== '' && preg_match('/\.php/', $href)) {
            $sep = (strpos($href, '?') !== false) ? '&' : '?';
            return $href . $sep . ltrim($bcParams, '&');
        }
        return $href;
    }

    // Special routes by label
    $config = getTileConfig();
    $specialRoutes = $config['special_routes'] ?? [];
    if (isset($specialRoutes[$label])) {
        $route = $specialRoutes[$label];
        if ($bcParams !== '' && preg_match('/\.php/', $route)) {
            $sep = (strpos($route, '?') !== false) ? '&' : '?';
            return $route . $sep . ltrim($bcParams, '&');
        }
        return $route;
    }

    // Video type with null href â€” generate encrypted tutorials.php link
    if ($type === 'video') {
        $contentPath = $topic['content_path'] ?? '';
        // Extract folder name from content_path (e.g. "videos/Primary Multiplication" â†’ "Primary Multiplication")
        $folderName = basename($contentPath);
        if ($folderName === '') {
            $folderName = $label;
        }

        $encCourse = tileEncrypt($folderName);
        $encKey = tileEncrypt('course');
        return 'tutorials.php?&' . $encKey . '=' . $encCourse . $bcParams;
    }

    return '#';
}

/**
 * Get content type badge info (label + color).
 *
 * @param  string $type Content type key.
 * @return array        ['label' => string, 'color' => string]
 */
function getContentTypeBadge(string $type): array
{
    $config = getTileConfig();
    $badges = $config['type_badges'] ?? [];

    if (isset($badges[$type])) {
        return $badges[$type];
    }

    return ['label' => ucfirst($type), 'color' => '#6B7280'];
}

/**
 * Get flat list of ALL content for the directory/all-content section.
 * Merges video folders from glob('videos/*') with services from config.
 *
 * @return array List of content items with: label, type, href, slug.
 */
function getAllContent(): array
{
    $config = getTileConfig();
    $hostname = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $specialRoutes = $config['special_routes'] ?? [];
    $items = [];
    $seen = [];

    // Video folders
    $videoDir = __DIR__ . '/../videos/';
    if (is_dir($videoDir)) {
        $folders = glob($videoDir . '*');
        if ($folders !== false) {
            foreach ($folders as $path) {
                if (!is_dir($path)) {
                    continue;
                }
                $folderName = basename($path);
                if (count(glob($path . '/*')) === 0) {
                    continue;
                }

                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $folderName));
                $type = 'video';
                $href = '#';

                if (isset($specialRoutes[$folderName])) {
                    $href = $specialRoutes[$folderName];
                    if ($folderName === 'Audiobooks') {
                        $type = 'audio';
                    } elseif ($folderName === 'Books' || $folderName === 'Comic Books') {
                        $type = 'book';
                    } elseif ($folderName === 'Music') {
                        $type = 'audio';
                    }
                } else {
                    $encCourse = tileEncrypt($folderName);
                    $encKey = tileEncrypt('course');
                    $href = 'tutorials.php?&' . $encKey . '=' . $encCourse;
                }

                $items[] = [
                    'label' => $folderName,
                    'type' => $type,
                    'href' => $href,
                    'slug' => $slug,
                ];
                $seen[$slug] = true;
            }
        }
    }

    // Services from config
    $services = $config['services'] ?? [];
    foreach ($services as $key => $svc) {
        $slug = is_string($key) ? $key : ($svc['slug'] ?? strtolower(preg_replace('/[^a-z0-9]+/i', '-', $svc['label'])));
        if (isset($seen[$slug])) {
            continue;
        }
        $port = $svc['port'] ?? null;
        $href = $svc['href'] ?? ($port ? ('http://' . $hostname . ':' . $port . '/') : '#');

        $items[] = [
            'label' => $svc['label'] ?? $slug,
            'type' => $svc['type'] ?? 'service',
            'href' => $href,
            'slug' => $slug,
            'icon' => $svc['icon'] ?? '',
        ];
        $seen[$slug] = true;
    }

    // Sort alphabetically
    usort($items, function ($a, $b) {
        return strcasecmp($a['label'], $b['label']);
    });

    return $items;
}

/**
 * Get content in videos/ not assigned to any segment in tiles.json.
 *
 * @return array List of folder names not found in any segment topic.
 */
function getUncategorizedContent(): array
{
    $segments = getSegments();
    $assigned = [];

    foreach ($segments as $seg) {
        foreach (($seg['topics'] ?? []) as $topic) {
            $contentPath = $topic['content_path'] ?? '';
            if (strpos($contentPath, 'videos/') === 0) {
                $assigned[basename($contentPath)] = true;
            }
        }
    }

    $uncategorized = [];
    $videoDir = __DIR__ . '/../videos/';
    if (is_dir($videoDir)) {
        $folders = glob($videoDir . '*');
        if ($folders !== false) {
            foreach ($folders as $path) {
                if (!is_dir($path)) {
                    continue;
                }
                $folderName = basename($path);
                if (count(glob($path . '/*')) === 0) {
                    continue;
                }
                if (!isset($assigned[$folderName])) {
                    $uncategorized[] = $folderName;
                }
            }
        }
    }

    sort($uncategorized);
    return $uncategorized;
}

/**
 * Find a topic by its slug across all segments.
 *
 * @param  string $slug Topic slug to search for.
 * @return array|null   Topic data array, or null if not found.
 */
function findTopicBySlugGlobal(string $slug): ?array
{
    $segments = getSegments();
    foreach ($segments as $seg) {
        foreach ($seg['topics'] ?? [] as $topic) {
            if (($topic['slug'] ?? '') === $slug) {
                return $topic;
            }
        }
    }
    return null;
}

/**
 * Get featured content for the hero slider.
 *
 * Reads the 'featured' array from tiles.json, resolves hrefs for items
 * with null cta_href (using buildTopicHref), and provides image fallbacks.
 *
 * @return array List of featured slide items.
 */
function getFeaturedContent(): array
{
    $config = getTileConfig();
    $featured = $config['featured'] ?? [];
    $hostname = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost', ENT_QUOTES, 'UTF-8');

    foreach ($featured as &$item) {
        // Resolve null hrefs from topic data
        if ($item['cta_href'] === null) {
            $topic = findTopicBySlugGlobal($item['slug'] ?? '');
            if ($topic) {
                $item['cta_href'] = buildTopicHref(
                    $topic,
                    $hostname,
                    $item['segment'] ?? '',
                    $item['slug'] ?? ''
                );
            } else {
                $item['cta_href'] = '#';
            }
        }

        // Fallback bg_image: topic image â†’ segment image â†’ default header
        if (empty($item['bg_image']) || !file_exists(__DIR__ . '/../' . $item['bg_image'])) {
            $topic = $topic ?? findTopicBySlugGlobal($item['slug'] ?? '');
            $item['bg_image'] = ($topic['image'] ?? '') ?: 'assets/img/header.jpg';
        }
    }
    unset($item);

    return $featured;
}
