<?php
$secret = 'CHANGE_THIS_SECRET';

header('Content-Type: application/json');

if (!isset($_GET['key']) || $_GET['key'] !== $secret) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$contentRoot = '/content';
$outputDir = __DIR__ . '/../storage';
$outputFile = $outputDir . '/video-index.json';

if (!is_dir($contentRoot)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'content root not found', 'path' => $contentRoot]);
    exit;
}

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

$allowedExtensions = ['mp4', 'm4v', 'webm', 'mov', 'avi', 'mkv', 'pdf', 'jpg', 'jpeg', 'png', 'webp'];
$categories = [];

$categoryDirs = glob($contentRoot . '/*', GLOB_ONLYDIR);
sort($categoryDirs, SORT_NATURAL | SORT_FLAG_CASE);

foreach ($categoryDirs as $categoryPath) {
    $categoryName = basename($categoryPath);
    $items = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($categoryPath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) {
            continue;
        }

        $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($contentRoot) + 1));

        $items[] = [
            'title' => pathinfo($file->getFilename(), PATHINFO_FILENAME),
            'filename' => $file->getFilename(),
            'category' => $categoryName,
            'path' => $relativePath,
            'url' => '/content/' . str_replace(' ', '%20', $relativePath),
            'extension' => $ext,
            'modified' => date('c', $file->getMTime()),
            'size' => $file->getSize(),
        ];
    }

    usort($items, function ($a, $b) {
        return strnatcasecmp($a['filename'], $b['filename']);
    });

    $categories[] = [
        'name' => $categoryName,
        'sort_key' => $categoryName,
        'item_count' => count($items),
        'items' => $items,
    ];
}

usort($categories, function ($a, $b) {
    return strnatcasecmp($a['name'], $b['name']);
});

$payload = [
    'ok' => true,
    'generated_at' => date('c'),
    'category_count' => count($categories),
    'categories' => $categories,
];

file_put_contents($outputFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'ok' => true,
    'generated_at' => $payload['generated_at'],
    'category_count' => $payload['category_count'],
    'output' => $outputFile
]);