<?php
/**
 * AJAX endpoint for paginated tutorial sections.
 * FRE-54: Returns a batch of rendered HTML for the next N tutorial sections.
 *
 * Parameters:
 *   course  — encrypted course name (same encoding as tutorials.php GET param)
 *   offset  — starting folder index (0-based), default 0
 *   limit   — number of folders to return, default 20
 *
 * Response: JSON { html, hasMore, loaded, total }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/tutorial-renderer.php';

// --- Encryption config (must match tutorials.php exactly) ---
$cipher         = "BF-CBC";
$options        = 0;
$iv             = "91011121";
$encryption_key = "hfjfydjnvhbjfi";
$decryption_key = "hfjfydjnvhbjfi";
$decryption_iv  = "91011121";

$enc = [$cipher, $encryption_key, $options, $iv];

// --- Validate parameters ---
if (!isset($_GET['course']) || $_GET['course'] === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing course parameter']);
    exit;
}

$courseParam = $_GET['course'];
$decryption = openssl_decrypt(
    base64_decode(str_replace('[equal]', '=', $courseParam)),
    $cipher, $decryption_key, $options, $decryption_iv
);

if ($decryption === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid course parameter']);
    exit;
}

$coursePath = "videos/" . $decryption . "/";

if (!is_dir($coursePath)) {
    http_response_code(404);
    echo json_encode(['error' => 'Course not found']);
    exit;
}

$offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;
$limit  = isset($_GET['limit'])  ? max(1, min(100, intval($_GET['limit']))) : 20;

// --- Build encrypted URL parameter keys (same as tutorials.php) ---
$encryption2 = tutRenderer_encParam("course",     $cipher, $encryption_key, $options, $iv);
$videolink1  = tutRenderer_encParam("videolink1",  $cipher, $encryption_key, $options, $iv);
$videoname1  = tutRenderer_encParam("videoname1",  $cipher, $encryption_key, $options, $iv);
$videolink   = tutRenderer_encParam("videolink",   $cipher, $encryption_key, $options, $iv);
$videoname   = tutRenderer_encParam("videoname",   $cipher, $encryption_key, $options, $iv);

$encKeys = [$encryption2, $videolink1, $videoname1, $videolink, $videoname];

// --- Breadcrumb context (optional, forwarded from frontend) ---
$bcQuery = '';
if (isset($_GET['seg'])   && $_GET['seg']   !== '') { $bcQuery .= '&seg='   . urlencode($_GET['seg']); }
if (isset($_GET['topic']) && $_GET['topic'] !== '') { $bcQuery .= '&topic=' . urlencode($_GET['topic']); }

// --- Render the requested page of sections ---
$result = renderTutorialSections($coursePath, $offset, $limit, $encKeys, $enc, $bcQuery);

echo json_encode($result);
