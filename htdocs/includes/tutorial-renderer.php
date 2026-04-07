<?php
/**
 * Shared tutorial section renderer — used by both tutorials.php (initial load)
 * and api/tutorials-page.php (AJAX pagination).
 *
 * FRE-54: Extracted to enable server-side pagination with lazy loading.
 */

// Encryption helpers (shared so both entry points use identical logic)
function tutRenderer_encParam($val, $cipher, $key, $opts, $iv) {
    return str_replace('=', '[equal]', base64_encode(openssl_encrypt($val, $cipher, $key, $opts, $iv)));
}

function tutRenderer_prettyName($filename) {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = str_replace(['_', '-'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', trim($name));
    return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
}

function tutRenderer_videoThumb($videoPath, $fallback = 'images/sample.png') {
    $dir = dirname($videoPath);
    $thumbDir = $dir . '/thumbs';
    $base = pathinfo($videoPath, PATHINFO_FILENAME);
    $thumbFile = $thumbDir . '/' . $base . '.jpg';

    if (file_exists($thumbFile)) {
        return $thumbFile;
    }
    return $fallback;
}

/**
 * Render tutorial sections for a given course folder.
 *
 * @param string $coursePath  Path like "videos/Zimbabwe-Education/"
 * @param int    $offset      Start index (0-based) into the sorted folder list
 * @param int    $limit       Max sections to render
 * @param array  $encKeys     Pre-computed encrypted URL parameter keys:
 *                            [encryption2, videolink1, videoname1, videolink, videoname]
 * @param array  $enc         Encryption config: [cipher, key, options, iv]
 * @param string $bcQuery     Breadcrumb query string fragment (e.g. "&seg=...&topic=...")
 * @return array ['html' => string, 'hasMore' => bool, 'loaded' => int, 'total' => int]
 */
function renderTutorialSections($coursePath, $offset, $limit, $encKeys, $enc, $bcQuery = '') {
    $videoExts = ['mp4','mov','wmv','flv','avi','webm','mkv','f4v'];

    list($cipher, $key, $options, $iv) = $enc;
    list($encryption2, $videolink1, $videoname1, $videolink, $videoname) = $encKeys;

    $length = strlen($coursePath);

    // Get all subdirectories
    $allFolders = glob($coursePath . "*");
    // Filter to directories only
    $allFolders = array_values(array_filter($allFolders, 'is_dir'));
    $total = count($allFolders);

    // Slice to the requested page
    $folders = array_slice($allFolders, $offset, $limit);

    ob_start();
    $sectionIdx = $offset; // Continue numbering from offset
    $renderedCount = 0;

    foreach ($folders as $value) {
        $folderName = substr($value, $length);
        $subFiles   = glob($value . "/*");
        $length12   = strlen($value . "/");

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
        $renderedCount++;

        // Encrypted params for the section-level link (plays first video)
        $encFolderName = tutRenderer_encParam($folderName, $cipher, $key, $options, $iv);
        $encFolderPath = tutRenderer_encParam($value, $cipher, $key, $options, $iv);
        $sectionHref = "watch.php?&{$videolink1}=&{$videoname1}=&{$videolink}={$encFolderPath}&{$videoname}={$encFolderName}{$bcQuery}";

        // Section thumbnail: use first video's auto-generated thumbnail
        $firstVidThumb = tutRenderer_videoThumb($videoFiles[0]);
        $thumbSrc = htmlspecialchars($firstVidThumb);
        $lazyAttr = ($firstVidThumb === 'images/sample.png') ? ' data-video-src="' . htmlspecialchars($videoFiles[0]) . '"' : '';
?>

<div class="tut-section" id="tut-sec-<?php echo $sectionIdx; ?>">
    <button class="tut-section-toggle" onclick="toggleSection(this)" aria-expanded="false">
        <img src="<?php echo $thumbSrc; ?>" alt="" class="tut-thumb"<?php echo $lazyAttr; ?>>
        <div class="tut-section-info">
            <p class="tut-section-title"><?php echo htmlspecialchars(tutRenderer_prettyName($folderName)); ?></p>
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

                $encFilePath = tutRenderer_encParam($value . "/", $cipher, $key, $options, $iv);
                $encFileName = tutRenderer_encParam($folderName, $cipher, $key, $options, $iv);
                $encVidPath  = tutRenderer_encParam($vf, $cipher, $key, $options, $iv);
                $encVidName  = tutRenderer_encParam($baseName, $cipher, $key, $options, $iv);
                $vidHref = "watch.php?&{$videolink}={$encFilePath}&{$videoname}={$encFileName}&{$videolink1}={$encVidPath}&{$videoname1}={$encVidName}{$bcQuery}";
                $vidThumb = tutRenderer_videoThumb($vf);
                $vidThumbSrc = htmlspecialchars($vidThumb);
                $vidLazyAttr = ($vidThumb === 'images/sample.png') ? ' data-video-src="' . htmlspecialchars($vf) . '"' : '';
?>
            <li class="tut-video-item">
                <span class="tut-vid-num"><?php echo $vidNum; ?></span>
                <img src="<?php echo $vidThumbSrc; ?>" alt="" class="tut-vid-thumb"<?php echo $vidLazyAttr; ?>>
                <a href="<?php echo $vidHref; ?>" class="tut-vid-name" title="<?php echo htmlspecialchars($baseName); ?>">
                    <?php echo htmlspecialchars(tutRenderer_prettyName($baseName)); ?>
                </a>
                <span class="tut-vid-ext"><?php echo $ext; ?></span>
            </li>
<?php       } ?>
        </ul>
    </div>
</div>

<?php
    } // end foreach

    $html = ob_get_clean();
    $newOffset = $offset + count($folders);
    $hasMore = $newOffset < $total;

    return [
        'html'    => $html,
        'hasMore' => $hasMore,
        'loaded'  => $newOffset,
        'total'   => $total,
    ];
}
