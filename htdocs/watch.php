<?php ob_start();
    include_once "includes/auth.php";
?>
  <!-- FRE-41: Watch Page Facelift — Fonts + CSS -->
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="css/WatchVideos.css" rel="stylesheet">
  <script src="ajax/jquery.min.js"></script>
    <script src="ajax/popper.min.js"></script>
    <script src="ajax/ajax.js"></script>

<?php
    //navbar
    include_once"navbar.php";
    $cipher = "BF-CBC";
  $iv_length = openssl_cipher_iv_length($cipher);
  $options = 0;
  $iv = "91011121";
  $encryption_key = "hfjfydjnvhbjfi";
  $decryption_iv = "91011121";
  $decryption_key = "hfjfydjnvhbjfi";

    // FRE-12: Propagate breadcrumb context through watch.php links
    $bcQuery = '';
    if (isset($_GET['seg']) && $_GET['seg'] !== '') {
        $bcQuery .= '&seg=' . urlencode($_GET['seg']);
    }
    if (isset($_GET['topic']) && $_GET['topic'] !== '') {
        $bcQuery .= '&topic=' . urlencode($_GET['topic']);
    }

        $videolink1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink1", $cipher, $encryption_key, $options, $iv)));
        $videoname1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname1", $cipher, $encryption_key, $options, $iv)));
        $videolink = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink", $cipher, $encryption_key, $options, $iv)));
        $videoname = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname", $cipher, $encryption_key, $options, $iv)));

     $file = '';
  $file = openssl_decrypt(base64_decode(str_replace('[equal]', '=', $_GET[$videolink])), $cipher, $decryption_key, $options, $iv);

  $file1 = '';
  $file1 = openssl_decrypt(base64_decode(str_replace('[equal]', '=', $_GET[$videoname])), $cipher, $decryption_key, $options, $iv);

  $filea = '';
  $filea = openssl_decrypt(base64_decode(str_replace('[equal]', '=', $_GET[$videolink1])), $cipher, $decryption_key, $options, $iv);

  $file1b = '';
  $file1b = openssl_decrypt(base64_decode(str_replace('[equal]', '=', $_GET[$videoname1])), $cipher, $decryption_key, $options, $iv);

  $dd2 = $file . "/";
  $length = strlen($dd2);
  $ff2 = (glob($dd2 . "*", GLOB_BRACE));
  $ray = array();
  $ff3 = array();
  $video = array('mp4','mov','wmv','flv','f4v','avi','WebM','mkv');

  // FRE-41: Determine current video source and name
  $currentVideoSrc = '';
  $currentVideoName = '';
  if ($filea == '') {
      $files2 = (glob($dd2 . "*", GLOB_BRACE));
      if (!empty($files2) && in_array(pathinfo(substr($files2[0], ($length)), PATHINFO_EXTENSION), $video)) {
          $file1b = substr($files2[0], ($length));
          $currentVideoSrc = $files2[0];
          $currentVideoName = $file1b;
      }
  } else {
      if (in_array(pathinfo($file1b, PATHINFO_EXTENSION), $video)) {
          $currentVideoSrc = $filea;
          $currentVideoName = $file1b;
      }
  }

  // FRE-41: Count videos in this topic for the sidebar header
  $videoCount = 0;
  foreach ($ff2 as $v) {
      if (in_array(pathinfo(substr($v, $length), PATHINFO_EXTENSION), $video)) {
          $videoCount++;
      }
  }

  // Color classes for playlist thumbnails (cycle through)
  $thumbColors = ['', 'playlist-item__thumb--blue', 'playlist-item__thumb--green', 'playlist-item__thumb--orange', 'playlist-item__thumb--purple', 'playlist-item__thumb--teal'];
  $colorIndex = 0;
?>

