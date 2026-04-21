<?php ob_start(); ?>
    <!-- Custom styles for this listofbooks.php-->
 <link href="css/saved.css" rel="stylesheet">

<?php
    //nabvbar
    include_once"navbar.php";
    //nabvbar
    ?>
    
        <!--contens are here-->
        
        <!--result found number-->
    <div class="SaveWrapper">
        <span class="fa fa-fw fa-bookmark"></span> Books
    </div>
        <!--//result found number-->
        
        <?php

        $cipher = CONTENT_CIPHER;
        $iv_length = openssl_cipher_iv_length($cipher);
        $options = 0;
        $iv = CONTENT_CIPHER_IV;
        $encryption_key = CONTENT_CIPHER_KEY;
        $decryption_iv = CONTENT_CIPHER_IV;
        $decryption_key = CONTENT_CIPHER_KEY;

        $videolink1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink1", $cipher, $encryption_key, $options, $iv)));
        $videoname1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname1", $cipher, $encryption_key, $options, $iv)));
        $videolink = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink", $cipher, $encryption_key, $options, $iv)));
        $videoname = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname", $cipher, $encryption_key, $options, $iv)));

        $dd2 = "videos/Books/";

        $ff2 = (glob($dd2 . "*"));
        foreach ($ff2 as $value) {
            if (is_dir($value)) {
                $f2a = (scandir($value));
                $files1 = 0;
                foreach ($f2a as $vala) {
                         $exta = pathinfo($vala, PATHINFO_EXTENSION);

                    if ($exta == 'pdf') {
                        $files1++;
                    }
                }
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
					<small>(' . number_format(count(glob($value . "/*", GLOB_BRACE)) / 2) . ' Parts)</small>
				</label>
			</a>
		</div>
	</div>';
                } else {
                }
            }
        }
        ?>

<?php
    include_once"footer.php";
?>
