<?php
/**
 * FRE-12: Breadcrumb Path
 *
 * Builds a breadcrumb trail (max 4 levels with Home):
 *   Home > Category (segment) > Sub-category (topic) > Current Video
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
 * Reverse-lookup: find the segment key and topic slug that own a given
 * content path or folder name. Scans tiles.json content_path fields.
 *
 * @param  array  $segments  The segments array from tiles.json
 * @param  string $contentHint  Folder name or path (e.g. "Primary Multiplication" or "videos/Primary Multiplication")
 * @return array{segKey: string, topicSlug: string}|null
 */
function findSegTopicByContent(array $segments, string $contentHint): ?array
{
    if ($contentHint === '') {
        return null;
    }
    // Normalize: strip leading "videos/" if present
    $needle = preg_replace('#^videos/#i', '', $contentHint);
    $needle = rtrim($needle, '/');

    foreach ($segments as $segKey => $segData) {
        foreach (($segData['topics'] ?? []) as $topic) {
            $cp = $topic['content_path'] ?? '';
            $cp = preg_replace('#^videos/#i', '', $cp);
            $cp = rtrim($cp, '/');
            if ($cp === '') {
                continue;
            }
            // Exact match OR needle starts with content_path (subfolder)
            // e.g. content_path = "Primary Multiplication"
            //      needle       = "Primary Multiplication/Multiplication Flash Cards"
            if (strcasecmp($cp, $needle) === 0 ||
                stripos($needle, $cp . '/') === 0) {
                return [
                    'segKey'    => $segKey,
                    'topicSlug' => $topic['slug'] ?? '',
                    'segData'   => $segData,
                    'topicData' => $topic,
                ];
            }
        }
    }
    return null;
}

/**
 * Build the breadcrumb trail array (max 3 items).
 *
 * @param  array $params    Typically $_GET — expects 'seg', 'topic', optional video context
 * @param  string|null $videoTitle  Optional video/content title for the leaf crumb
 * @param  bool  $isContentPage    Whether this is a video/content page (adds leaf crumb)
 * @return array            Array of crumb items: ['label', 'color', 'href']
 */
function buildBreadcrumb(array $params, ?string $videoTitle = null, bool $isContentPage = false, ?string $contentPath = null): array
{
    $crumbs = [];

    $seg   = isset($params['seg'])   ? sanitizeBreadcrumbParam(trim($params['seg']))   : '';
    $topic = isset($params['topic']) ? sanitizeBreadcrumbParam(trim($params['topic'])) : '';

    // Load tile config
    $config = getTileConfig();
    $segments = $config['segments'] ?? [];

    // Fallback: if seg/topic missing, reverse-lookup from content path or video title
    if ($seg === '') {
        // Try content path first (e.g. "videos/Primary Multiplication"), then video title
        $hints = array_filter([$contentPath, $videoTitle], function($v) { return $v !== null && $v !== ''; });
        foreach ($hints as $hint) {
            $match = findSegTopicByContent($segments, $hint);
            if ($match !== null) {
                $seg   = $match['segKey'];
                $topic = $match['topicSlug'];
                break;
            }
        }
    }

    // Early exit: nothing to show
    if ($seg === '') {
        // On content pages with no context at all, show just the leaf
        // Otherwise, nothing to render
        if (!$isContentPage) {
            return $crumbs;
        }
    }

    // Level 1: Category (segment)
    if ($seg !== '' && isset($segments[$seg])) {
        $segData = $segments[$seg];
        $segTopics = $segData['topics'] ?? [];
        $crumbs[] = [
            'label' => $segData['label'] ?? $seg,
            'color' => $segData['color'] ?? null,
            // Link back to browse if there are deeper levels (topic or content page)
            'href'  => ($topic !== '' || $isContentPage) ? 'browse.php?seg=' . urlencode($seg) : null,
        ];

        // Level 2: Sub-category (topic)
        if ($topic !== '') {
            $topicData = findTopicBySlug($segTopics, $topic);
            if ($topicData !== null) {
                // Build the proper topic link (encrypted tutorials.php for video topics, etc.)
                $topicHref = null;
                if ($isContentPage) {
                    $hostname = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $topicHref = buildTopicHref($topicData, $hostname, $seg, $topic);
                }
                $crumbs[] = [
                    'label' => $topicData['label'] ?? $topic,
                    'color' => $topicData['color'] ?? $segData['color'] ?? null,
                    'href'  => $topicHref,
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

    // Prepend "Home" crumb to all trails so every page links back to index
    if (!empty($crumbs)) {
        array_unshift($crumbs, [
            'label' => 'Home',
            'color' => null,
            'href'  => 'index.php',
        ]);
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
function renderBreadcrumb(array $crumbs = []): void
{
    // Always render — the nav shell (toggle + mode icon) should appear on every page,
    // even when there are no crumbs (e.g. homepage).
    include __DIR__ . '/breadcrumb.html.php';
}
