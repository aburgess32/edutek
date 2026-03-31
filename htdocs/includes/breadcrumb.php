<?php
/**
 * FRE-12: Breadcrumb Path
 *
 * Builds a max-3-level breadcrumb trail:
 *   Category (segment) > Sub-category (topic) > Current Video
 *
 * Data sourced from URL query params + tiles.json + filesystem.
 * No DB dependency — all fallback-safe.
 */

require_once __DIR__ . '/tiles.php';

/**
 * Find a topic by its slug within a segment's topics array.
 * tiles.json stores topics as arrays (not keyed objects).
 *
 * @param  array  $topics Array of topic objects from tiles.json
 * @param  string $slug   The slug to match
 * @return array|null      The matched topic or null
 */
function findTopicBySlug(array $topics, string $slug): ?array
{
    if ($slug === '') {
        return null;
    }
    foreach ($topics as $t) {
        if (isset($t['slug']) && $t['slug'] === $slug) {
            return $t;
        }
    }
    return null;
}

/**
 * Resolve a human-readable title for a content item.
 *
 * Strategy:
 *   1. Use decrypted folder/file name from watch.php context (if available)
 *   2. Derive from content_path in tiles.json
 *   3. Final fallback: "Video"
 *
 * @param  string|null $videoName Decrypted video/folder name from URL context
 * @return string                 Human-readable title
 */
function getContentTitle(?string $videoName = null): string
{
    if ($videoName !== null && $videoName !== '') {
        // Clean up filesystem-style names: remove extension, replace underscores
        $name = pathinfo($videoName, PATHINFO_FILENAME);
        $name = str_replace(['_', '-'], ' ', $name);
        $name = trim($name);
        if ($name !== '') {
            return $name;
        }
    }
    return 'Video';
}

/**
 * Sanitize a segment key — allow only alphanumeric + underscore + hyphen.
 *
 * @param  string $input Raw input
 * @return string        Sanitized string
 */
function sanitizeBreadcrumbParam(string $input): string
{
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $input);
}

/**
 * Build the breadcrumb trail array (max 3 items).
 *
 * @param  array $params    Typically $_GET — expects 'seg', 'topic', optional video context
 * @param  string|null $videoTitle  Optional video/content title for the leaf crumb
 * @param  bool  $isContentPage    Whether this is a video/content page (adds leaf crumb)
 * @return array            Array of crumb items: ['label', 'color', 'href']
 */
function buildBreadcrumb(array $params, ?string $videoTitle = null, bool $isContentPage = false): array
{
    $crumbs = [];

    $seg   = isset($params['seg'])   ? sanitizeBreadcrumbParam(trim($params['seg']))   : '';
    $topic = isset($params['topic']) ? sanitizeBreadcrumbParam(trim($params['topic'])) : '';

    // Early exit: nothing to show
    if ($seg === '' && !$isContentPage) {
        return $crumbs;
    }

    // Load tile config
    $config = getTileConfig();
    $segments = $config['segments'] ?? [];

    // Level 1: Category (segment)
    if ($seg !== '' && isset($segments[$seg])) {
        $segData = $segments[$seg];
        $segTopics = $segData['topics'] ?? [];
        $crumbs[] = [
            'label' => $segData['label'] ?? $seg,
            'color' => $segData['color'] ?? null,
            'href'  => ($topic !== '' || $isContentPage) ? 'browse.php?seg=' . urlencode($seg) : null,
        ];

        // Level 2: Sub-category (topic)
        if ($topic !== '') {
            $topicData = findTopicBySlug($segTopics, $topic);
            if ($topicData !== null) {
                $crumbs[] = [
                    'label' => $topicData['label'] ?? $topic,
                    'color' => $topicData['color'] ?? $segData['color'] ?? null,
                    'href'  => $isContentPage ? 'browse.php?seg=' . urlencode($seg) . '&topic=' . urlencode($topic) : null,
                ];
            }
        }
    }

    // Level 3: Current video/content (leaf)
    if ($isContentPage) {
        $title = getContentTitle($videoTitle);
        $crumbs[] = [
            'label' => $title,
            'color' => null,
            'href'  => null, // current page — not a link
        ];
    }

    return $crumbs;
}

/**
 * Render the breadcrumb HTML.
 * Call this from any page that needs the breadcrumb.
 *
 * @param  array $crumbs Array from buildBreadcrumb()
 * @return void          Outputs HTML directly
 */
function renderBreadcrumb(array $crumbs): void
{
    if (empty($crumbs)) {
        return;
    }
    include __DIR__ . '/breadcrumb.html.php';
}
