<?php ob_start(); ?>
  <link href="css/tutorials.css" rel="stylesheet">
<?php
    // navbar
    include_once "navbar.php";
    require_once __DIR__ . '/includes/db-content.php';

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

    // FRE-54: Query subcategories from the database instead of glob()
    $pdo = getDbConnection();
    $subcategories = getSubcategories($pdo, $decryption);
    $totalVideos = getCategoryVideoCount($pdo, $decryption);
?>

<!-- Page header -->
<div class="tutorials-header">
    <span class="fa fa-fw fa-bookmark"></span><?php echo htmlspecialchars($decryption); ?> Tutorials
    <span class="tut-header-count">(<?php echo $totalVideos; ?> video<?php echo $totalVideos !== 1 ? 's' : ''; ?>)</span>
</div>

<?php
    $sectionIdx = 0;

    foreach ($subcategories as $sub) {
        $folderName = $sub['subcategory'];
        $videoCount = (int) $sub['video_count'];
        $firstThumb = $sub['first_thumb'];

        $sectionIdx++;

        // Section thumbnail from DB
        $thumbSrc = 'images/sample.png';
        if (!empty($firstThumb)) {
            $thumbSrc = str_replace('\\', '/', $firstThumb);
            $thumbSrc = ltrim($thumbSrc, '/');
        }
?>

<div class="tut-section" id="tut-sec-<?php echo $sectionIdx; ?>"
     data-category="<?php echo htmlspecialchars($decryption); ?>"
     data-subcategory="<?php echo htmlspecialchars($folderName); ?>">
    <button class="tut-section-toggle" onclick="toggleSection(this)" aria-expanded="false">
        <img src="<?php echo htmlspecialchars($thumbSrc); ?>" alt="" class="tut-thumb" loading="lazy">
        <div class="tut-section-info">
            <p class="tut-section-title"><?php echo htmlspecialchars(prettyName($folderName)); ?></p>
            <div class="tut-section-count"><?php echo $videoCount; ?> video<?php echo $videoCount !== 1 ? 's' : ''; ?></div>
        </div>
        <span class="fa fa-chevron-down tut-chevron"></span>
    </button>
    <div class="tut-section-body">
        <ul class="tut-video-list">
            <!-- FRE-54: Videos loaded via AJAX on expand -->
        </ul>
        <div class="tut-loading" style="display:none; text-align:center; padding:20px;">
            <span class="fa fa-spinner fa-spin"></span> Loading videos&hellip;
        </div>
    </div>
</div>

<?php } // end foreach

    if ($sectionIdx === 0) {
        echo '<div class="tut-empty">No tutorials found in this category.</div>';
    }
?>

<script>
/**
 * FRE-54: Toggle section and lazy-load videos via AJAX.
 */
function toggleSection(btn) {
    var section = btn.closest('.tut-section');
    section.classList.toggle('open');
    var expanded = section.classList.contains('open');
    btn.setAttribute('aria-expanded', expanded);

    if (!expanded) return;

    // If videos are already loaded, do nothing
    var videoList = section.querySelector('.tut-video-list');
    if (videoList.children.length > 0) return;

    // Load videos via AJAX
    var category    = section.getAttribute('data-category');
    var subcategory = section.getAttribute('data-subcategory');
    var loader      = section.querySelector('.tut-loading');

    loader.style.display = 'block';

    var params = 'category=' + encodeURIComponent(category)
               + '&subcategory=' + encodeURIComponent(subcategory);

    // Pass breadcrumb context if present
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('seg'))   params += '&seg='   + encodeURIComponent(urlParams.get('seg'));
    if (urlParams.get('topic')) params += '&topic=' + encodeURIComponent(urlParams.get('topic'));

    var xhr = new XMLHttpRequest();
    xhr.open('GET', '/api/subcategory-videos.php?' + params, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        loader.style.display = 'none';

        if (xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                videoList.innerHTML = data.html;
            } catch (e) {
                videoList.innerHTML = '<li class="tut-video-item" style="color:#999;">Failed to load videos.</li>';
            }
        } else {
            videoList.innerHTML = '<li class="tut-video-item" style="color:#999;">Failed to load videos.</li>';
        }
    };
    xhr.send();
}
</script>

<?php
    // FRE-12: Breadcrumb
    require_once 'includes/breadcrumb.php';
    $crumbs = buildBreadcrumb($_GET, null, false, $decryption);
    renderBreadcrumb($crumbs);

    include_once "footer.php";
?>
