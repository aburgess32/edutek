<?php ob_start();
    include_once "includes/auth.php";
    require_once 'includes/tiles.php';
?>
  <!-- FRE-41: Watch Page Facelift — CSS -->
  <!-- NOTE: No external font CDN — EduPak is offline-first. System fonts only. -->
  <link href="css/WatchVideos.css" rel="stylesheet">
  <script src="ajax/jquery.min.js"></script>
    <script src="ajax/popper.min.js"></script>
    <script src="ajax/ajax.js"></script>

<?php
    //navbar
    include_once"navbar.php";
    $cipher         = CONTENT_CIPHER;
  $iv_length      = openssl_cipher_iv_length($cipher);
  $options        = 0;
  $iv             = CONTENT_CIPHER_IV;
  $encryption_key = CONTENT_CIPHER_KEY;
  $decryption_iv  = CONTENT_CIPHER_IV;
  $decryption_key = CONTENT_CIPHER_KEY;

    // FRE-12: Propagate breadcrumb context through watch.php links
    $bcQuery = '';
    if (isset($_GET['seg']) && $_GET['seg'] !== '') {
        $bcQuery .= '&seg=' . urlencode($_GET['seg']);
    }
    if (isset($_GET['topic']) && $_GET['topic'] !== '') {
        $bcQuery .= '&topic=' . urlencode($_GET['topic']);
    }

    // FRE-45: Detect lesson plan context
    $lessonPlanId = isset($_GET['plan']) ? (int) $_GET['plan'] : 0;
    if ($lessonPlanId > 0) {
        $bcQuery .= '&plan=' . $lessonPlanId;
    }

        $videolink1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink1", $cipher, $encryption_key, $options, $iv)));
        $videoname1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname1", $cipher, $encryption_key, $options, $iv)));
        $videolink = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink", $cipher, $encryption_key, $options, $iv)));
        $videoname = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname", $cipher, $encryption_key, $options, $iv)));

  // FRE-48: Handle content_id parameter (used by student assignments & search)
  // content_id is the file path stored in content_meta, e.g. "videos/Category/Sub/file.mp4"
  $file  = '';
  $file1 = '';
  $filea = '';
  $file1b = '';

  $contentIdParam = isset($_GET['content_id']) && $_GET['content_id'] !== '' ? $_GET['content_id']
                   : (isset($_GET['id']) && $_GET['id'] !== '' ? $_GET['id'] : '');
  if ($contentIdParam !== '') {
      // content_id is the full file path — derive the 4 watch variables from it
      // $file  = folder path (dirname + /)
      // $file1 = folder name (basename of dirname)
      // $filea = full file path (the content_id itself)
      // $file1b = filename (basename)
      $file   = dirname($contentIdParam) . '/';
      $file1  = basename(dirname($contentIdParam));
      $filea  = $contentIdParam;
      $file1b = basename($contentIdParam);
  } elseif (isset($_GET[$videolink])) {
      $file = openssl_decrypt(base64_decode(str_replace('[equal]', '=', $_GET[$videolink])), $cipher, $decryption_key, $options, $iv) ?: '';
      $file1 = isset($_GET[$videoname])
          ? (openssl_decrypt(base64_decode(str_replace('[equal]', '=', $_GET[$videoname])), $cipher, $decryption_key, $options, $iv) ?: '')
          : '';
      $filea = isset($_GET[$videolink1])
          ? (openssl_decrypt(base64_decode(str_replace('[equal]', '=', $_GET[$videolink1])), $cipher, $decryption_key, $options, $iv) ?: '')
          : '';
      $file1b = isset($_GET[$videoname1])
          ? (openssl_decrypt(base64_decode(str_replace('[equal]', '=', $_GET[$videoname1])), $cipher, $decryption_key, $options, $iv) ?: '')
          : '';
  }

  $dd2 = contentFilePath($file) . "/";
  $length = strlen($dd2);
  $ff2 = glob($dd2 . "*", GLOB_BRACE);
  $ray = array();
  $ff3 = array();
  $video = array('mp4','mov','wmv','flv','f4v','avi','webm','mkv');

  // Build a naturally sorted list of video files only
  $videoFiles = array();
  foreach ($ff2 as $path) {
      $name = substr($path, $length);
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if (in_array($ext, $video)) {
          $videoFiles[] = array(
              'src' => $path,
              'name' => $name
          );
      }
  }

