<?php
ob_start();

$requestStartedAt = microtime(true);

function audiobookTimingLog($label, $requestStartedAt)
{
    error_log(sprintf(
        '[audiobooks] %-34s %8.2f ms',
        $label,
        (microtime(true) - $requestStartedAt) * 1000
    ));
}

audiobookTimingLog('request start', $requestStartedAt);

echo '<!-- AUDIOBOOKS-FILE: LIVE-D-EDUTEK-TIMED-CATALOG-WITH-COVERS -->';
?>
<link href="css/saved.css" rel="stylesheet">

<style>
.audiobook-cover-placeholder {
    width: 100%;
    height: 100%;
    min-height: 220px;
    display: block;
    background: linear-gradient(135deg, #e5e7eb, #cbd5e1);
    border: 1px solid #cbd5e1;
}
.audiobook-pagination {
    clear: both;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
    margin: 28px 0;
    padding: 14px;
    border-top: 1px solid #e5e7eb;
    font-family: Arial, sans-serif;
}

.audiobook-page-link {
    display: inline-block;
    padding: 9px 14px;
    color: #ffffff;
    background: #5b21b6;
    border-radius: 6px;
    text-decoration: none;
}

.audiobook-page-link:hover,
.audiobook-page-link:focus {
    color: #ffffff;
    background: #4c1d95;
}

.audiobook-page-status {
    color: #334155;
    font-size: 14px;
}
</style>

<?php
include_once __DIR__ . "/navbar.php";
audiobookTimingLog('after navbar', $requestStartedAt);

$loadingFile = __DIR__ . "/includes/loading.php";

if (file_exists($loadingFile)) {
    require_once $loadingFile;

    if (function_exists('loadingStart')) {
        loadingStart('Loading audiobooks...');
    }
}

audiobookTimingLog('after loading setup', $requestStartedAt);

$cacheFile = null;
$pageCacheFile = __DIR__ . "/includes/page-cache.php";

if (file_exists($pageCacheFile)) {
    require_once $pageCacheFile;

    if (function_exists('pageCache_start')) {
        $cacheFile = pageCache_start(
            "audiobooks-" . md5($_SERVER["QUERY_STRING"] ?? "")
        );

        if ($cacheFile === null) {
            audiobookTimingLog('served from page cache', $requestStartedAt);

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

$cipher = "BF-CBC";
$options = 0;
$iv = "91011121";
$encryption_key = "hfjfydjnvhbjfi";

$videolink1 = str_replace(
    '=',
    '[equal]',
    base64_encode(
        openssl_encrypt("videolink1", $cipher, $encryption_key, $options, $iv)
    )
);

$videoname1 = str_replace(
    '=',
    '[equal]',
    base64_encode(
        openssl_encrypt("videoname1", $cipher, $encryption_key, $options, $iv)
    )
);

$videolink = str_replace(
    '=',
    '[equal]',
    base64_encode(
        openssl_encrypt("videolink", $cipher, $encryption_key, $options, $iv)
    )
);

$videoname = str_replace(
    '=',
    '[equal]',
    base64_encode(
        openssl_encrypt("videoname", $cipher, $encryption_key, $options, $iv)
    )
);

$pathCandidates = [
    __DIR__ . '/videos/Audiobooks',
    __DIR__ . '/storage/Audiobooks',
    '/content/Audiobooks'
];

$baseFsPath = null;

foreach ($pathCandidates as $candidatePath) {
    if (is_dir($candidatePath)) {
        $baseFsPath = $candidatePath;
        break;
    }
}

$baseWebPath = 'videos/Audiobooks';
$maxBooksPerPage = 48;

$currentPage = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$normalizedBaseFsPath = null;

if ($baseFsPath !== null) {
    $resolvedBasePath = realpath($baseFsPath);

    if ($resolvedBasePath !== false) {
        $normalizedBaseFsPath = str_replace('\\', '/', $resolvedBasePath);
    }
}

audiobookTimingLog('after base path resolution', $requestStartedAt);

function audiobookEncodePathSegment($segment)
{
    return rawurlencode($segment);
}

function audiobookBuildWebPath(
    $normalizedBaseFsPath,
    $baseWebPath,
    $fullFsPath
) {
    if ($normalizedBaseFsPath === null) {
        return null;
    }

    $resolvedFullPath = realpath($fullFsPath);

    if ($resolvedFullPath === false) {
        return null;
    }

    $normalizedFullPath = str_replace('\\', '/', $resolvedFullPath);

    if (strpos($normalizedFullPath, $normalizedBaseFsPath) !== 0) {
        return null;
    }

    $relativePath = ltrim(
        substr($normalizedFullPath, strlen($normalizedBaseFsPath)),
        '/'
    );

    if ($relativePath === '') {
        return $baseWebPath;
    }

    $pathParts = array_filter(
        explode('/', $relativePath),
        'strlen'
    );

    $encodedParts = array_map(
        'audiobookEncodePathSegment',
        $pathParts
    );

    return $baseWebPath . '/' . implode('/', $encodedParts);
}

function audiobookFindCoverWebPath(
    $folderFsPath,
    $bookName,
    $normalizedBaseFsPath,
    $baseWebPath
) {
    $coverCandidates = [
        $folderFsPath . '/' . $bookName . '.jpg',
        $folderFsPath . '/' . $bookName . '.jpeg',
        $folderFsPath . '/' . $bookName . '.png',
        $folderFsPath . '/cover.jpg',
        $folderFsPath . '/cover.jpeg',
        $folderFsPath . '/cover.png',
        $folderFsPath . '/folder.jpg',
        $folderFsPath . '/folder.jpeg',
        $folderFsPath . '/folder.png'
    ];

    foreach ($coverCandidates as $coverFsPath) {
        if (!is_file($coverFsPath)) {
            continue;
        }

        $coverWebPath = audiobookBuildWebPath(
            $normalizedBaseFsPath,
            $baseWebPath,
            $coverFsPath
        );

        if ($coverWebPath !== null) {
            return $coverWebPath;
        }
    }

    return null;
}
?>

<div class="SaveWrapper">
    <span class="fa fa-fw fa-bookmark"></span>Audio Books
</div>

<?php
if ($baseFsPath === null || !is_dir($baseFsPath)) {
    echo '<div class="SavedWrapper">'
        . '<div class="rcontents">'
        . '<p><b class="rtitle">Audiobooks folder not found</b></p>'
        . '<label class="rdesc"><small>Checked: '
        . htmlspecialchars(
            implode(' | ', $pathCandidates),
            ENT_QUOTES,
            'UTF-8'
        )
        . '</small></label>'
        . '</div>'
        . '</div>';

    audiobookTimingLog('audiobooks folder missing', $requestStartedAt);
} else {
    audiobookTimingLog('before folder glob', $requestStartedAt);

    $folders = glob($baseFsPath . '/*', GLOB_ONLYDIR) ?: [];

    $folders = array_values(array_filter(
        $folders,
        static function ($folderFsPath) {
            return strncmp(basename($folderFsPath), '_', 1) !== 0;
        }
    ));

    audiobookTimingLog(
        'after folder glob and maintenance-folder exclusion: '
        . count($folders) . ' folders',
        $requestStartedAt
    );

    natcasesort($folders);

    $folders = array_values($folders);

    $totalBooks = count($folders);
    $totalPages = max(1, (int) ceil($totalBooks / $maxBooksPerPage));

    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }

    $pageOffset = ($currentPage - 1) * $maxBooksPerPage;

    $foldersForPage = array_slice(
        $folders,
        $pageOffset,
        $maxBooksPerPage
    );

    audiobookTimingLog(
        'after pagination: page ' . $currentPage
        . ' of ' . $totalPages
        . ', showing ' . count($foldersForPage)
        . ' books',
        $requestStartedAt
    );

    $renderedCount = 0;

    foreach ($foldersForPage as $folderFsPath) {

        $bookName = basename($folderFsPath);

        /*
         * Performance fix:
         * Do not inspect audio tracks or recursively scan chapters.
         * Every top-level Audiobooks folder is one catalog entry.
         */
        $bookWebFolder = audiobookBuildWebPath(
            $normalizedBaseFsPath,
            $baseWebPath,
            $folderFsPath
        );

        if ($bookWebFolder === null) {
            continue;
        }

        $renderedCount++;

        $encryptedFolder = str_replace(
            '=',
            '[equal]',
            base64_encode(
                openssl_encrypt(
                    $bookWebFolder . '/',
                    $cipher,
                    $encryption_key,
                    $options,
                    $iv
                )
            )
        );

        $encryptedBookName = str_replace(
            '=',
            '[equal]',
            base64_encode(
                openssl_encrypt(
                    $bookName,
                    $cipher,
                    $encryption_key,
                    $options,
                    $iv
                )
            )
        );

        /*
         * Cover lookup is limited to the visible first 48 book folders.
         * It checks a small fixed list of expected JPG/JPEG/PNG filenames
         * and does not scan chapter files or subdirectories.
         */
        $coverWebPath = audiobookFindCoverWebPath(
            $folderFsPath,
            $bookName,
            $normalizedBaseFsPath,
            $baseWebPath
        );

        $listenUrl = 'listen.php?&'
            . $videolink1 . '=&'
            . $videoname1 . '=&'
            . $videolink . '=' . $encryptedFolder . '&'
            . $videoname . '=' . $encryptedBookName;

        $safeBookName = htmlspecialchars(
            $bookName,
            ENT_QUOTES,
            'UTF-8'
        );

        $safeListenUrl = htmlspecialchars(
            $listenUrl,
            ENT_QUOTES,
            'UTF-8'
        );

        $coverMarkup = '';

        if ($coverWebPath !== null) {
            $safeCoverWebPath = htmlspecialchars(
                $coverWebPath,
                ENT_QUOTES,
                'UTF-8'
            );

            $coverMarkup = '<img src="' . $safeCoverWebPath . '"'
                . ' class="rimage"'
                . ' alt="' . $safeBookName . ' cover"'
                . ' loading="lazy"'
                . ' decoding="async">';
        } else {
            $coverMarkup = '<div class="rimage audiobook-cover-placeholder"'
                . ' role="img"'
                . ' aria-label="No cover available"></div>';
        }

        echo '<div class="SavedWrapper">'
            . '<a href="' . $safeListenUrl . '">'
            . $coverMarkup
            . '</a>'
            . '<div class="rcontents">'
            . '&nbsp;&nbsp;&nbsp;&nbsp;'
            . '<a href="' . $safeListenUrl . '">'
            . '<p><b class="rtitle">'
            . strtoupper($safeBookName)
            . '</b></p>'
            . '<label class="rdesc"><small>(Audiobook)</small></label>'
            . '</a>'
            . '</div>'
            . '</div>';
    }

    audiobookTimingLog(
        'after render: ' . $renderedCount . ' books',
        $requestStartedAt
    );
if ($totalBooks > $maxBooksPerPage) {
    $queryParameters = $_GET;
    unset($queryParameters['page']);

    echo '<div class="audiobook-pagination">';

    if ($currentPage > 1) {
        $previousParameters = $queryParameters;
        $previousParameters['page'] = $currentPage - 1;

        echo '<a class="audiobook-page-link" href="audiobooks.php?'
            . htmlspecialchars(
                http_build_query($previousParameters),
                ENT_QUOTES,
                'UTF-8'
            )
            . '">&laquo; Previous</a>';
    }

    echo '<span class="audiobook-page-status">'
        . 'Page ' . $currentPage . ' of ' . $totalPages
        . ' &mdash; ' . $totalBooks . ' audiobooks'
        . '</span>';

    if ($currentPage < $totalPages) {
        $nextParameters = $queryParameters;
        $nextParameters['page'] = $currentPage + 1;

        echo '<a class="audiobook-page-link" href="audiobooks.php?'
            . htmlspecialchars(
                http_build_query($nextParameters),
                ENT_QUOTES,
                'UTF-8'
            )
            . '">Next &raquo;</a>';
    }

    echo '</div>';
}
    if ($renderedCount === 0) {
        echo '<div class="SavedWrapper">'
            . '<div class="rcontents">'
            . '<p><b class="rtitle">No audiobooks found</b></p>'
            . '<label class="rdesc"><small>Checked: '
            . htmlspecialchars($baseFsPath, ENT_QUOTES, 'UTF-8')
            . '</small></label>'
            . '</div>'
            . '</div>';
    }
}

$breadcrumbFile = __DIR__ . '/includes/breadcrumb.php';

if (file_exists($breadcrumbFile)) {
    require_once $breadcrumbFile;

    if (
        function_exists('buildBreadcrumb')
        && function_exists('renderBreadcrumb')
    ) {
        $crumbs = buildBreadcrumb($_GET);

        if (empty($crumbs)) {
            $crumbs = [[
                'label' => 'Audiobooks',
                'color' => '#A78BFA',
                'href' => null
            ]];
        }

        renderBreadcrumb($crumbs);
    }
}

audiobookTimingLog('after breadcrumb', $requestStartedAt);

include_once __DIR__ . "/footer.php";

audiobookTimingLog('after footer', $requestStartedAt);

if ($cacheFile !== null && function_exists('pageCache_end')) {
    pageCache_end($cacheFile);
}

audiobookTimingLog('request complete', $requestStartedAt);
?>
