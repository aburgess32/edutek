<?php
/**
 * On-demand thumbnail generator for video files.
 * Called via AJAX after page load to generate thumbnails in the background.
 *
 * GET params:
 *   video - path to video file (relative to htdocs)
 *
 * Returns JSON: { "thumb": "path/to/thumb.jpg" } or { "error": "message" }
 */
header('Content-Type: application/json');

$videoPath = isset($_GET['video']) ? $_GET['video'] : '';

if ($videoPath === '') {
    echo json_encode(['error' => 'Missing video parameter']);
    exit;
}

// Sanitize: prevent directory traversal
$videoPath = str_replace(['..', "\0"], '', $videoPath);

// Ensure the video file exists
if (!file_exists($videoPath)) {
    echo json_encode(['error' => 'Video not found']);
    exit;
}

// Only allow video extensions
$ext = strtolower(pathinfo($videoPath, PATHINFO_EXTENSION));
$allowed = ['mp4', 'mov', 'wmv', 'flv', 'avi', 'webm', 'mkv', 'f4v'];
if (!in_array($ext, $allowed)) {
    echo json_encode(['error' => 'Invalid file type']);
    exit;
}

$dir = dirname($videoPath);
$thumbDir = $dir . '/thumbs';
$base = pathinfo($videoPath, PATHINFO_FILENAME);
$thumbFile = $thumbDir . '/' . $base . '.jpg';

// Return cached thumb if it already exists
if (file_exists($thumbFile)) {
    echo json_encode(['thumb' => $thumbFile]);
    exit;
}

// Ensure thumbs directory exists
if (!is_dir($thumbDir)) {
    @mkdir($thumbDir, 0755, true);
}

// Generate thumbnail at 10s mark
$cmd = sprintf(
    'ffmpeg -ss 10 -i %s -frames:v 1 -update 1 -vf "scale=160:90:force_original_aspect_ratio=decrease,pad=160:90:(ow-iw)/2:(oh-ih)/2" -q:v 4 %s 2>/dev/null',
    escapeshellarg($videoPath),
    escapeshellarg($thumbFile)
);
@exec($cmd);

// If 10s failed (video shorter than 10s), try 1s
if (!file_exists($thumbFile)) {
    $cmd = sprintf(
        'ffmpeg -ss 1 -i %s -frames:v 1 -update 1 -vf "scale=160:90:force_original_aspect_ratio=decrease,pad=160:90:(ow-iw)/2:(oh-ih)/2" -q:v 4 %s 2>/dev/null',
        escapeshellarg($videoPath),
        escapeshellarg($thumbFile)
    );
    @exec($cmd);
}

if (file_exists($thumbFile)) {
    echo json_encode(['thumb' => $thumbFile]);
} else {
    echo json_encode(['error' => 'Thumbnail generation failed']);
}
