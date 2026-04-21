<?php ob_start(); ?>
    <!-- Custom styles for this watch-->
  <link rel="stylesheet" type="text/css" media="all" href="css/styles.css">
    <style>
.playlist {
  background-color: #2596be;
  color: #ffffff;
  padding: 20px;
  margin: 10px;
  margin-left:10px;
  border-radius: 10px;
  box-shadow: 0 0 10px rgba(0, 0, 0, 0.2);
  width: fit-content;
  display:block;
}
button{

  margin: 10px;
  margin-left:200px;
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
  color: #fffff;
}

.playlist a:hover {
  color: #fffff;
}
a{
color: #ffffff;
 font-size: 16px;
}

</style>
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
    $audio = array('wav','wma','mp3','m4a');
    ?>

    <?php

    if ($filea == '') {
        $files2 = (glob($dd2 . "*", GLOB_BRACE));
        $length = strlen($dd2);
        if (in_array(pathinfo(substr($files2[0], ($length)), PATHINFO_EXTENSION), $audio)) {
            echo'
	<div id="w">
    <div id="content">
      <div class="audio-player">
        <h2>' . substr($files2[0], ($length)) . '</h2>
        <audio id="audio-player" src="' . $files2[0] . '" type="audio/mp3" controls="controls">
		</audio>
      </div>
    </div>
  </div>';
        }
    } else {
        if (in_array(pathinfo($file1b, PATHINFO_EXTENSION), $audio)) {
            echo'
	<div id="w">
    <div id="content">
      <div class="audio-player">
       
        <audio id="audio-player" src="' . $filea . '" type="audio/mp3" controls="controls"></audio>
      </div>
    </div>
  </div>';
        }
    }

    ?>
    <hr>
        <h3>Matched &  Recommended</h3>
        <hr>
                    <?php
    #t=<?php print '20,40';
                    if ($filea != '') {
                        if (in_array(pathinfo($file1b, PATHINFO_EXTENSION), $audio)) {
                            $encryptfile = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file, $cipher, $encryption_key, $options, $iv)));
                            $encryptfile1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file1, $cipher, $encryption_key, $options, $iv)));
                            $encryptfilea = str_replace('=', '[equal]', base64_encode(openssl_encrypt($filea, $cipher, $encryption_key, $options, $iv)));
                            $encryptfile1b = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file1b, $cipher, $encryption_key, $options, $iv)));
                            echo'
	<div id="w">
    <div id="content">
      <div class="playlist">
	  <a href="listen.php?&' . $videolink . '=' . $encryptfile . '&' . $videoname . '=' . $encryptfile1 . '&' . $videolink1 . '=' . $encryptfilea . '&' . $videoname1 . '=' . $encryptfile1b . '">
        <h2><i class="fa fa-volume-up" style="font-size:20px;color:red"></i> ' . $file1b . '</h2>
		</a>
      </div>
    </div>
  </div>';
                        }
                    }

                    foreach ($ff2 as $value) {
                        if (substr($value, ($length)) != $file1b) {
                            if (in_array(pathinfo(substr($value, ($length)), PATHINFO_EXTENSION), $audio)) {
                                $encryptfile = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file, $cipher, $encryption_key, $options, $iv)));
                                $encryptfile1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file1, $cipher, $encryption_key, $options, $iv)));
                                $encryptfilea = str_replace('=', '[equal]', base64_encode(openssl_encrypt($value, $cipher, $encryption_key, $options, $iv)));
                                $encryptfile1b = str_replace('=', '[equal]', base64_encode(openssl_encrypt(substr($value, ($length)), $cipher, $encryption_key, $options, $iv)));
                                 echo'
	
    <div class="playlist">
	  <a href="listen.php?&' . $videolink . '=' . $encryptfile . '&' . $videoname . '=' . $encryptfile1 . '&' . $videolink1 . '=' . $encryptfilea . '&' . $videoname1 . '=' . $encryptfile1b . '">
        
		<h2><i class="fa fa-music" style="font-size:20px;color:red"></i> ' . substr($value, ($length)) . '</h2>
		</a>
    </div>
	
	              <button>
				<a  href="' . $value . '" download="' . substr($value, ($length)) . '"><i class="fa fa-download "> Download</i></a>
			</button>
  ';
                            }
                        }
                    }

                    ?>

  <script type="text/javascript" src="js/mediaelement-and-player.min.js"></script>
        <script type="text/javascript">
$(function(){
  $('#audio-player').mediaelementplayer({
    alwaysShowControls: true,
    features: ['playpause','progress','volume'],
    audioVolume: 'horizontal',
    iPadUseNativeControls: true,
    iPhoneUseNativeControls: true,
    AndroidUseNativeControls: true
  });
});
</script>

<?php
    // FRE-12: Breadcrumb
    require_once 'includes/breadcrumb.php';
    $crumbs = buildBreadcrumb($_GET, $file1 ?? null, true, $file ?? null);
    // If no seg context, show "Audiobooks" as the category
    if (empty($crumbs)) {
        $crumbs = [['label' => 'Audiobooks', 'color' => '#A78BFA', 'href' => 'audiobooks.php']];
        if (isset($file1) && $file1 !== '') {
            $crumbs[] = ['label' => getContentTitle($file1), 'color' => null, 'href' => null];
        }
    }
    renderBreadcrumb($crumbs);
?>

<script src="js/login.js"></script>
<script src="js/avatar-bubble.js"></script>
