<?php ob_start(); ?>
  <link href="css/tutorials.css" rel="stylesheet">
<?php
    // navbar
    include_once "navbar.php";
    require_once "includes/loading.php";
    loadingStart('Loading tutorials...');
    require_once "includes/page-cache.php";
    $cacheFile = pageCache_start("tutorials-" . md5($_SERVER["QUERY_STRING"] ?? ""));
    if ($cacheFile === null) { loadingEnd(); exit; } // served from cache

    require_once "includes/tutorial-renderer.php";

    $cipher = "BF-CBC";
    $iv_length = openssl_cipher_iv_length($cipher);
    $options = 0;
    $iv = "91011121";
    $encryption_key = "hfjfydjnvhbjfi";
    $decryption_iv = "91011121";
    $decryption_key = "hfjfydjnvhbjfi";

    $enc = [$cipher, $encryption_key, $options, $iv];

    // Encrypted GET-param keys (same as before)
    $encryption2 = tutRenderer_encParam("course", $cipher, $encryption_key, $options, $iv);
    $videolink1  = tutRenderer_encParam("videolink1",  $cipher, $encryption_key, $options, $iv);
    $videoname1  = tutRenderer_encParam("videoname1",  $cipher, $encryption_key, $options, $iv);
    $videolink   = tutRenderer_encParam("videolink",   $cipher, $encryption_key, $options, $iv);
    $videoname   = tutRenderer_encParam("videoname",   $cipher, $encryption_key, $options, $iv);

    $encKeys = [$encryption2, $videolink1, $videoname1, $videolink, $videoname];

    $file = $_GET[$encryption2];
    $decryption = openssl_decrypt(base64_decode(str_replace('[equal]', '=', $file)), $cipher, $decryption_key, $options, $iv);

    // FRE-12: Propagate breadcrumb context
    $bcQuery = '';
    if (isset($_GET['seg'])   && $_GET['seg']   !== '') { $bcQuery .= '&seg='   . urlencode($_GET['seg']); }
    if (isset($_GET['topic']) && $_GET['topic'] !== '') { $bcQuery .= '&topic=' . urlencode($_GET['topic']); }

    $dd2 = "videos/" . $decryption . "/";

    // FRE-54: Render only the first 20 sections (server-side pagination)
    $pageLimit = 20;
    $result = renderTutorialSections($dd2, 0, $pageLimit, $encKeys, $enc, $bcQuery);

    loadingEnd();
?>

<!-- Page header -->
<div class="tutorials-header">
    <span class="fa fa-fw fa-bookmark"></span><?php echo htmlspecialchars($decryption); ?> Tutorials
</div>

<div id="tutorial-sections">
<?php echo $result['html']; ?>
</div>

<?php if ($result['total'] === 0): ?>
    <div class="tut-empty">No tutorials found in this category.</div>
<?php elseif ($result['hasMore']): ?>
<div id="load-more-container"
     data-course="<?php echo htmlspecialchars($file); ?>"
     data-offset="<?php echo $result['loaded']; ?>"
     data-total="<?php echo $result['total']; ?>"
     data-seg="<?php echo htmlspecialchars($_GET['seg'] ?? ''); ?>"
     data-topic="<?php echo htmlspecialchars($_GET['topic'] ?? ''); ?>">
    <button id="load-more-btn" onclick="loadMoreSections()">
        Load More (showing <?php echo $result['loaded']; ?> of <?php echo $result['total']; ?> sections)
    </button>
</div>
<?php endif; ?>

<?php
    if (ob_get_level()) ob_flush();
    flush();
?>

<script>
function toggleSection(btn) {
    var section = btn.closest('.tut-section');
    section.classList.toggle('open');
    var expanded = section.classList.contains('open');
    btn.setAttribute('aria-expanded', expanded);
}

function loadMoreSections() {
    var container = document.getElementById('load-more-container');
    var btn = document.getElementById('load-more-btn');
    if (!container || !btn) return;

    var course = container.getAttribute('data-course');
    var offset = parseInt(container.getAttribute('data-offset'), 10);
    var total  = parseInt(container.getAttribute('data-total'), 10);
    var seg    = container.getAttribute('data-seg');
    var topic  = container.getAttribute('data-topic');

    // Show loading state
    btn.disabled = true;
    btn.innerHTML = '<span class="load-more-spinner"></span> Loading...';

    var url = 'api/tutorials-page.php?course=' + encodeURIComponent(course)
            + '&offset=' + offset + '&limit=20';
    if (seg)   url += '&seg='   + encodeURIComponent(seg);
    if (topic) url += '&topic=' + encodeURIComponent(topic);

    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                // Append new sections before the Load More button
                var sections = document.getElementById('tutorial-sections');
                sections.insertAdjacentHTML('beforeend', data.html);

                // Trigger lazy-load for any new thumbnail placeholders
                initLazyThumbs(sections);

                if (data.hasMore) {
                    container.setAttribute('data-offset', data.loaded);
                    btn.disabled = false;
                    btn.textContent = 'Load More (showing ' + data.loaded + ' of ' + data.total + ' sections)';
                } else {
                    container.style.display = 'none';
                }
            } catch(e) {
                btn.disabled = false;
                btn.textContent = 'Error loading — tap to retry';
            }
        } else {
            btn.disabled = false;
            btn.textContent = 'Error loading — tap to retry';
        }
    };
    xhr.onerror = function() {
        btn.disabled = false;
        btn.textContent = 'Error loading — tap to retry';
    };
    xhr.send();
}

// Lazy-load thumbnails for videos that don't have cached thumbs yet
function initLazyThumbs(root) {
    var imgs = (root || document).querySelectorAll('img[data-video-src]');
    var queue = [];
    for (var i = 0; i < imgs.length; i++) {
        // Only queue images that haven't been processed
        if (!imgs[i].getAttribute('data-thumb-queued')) {
            imgs[i].setAttribute('data-thumb-queued', '1');
            queue.push(imgs[i]);
        }
    }
    function processNext() {
        if (queue.length === 0) return;
        var img = queue.shift();
        var videoSrc = img.getAttribute('data-video-src');
        if (!videoSrc) { processNext(); return; }
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'api/generate-thumb.php?video=' + encodeURIComponent(videoSrc), true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.thumb) {
                        img.src = data.thumb;
                        img.removeAttribute('data-video-src');
                    }
                } catch(e) {}
            }
            processNext();
        };
        xhr.onerror = function() { processNext(); };
        xhr.send();
    }
    // Process 2 thumbnails at a time
    processNext();
    processNext();
}

// Initial lazy-load on page load
initLazyThumbs();
</script>

<style>
#load-more-container {
    text-align: center;
    padding: 20px 0 30px;
}
#load-more-btn {
    background: #6366f1;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 12px 32px;
    font-size: 15px;
    cursor: pointer;
    transition: background 0.2s;
}
#load-more-btn:hover:not(:disabled) {
    background: #4f46e5;
}
#load-more-btn:disabled {
    opacity: 0.7;
    cursor: wait;
}
.load-more-spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: edupak-spin 0.8s linear infinite;
    vertical-align: middle;
    margin-right: 6px;
}
</style>

<?php
    // FRE-12: Breadcrumb
    require_once 'includes/breadcrumb.php';
    $crumbs = buildBreadcrumb($_GET, null, false, $decryption);
    renderBreadcrumb($crumbs);

    include_once "footer.php";
    pageCache_end($cacheFile);
?>
