<?php ob_start(); ?>
<link rel="stylesheet" type="text/css" media="all" href="css/styles.css">

<style>
.playlist {
  background-color: #2596be;
  color: #ffffff;
  padding: 20px;
  margin: 10px;
  margin-left: 10px;
  border-radius: 10px;
  box-shadow: 0 0 10px rgba(0, 0, 0, 0.2);
  width: fit-content;
  display: block;
}
button {
  margin: 10px;
  margin-left: 200px;
  border-radius: 10px;
  box-shadow: 0 0 10px rgba(0, 0, 0, 0.2);
}
.playlist h2 {
  font-size: 16px;
  margin-top: 0;
  color: #ffffff;
}
.playlist ul {
  list-style: none;
  padding: 0;
  margin: 0;
  color: #ffffff;
}
.playlist li {
  color: #ffffff;
  padding: 10px 0;
  border-bottom: 1px solid #ddd;
}
.playlist li:last-child {
  border-bottom: none;
  color: #ffffff;
}
.playlist a {
  text-decoration: none;
  color: #ffffff;
}
.playlist a:hover {
  color: #ffffff;
}
a {
  color: #ffffff;
  font-size: 16px;
}
.audio-player {
  margin: 20px 10px;
}
.audio-player h2 {
  margin-bottom: 12px;
}
.audio-player audio {
  width: 100%;
  max-width: 700px;
}
</style>

<?php
include_once "navbar.php";

$cipher = "BF-CBC";
$options = 0;
$iv = "91011121";
$encryption_key = "hfjfydjnvhbjfi";
$decryption_key = "hfjfydjnvhbjfi";

$videolink1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink1", $cipher, $encryption_key, $options, $iv)));
$videoname1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname1", $cipher, $encryption_key, $options, $iv)));
$videolink  = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink", $cipher, $encryption_key, $options, $iv)));
$videoname  = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname", $cipher, $encryption_key, $options, $iv)));

function getQueryValue($key) {
    return isset($_GET[$key]) ? $_GET[$key] : '';
}

$file = '';
if (getQueryValue($videolink) !== '') {
    $file = openssl_decrypt(
        base64_decode(str_replace('[equal]', '=', getQueryValue($videolink))),
        $cipher,
        $decryption_key,
        $options,
        $iv
    );
}

$file1 = '';
if (getQueryValue($videoname) !== '') {
    $file1 = openssl_decrypt(
        base64_decode(str_replace('[equal]', '=', getQueryValue($videoname))),
        $cipher,
        $decryption_key,
        $options,
        $iv
    );
}

$filea = '';
if (getQueryValue($videolink1) !== '') {
    $filea = openssl_decrypt(
        base64_decode(str_replace('[equal]', '=', getQueryValue($videolink1))),
        $cipher,
        $decryption_key,
        $options,
        $iv
    );
}

$file1b = '';
if (getQueryValue($videoname1) !== '') {
    $file1b = openssl_decrypt(
        base64_decode(str_replace('[equal]', '=', getQueryValue($videoname1))),
        $cipher,
        $decryption_key,
        $options,
        $iv
    );
}

$audioExts = ['wav', 'wma', 'mp3', 'm4a', 'm4b', 'flac', 'ogg'];

$file = rawurldecode((string)$file);
$filea = rawurldecode((string)$filea);

$playlistFiles = [];

if ($file !== '' && is_dir($file)) {
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($file, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $ext = strtolower($item->getExtension());
                if (in_array($ext, $audioExts, true)) {
                    $path = str_replace('\\', '/', $item->getPathname());
                    $path = preg_replace('#^/var/www/html/#', '', $path);
                    $playlistFiles[] = $path;
                }
            }
        }
    } catch (UnexpectedValueException $e) {
    }
}

natcasesort($playlistFiles);
$playlistFiles = array_values($playlistFiles);

$currentTrack = '';
$currentTrackName = '';

if ($filea !== '' && in_array(strtolower(pathinfo($filea, PATHINFO_EXTENSION)), $audioExts, true)) {
    $currentTrack = $filea;
    $currentTrackName = $file1b !== '' ? $file1b : basename($filea);
} elseif (!empty($playlistFiles)) {
    $currentTrack = $playlistFiles[0];
    $currentTrackName = basename($playlistFiles[0]);
}

if ($currentTrack !== '') {
    echo '
    <div id="w">
      <div id="content">
        <div class="audio-player">
          <h2>' . htmlspecialchars($currentTrackName, ENT_QUOTES) . '</h2>
          <audio id="audio-player" src="' . htmlspecialchars($currentTrack, ENT_QUOTES) . '" type="audio/mpeg" controls="controls"></audio>
        </div>
      </div>
    </div>';
} else {
    echo '
    <div class="playlist">
      <h2>No playable audio found in this audiobook folder.</h2>
    </div>';
}
?>

<hr>
<h3>Matched & Recommended</h3>
<hr>

<?php
if ($currentTrack !== '') {
    $encryptfile  = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file, $cipher, $encryption_key, $options, $iv)));
    $encryptfile1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file1, $cipher, $encryption_key, $options, $iv)));
    $encryptfilea = str_replace('=', '[equal]', base64_encode(openssl_encrypt($currentTrack, $cipher, $encryption_key, $options, $iv)));
    $encryptfile1b = str_replace('=', '[equal]', base64_encode(openssl_encrypt($currentTrackName, $cipher, $encryption_key, $options, $iv)));

    echo '
    <div id="w">
      <div id="content">
        <div class="playlist">
          <a href="listen.php?&' . $videolink . '=' . $encryptfile . '&' . $videoname . '=' . $encryptfile1 . '&' . $videolink1 . '=' . $encryptfilea . '&' . $videoname1 . '=' . $encryptfile1b . '">
            <h2><i class="fa fa-volume-up" style="font-size:20px;color:red"></i> ' . htmlspecialchars($currentTrackName, ENT_QUOTES) . '</h2>
          </a>
        </div>
      </div>
    </div>';
}

foreach ($playlistFiles as $value) {
    if ($value === $currentTrack) {
        continue;
    }

    $displayName = basename($value);

    $encryptfile  = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file, $cipher, $encryption_key, $options, $iv)));
    $encryptfile1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file1, $cipher, $encryption_key, $options, $iv)));
    $encryptfilea = str_replace('=', '[equal]', base64_encode(openssl_encrypt($value, $cipher, $encryption_key, $options, $iv)));
    $encryptfile1b = str_replace('=', '[equal]', base64_encode(openssl_encrypt($displayName, $cipher, $encryption_key, $options, $iv)));

    echo '
    <div class="playlist">
      <a href="listen.php?&' . $videolink . '=' . $encryptfile . '&' . $videoname . '=' . $encryptfile1 . '&' . $videolink1 . '=' . $encryptfilea . '&' . $videoname1 . '=' . $encryptfile1b . '">
        <h2><i class="fa fa-music" style="font-size:20px;color:red"></i> ' . htmlspecialchars($displayName, ENT_QUOTES) . '</h2>
      </a>
    </div>

    <button>
      <a href="' . htmlspecialchars($value, ENT_QUOTES) . '" download="' . htmlspecialchars($displayName, ENT_QUOTES) . '">
        <i class="fa fa-download"> Download</i>
      </a>
    </button>';
}

include_once "footer.php";
?>