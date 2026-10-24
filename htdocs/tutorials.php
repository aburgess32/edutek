<?php ob_start(); ?>
<link href="css/tutorials.css" rel="stylesheet">
<?php
include_once "navbar.php";
require_once __DIR__ . '/includes/tiles.php';

$cipher = CONTENT_CIPHER;
$options = 0;
$iv = CONTENT_CIPHER_IV;
$encryption_key = CONTENT_CIPHER_KEY;
$decryption_key = CONTENT_CIPHER_KEY;

function encParam($val, $cipher, $key, $opts, $iv) {
    return str_replace('=', '[equal]', base64_encode(openssl_encrypt($val, $cipher, $key, $opts, $iv)));
}

function prettyName($filename) {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = str_replace(['_', '-'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', trim($name));
    return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
}

function findSubcategoryThumb($category, $subcategory, $contentRoot) {
    $base = rtrim($contentRoot, '/') . '/' . $category . '/' . $subcategory;
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $path = $base . '.' . $ext;
        if (file_exists($path)) {
            return contentWebUrl($category . '/' . $subcategory . '.' . $ext);
        }
    }
    return null;
}

function detectContentTypeFromPath($filePath) {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    $videoExts = ['mp4', 'm4v', 'mov', 'avi', 'mkv', 'webm', 'mpeg', 'mpg'];
    $audioExts = ['mp3', 'm4a', 'aac', 'wav', 'ogg'];
    $pdfExts   = ['pdf'];
    $bookExts  = ['epub', 'mobi', 'azw', 'azw3'];

    if (in_array($ext, $videoExts, true)) return 'video';
    if (in_array($ext, $audioExts, true)) return 'audiobook';
    if (in_array($ext, $pdfExts, true)) return 'pdf';
    if (in_array($ext, $bookExts, true)) return 'book';

    return 'file';
}

function contentTypeLabel($type) {
    switch ($type) {
        case 'video': return 'Video';
        case 'audiobook': return 'Audio';
        case 'pdf': return 'PDF';
        case 'book': return 'Book';
        default: return 'File';
    }
}

function contentTypeIcon($type) {
    switch ($type) {
        case 'video': return 'fa-film';
        case 'audiobook': return 'fa-headphones';
        case 'pdf': return 'fa-file-pdf-o';
        case 'book': return 'fa-book';
        default: return 'fa-file';
    }
}

function buildAbsoluteContentPath($filePath, $contentRoot) {
    $filePath = trim((string)$filePath);
    $contentRoot = rtrim(trim((string)$contentRoot), '/');

    if ($filePath === '') {
        return '';
    }

    if ($filePath[0] === '/') {
        return $filePath;
    }

    $filePath = ltrim($filePath, '/');
    $rootBase = basename($contentRoot);

    if ($rootBase !== '' && ($filePath === $rootBase || strpos($filePath, $rootBase . '/') === 0)) {
        return '/' . $filePath;
    }

    return $contentRoot . '/' . $filePath;
}

function buildContentHref($type, $folderPath, $folderName, $fullPath, $baseName, $cipher, $key, $options, $iv, $videolink, $videoname, $videolink1, $videoname1, $bcQuery) {
    $encFilePath = encParam($folderPath, $cipher, $key, $options, $iv);
    $encFileName = encParam($folderName, $cipher, $key, $options, $iv);
    $encVidPath  = encParam($fullPath, $cipher, $key, $options, $iv);
    $encVidName  = encParam($baseName, $cipher, $key, $options, $iv);

    if ($type === 'pdf' || $type === 'book') {
        return "readpdf.php?&{$videolink}={$encFilePath}&{$videoname}={$encFileName}&{$videolink1}={$encVidPath}&{$videoname1}={$encVidName}{$bcQuery}";
    }

    if ($type === 'audiobook') {
        return "listen.php?&{$videolink}={$encFilePath}&{$videoname}={$encFileName}&{$videolink1}={$encVidPath}&{$videoname1}={$encVidName}{$bcQuery}";
    }

    if ($type === 'video') {
        return "watch.php?&{$videolink}={$encFilePath}&{$videoname}={$encFileName}&{$videolink1}={$encVidPath}&{$videoname1}={$encVidName}{$bcQuery}";
    }

    return "#";
}

$encryption2 = encParam("course", $cipher, $encryption_key, $options, $iv);
$videolink1  = encParam("videolink1", $cipher, $encryption_key, $options, $iv);
$videoname1  = encParam("videoname1", $cipher, $encryption_key, $options, $iv);
$videolink   = encParam("videolink", $cipher, $encryption_key, $options, $iv);
$videoname   = encParam("videoname", $cipher, $encryption_key, $options, $iv);

$file = $_GET[$encryption2] ?? '';

$decryption = '';
if ($file !== '') {
    $safeFile = str_replace('[equal]', '=', $file);
    $decodedFile = base64_decode($safeFile, true);

    if ($decodedFile !== false) {
        $tmp = openssl_decrypt($decodedFile, $cipher, $decryption_key, $options, $iv);
        if ($tmp !== false && $tmp !== null) {
            $decryption = $tmp;
        }
    }
}

$bcQuery = '';
if (isset($_GET['seg']) && $_GET['seg'] !== '') { $bcQuery .= '&seg=' . urlencode($_GET['seg']); }
if (isset($_GET['topic']) && $_GET['topic'] !== '') { $bcQuery .= '&topic=' . urlencode($_GET['topic']); }

$contentRoot = rtrim(CONTENT_PATH, '/');
$sectionIdx  = 0;

try {
    require_once __DIR__ . '/includes/auth.php';
    $pdo = getDbConnection();

    $stmt = $pdo->prepare(
        "SELECT DISTINCT subcategory FROM content_meta
         WHERE category = :cat AND subcategory != ''
         ORDER BY subcategory ASC"
    );
    $stmt->execute([':cat' => $decryption]);
    $subcategories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $subcategories = [];
}

// Patch 5: items stored directly in the category folder (no subfolder) have
// subcategory = '' in content_meta. List them in their own section.
$looseItems = [];
if ($decryption !== '' && isset($pdo)) {
    try {
        $stmtLoose = $pdo->prepare(
            "SELECT content_id, title, file_path, thumbnail_path
             FROM content_meta
             WHERE category = :cat AND (subcategory = '' OR subcategory IS NULL)
             ORDER BY title ASC"
        );
        $stmtLoose->execute([':cat' => $decryption]);
        foreach ($stmtLoose->fetchAll(PDO::FETCH_ASSOC) as $looseRow) {
            // Skip catalog rows whose file is gone, so no dead links are shown.
            $looseAbs = buildAbsoluteContentPath($looseRow['file_path'], $contentRoot);
            if ($looseAbs !== '' && is_file($looseAbs)) {
                $looseItems[] = $looseRow;
            }
        }
    } catch (Exception $e) {
        $looseItems = [];
    }
}
?>
<style>
/* Patch 5: collapsed sections are skipped by Tab; clear keyboard focus ring */
.tut-section:not(.open) .tut-section-body { visibility: hidden; transition: max-height 0.35s ease, visibility 0s linear 0.35s; }
.tut-section.open .tut-section-body { visibility: visible; transition: max-height 0.35s ease, visibility 0s; }
.tut-section-toggle:focus-visible, .tut-video-item .tut-vid-name:focus-visible { outline: 3px solid #f0b429; outline-offset: 2px; }
</style>

<div class="tutorials-header">
    <span class="fa fa-fw fa-bookmark"></span><?php echo htmlspecialchars($decryption ?: 'Library'); ?> Library
</div>

<?php
if (!empty($looseItems)) {
    $sectionIdx++;
    $looseFolderPath = $contentRoot . '/' . $decryption . '/';
    $looseFolderName = $decryption;

    $looseTypeCounts = [];
    $looseThumb = 'images/sample.png';
    foreach ($looseItems as $tmpItem) {
        $tmpType = detectContentTypeFromPath($tmpItem['file_path']);
        $looseTypeCounts[$tmpType] = ($looseTypeCounts[$tmpType] ?? 0) + 1;
        if ($looseThumb === 'images/sample.png' && !empty($tmpItem['thumbnail_path'])) {
            $looseThumb = contentWebUrl($tmpItem['thumbnail_path']);
        }
    }
    $looseTitle = 'Items in this category';
    $looseLabel = 'items';
    if (count($looseTypeCounts) === 1) {
        $onlyType = array_key_first($looseTypeCounts);
        if ($onlyType === 'video') { $looseTitle = 'Videos in this category'; $looseLabel = 'videos'; }
        elseif ($onlyType === 'pdf' || $onlyType === 'book') { $looseTitle = 'Books in this category'; $looseLabel = 'books'; }
        elseif ($onlyType === 'audiobook') { $looseTitle = 'Audio in this category'; $looseLabel = 'audio files'; }
    }
    if (count($looseItems) === 1) {
        $looseLabel = rtrim($looseLabel, 's');
    }
    $looseOpen = empty($subcategories);
?>
<div class="tut-section tut-section--loose<?php echo $looseOpen ? ' open' : ''; ?>" id="tut-sec--loose">
    <button class="tut-section-toggle" onclick="toggleSection(this)" aria-expanded="<?php echo $looseOpen ? 'true' : 'false'; ?>" aria-controls="tut-sec--loose-body">
        <img src="<?php echo htmlspecialchars($looseThumb, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="tut-thumb">
        <div class="tut-section-info">
            <p class="tut-section-title"><?php echo htmlspecialchars($decryption ?: 'Library'); ?></p>
            <div class="tut-section-count"><?php echo count($looseItems); ?> <?php echo htmlspecialchars($looseLabel, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <span class="fa fa-chevron-down tut-chevron"></span>
    </button>

    <div class="tut-section-body" id="tut-sec--loose-body">
        <ul class="tut-video-list">
<?php
    $itemNum = 0;
    foreach ($looseItems as $item) {
        $itemNum++;
        $filePath = $item['file_path'];
        $fullPath = buildAbsoluteContentPath($filePath, $contentRoot);
        $baseName = basename($filePath);
        $ext = strtoupper(pathinfo($baseName, PATHINFO_EXTENSION));
        $title = $item['title'] ?: prettyName($baseName);
        $type = detectContentTypeFromPath($filePath);

        $href = buildContentHref(
            $type,
            $looseFolderPath,
            $looseFolderName,
            $fullPath,
            $baseName,
            $cipher,
            $encryption_key,
            $options,
            $iv,
            $videolink,
            $videoname,
            $videolink1,
            $videoname1,
            $bcQuery
        );

        if (!empty($item['thumbnail_path']) && $type === 'video') {
            $thumbHtml = '<img src="' . htmlspecialchars(contentWebUrl($item['thumbnail_path']), ENT_QUOTES, 'UTF-8') . '" alt="" class="tut-vid-thumb">';
        } elseif ($type === 'video') {
            $thumbHtml = '<img src="images/sample.png" data-lazy-thumb="' . htmlspecialchars($item['content_id'], ENT_QUOTES, 'UTF-8') . '" alt="" class="tut-vid-thumb">';
        } else {
            $iconCls = contentTypeIcon($type);
            $thumbHtml = '<span class="tut-vid-thumb tut-vid-thumb--icon"><i class="fa ' . htmlspecialchars($iconCls) . '" aria-hidden="true"></i></span>';
        }
?>
            <li class="tut-video-item">
                <span class="tut-vid-num"><?php echo $itemNum; ?></span>
                <?php echo $thumbHtml; ?>
                <a href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" class="tut-vid-name" title="<?php echo htmlspecialchars($baseName, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                </a>
                <span class="tut-vid-ext"><?php echo htmlspecialchars(contentTypeLabel($type), ENT_QUOTES, 'UTF-8'); ?><?php if ($ext) echo ' &middot; ' . htmlspecialchars($ext, ENT_QUOTES, 'UTF-8'); ?></span>
            </li>
<?php } ?>
        </ul>
    </div>
</div>
<?php } ?>

<?php
foreach ($subcategories as $folderName) {
    $stmt2 = $pdo->prepare(
        "SELECT content_id, title, file_path, thumbnail_path
         FROM content_meta
         WHERE category = :cat AND subcategory = :sub
         ORDER BY title ASC"
    );
    $stmt2->execute([':cat' => $decryption, ':sub' => $folderName]);
    $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) continue;

    $sectionIdx++;
    $sectionSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $folderName));
    $folderPath  = $contentRoot . '/' . $decryption . '/' . $folderName . '/';

    $subcatThumb = findSubcategoryThumb($decryption, $folderName, $contentRoot);
    if ($subcatThumb) {
        $firstThumb = $subcatThumb;
    } elseif (!empty($items[0]['thumbnail_path'])) {
        $firstThumb = contentWebUrl($items[0]['thumbnail_path']);
    } else {
        $firstThumb = 'images/sample.png';
    }

    $thumbSrc = htmlspecialchars($firstThumb, ENT_QUOTES, 'UTF-8');

    $typeCounts = [];
    foreach ($items as $tmpItem) {
        $tmpType = detectContentTypeFromPath($tmpItem['file_path']);
        $typeCounts[$tmpType] = ($typeCounts[$tmpType] ?? 0) + 1;
    }

    $sectionLabel = 'items';
    if (count($typeCounts) === 1) {
        $onlyType = array_key_first($typeCounts);
        if ($onlyType === 'video') $sectionLabel = 'videos';
        elseif ($onlyType === 'pdf' || $onlyType === 'book') $sectionLabel = 'books';
        elseif ($onlyType === 'audiobook') $sectionLabel = 'audio files';
    }
?>
<div class="tut-section" id="tut-sec-<?php echo htmlspecialchars($sectionSlug, ENT_QUOTES, 'UTF-8'); ?>">
    <button class="tut-section-toggle" onclick="toggleSection(this)" aria-expanded="false">
        <img src="<?php echo $thumbSrc; ?>" alt="" class="tut-thumb">
        <div class="tut-section-info">
            <p class="tut-section-title"><?php echo htmlspecialchars($folderName); ?></p>
            <div class="tut-section-count"><?php echo count($items); ?> <?php echo htmlspecialchars($sectionLabel); ?></div>
        </div>
        <span class="fa fa-chevron-down tut-chevron"></span>
    </button>

    <div class="tut-section-body">
        <ul class="tut-video-list">
<?php
    $itemNum = 0;
    foreach ($items as $item) {
        $itemNum++;
        $filePath = $item['file_path'];
        $fullPath = buildAbsoluteContentPath($filePath, $contentRoot);
        $baseName = basename($filePath);
        $ext = strtoupper(pathinfo($baseName, PATHINFO_EXTENSION));
        $title = $item['title'] ?: prettyName($baseName);
        $type = detectContentTypeFromPath($filePath);

        $href = buildContentHref(
            $type,
            $folderPath,
            $folderName,
            $fullPath,
            $baseName,
            $cipher,
            $encryption_key,
            $options,
            $iv,
            $videolink,
            $videoname,
            $videolink1,
            $videoname1,
            $bcQuery
        );

        if (!empty($item['thumbnail_path']) && $type === 'video') {
            $thumbHtml = '<img src="' . htmlspecialchars(contentWebUrl($item['thumbnail_path']), ENT_QUOTES, 'UTF-8') . '" alt="" class="tut-vid-thumb">';
        } elseif ($type === 'video') {
            $thumbHtml = '<img src="images/sample.png" data-lazy-thumb="' . htmlspecialchars($item['content_id'], ENT_QUOTES, 'UTF-8') . '" alt="" class="tut-vid-thumb">';
        } else {
            $iconCls = contentTypeIcon($type);
            $thumbHtml = '<span class="tut-vid-thumb tut-vid-thumb--icon"><i class="fa ' . htmlspecialchars($iconCls) . '" aria-hidden="true"></i></span>';
        }
?>
            <li class="tut-video-item">
                <span class="tut-vid-num"><?php echo $itemNum; ?></span>
                <?php echo $thumbHtml; ?>
                <a href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" class="tut-vid-name" title="<?php echo htmlspecialchars($baseName, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                </a>
                <span class="tut-vid-ext"><?php echo htmlspecialchars(contentTypeLabel($type), ENT_QUOTES, 'UTF-8'); ?><?php if ($ext) echo ' · ' . htmlspecialchars($ext, ENT_QUOTES, 'UTF-8'); ?></span>
            </li>
<?php } ?>
        </ul>
    </div>
</div>
<?php } ?>

<?php
if ($sectionIdx === 0) {
    echo '<div class="tut-empty">No content found in this category yet — content is still being indexed.</div>';
}
?>

<script>
function toggleSection(btn) {
    var section = btn.closest('.tut-section');
    section.classList.toggle('open');
    var expanded = section.classList.contains('open');
    btn.setAttribute('aria-expanded', expanded);
}
// Patch 5: open and focus the section named in the address (#tut-sec-...)
(function () {
    var id = decodeURIComponent((window.location.hash || '').replace(/^#/, ''));
    if (id.indexOf('tut-sec-') !== 0) return;
    var sec = document.getElementById(id);
    if (!sec) return;
    var btn = sec.querySelector('.tut-section-toggle');
    if (!sec.classList.contains('open') && btn) toggleSection(btn);
    if (btn) btn.focus();
})();
</script>

<script src="js/lazy-thumbs.js"></script>

<?php
require_once 'includes/breadcrumb.php';
$crumbs = buildBreadcrumb($_GET, null, false, $decryption);
renderBreadcrumb($crumbs);

include_once "footer.php";
?>
