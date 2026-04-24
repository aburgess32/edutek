<?php ob_start(); ?>
  <link href="css/tutorials.css" rel="stylesheet">
<?php
    // navbar
    include_once "navbar.php";

    $cipher = CONTENT_CIPHER;
    $iv_length = openssl_cipher_iv_length($cipher);
    $options = 0;
    $iv = CONTENT_CIPHER_IV;
    $encryption_key = CONTENT_CIPHER_KEY;
    $decryption_iv = CONTENT_CIPHER_IV;
    $decryption_key = CONTENT_CIPHER_KEY;

    // Helper: encrypt a value for URL params
    function encParam($val, $cipher, $key, $opts, $iv) {
        return str_replace('=', '[equal]', base64_encode(openssl_encrypt($val, $cipher, $key, $opts, $iv)));
    }

    // Helper: turn a filename into a human-readable title
    function prettyName($filename) {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = str_replace(['_', '-'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', trim($name));
        return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }

    // Helper: generate a thumbnail from a video at the 10-second mark.
    // Caches in a thumbs/ directory next to the video. Returns path or fallback.
    function videoThumb($videoPath, $fallback = 'images/sample.png') {
        $dir = dirname($videoPath);
        $thumbDir = $dir . '/thumbs';
        $base = pathinfo($videoPath, PATHINFO_FILENAME);
        $thumbFile = $thumbDir . '/' . $base . '.jpg';

        // Return cached thumbnail if it exists
        if (file_exists($thumbFile)) {
            return $thumbFile;
        }

        // Ensure thumbs directory exists
        if (!is_dir($thumbDir)) {
            @mkdir($thumbDir, 0755, true);
        }

        // Generate thumbnail at 10s mark (fall back to 1s for short videos)
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

        return file_exists($thumbFile) ? $thumbFile : $fallback;
    }

    // Encrypted GET-param keys (same as before)
    $encryption2 = encParam("course", $cipher, $encryption_key, $options, $iv);
    $videolink1  = encParam("videolink1",  $cipher, $encryption_key, $options, $iv);
    $videoname1  = encParam("videoname1",  $cipher, $encryption_key, $options, $iv);
    $videolink   = encParam("videolink",   $cipher, $encryption_key, $options, $iv);
    $videoname   = encParam("videoname",   $cipher, $encryption_key, $options, $iv);

    $file = $_GET[$encryption2];
    $decryption = openssl_decrypt(base64_decode(str_replace('[equal]', '=', $file)), $cipher, $decryption_key, $options, $iv);

    // FRE-12: Propagate breadcrumb context
    $bcQuery = '';
    if (isset($_GET['seg'])   && $_GET['seg']   !== '') { $bcQuery .= '&seg='   . urlencode($_GET['seg']); }
    if (isset($_GET['topic']) && $_GET['topic'] !== '') { $bcQuery .= '&topic=' . urlencode($_GET['topic']); }

    // FRE-DB: Load subcategories and videos from content_meta (not filesystem)
    $contentRoot = rtrim(CONTENT_PATH, '/');
    $sectionIdx  = 0;

    try {
        require_once __DIR__ . '/includes/auth.php';
        $pdo = getDbConnection();

        // Get distinct subcategories for this category, ordered
        $stmt = $pdo->prepare(
            "SELECT DISTINCT subcategory FROM content_meta
             WHERE category = :cat AND subcategory != ''
             ORDER BY subcategory ASC"
        );
        $stmt->execute([':cat' => $decryption]);
        $subcategories = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Also get any files directly in the category (no subcategory)
        $stmtDirect = $pdo->prepare(
            "SELECT title, file_path, thumbnail_path FROM content_meta
             WHERE category = :cat AND (subcategory = '' OR subcategory IS NULL)
             ORDER BY title ASC"
        );
        $stmtDirect->execute([':cat' => $decryption]);
        $directVideos = $stmtDirect->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $subcategories = [];
        $directVideos  = [];
    }
?>

<!-- Page header -->
<div class="tutorials-header">
    <span class="fa fa-fw fa-bookmark"></span><?php echo htmlspecialchars($decryption); ?> Tutorials
</div>

<?php
    // Helper: build full server path from DB file_path
    function contentServerPath($filePath, $contentRoot) {
        return $contentRoot . '/' . ltrim($filePath, '/');
    }

    // Render subcategory sections from DB
    foreach ($subcategories as $folderName) {
        // Get all videos in this subcategory
        $stmt2 = $pdo->prepare(
            "SELECT title, file_path, thumbnail_path FROM content_meta
             WHERE category = :cat AND subcategory = :sub
             ORDER BY title ASC"
        );
        $stmt2->execute([':cat' => $decryption, ':sub' => $folderName]);
        $videos = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        if (empty($videos)) continue;

        $sectionIdx++;
        $sectionSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $folderName));

        $folderPath    = $contentRoot . '/' . $decryption . '/' . $folderName . '/';
        $encFolderName = encParam($folderName, $cipher, $encryption_key, $options, $iv);
        $encFolderPath = encParam($folderPath, $cipher, $encryption_key, $options, $iv);
        $sectionHref   = "watch.php?&{$videolink1}=&{$videoname1}=&{$videolink}={$encFolderPath}&{$videoname}={$encFolderName}{$bcQuery}";

        // Thumbnail: use DB thumbnail or fallback
        $firstThumb = !empty($videos[0]['thumbnail_path']) ? $videos[0]['thumbnail_path'] : 'images/sample.png';
        $thumbSrc   = htmlspecialchars($firstThumb);
?>

<div class="tut-section" id="tut-sec-<?php echo htmlspecialchars($sectionSlug, ENT_QUOTES, 'UTF-8'); ?>">
    <button class="tut-section-toggle" onclick="toggleSection(this)" aria-expanded="false">
        <img src="<?php echo $thumbSrc; ?>" alt="" class="tut-thumb">
        <div class="tut-section-info">
            <p class="tut-section-title"><?php echo htmlspecialchars($folderName); ?></p>
            <div class="tut-section-count"><?php echo count($videos); ?> video<?php echo count($videos) !== 1 ? 's' : ''; ?></div>
        </div>
        <span class="fa fa-chevron-down tut-chevron"></span>
    </button>
    <div class="tut-section-body">
        <ul class="tut-video-list">
<?php
        $vidNum = 0;
        foreach ($videos as $vid) {
            $vidNum++;
            $filePath  = $vid['file_path'];
            $fullPath  = $contentRoot . '/' . ltrim($filePath, '/');
            $baseName  = basename($filePath);
            $ext       = strtoupper(pathinfo($baseName, PATHINFO_EXTENSION));
            $title     = $vid['title'] ?: prettyName($baseName);
            $thumbSrc  = htmlspecialchars($vid['thumbnail_path'] ?: 'images/sample.png');

            $encFilePath = encParam($folderPath,  $cipher, $encryption_key, $options, $iv);
            $encFileName = encParam($folderName,  $cipher, $encryption_key, $options, $iv);
            $encVidPath  = encParam($fullPath,    $cipher, $encryption_key, $options, $iv);
            $encVidName  = encParam($baseName,    $cipher, $encryption_key, $options, $iv);
            $vidHref = "watch.php?&{$videolink}={$encFilePath}&{$videoname}={$encFileName}&{$videolink1}={$encVidPath}&{$videoname1}={$encVidName}{$bcQuery}";
?>
            <li class="tut-video-item">
                <span class="tut-vid-num"><?php echo $vidNum; ?></span>
                <img src="<?php echo $thumbSrc; ?>" alt="" class="tut-vid-thumb">
                <a href="<?php echo $vidHref; ?>" class="tut-vid-name" title="<?php echo htmlspecialchars($baseName); ?>">
                    <?php echo htmlspecialchars($title); ?>
                </a>
                <span class="tut-vid-ext"><?php echo $ext; ?></span>
            </li>
<?php   } ?>
        </ul>
    </div>
</div>

<?php } // end foreach subcategories

    if ($sectionIdx === 0) {
        echo '<div class="tut-empty">No tutorials found in this category yet — content is still being indexed.</div>';
    }
?>

<script>
function toggleSection(btn) {
    var section = btn.closest('.tut-section');
    section.classList.toggle('open');
    var expanded = section.classList.contains('open');
    btn.setAttribute('aria-expanded', expanded);
}
</script>

<?php
    // FRE-12: Breadcrumb
    require_once 'includes/breadcrumb.php';
    $crumbs = buildBreadcrumb($_GET, null, false, $decryption);
    renderBreadcrumb($crumbs);

    include_once "footer.php";
?>
