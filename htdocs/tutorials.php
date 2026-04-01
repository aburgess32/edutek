<?php ob_start(); ?>
  <link href="css/tutorials.css" rel="stylesheet">
<?php
    // navbar
    include_once "navbar.php";

    $cipher = "BF-CBC";
    $iv_length = openssl_cipher_iv_length($cipher);
    $options = 0;
    $iv = "91011121";
    $encryption_key = "hfjfydjnvhbjfi";
    $decryption_iv = "91011121";
    $decryption_key = "hfjfydjnvhbjfi";

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
            'ffmpeg -ss 10 -i %s -frames:v 1 -vf "scale=160:90:force_original_aspect_ratio=decrease,pad=160:90:(ow-iw)/2:(oh-ih)/2" -q:v 4 %s 2>/dev/null',
            escapeshellarg($videoPath),
            escapeshellarg($thumbFile)
        );
        @exec($cmd);

        // If 10s failed (video shorter than 10s), try 1s
        if (!file_exists($thumbFile)) {
            $cmd = sprintf(
                'ffmpeg -ss 1 -i %s -frames:v 1 -vf "scale=160:90:force_original_aspect_ratio=decrease,pad=160:90:(ow-iw)/2:(oh-ih)/2" -q:v 4 %s 2>/dev/null',
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

    $dd2    = "videos/" . $decryption . "/";
    $length = strlen($dd2);
    $ff2    = glob($dd2 . "*");
?>

<!-- Page header -->
<div class="tutorials-header">
    <span class="fa fa-fw fa-bookmark"></span><?php echo htmlspecialchars($decryption); ?> Tutorials
</div>

<?php
    $videoExts  = ['mp4','mov','wmv','flv','avi','webm','mkv','f4v'];
    $sectionIdx = 0;

    foreach ($ff2 as $value) {
        if (!is_dir($value)) continue;

        $folderName  = substr($value, $length);
        $subFiles    = glob($value . "/*");
        $length12    = strlen($value . "/");

        // Filter to video files only
        $videoFiles = [];
        foreach ($subFiles as $sf) {
            $ext = strtolower(pathinfo($sf, PATHINFO_EXTENSION));
            if (in_array($ext, $videoExts)) {
                $videoFiles[] = $sf;
            }
        }
        if (count($videoFiles) === 0) continue;

        $sectionIdx++;

        // Encrypted params for the section-level link (plays first video)
        $encFolderName = encParam($folderName, $cipher, $encryption_key, $options, $iv);
        $encFolderPath = encParam($value,      $cipher, $encryption_key, $options, $iv);
        $sectionHref = "watch.php?&{$videolink1}=&{$videoname1}=&{$videolink}={$encFolderPath}&{$videoname}={$encFolderName}{$bcQuery}";

        // Section thumbnail: use first video's auto-generated thumbnail
        $firstVidThumb = videoThumb($videoFiles[0]);
        $thumbSrc = htmlspecialchars($firstVidThumb);
?>

<div class="tut-section" id="tut-sec-<?php echo $sectionIdx; ?>">
    <button class="tut-section-toggle" onclick="toggleSection(this)" aria-expanded="false">
        <img src="<?php echo $thumbSrc; ?>" alt="" class="tut-thumb">
        <div class="tut-section-info">
            <p class="tut-section-title"><?php echo htmlspecialchars(prettyName($folderName)); ?></p>
            <div class="tut-section-count"><?php echo count($videoFiles); ?> video<?php echo count($videoFiles) !== 1 ? 's' : ''; ?></div>
        </div>
        <span class="fa fa-chevron-down tut-chevron"></span>
    </button>
    <div class="tut-section-body">
        <ul class="tut-video-list">
<?php
        $vidNum = 0;
        foreach ($videoFiles as $vf) {
            $vidNum++;
            $baseName = substr($vf, $length12);
            $ext      = strtoupper(pathinfo($baseName, PATHINFO_EXTENSION));

            $encFilePath = encParam($value . "/", $cipher, $encryption_key, $options, $iv);
            $encFileName = encParam($folderName,   $cipher, $encryption_key, $options, $iv);
            $encVidPath  = encParam($vf,           $cipher, $encryption_key, $options, $iv);
            $encVidName  = encParam($baseName,     $cipher, $encryption_key, $options, $iv);
            $vidHref = "watch.php?&{$videolink}={$encFilePath}&{$videoname}={$encFileName}&{$videolink1}={$encVidPath}&{$videoname1}={$encVidName}{$bcQuery}";
            $vidThumbSrc = htmlspecialchars(videoThumb($vf));
?>
            <li class="tut-video-item">
                <span class="tut-vid-num"><?php echo $vidNum; ?></span>
                <img src="<?php echo $vidThumbSrc; ?>" alt="" class="tut-vid-thumb">
                <a href="<?php echo $vidHref; ?>" class="tut-vid-name" title="<?php echo htmlspecialchars($baseName); ?>">
                    <?php echo htmlspecialchars(prettyName($baseName)); ?>
                </a>
                <span class="tut-vid-ext"><?php echo $ext; ?></span>
            </li>
<?php   } ?>
        </ul>
    </div>
</div>

<?php } // end foreach

    if ($sectionIdx === 0) {
        echo '<div class="tut-empty">No tutorials found in this category.</div>';
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
