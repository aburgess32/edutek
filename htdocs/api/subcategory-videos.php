<?php

/**
 * AJAX endpoint: subcategory video list (FRE-54).
 *
 * Returns rendered HTML for a single subcategory's video list,
 * used by the tutorials page to lazily load video items on expand.
 *
 * Parameters (GET):
 *   category    — category name
 *   subcategory — subcategory name
 *
 * Response: JSON { html: "<li>...</li>...", count: N }
 *
 * @package EduPak
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db-content.php';

header('Content-Type: application/json; charset=utf-8');

$category    = isset($_GET['category']) ? trim($_GET['category']) : '';
$subcategory = isset($_GET['subcategory']) ? trim($_GET['subcategory']) : '';

if ($category === '' || $subcategory === '') {
    echo json_encode(['html' => '', 'count' => 0]);
    exit;
}

// Encryption setup (must match tutorials.php / watch.php)
$cipher         = 'BF-CBC';
$options        = 0;
$iv             = '91011121';
$encryption_key = 'hfjfydjnvhbjfi';

// Encrypted GET-param key names
$videolink1_key = str_replace('=', '[equal]', base64_encode(openssl_encrypt('videolink1', $cipher, $encryption_key, $options, $iv)));
$videoname1_key = str_replace('=', '[equal]', base64_encode(openssl_encrypt('videoname1', $cipher, $encryption_key, $options, $iv)));
$videolink_key  = str_replace('=', '[equal]', base64_encode(openssl_encrypt('videolink',  $cipher, $encryption_key, $options, $iv)));
$videoname_key  = str_replace('=', '[equal]', base64_encode(openssl_encrypt('videoname',  $cipher, $encryption_key, $options, $iv)));

// Breadcrumb context passthrough
$bcQuery = '';
if (isset($_GET['seg']) && $_GET['seg'] !== '') {
    $bcQuery .= '&seg=' . urlencode($_GET['seg']);
}
if (isset($_GET['topic']) && $_GET['topic'] !== '') {
    $bcQuery .= '&topic=' . urlencode($_GET['topic']);
}

$pdo    = getDbConnection();
$videos = getSubcategoryVideos($pdo, $category, $subcategory);
$count  = count($videos);

if ($count === 0) {
    echo json_encode(['html' => '', 'count' => 0]);
    exit;
}

// Helper: encrypt a value for URL params
function encParam($val, $cipher, $key, $opts, $iv)
{
    return str_replace('=', '[equal]', base64_encode(openssl_encrypt($val, $cipher, $key, $opts, $iv)));
}

// Helper: turn a filename/title into a human-readable name
function prettyName($filename)
{
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = str_replace(array('_', '-'), ' ', $name);
    $name = preg_replace('/\s+/', ' ', trim($name));
    return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
}

// Build HTML
ob_start();
$vidNum = 0;
foreach ($videos as $row) {
    $vidNum++;

    // Normalize path: ensure forward slashes, strip leading slash
    $filePath = ltrim(str_replace(chr(92), '/', \$row['file_path']), '/');
    $filePath = ltrim($filePath, '/');

    $baseName  = basename($filePath);
    $folderPath = rtrim(dirname($filePath), '/') . '/';
    $folderName = $subcategory;
    $ext = strtoupper(pathinfo($baseName, PATHINFO_EXTENSION));

    // Encrypt URL parameters (matches watch.php expectations)
    $encFolderPath = encParam($folderPath,  $cipher, $encryption_key, $options, $iv);
    $encFolderName = encParam($folderName,  $cipher, $encryption_key, $options, $iv);
    $encVidPath    = encParam($filePath,    $cipher, $encryption_key, $options, $iv);
    $encVidName    = encParam($baseName,    $cipher, $encryption_key, $options, $iv);

    $vidHref = 'watch.php?&' . $videolink_key . '=' . $encFolderPath
             . '&' . $videoname_key . '=' . $encFolderName
             . '&' . $videolink1_key . '=' . $encVidPath
             . '&' . $videoname1_key . '=' . $encVidName
             . $bcQuery;

    // Use DB title if available, otherwise derive from filename
    $displayTitle = !empty($row['title']) ? $row['title'] : prettyName($baseName);

    // Thumbnail
    $thumbSrc = 'images/sample.png';
    if (!empty($row['thumbnail_path'])) {
        $thumbSrc = str_replace('\\', '/', $row['thumbnail_path']);
        $thumbSrc = ltrim($thumbSrc, '/');
    }
?>
<li class="tut-video-item">
    <span class="tut-vid-num"><?php echo $vidNum; ?></span>
    <img src="<?php echo htmlspecialchars($thumbSrc); ?>" alt="" class="tut-vid-thumb" loading="lazy">
    <a href="<?php echo $vidHref; ?>" class="tut-vid-name" title="<?php echo htmlspecialchars($baseName); ?>">
        <?php echo htmlspecialchars($displayTitle); ?>
    </a>
    <span class="tut-vid-ext"><?php echo $ext; ?></span>
</li>
<?php
}
$html = ob_get_clean();

echo json_encode(['html' => $html, 'count' => $count]);