<!-- FRE-41: Watch Page Grid Layout -->
<div class="watchpage">
  <div class="watch-grid">

    <!-- LEFT COLUMN: Player -->
    <div class="player-col">

      <!-- Breadcrumb -->
      <nav class="wp-breadcrumb" aria-label="Breadcrumb">
        <ol>
          <li><a href="index.php">Home</a></li>
          <li><a href="index.php"><?php echo htmlspecialchars($file1); ?></a></li>
          <li class="wp-bc-current"><?php echo htmlspecialchars($currentVideoName); ?></li>
        </ol>
      </nav>

      <!-- Player Card -->
      <div class="player-card">
        <!-- Video -->
        <div class="player-card__video-wrap">
          <video class="Wvideo" id="wp-video" src="<?php echo htmlspecialchars($currentVideoSrc); ?>" autoplay></video>
          <!-- Keyboard shortcuts hint -->
          <div class="player-shortcut-hint">
            <button class="shortcut-btn" aria-label="Keyboard shortcuts">?</button>
            <div class="shortcut-tooltip">
              <h4>Keyboard Shortcuts</h4>
              <ul>
                <li><kbd>Space</kbd> Play / Pause</li>
                <li><kbd>←</kbd><kbd>→</kbd> Skip 10s</li>
                <li><kbd>↑</kbd><kbd>↓</kbd> Volume</li>
                <li><kbd>F</kbd> Fullscreen</li>
                <li><kbd>M</kbd> Mute</li>
              </ul>
            </div>
          </div>
        </div>

        <!-- Title Bar -->
        <div class="player-card__title-bar">
          <h1 class="player-card__title"><?php echo htmlspecialchars($currentVideoName); ?></h1>
          <?php if (in_array(pathinfo($currentVideoName, PATHINFO_EXTENSION), $video)): ?>
          <a class="btn-download" href="<?php echo htmlspecialchars($file . '/' . $currentVideoName); ?>" download="<?php echo htmlspecialchars($currentVideoName); ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download
          </a>
          <?php endif; ?>
        </div>

        <!-- Custom Controls Bar -->
        <div class="player-controls" id="wp-controls">
          <div class="controls-row">
            <!-- Play/Pause -->
            <button class="ctrl-btn" id="ctrl-play" aria-label="Play">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" class="icon-play"><path d="M8 5v14l11-7z"/></svg>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" class="icon-pause" style="display:none"><path d="M6 4h4v16H6zM14 4h4v16h-4z"/></svg>
            </button>

            <!-- Time -->
            <span class="ctrl-time" id="ctrl-time">0:00 / 0:00</span>

            <!-- Progress Bar -->
            <div class="ctrl-progress" id="ctrl-progress">
              <div class="ctrl-progress__track">
                <div class="ctrl-progress__buffered" id="ctrl-buffered"></div>
                <div class="ctrl-progress__filled" id="ctrl-filled"></div>
                <div class="ctrl-progress__loop-region" id="ctrl-loop-region"></div>
                <div class="ctrl-progress__thumb" id="ctrl-thumb"></div>
              </div>
            </div>

            <!-- Speed -->
            <div class="ctrl-speed-wrap">
              <button class="ctrl-btn ctrl-speed" id="ctrl-speed">
                1x
                <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>
              </button>
              <div class="speed-menu" id="speed-menu">
                <button class="speed-menu__item" data-speed="0.5">0.5x</button>
                <button class="speed-menu__item" data-speed="0.75">0.75x</button>
                <button class="speed-menu__item speed-menu__item--active" data-speed="1">1x</button>
                <button class="speed-menu__item" data-speed="1.25">1.25x</button>
                <button class="speed-menu__item" data-speed="1.5">1.5x</button>
                <button class="speed-menu__item" data-speed="2">2x</button>
              </div>
            </div>

            <!-- CC (placeholder — FRE-42) -->
            <div class="ctrl-cc-wrap">
              <button class="ctrl-btn" id="ctrl-cc" aria-label="Subtitles">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M7 12h2m2 0h2"/><path d="M7 16h4m2 0h4"/></svg>
              </button>
              <div class="ctrl-cc-tooltip">Coming soon (FRE-42)</div>
            </div>

            <!-- Font size -->
            <div class="ctrl-fontsize">
              <button class="ctrl-btn ctrl-btn--small" id="ctrl-font-sm" aria-label="Smaller subtitles">A</button>
              <button class="ctrl-btn ctrl-btn--large" id="ctrl-font-lg" aria-label="Larger subtitles">A</button>
            </div>

            <!-- Loop -->
            <button class="ctrl-btn" id="ctrl-loop" aria-label="A-B Loop">
              <span class="loop-label">A↔B</span>
            </button>

            <!-- Fullscreen -->
            <button class="ctrl-btn" id="ctrl-fullscreen" aria-label="Fullscreen">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 00-2 2v3m18 0V5a2 2 0 00-2-2h-3m0 18h3a2 2 0 002-2v-3M3 16v3a2 2 0 002 2h3"/></svg>
            </button>

            <!-- Volume -->
            <div class="ctrl-volume">
              <button class="ctrl-btn" id="ctrl-mute" aria-label="Volume">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-vol-on"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/><path d="M19.07 4.93a10 10 0 010 14.14"/></svg>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-vol-off" style="display:none"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>
              </button>
              <div class="ctrl-volume__slider" id="ctrl-vol-slider">
                <div class="ctrl-volume__track">
                  <div class="ctrl-volume__filled" id="ctrl-vol-filled" style="width: 100%"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- RIGHT COLUMN: Playlist Sidebar -->
    <aside class="playlist-sidebar">
      <div class="playlist-header">
        <h2 class="playlist-header__title"><?php echo htmlspecialchars($file1); ?></h2>
        <span class="playlist-header__count"><?php echo $videoCount; ?> video<?php echo $videoCount !== 1 ? 's' : ''; ?></span>
      </div>

      <div class="playlist-items">
        <?php
        // FRE-41: Render current video as first playlist item (active)
        if ($currentVideoName !== '') {
            $encryptfile = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file, $cipher, $encryption_key, $options, $iv)));
            $encryptfile1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file1, $cipher, $encryption_key, $options, $iv)));
            $encryptfilea = str_replace('=', '[equal]', base64_encode(openssl_encrypt($currentVideoSrc, $cipher, $encryption_key, $options, $iv)));
            $encryptfile1b = str_replace('=', '[equal]', base64_encode(openssl_encrypt($currentVideoName, $cipher, $encryption_key, $options, $iv)));
            $thumbClass = $thumbColors[$colorIndex % count($thumbColors)];
            $colorIndex++;
        ?>
        <a class="playlist-item playlist-item--active" href="watch.php?&<?php echo $videolink . '=' . $encryptfile . '&' . $videoname . '=' . $encryptfile1 . '&' . $videolink1 . '=' . $encryptfilea . '&' . $videoname1 . '=' . $encryptfile1b . $bcQuery; ?>">
          <div class="playlist-item__indicator">
            <div class="now-playing-badge">Now Playing</div>
          </div>
          <div class="playlist-item__thumb <?php echo $thumbClass; ?>">
            <div class="playlist-item__play-icon">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="#fff"><path d="M8 5v14l11-7z"/></svg>
            </div>
          </div>
          <div class="playlist-item__info">
            <span class="playlist-item__title"><?php echo htmlspecialchars($currentVideoName); ?></span>
            <div class="playlist-item__meta">
              <span class="playlist-item__duration" data-video-src="<?php echo htmlspecialchars($currentVideoSrc); ?>">--:--</span>
            </div>
          </div>
        </a>
        <?php } ?>

        <?php
        // FRE-41: Render other videos in the playlist
        foreach ($ff2 as $key => $value) {
            if (substr($value, ($length)) != $currentVideoName) {
                if (in_array(pathinfo(substr($value, ($length)), PATHINFO_EXTENSION), $video)) {
                    $otherName = substr($value, ($length));
                    $encryptfile = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file, $cipher, $encryption_key, $options, $iv)));
                    $encryptfile1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file1, $cipher, $encryption_key, $options, $iv)));
                    $encryptvalue = str_replace('=', '[equal]', base64_encode(openssl_encrypt($value, $cipher, $encryption_key, $options, $iv)));
                    $encryptname = str_replace('=', '[equal]', base64_encode(openssl_encrypt($otherName, $cipher, $encryption_key, $options, $iv)));
                    $thumbClass = $thumbColors[$colorIndex % count($thumbColors)];
                    $colorIndex++;
        ?>
        <a class="playlist-item" href="watch.php?&<?php echo $videolink . '=' . $encryptfile . '&' . $videoname . '=' . $encryptfile1 . '&' . $videolink1 . '=' . $encryptvalue . '&' . $videoname1 . '=' . $encryptname . $bcQuery; ?>">
          <div class="playlist-item__thumb <?php echo $thumbClass; ?>">
            <div class="playlist-item__play-icon">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="#fff"><path d="M8 5v14l11-7z"/></svg>
            </div>
          </div>
          <div class="playlist-item__info">
            <span class="playlist-item__title"><?php echo htmlspecialchars($otherName); ?></span>
            <div class="playlist-item__meta">
              <span class="playlist-item__duration">--:--</span>
            </div>
          </div>
        </a>
        <?php
                }
            }
        }
        ?>
      </div>
    </aside>

  </div>
