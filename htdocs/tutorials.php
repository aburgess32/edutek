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
    // Strips extension, replaces underscores/hyphens with spaces, title-cases
    function prettyName($filename) {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = str_replace(['_', '-'], ' ', $name);
        // Collapse multiple spaces
        $name = preg_replace('/\s+/', ' ', trim($name));
        return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
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

        // Thumbnail: look for <folder>.jpg next to the folder
        $thumbPath = $dd2 . $folderName . '.jpg';
        $thumbSrc  = file_exists($thumbPath) ? htmlspecialchars($thumbPath) : 'images/sample.png';
?>

<div class="tut-section<?php echo ($sectionIdx <= 2) ? ' open' : ''; ?>" id="tut-sec-<?php echo $sectionIdx; ?>">
    <button class="tut-section-toggle" onclick="toggleSection(this)" aria-expanded="<?php echo ($sectionIdx <= 2) ? 'true' : 'false'; ?>">
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
?>
            <li class="tut-video-item">
                <span class="tut-vid-num"><?php echo $vidNum; ?></span>
                <span class="tut-vid-icon"><i class="fa fa-play-circle"></i></span>
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
