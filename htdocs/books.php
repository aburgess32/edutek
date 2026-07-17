<?php ob_start(); ?>
<link href="css/saved.css" rel="stylesheet">

<?php include_once "navbar.php"; ?>

<div class="SaveWrapper">
    <span class="fa fa-fw fa-bookmark"></span> Books
</div>

<?php

$cipher = CONTENT_CIPHER;
$options = 0;
$iv = CONTENT_CIPHER_IV;
$encryption_key = CONTENT_CIPHER_KEY;

$videolink1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink1", $cipher, $encryption_key, $options, $iv)));
$videoname1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname1", $cipher, $encryption_key, $options, $iv)));
$videolink = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink", $cipher, $encryption_key, $options, $iv)));
$videoname = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname", $cipher, $encryption_key, $options, $iv)));

$booksFsPath = "/content/Books";

if (!is_dir($booksFsPath)) {
    echo '<div class="SavedWrapper">
        <div class="rcontents">
            <p><b class="rtitle">Books folder not found</b></p>
            <label class="rdesc"><small>' . htmlspecialchars($booksFsPath) . '</small></label>
        </div>
    </div>';
} else {
    $ff2 = glob($booksFsPath . "/*", GLOB_ONLYDIR);

    if ($ff2 === false || count($ff2) === 0) {
        echo '<div class="SavedWrapper">
            <div class="rcontents">
                <p><b class="rtitle">No book folders found</b></p>
            </div>
        </div>';
    } else {
        foreach ($ff2 as $value) {
            $pdfFiles = glob($value . "/*.pdf");
            $files1 = is_array($pdfFiles) ? count($pdfFiles) : 0;

            if ($files1 > 0) {
                $folderName = basename($value);

                $encryptvalue = str_replace(
                    '=',
                    '[equal]',
                    base64_encode(openssl_encrypt($value, $cipher, $encryption_key, $options, $iv))
                );

                $value1 = str_replace(
                    '=',
                    '[equal]',
                    base64_encode(openssl_encrypt($folderName, $cipher, $encryption_key, $options, $iv))
                );

                echo '<div class="SavedWrapper">
<div class="rcontents">
&nbsp;&nbsp;&nbsp;&nbsp;
<a href="listofbooks.php?&' . $videolink1 . '=&' . $videoname1 . '=&' . $videolink . '=' . $encryptvalue . '&' . $videoname . '=' . $value1 . '">
<p>
<b class="rtitle">' . htmlspecialchars($folderName) . '</b>
</p>
<label class="rdesc">
<small>(' . number_format($files1) . ' PDF' . ($files1 === 1 ? '' : 's') . ')</small>
</label>
</a>
</div>
</div>';
            }
        }
    }
}
?>

<?php include_once "footer.php"; ?>