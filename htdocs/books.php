<?php ob_start(); ?>
    <!-- Custom styles for this listofbooks.php-->
 <link href="css/saved.css" rel="stylesheet">

<?php
    //nabvbar
    include_once"navbar.php";
    require_once "includes/loading.php";
    loadingStart('Loading books...');
    require_once "includes/page-cache.php";
    $cacheFile = pageCache_start("books-" . md5($_SERVER["QUERY_STRING"] ?? ""));
    if ($cacheFile === null) { loadingEnd(); exit; }

    loadingEnd();
    ?>

        <!--contens are here-->

        <!--result found number-->
    <div class="SaveWrapper">
        <span class="fa fa-fw fa-bookmark"></span> Books
    </div>
        <!--//result found number-->

        <?php

        $cipher = "BF-CBC";
        $iv_length = openssl_cipher_iv_length($cipher);
        $options = 0;
        $iv = "91011121";
        $encryption_key = "hfjfydjnvhbjfi";
        $decryption_iv = "91011121";
        $decryption_key = "hfjfydjnvhbjfi";

        $videolink1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink1", $cipher, $encryption_key, $options, $iv)));
        $videoname1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname1", $cipher, $encryption_key, $options, $iv)));
        $videolink = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink", $cipher, $encryption_key, $options, $iv)));
        $videoname = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname", $cipher, $encryption_key, $options, $iv)));

        $dd2 = "videos/Books/";

        $ff2 = glob($dd2 . "*");
        foreach ($ff2 as $value) {
            if (is_dir($value)) {
                // Single glob with extension filter instead of scandir + manual filter
                $pdfFiles = glob($value . "/*.pdf");
                $files1 = count($pdfFiles);
                if ($files1 > 0) {
                            $encryptvalue = str_replace('=', '[equal]', base64_encode(openssl_encrypt($value, $cipher, $encryption_key, $options, $iv)));
                           $value1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt(substr($value, 16), $cipher, $encryption_key, $options, $iv)));
                           echo'<div class="SavedWrapper">
		<a href="listofbooks.php?&' . $videolink1 . '=&' . $videoname1 . '=&' . $videolink . '=' . $encryptvalue . '&' . $videoname . '=' . $value1 . '">
			<img src="videos/Books/' . substr($value, 13) . '.jpg" class="rimage">
		</a>
		<div class="rcontents">
			&nbsp;&nbsp;&nbsp;&nbsp;
			<a href="listofbooks.php?&' . $videolink1 . '=&' . $videoname1 . '=&' . $videolink . '=' . $encryptvalue . '&' . $videoname . '=' . $value1 . '">
				<p>
					<b class="rtitle"><b>' . substr($value, 13) . '</b></b>
				</p>
				<label class="rdesc">
					<small>(' . number_format($files1 / 2) . ' Parts)</small>
				</label>
			</a>
		</div>
	</div>';
                }
            }
        }
        ?>

<?php
    include_once"footer.php";
    pageCache_end($cacheFile);
?>
