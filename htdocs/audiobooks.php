<?php ob_start(); ?>
    <!-- Custom styles for this listen-->
  <link href="css/saved.css" rel="stylesheet">

<?php
    //nabvbar
    include_once"navbar.php";
    require_once "includes/loading.php";
    loadingStart('Loading audiobooks...');
    require_once "includes/page-cache.php";
    $cacheFile = pageCache_start("audiobooks-" . md5($_SERVER["QUERY_STRING"] ?? ""));
    if ($cacheFile === null) { loadingEnd(); exit; }
    //nabvbar

    loadingEnd();
    ?>

        <!--contens are here-->

        <!--result found number-->
    <div class="SaveWrapper">
        <span class="fa fa-fw fa-bookmark"></span>Audio Books
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

        $dd2 = "videos/Audiobooks/";
        $audioExts = ['mp3', 'wav', 'wma', 'm4a'];

        $ff2 = glob($dd2 . "*");
        foreach ($ff2 as $value) {
            // Single glob per folder with extension filter instead of scandir + manual filter
            $audioFiles = glob($value . "/*.{" . implode(',', $audioExts) . "}", GLOB_BRACE);
            $files1 = count($audioFiles);
            if ($files1 > 0) {
                $encryptvalue = str_replace('=', '[equal]', base64_encode(openssl_encrypt($value, $cipher, $encryption_key, $options, $iv)));
                $value1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt(substr($value, 18), $cipher, $encryption_key, $options, $iv)));
                echo'<div class="SavedWrapper">
		<a href="listen.php?&' . $videolink1 . '=&' . $videoname1 . '=&' . $videolink . '=' . $encryptvalue . '&' . $videoname . '=' . $value1 . '">
			<img src="' . $value . '/' . substr($value, 18) . '.jpg" class="rimage">
		</a>
		<div class="rcontents">
			&nbsp;&nbsp;&nbsp;&nbsp;
			<a href="listen.php?&' . $videolink1 . '=&' . $videoname1 . '=&' . $videolink . '=' . $encryptvalue . '&' . $videoname . '=' . $value1 . '">
				<p>
					<b class="rtitle"><b>' . strtoupper(substr($value, 18)) . '</b></b>
				</p>
				<label class="rdesc">
					<small>(' . $files1 . ' Parts)</small>
				</label>
			</a>
		</div>
	</div>';
            }
        }
        ?>

<?php
    // FRE-12: Breadcrumb
    require_once 'includes/breadcrumb.php';
    $crumbs = buildBreadcrumb($_GET);
    // If no seg context, show "Audiobooks" as single crumb
    if (empty($crumbs)) {
        $crumbs = [['label' => 'Audiobooks', 'color' => '#A78BFA', 'href' => null]];
    }
    renderBreadcrumb($crumbs);

    include_once"footer.php";
    pageCache_end($cacheFile);
?>
