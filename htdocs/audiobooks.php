<?php ob_start(); ?>
<link href="css/saved.css" rel="stylesheet">

<?php
include_once __DIR__ . "/navbar.php";

if (file_exists(__DIR__ . "/includes/loading.php")) {
    require_once __DIR__ . "/includes/loading.php";
    if (function_exists('loadingStart')) {
        loadingStart('Loading audiobooks...');
    }
}

$cacheFile = null;
if (file_exists(__DIR__ . "/includes/page-cache.php")) {
    require_once __DIR__ . "/includes/page-cache.php";
    if (function_exists('pageCache_start')) {
        $cacheFile = pageCache_start("audiobooks-" . md5($_SERVER["QUERY_STRING"] ?? ""));
        if ($cacheFile === null) {
            if (function_exists('loadingEnd')) {
                loadingEnd();
            }
            exit;
        }
    }
}

if (function_exists('loadingEnd')) {
    loadingEnd();
}
?>

<div class="SaveWrapper">
    <span class="fa fa-fw fa-bookmark"></span>Audio Books
</div>

<?php
$cipher = "BF-CBC";
$options = 0;
$iv = "91011121";
$encryption_key = "hfjfydjnvhbjfi";

$videolink1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink1", $cipher, $encryption_key, $options, $iv)));
$videoname1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname1", $cipher, $encryption_key, $options, $iv)));
$videolink  = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink",  $cipher, $encryption_key, $options, $iv)));
$videoname  = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname",  $cipher, $encryption_key, $options, $iv)));

$audioExts = ['mp3', 'wav', 'wma', 'm4a', 'm4b', 'flac', 'ogg'];

$pathCandidates = [
    __DIR__ . '/videos/Audiobooks',
    __DIR__ . '/storage/Audiobooks',
    '/var/www/html/videos/Audiobooks',
    '/var/www/html/storage/Audiobooks'
];

$baseFsPath = null;
foreach ($pathCandidates as $candidate) {
    if (is_dir($candidate)) {
        $baseFsPath = $candidate;
        break;
    }
}

$baseWebPath = 'videos/Audiobooks';

function encodePathSegment($segment) {
    return rawurlencode($segment);
}

function buildWebPathFromFs($baseFsPath, $baseWebPath, $fullFsPath) {
    $normalizedBase = str_replace('\\', '/', realpath($baseFsPath));
    $normalizedFull = str_replace('\\', '/', realpath($fullFsPath));

    if (!$normalizedBase || !$normalizedFull) {
        return null;
    }

    if (strpos($normalizedFull, $normalizedBase) !== 0) {
        return null;
    }

    $relative = ltrim(substr($normalizedFull, strlen($normalizedBase)), '/');
    if ($relative === '') {
        return $baseWebPath;
    }

    $parts = array_filter(explode('/', $relative), 'strlen');
    $encodedParts = array_map('encodePathSegment', $parts);

    return $baseWebPath . '/' . implode('/', $encodedParts);
}

if ($baseFsPath === null) {
    echo '<div class="SavedWrapper"><div class="rcontents"><p><b class="rtitle">Audiobooks folder not found</b></p><label class="rdesc"><small>Checked: videos/Audiobooks and storage/Audiobooks</small></label></div></div>';
} else {
    $folders = glob($baseFsPath . '/*', GLOB_ONLYDIR) ?: [];
    natcasesort($folders);

    $renderedCount = 0;

    foreach ($folders as $folderFsPath) {
        $bookName = basename($folderFsPath);
        $audioFiles = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folderFsPath, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $ext = strtolower($file->getExtension());
                    if (in_array($ext, $audioExts, true)) {
                        $audioFiles[] = $file->getPathname();
                    }
                }
            }
        } catch (UnexpectedValueException $e) {
            continue;
        }

        $files1 = count($audioFiles);
        if ($files1 < 1) {
            continue;
        }

        $renderedCount++;

        $bookWebFolder = buildWebPathFromFs($baseFsPath, $baseWebPath, $folderFsPath);
        if ($bookWebFolder === null) {
            continue;
        }

        $encryptvalue = str_replace('=', '[equal]', base64_encode(openssl_encrypt($bookWebFolder . '/', $cipher, $encryption_key, $options, $iv)));
        $value1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($bookName, $cipher, $encryption_key, $options, $iv)));

        $coverWebPath = 'images/default-book.jpg';

        $coverCandidates = [
            $folderFsPath . '/' . $bookName . '.jpg',
            $folderFsPath . '/' . $bookName . '.png',
            $folderFsPath . '/cover.jpg',
            $folderFsPath . '/cover.png',
            $folderFsPath . '/folder.jpg',
            $folderFsPath . '/folder.png'
        ];

        foreach ($coverCandidates as $coverFsPath) {
            if (file_exists($coverFsPath)) {
                $candidateWebPath = buildWebPathFromFs($baseFsPath, $baseWebPath, $coverFsPath);
                if ($candidateWebPath !== null) {
                    $coverWebPath = $candidateWebPath;
                    break;
                }
            }
        }

        echo '<div class="SavedWrapper">
<a href="listen.php?&' . $videolink1 . '=&' . $videoname1 . '=&' . $videolink . '=' . $encryptvalue . '&' . $videoname . '=' . $value1 . '">
<img src="' . htmlspecialchars($coverWebPath, ENT_QUOTES) . '" class="rimage" alt="' . htmlspecialchars($bookName, ENT_QUOTES) . ' cover">
</a>
<div class="rcontents">
&nbsp;&nbsp;&nbsp;&nbsp;
<a href="listen.php?&' . $videolink1 . '=&' . $videoname1 . '=&' . $videolink . '=' . $encryptvalue . '&' . $videoname . '=' . $value1 . '">
<p><b class="rtitle">' . strtoupper(htmlspecialchars($bookName, ENT_QUOTES)) . '</b></p>
<label class="rdesc"><small>(' . $files1 . ' Parts)</small></label>
</a>
</div>
</div>';
    }

    if ($renderedCount === 0) {
        echo '<div class="SavedWrapper"><div class="rcontents"><p><b class="rtitle">No audiobooks found</b></p><label class="rdesc"><small>Path found, but no matching audio files were detected.</small></label></div></div>';
    }
}

if (file_exists(__DIR__ . '/includes/breadcrumb.php')) {
    require_once __DIR__ . '/includes/breadcrumb.php';
    if (function_exists('buildBreadcrumb') && function_exists('renderBreadcrumb')) {
        $crumbs = buildBreadcrumb($_GET);
        if (empty($crumbs)) {
            $crumbs = [['label' => 'Audiobooks', 'color' => '#A78BFA', 'href' => null]];
        }
        renderBreadcrumb($crumbs);
    }
}

include_once __DIR__ . "/footer.php";

if ($cacheFile !== null && function_exists('pageCache_end')) {
    pageCache_end($cacheFile);
}
?>