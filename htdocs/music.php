<?php ob_start(); ?>
<link href="css/saved.css" rel="stylesheet">

<style>
.music-page {
    padding: 8px 12px 24px;
}

.music-title {
    font-size: 28px;
    font-weight: 700;
    margin: 8px 0 16px;
    color: #222;
}

.music-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
}

.music-card {
    display: flex;
    align-items: center;
    gap: 16px;
    background: #fff;
    border: 1px solid #e7dfea;
    border-radius: 14px;
    padding: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    text-decoration: none;
}

.music-card:hover {
    box-shadow: 0 6px 18px rgba(0,0,0,0.08);
    border-color: #d7c7dd;
}

.music-thumb-link {
    flex: 0 0 110px;
    text-decoration: none;
}

.music-thumb {
    width: 110px;
    height: 110px;
    object-fit: cover;
    border-radius: 12px;
    display: block;
    background: #f2f2f2;
    border: 1px solid #e5e5e5;
}

.music-info {
    min-width: 0;
    flex: 1;
}

.music-name {
    margin: 0 0 8px;
    font-size: 22px;
    line-height: 1.2;
    font-weight: 700;
    color: #1f1f1f;
    text-transform: uppercase;
}

.music-meta {
    font-size: 15px;
    color: #666;
    margin: 0;
}

.music-open {
    display: inline-flex;
    align-items: center;
    margin-top: 10px;
    font-size: 14px;
    font-weight: 600;
    color: #7a2ea8;
    text-decoration: none;
}

.music-open:hover {
    text-decoration: underline;
}

.music-empty {
    background: #fff;
    border: 1px solid #eadfea;
    border-radius: 14px;
    padding: 18px;
    color: #333;
}

@media (max-width: 640px) {
    .music-card {
        align-items: flex-start;
        gap: 12px;
        padding: 12px;
    }

    .music-thumb-link {
        flex-basis: 88px;
    }

    .music-thumb {
        width: 88px;
        height: 88px;
    }

    .music-name {
        font-size: 18px;
    }

    .music-meta {
        font-size: 14px;
    }
}
</style>

<?php
include_once __DIR__ . "/navbar.php";
?>

<div class="music-page">
    <div class="music-title">Gospel Music</div>

<?php
$cipher = "BF-CBC";
$options = 0;
$iv = "91011121";
$encryption_key = "hfjfydjnvhbjfi";

$videolink1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink1", $cipher, $encryption_key, $options, $iv)));
$videoname1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname1", $cipher, $encryption_key, $options, $iv)));
$videolink  = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink", $cipher, $encryption_key, $options, $iv)));
$videoname  = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname", $cipher, $encryption_key, $options, $iv)));

$audioExts = ['mp3', 'wav', 'wma', 'm4a', 'm4b', 'flac', 'ogg'];

$pathCandidates = [
    __DIR__ . '/videos/Music',
    __DIR__ . '/storage/Music',
    '/var/www/html/videos/Music',
    '/var/www/html/storage/Music'
];

$baseFsPath = null;
foreach ($pathCandidates as $candidate) {
    if (is_dir($candidate)) {
        $baseFsPath = $candidate;
        break;
    }
}

function musicBuildCoverPath($baseFsPath, $folderFsPath, $folderName) {
    $coverCandidates = [
        $folderFsPath . '/' . $folderName . '.jpg',
        $folderFsPath . '/' . $folderName . '.png',
        $folderFsPath . '/cover.jpg',
        $folderFsPath . '/cover.png',
        $folderFsPath . '/folder.jpg',
        $folderFsPath . '/folder.png'
    ];

    foreach ($coverCandidates as $coverFsPath) {
        if (file_exists($coverFsPath)) {
            $webPath = str_replace('\\', '/', $coverFsPath);
            $webPath = str_replace('/var/www/html/', '', $webPath);
            return $webPath;
        }
    }

    return 'images/sample.png';
}

if ($baseFsPath === null) {
    echo '<div class="music-empty"><b>Music folder not found.</b><br><small>Checked: videos/Music and storage/Music</small></div>';
} else {
    $folders = glob($baseFsPath . '/*', GLOB_ONLYDIR) ?: [];
    natcasesort($folders);
    $folders = array_values($folders);

    $renderedCount = 0;

    echo '<div class="music-grid">';

    foreach ($folders as $folderFsPath) {
        $folderName = basename($folderFsPath);
        $audioFiles = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folderFsPath, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $ext = strtolower($file->getExtension());
                    if (in_array($ext, $audioExts, true)) {
                        $audioFiles[] = $file->getPathname();
                    }
                }
            }
        } catch (UnexpectedValueException $e) {
            continue;
        }

        $files1 = count($audioFiles);
        if ($files1 < 1) {
            continue;
        }

        $renderedCount++;

        $encryptvalue = str_replace('=', '[equal]', base64_encode(openssl_encrypt($folderFsPath, $cipher, $encryption_key, $options, $iv)));
        $value1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($folderName, $cipher, $encryption_key, $options, $iv)));

        $listenUrl = 'listen.php?&' . $videolink1 . '=&' . $videoname1 . '=&' . $videolink . '=' . $encryptvalue . '&' . $videoname . '=' . $value1;
        $coverPath = musicBuildCoverPath($baseFsPath, $folderFsPath, $folderName);

        echo '
        <div class="music-card">
            <a class="music-thumb-link" href="' . htmlspecialchars($listenUrl, ENT_QUOTES) . '">
                <img src="' . htmlspecialchars($coverPath, ENT_QUOTES) . '" class="music-thumb" alt="' . htmlspecialchars($folderName, ENT_QUOTES) . '">
            </a>

            <div class="music-info">
                <h2 class="music-name">' . htmlspecialchars($folderName, ENT_QUOTES) . '</h2>
                <p class="music-meta">' . $files1 . ' track' . ($files1 !== 1 ? 's' : '') . '</p>
                <a class="music-open" href="' . htmlspecialchars($listenUrl, ENT_QUOTES) . '">Open playlist</a>
            </div>
        </div>';
    }

    echo '</div>';

    if ($renderedCount === 0) {
        echo '<div class="music-empty"><b>No music found.</b><br><small>Path found, but no matching audio files were detected.</small></div>';
    }
}

include_once __DIR__ . "/footer.php";
?>

</div>