usort($videoFiles, function ($a, $b) {
    $aName = $a['name'];
    $bName = $b['name'];

    preg_match('/^\d+/', $aName, $aMatch);
    preg_match('/^\d+/', $bName, $bMatch);

    $aNum = isset($aMatch[0]) ? (int)$aMatch[0] : PHP_INT_MAX;
    $bNum = isset($bMatch[0]) ? (int)$bMatch[0] : PHP_INT_MAX;

    if ($aNum !== $bNum) {
        return $aNum <=> $bNum;
    }

    return strnatcasecmp($aName, $bName);
});

  // FRE-41: Determine current video source and name
  $currentVideoSrc = '';
  $currentVideoName = '';

  if ($filea == '') {
      if (!empty($videoFiles)) {
          $file1b = $videoFiles[0]['name'];
          $currentVideoSrc = $videoFiles[0]['src'];
          $currentVideoName = $videoFiles[0]['name'];
      }
  } else {
      $selectedExt = strtolower(pathinfo($file1b, PATHINFO_EXTENSION));
      if (in_array($selectedExt, $video)) {
          foreach ($videoFiles as $vf) {
              if ($vf['name'] === $file1b) {
                  $currentVideoSrc = $vf['src'];
                  $currentVideoName = $vf['name'];
                  break;
              }
          }

          if ($currentVideoSrc === '') {
              $currentVideoSrc = $filea;
              $currentVideoName = $file1b;
          }
      }
  }
  // Normalize video and folder paths for web serving
  $videoWebPath  = $currentVideoSrc  !== '' ? contentWebUrl($currentVideoSrc)  : '';
  $folderWebPath = $file             !== '' ? contentWebUrl($file)             : '';

  // FRE-50: Check DB for transcoded HEVC path
  $transcodedPath = '';
  if ($currentVideoSrc !== '') {
      try {
          $pdo = getDbConnection();
          $stmt = $pdo->prepare("SELECT codec, transcoded_path, duration_seconds FROM content_meta WHERE content_id = :id");
          $stmt->execute([':id' => $currentVideoSrc]);
          $meta = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($meta && $meta['codec'] === 'hevc' && !empty($meta['transcoded_path'])) {
              $tp = $meta['transcoded_path'];
              // Check if transcoded file exists on disk
              $tpFs = contentFilePath($tp);
              if (file_exists($tpFs) && filesize($tpFs) > 0) {
                  $transcodedPath = contentWebUrl($tp);
              }
          }
      } catch (PDOException $e) {
          // ignore DB errors here — fallback to raw video
      }
  }
  if ($transcodedPath !== '') {
      $videoWebPath = $transcodedPath;
  }

  // FRE-41: Count videos in this topic for the sidebar header
  $videoCount = count($videoFiles);

  // Color classes for playlist thumbnails (cycle through)
  $thumbColors = ['', 'playlist-item__thumb--blue', 'playlist-item__thumb--green', 'playlist-item__thumb--orange', 'playlist-item__thumb--purple', 'playlist-item__thumb--teal'];
  $colorIndex = 0;

  // FRE-45: Fetch lesson plan data if plan context is present
  $lessonPlan = null;
  $lpItems    = [];

  if ($lessonPlanId > 0) {
      try {
          $pdo = getDbConnection();
          $stmt = $pdo->prepare("
              SELECT lp.id, lp.title, lp.icon, lp.color, lp.content_ids
              FROM lesson_plans lp
              WHERE lp.id = ?
                AND lp.published_segments IS NOT NULL
                AND JSON_LENGTH(lp.published_segments) > 0
          ");
          $stmt->execute([$lessonPlanId]);
          $lessonPlan = $stmt->fetch(PDO::FETCH_ASSOC);

          if ($lessonPlan) {
              $rawIds = json_decode($lessonPlan['content_ids'], true) ?: [];
              $contentIds = [];
              foreach ($rawIds as $item) {
                  $contentIds[] = is_array($item) && isset($item['id']) ? $item['id'] : (string) $item;
              }
              if (!empty($contentIds)) {
                  $ph = implode(',', array_fill(0, count($contentIds), '?'));
                  $metaStmt = $pdo->prepare("
                      SELECT content_id, title, thumbnail_path, duration_seconds
                      FROM content_meta WHERE content_id IN ({$ph})
                  ");
                  $metaStmt->execute($contentIds);
                  $metaMap = [];
                  foreach ($metaStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                      $metaMap[$r['content_id']] = $r;
                  }
                  $videoExts = ['mp4','mov','wmv','flv','f4v','avi','webm','mkv'];
                  foreach ($contentIds as $cid) {
                      $ext = strtolower(pathinfo($cid, PATHINFO_EXTENSION));
                      if (!in_array($ext, $videoExts)) continue;
                      $lpItems[] = $metaMap[$cid] ?? ['content_id' => $cid, 'title' => basename($cid), 'thumbnail_path' => '', 'duration_seconds' => 0];
                  }
              }
          }
      } catch (PDOException $e) {
          $lessonPlan = null;
      }
  }

  // FRE-45: Compute "Up Next" video
  $upNextHref   = '';
  $upNextTitle  = '';
  $upNextSource = '';
  $lpCurrentIdx = -1;

  // Priority 1: next item in lesson plan
  if ($lessonPlan && !empty($lpItems)) {
      foreach ($lpItems as $i => $lpItem) {
          if ($lpItem['content_id'] === $currentVideoSrc) {
              $lpCurrentIdx = $i;
              break;
          }
      }
      if ($lpCurrentIdx >= 0 && $lpCurrentIdx < count($lpItems) - 1) {
          $nextLp = $lpItems[$lpCurrentIdx + 1];
          $nFolderPath = dirname($nextLp['content_id']) . '/';
          $nFolderName = basename(dirname($nextLp['content_id']));
          $nFileName   = basename($nextLp['content_id']);

          $upNextHref = 'watch.php?&'
              . $videolink  . '=' . str_replace('=','[equal]', base64_encode(openssl_encrypt($nFolderPath, $cipher, $encryption_key, $options, $iv)))
              . '&' . $videoname  . '=' . str_replace('=','[equal]', base64_encode(openssl_encrypt($nFolderName, $cipher, $encryption_key, $options, $iv)))
              . '&' . $videolink1 . '=' . str_replace('=','[equal]', base64_encode(openssl_encrypt($nextLp['content_id'], $cipher, $encryption_key, $options, $iv)))
              . '&' . $videoname1 . '=' . str_replace('=','[equal]', base64_encode(openssl_encrypt($nFileName, $cipher, $encryption_key, $options, $iv)))
              . '&plan=' . $lessonPlanId . $bcQuery;
          $upNextTitle = $nextLp['title'] ?? $nFileName;
          $upNextSource = 'plan';
      }
  }

  // Priority 2: fallback to subcategory next video
  if ($upNextHref === '') {
      $subcatVideos = $videoFiles;
      $scCurrentIdx = -1;
      foreach ($subcatVideos as $i => $sv) {
          if ($sv['name'] === $currentVideoName) {
              $scCurrentIdx = $i;
              break;
          }
      }
      if ($scCurrentIdx >= 0 && $scCurrentIdx < count($subcatVideos) - 1) {
          $nextSc = $subcatVideos[$scCurrentIdx + 1];
          $upNextHref = 'watch.php?&'
              . $videolink  . '=' . str_replace('=','[equal]', base64_encode(openssl_encrypt($file, $cipher, $encryption_key, $options, $iv)))
              . '&' . $videoname  . '=' . str_replace('=','[equal]', base64_encode(openssl_encrypt($file1, $cipher, $encryption_key, $options, $iv)))
              . '&' . $videolink1 . '=' . str_replace('=','[equal]', base64_encode(openssl_encrypt($nextSc['src'], $cipher, $encryption_key, $options, $iv)))
              . '&' . $videoname1 . '=' . str_replace('=','[equal]', base64_encode(openssl_encrypt($nextSc['name'], $cipher, $encryption_key, $options, $iv)))
              . ($lessonPlanId > 0 ? '&plan=' . $lessonPlanId : '')
              . $bcQuery;
          $upNextTitle = $nextSc['name'];
          $upNextSource = 'subcategory';
      }
  }
?>

<!-- FRE-41: Watch Page Grid Layout -->
<div class="watchpage">
  <div class="watch-grid">

    <!-- LEFT COLUMN: Player -->
    <div class="player-col">

      <!-- FRE-45: Back to Lesson Plan link -->
      <?php if ($lessonPlan): ?>
      <a class="lp-back-link" href="playlist.php?plan=<?php echo $lessonPlanId; ?>">
        &larr; Back to <?php echo htmlspecialchars($lessonPlan['title']); ?>
      </a>
      <?php endif; ?>

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
          <video class="Wvideo" id="wp-video" src="<?php echo htmlspecialchars($videoWebPath); ?>" autoplay></video>
          <!-- FRE-45: Up Next Toast Overlay -->
          <div class="up-next-toast" id="up-next-toast" style="display:none;">
            <span class="up-next-toast__label">Up Next</span>
            <span class="up-next-toast__title" id="up-next-toast-title"></span>
            <span class="up-next-toast__countdown" id="up-next-countdown"></span>
            <button class="up-next-toast__cancel" id="up-next-cancel">Cancel</button>
          </div>
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
          <a class="btn-download" href="<?php echo htmlspecialchars(rtrim($folderWebPath, '/') . '/' . $currentVideoName); ?>" download="<?php echo htmlspecialchars($currentVideoName); ?>">
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

      <?php // ── FRE-45: Lesson Plan Playlist (collapsible) ── ?>
      <?php if ($lessonPlan && !empty($lpItems)): ?>
      <details class="sidebar-section sidebar-section--lp" open
               style="--lp-color: <?php echo htmlspecialchars($lessonPlan['color'] ?? '#4ECDC4'); ?>;">
        <summary class="sidebar-section__header lp-playlist__header">
          <span class="lp-playlist__icon"><?php echo $lessonPlan['icon'] ?? '📚'; ?></span>
          <div class="sidebar-section__header-text">
            <h3 class="sidebar-section__title"><?php echo htmlspecialchars($lessonPlan['title']); ?></h3>
            <span class="sidebar-section__count"><?php echo count($lpItems); ?> item<?php echo count($lpItems) !== 1 ? 's' : ''; ?></span>
          </div>
          <span class="lp-playlist__active-label">Active Plan</span>
          <svg class="sidebar-section__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="sidebar-section__items lp-playlist__items">
          <?php
          $lpColorIndex = 0;
          foreach ($lpItems as $idx => $lpItem):
              $isCurrentVideo = ($lpItem['content_id'] === $currentVideoSrc);
              $isUpNext = ($upNextSource === 'plan' && $lpCurrentIdx >= 0 && isset($lpItems[$lpCurrentIdx + 1]) && $lpItem['content_id'] === $lpItems[$lpCurrentIdx + 1]['content_id']);
              $lpFileName   = basename($lpItem['content_id']);
              $lpFolderPath = dirname($lpItem['content_id']) . '/';
              $lpFolderName = basename(dirname($lpItem['content_id']));

              $lpEncLink  = str_replace('=', '[equal]', base64_encode(openssl_encrypt($lpFolderPath, $cipher, $encryption_key, $options, $iv)));
              $lpEncName  = str_replace('=', '[equal]', base64_encode(openssl_encrypt($lpFolderName, $cipher, $encryption_key, $options, $iv)));
              $lpEncLink1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($lpItem['content_id'], $cipher, $encryption_key, $options, $iv)));
              $lpEncName1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($lpFileName, $cipher, $encryption_key, $options, $iv)));

              $lpHref = 'watch.php?&' . $videolink . '=' . $lpEncLink
                      . '&' . $videoname . '=' . $lpEncName
                      . '&' . $videolink1 . '=' . $lpEncLink1
                      . '&' . $videoname1 . '=' . $lpEncName1
                      . '&plan=' . $lessonPlanId . $bcQuery;

              $lpDuration = (int) $lpItem['duration_seconds'];
              $lpDurStr = $lpDuration > 0 ? floor($lpDuration/60) . ':' . str_pad($lpDuration % 60, 2, '0', STR_PAD_LEFT) : '';

              // Thumbnail: use content_meta thumbnail_path if available, else color block
              $lpThumbPath = $lpItem['thumbnail_path'] ?? '';
              $lpHasThumb  = ($lpThumbPath !== '' && file_exists(contentFilePath($lpThumbPath)));
              $lpThumbClass = $thumbColors[$lpColorIndex % count($thumbColors)];
              $lpColorIndex++;
          ?>
          <a class="playlist-item lp-playlist__item <?php echo $isCurrentVideo ? 'playlist-item--active' : ''; ?> <?php echo $isUpNext ? 'playlist-item--up-next' : ''; ?>"
             href="<?php echo $lpHref; ?>"
             <?php echo $isUpNext ? 'data-up-next="true"' : ''; ?>>
            <?php if ($isCurrentVideo): ?>
            <div class="playlist-item__indicator">
              <div class="now-playing-badge">Now Playing</div>
            </div>
            <?php elseif ($isUpNext): ?>
            <div class="playlist-item__indicator">
              <div class="up-next-badge">Up Next</div>
            </div>
            <?php endif; ?>
            <div class="playlist-item__thumb <?php echo $lpThumbClass; ?>">
              <?php if ($lpHasThumb): ?>
              <img src="<?php echo htmlspecialchars(contentWebUrl($lpThumbPath)); ?>" alt="" loading="lazy" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;border-radius:var(--wp-radius-sm);">
              <?php endif; ?>
              <div class="playlist-item__play-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="#fff"><path d="M8 5v14l11-7z"/></svg>
              </div>
            </div>
            <div class="playlist-item__info">
              <span class="playlist-item__title"><?php echo htmlspecialchars($lpItem['title'] ?? $lpFileName); ?></span>
              <div class="playlist-item__meta">
                <span class="playlist-item__duration" data-video-src="<?php echo htmlspecialchars(contentWebUrl($lpItem['content_id'])); ?>"><?php echo $lpDurStr ?: '--:--'; ?></span>
              </div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </details>
      <?php endif; ?>

      <?php // ── FRE-45: Subcategory Videos (collapsible, always present) ── ?>
      <details class="sidebar-section" <?php echo (!$lessonPlan) ? 'open' : ''; ?>>
        <summary class="sidebar-section__header">
          <div class="sidebar-section__header-text">
            <h2 class="sidebar-section__title"><?php echo htmlspecialchars($file1); ?></h2>
            <span class="sidebar-section__count"><?php echo $videoCount; ?> video<?php echo $videoCount !== 1 ? 's' : ''; ?></span>
          </div>
          <svg class="sidebar-section__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="sidebar-section__items playlist-items">
          <?php
          // Current video as first item (active)
          $colorIndex = 0;
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
                <span class="playlist-item__duration" data-video-src="<?php echo htmlspecialchars(contentWebUrl($currentVideoSrc)); ?>">--:--</span>
              </div>
            </div>
          </a>
          <?php } ?>

          <?php
          // Other videos in the subcategory
               foreach ($videoFiles as $item) {
              if ($item['name'] != $currentVideoName) {
                  $value = $item['src'];
                  $otherName = $item['name'];
                  $encryptfile = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file, $cipher, $encryption_key, $options, $iv)));
                  $encryptfile1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file1, $cipher, $encryption_key, $options, $iv)));
                  $encryptvalue = str_replace('=', '[equal]', base64_encode(openssl_encrypt($value, $cipher, $encryption_key, $options, $iv)));
                  $encryptname = str_replace('=', '[equal]', base64_encode(openssl_encrypt($otherName, $cipher, $encryption_key, $options, $iv)));
                  $thumbClass = $thumbColors[$colorIndex % count($thumbColors)];
                  $colorIndex++;

                  // Check if this is the "up next" video from subcategory source
                  $scItemIsUpNext = ($upNextSource === 'subcategory' && $otherName === $upNextTitle);
          ?>
          <a class="playlist-item <?php echo $scItemIsUpNext ? 'playlist-item--up-next' : ''; ?>"
             href="watch.php?&<?php echo $videolink . '=' . $encryptfile . '&' . $videoname . '=' . $encryptfile1 . '&' . $videolink1 . '=' . $encryptvalue . '&' . $videoname1 . '=' . $encryptname . $bcQuery; ?>"
             <?php echo $scItemIsUpNext ? 'data-up-next="true"' : ''; ?>>
            <?php if ($scItemIsUpNext): ?>
            <div class="playlist-item__indicator">
              <div class="up-next-badge">Up Next</div>
            </div>
            <?php endif; ?>
            <div class="playlist-item__thumb <?php echo $thumbClass; ?>">
              <div class="playlist-item__play-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="#fff"><path d="M8 5v14l11-7z"/></svg>
              </div>
            </div>
            <div class="playlist-item__info">
              <span class="playlist-item__title"><?php echo htmlspecialchars($otherName); ?></span>
              <div class="playlist-item__meta">
                <span class="playlist-item__duration" data-video-src="<?php echo htmlspecialchars(contentWebUrl($value)); ?>">--:--</span>
              </div>
            </div>
          </a>
          <?php
                  }
              }
          ?>
        </div>
      </details>

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
<!-- FRE-48: Resume at position from ?t= parameter (assignment links) -->
<script>
(function() {
    var params = new URLSearchParams(window.location.search);
    var t = parseInt(params.get('t'), 10);
    if (!t || t <= 0) return;
    var video = document.getElementById('wp-video');
    if (!video) return;
    function seek() { if (video.duration && video.duration > t) video.currentTime = t; }
    if (video.readyState >= 1) seek();
    else video.addEventListener('loadedmetadata', seek, { once: true });
})();
</script>
<script src="js/playlist-metadata.js"></script>
<script src="js/login.js"></script>
<script src="js/avatar-bubble.js"></script>