</div>

<?php
    // FRE-12: Breadcrumb — watch page shows full 3-level trail
    require_once 'includes/breadcrumb.php';
    $bcVideoTitle = $file1b ?? $file1 ?? null;
    $bcContentPath = $file ?? null;
    $crumbs = buildBreadcrumb($_GET, $bcVideoTitle, true, $bcContentPath);
    // FRE-41: We now render our own breadcrumb, so hide the legacy one
    // renderBreadcrumb($crumbs);
?>

    <!----Footer-->
    <footer class="sticky-footer">
      <div class="container">
        <div class="text-center">
          <small>&copy; <?php echo date('Y'); ?> Community Learning Platform</small>
        </div>
      </div>
    </footer>

</div>

<?php
// FRE-10: Determine thumbnail path server-side for progress tracking
$cwThumbPath = '';
if (!empty($filea)) {
    $cwThumbPath = preg_replace('/\.[^.]+$/', '.jpg', $filea);
    if (!file_exists($cwThumbPath)) {
        $cwFolder = rtrim($file, '/');
        $cwFallback = dirname($cwFolder) . '/' . basename($cwFolder) . '.jpg';
        if (file_exists($cwFallback)) {
            $cwThumbPath = $cwFallback;
        }
    }
}
?>
<?php if (isLoggedIn() && !isGuest() && !empty($filea)): ?>
<script>
(function() {
    var video = document.querySelector('.Wvideo');
    if (!video) return;

    var contentId = <?php echo json_encode($filea); ?>;
    var contentTitle = <?php echo json_encode($file1b); ?>;
    var thumbnailPath = <?php echo json_encode($cwThumbPath); ?>;
    var lastReported = 0;
    var INTERVAL = 30;
    var apiUrl = '/api/update_progress.php';

    function sendProgress() {
        if (!video.duration || video.duration <= 0) return;
        var now = Math.floor(video.currentTime);
        if (now === lastReported) return;
        lastReported = now;

        var data = {
            content_id: contentId,
            content_title: contentTitle,
            content_type: 'video',
            thumbnail_path: thumbnailPath,
            progress_seconds: now,
            duration_seconds: Math.floor(video.duration)
        };
        navigator.sendBeacon(apiUrl, new Blob([JSON.stringify(data)], {type: 'application/json'}));
    }

    var timer = null;
    video.addEventListener('play', function() {
        if (timer) clearInterval(timer);
        timer = setInterval(sendProgress, INTERVAL * 1000);
    });
    video.addEventListener('pause', function() {
        if (timer) { clearInterval(timer); timer = null; }
        sendProgress();
    });
    video.addEventListener('ended', function() {
        if (timer) { clearInterval(timer); timer = null; }
        sendProgress();
    });

    window.addEventListener('beforeunload', sendProgress);
})();
</script>
<?php endif; ?>
<!-- FRE-41: Player Controls JS -->
<script src="js/player-controls.js"></script>
<script src="js/login.js"></script>
<script src="js/avatar-bubble.js"></script>
