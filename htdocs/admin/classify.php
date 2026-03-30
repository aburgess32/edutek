<?php
/**
 * EduPak Auto-Classification Endpoint
 *
 * Scans videos/ folder for new directories not assigned to any segment.
 * Uses keyword-based classification to suggest segment assignments.
 * Returns JSON with results.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/tiles.php';

header('Content-Type: application/json; charset=utf-8');

// Must be authenticated
if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// CSRF check
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

// ── Keyword classification rules ────────────────────────────────────────────
$classificationRules = [
    'early_learners' => [
        'Primary', 'Kids', 'Children', 'Elementary', 'Nursery',
        'Kindergarten', 'Preschool', 'Early', 'Foundation', 'Basic',
    ],
    'advanced' => [
        'Engineering', 'Management', 'Professional', 'Vocational',
        'Hotel', 'Electrical', 'Medical', 'University', 'Advanced',
        'Technical', 'Diploma', 'Degree',
    ],
    'educators' => [
        'Teaching', 'Lesson', 'Curriculum', 'Pedagogy',
        'Teacher', 'Instructor', 'Training', 'Education',
    ],
    // 'explorers' is the default fallback
];

/**
 * Classify a folder name by keyword matching.
 *
 * @param  string $folderName The video folder name.
 * @return string             Segment key.
 */
function classifyByKeyword(string $folderName): string
{
    global $classificationRules;
    $lower = strtolower($folderName);

    foreach ($classificationRules as $segment => $keywords) {
        foreach ($keywords as $kw) {
            if (stripos($lower, strtolower($kw)) !== false) {
                return $segment;
            }
        }
    }

    return 'explorers'; // Default: broadest category
}

// ── Scan and classify ───────────────────────────────────────────────────────
$uncategorized = getUncategorizedContent();

if (empty($uncategorized)) {
    echo json_encode([
        'classified' => 0,
        'message' => 'No new uncategorized content found.',
    ]);
    exit;
}

$jsonPath = __DIR__ . '/../config/tiles.json';
if (!file_exists($jsonPath)) {
    echo json_encode(['error' => 'tiles.json not found']);
    exit;
}

$config = json_decode(file_get_contents($jsonPath), true);
if (!is_array($config) || !isset($config['segments'])) {
    echo json_encode(['error' => 'Invalid tiles.json']);
    exit;
}

$classified = 0;
$results = [];

foreach ($uncategorized as $folder) {
    $suggestedSegment = classifyByKeyword($folder);

    // Add to the suggested segment
    if (isset($config['segments'][$suggestedSegment])) {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $folder));
        $config['segments'][$suggestedSegment]['topics'][] = [
            'slug' => $slug,
            'label' => $folder,
            'icon' => "\xF0\x9F\x8E\xAC",
            'color' => $config['segments'][$suggestedSegment]['color'] ?? '#333',
            'type' => 'video',
            'content_path' => 'videos/' . $folder,
            'href' => null,
            'suggested_by' => 'keyword',
            'confirmed' => false,
        ];
        $classified++;
        $results[] = [
            'folder' => $folder,
            'segment' => $suggestedSegment,
            'method' => 'keyword',
        ];
    }
}

// Write updated config
if ($classified > 0) {
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($jsonPath, $json, LOCK_EX) !== false) {
        // Clear the file cache
        $cachePath = sys_get_temp_dir() . '/edupak_tiles_cache.php';
        if (file_exists($cachePath)) {
            @unlink($cachePath);
        }
    }
}

echo json_encode([
    'classified' => $classified,
    'results' => $results,
    'message' => "Classified {$classified} item(s) by keyword matching.",
]);
