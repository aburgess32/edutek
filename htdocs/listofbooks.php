<?php ob_start(); ?>
    <!-- Custom styles for this readpdf.php-->
 <link href="css/saved.css" rel="stylesheet">

<?php
    //nabvbar
    include_once"navbar.php";
    //nabvbar

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
    $acceptedFormats = array('pdf','htm');
    ?>
    
        <!--contens are here-->
        
        <!--result found number-->
    <div class="SaveWrapper">
        <span class="fa fa-fw fa-bookmark"></span> Books
    </div>
        <!--//result found number-->
        
        <?php

        foreach ($ff2 as $value) {
            if (in_array(pathinfo(substr($value, ($length)), PATHINFO_EXTENSION), $acceptedFormats)) {
                    $encryptfile = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file, $cipher, $encryption_key, $options, $iv)));
                $encryptfile1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file1, $cipher, $encryption_key, $options, $iv)));
                $encryptvalue = str_replace('=', '[equal]', base64_encode(openssl_encrypt($value, $cipher, $encryption_key, $options, $iv)));
                $encryptvalue1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt(substr($value, ($length)), $cipher, $encryption_key, $options, $iv)));

                if (pathinfo(substr($value, ($length)), PATHINFO_EXTENSION) == 'htm' or pathinfo(substr($value, ($length)), PATHINFO_EXTENSION) == 'html') {
                    echo'<div class="SavedWrapper">
		<a href="' . $value . '">
			<img src="' . $file . '/' . pathinfo(substr($value, ($length)), PATHINFO_FILENAME) . '.png" class="rimage">
		</a>
		<div class="rcontents">
			&nbsp;&nbsp;&nbsp;&nbsp;
			<a href="' . $value . '">
						<p>
					<b class="rtitle">' . substr($value, ($length)) . '</b>
				</p>
			</a>
		</div>
	</div>';
                } else {
                    echo'<div class="SavedWrapper">
		<a href="readpdf.php?&' . $videolink . '=' . $encryptfile . '&' . $videoname . '=' . $encryptfile1 . '&' . $videolink1 . '=' . $encryptvalue . '&' . $videoname1 . '=' . $encryptvalue1 . '">
			
		<img src="' . $file . '/' . pathinfo(substr($value, ($length)), PATHINFO_FILENAME) . '.jpg" class="rimage">
		</a>
		<div class="rcontents">
			&nbsp;&nbsp;&nbsp;&nbsp;
			<a href="readpdf.php?&' . $videolink . '=' . $encryptfile . '&' . $videoname . '=' . $encryptfile1 . '&' . $videolink1 . '=' . $encryptvalue . '&' . $videoname1 . '=' . $encryptvalue1 . '">
			
			<p>
					<b class="rtitle">' . substr($value, ($length)) . '</b>
				</p>
			</a>
			 <button>
				<a href="' . $value . '" download="' . substr($value, ($length)) . '"><i class="fa fa-download "> Download</i></a>
			</button>
		</div>
	</div>';
                }
            }
        }

        include_once"footer.php";
        ?>
