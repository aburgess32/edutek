<?php ob_start();
    include_once "includes/auth.php";
?>
  <link href="css/WatchVideos.css" rel="stylesheet">
  <script src="ajax/jquery.min.js"></script>
    <script src="ajax/popper.min.js"></script>
    <script src="ajax/ajax.js"></script>
    <style>
.playlist {
  background-color: #2596be;
  color: #ffffff;
  padding: 20px;
  margin: 10px;
  margin-left:38px;
  border-radius: 10px;
  box-shadow: 0 0 10px rgba(0, 0, 0, 0.2);
}

.playlist h2 {
  font-size: 24px;
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
    $cipher = "BF-CBC";
  $iv_length = openssl_cipher_iv_length($cipher);
  $options = 0;
  $iv = "91011121";
  $encryption_key = "hfjfydjnvhbjfi";
  $decryption_iv = "91011121";
  $decryption_key = "hfjfydjnvhbjfi";

    // FRE-12: Propagate breadcrumb context through watch.php links
    $bcQuery = '';
    if (isset($_GET['seg']) && $_GET['seg'] !== '') {
        $bcQuery .= '&seg=' . urlencode($_GET['seg']);
    }
    if (isset($_GET['topic']) && $_GET['topic'] !== '') {
        $bcQuery .= '&topic=' . urlencode($_GET['topic']);
    }

        $videolink1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink1", $cipher, $encryption_key, $options, $iv)));
        $videoname1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname1", $cipher, $encryption_key, $options, $iv)));
        $videolink = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink", $cipher, $encryption_key, $options, $iv)));
        $videoname = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname", $cipher, $encryption_key, $options, $iv)));

    //nabvbar
/*$dfile =$_GET[$videolink];
 $dfile1 = $_GET[$videoname];
 $dfilea =$_GET[$videolink1];
 $dfile1b =$_GET[$videoname1];*/
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
  $ff3 = array();
  $video = array('mp4','mov','wmv','flv','f4v','avi','WebM','mkv');
    ?>
    
        <!--contens are here-->

        <!--//much vodes-->
<div class="watchpage">
        <!-----Container Fluid-->
    <?php

    if ($filea == '') {
        $files2 = (glob($dd2 . "*", GLOB_BRACE));
        $length = strlen($dd2);
        if (in_array(pathinfo(substr($files2[0], ($length)), PATHINFO_EXTENSION), $video)) {
            $file1b = substr($files2[0], ($length));
            echo'<div class="Wwrapper">
		<video class="Wvideo"  src="' . $files2[0] . '" autoplay controls></video>
		<p class="Wtitle" ex1="' . $file1b . '">' . $file1b . '</p>
	</div>';
        }
    } else {
        if (in_array(pathinfo($file1b, PATHINFO_EXTENSION), $video)) {
            echo'<div class="Wwrapper">
		<video class="Wvideo"  src="' . $filea . '" autoplay controls></video>
		<p class="Wtitle" ex1="' . $file1b . '">' . $file1b . '</p>
	</div>';
        }
    }

    ?>

    
    
        <!--Match Videos-->
    <div class="Swrapper">
    
        <h3><?php echo $file1; ?></h3>
        <hr/>           <?php
    #t=<?php print '20,40';

        if (in_array(pathinfo($file1b, PATHINFO_EXTENSION), $video)) {
            $encryptfile = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file, $cipher, $encryption_key, $options, $iv)));
            $encryptfile1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file1, $cipher, $encryption_key, $options, $iv)));
            $encryptfilea = str_replace('=', '[equal]', base64_encode(openssl_encrypt($filea, $cipher, $encryption_key, $options, $iv)));
            $encryptfile1b = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file1b, $cipher, $encryption_key, $options, $iv)));

            echo'<div class="WSWrapper">
			
			<div class="footp">
				&nbsp;
				
				<a href="watch.php?&' . $videolink . '=' . $encryptfile . '&' . $videoname . '=' . $encryptfile1 . '&' . $videolink1 . '=' . $encryptfilea . '&' . $videoname1 . '=' . $encryptfile1b . $bcQuery . '">
						<p> ' . $file1b . '
					</p>
				</a>
				&nbsp;
				<button>
				<a href="' . $file . '/' . $file1b . '" download="' . $file1b . '"><i class="fa fa-download "> Download</i></a>
			</button>
			
			</div>
			&nbsp;
		</div>';
        }
        ?>


<?php
foreach ($ff2 as $key => $value) {
    if (substr($value, ($length)) != $file1b) {
        if (in_array(pathinfo(substr($value, ($length)), PATHINFO_EXTENSION), $video)) {
            $encryptfile = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file, $cipher, $encryption_key, $options, $iv)));
            $encryptfile1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file1, $cipher, $encryption_key, $options, $iv)));
            $encryptvalue = str_replace('=', '[equal]', base64_encode(openssl_encrypt($value, $cipher, $encryption_key, $options, $iv)));
            $encryptname = str_replace('=', '[equal]', base64_encode(openssl_encrypt(substr($value, ($length)), $cipher, $encryption_key, $options, $iv)));

            ?>
        <br>
        <br>
        <br>

<div class="WSWrapper playlist">    
            
                <a href="watch.php?&<?php echo $videolink . '=' . $encryptfile . '&' . $videoname . '=' . $encryptfile1 . '&' . $videolink1 . '=' . $encryptvalue . '&' . $videoname1 . '=' . $encryptname . $bcQuery;?>">
                    <p class="playing" style="color: #ffffff;">
                        <i class="fa fa-youtube-play" style="font-size:48px;color:red"></i><?php echo substr($value, ($length));?>
                    </p>
                </a>
        </div>
    
            <?php
        }
    }
}
?>
 
    </div>
    </div>
    <!---/Contaner Fliud-->

<?php
    // FRE-12: Breadcrumb — watch page shows full 3-level trail
    require_once 'includes/breadcrumb.php';
    // Pass $file (decrypted content path e.g. "videos/Primary Multiplication") for reverse-lookup fallback
    // when seg/topic params are missing. $file1b is the video filename for the leaf crumb title.
    $bcVideoTitle = $file1b ?? $file1 ?? null;
    $bcContentPath = $file ?? null;
    $crumbs = buildBreadcrumb($_GET, $bcVideoTitle, true, $bcContentPath);
    renderBreadcrumb($crumbs);
?>

        <!----Foter-->
    <footer class="sticky-footer">
      <div class="container">
        <div class="text-center">
          <small></small>
        </div>
      </div>
    </footer>

        <!---/Scrol-->
    
</div>
    <!-----/Main Container
    
    $(document).ready(function(){
     
    $(window).scroll(function(){
        //var lastID = $('.load-more').attr('lastID');
        var winCached = $(window),
  docCached = $(document);
        var docElement = $(document)[0].documentElement;
         var winElement = $(window)[0];
          var counter  = parseInt($(".WSWrapper:last").attr("counter"));
          var LastId1  = parseInt($(".WSWrapper:last").attr("lid")); /* get the id of the last div */

        var matched = 0;
        matched = $(".Swrapper p");
        if(($(window).scrollTop() + window.innerHeight == $(document).height()) && counter>=matched.length){

            var LastDiv = $(".WSWrapper:last"); /* get the last div of the dynamic content using ":last" */
            var LastId  = parseInt($(".WSWrapper:last").attr("lid"));
            var FirstId  = parseInt($(".WSWrapper:last").attr("sid")); /* get the id of the last div */
            var File1  = $(".footp:last").attr("d1"); /* get the id of the last div */
            var current1  = $(".playing:last").attr("ex"); /* get the id of the last div */
            var File2  = $(".trying:last").attr("files2"); /* get the id of the last div */
            $.ajax({
                type: "POST",
                 url: "check_array.php",
                data: {firstid : FirstId,lastid : LastId,filea : File1,file1a : File2,current : current1},
                beforeSend:function(){
                    $('.load-more').show();
                },
                success:function(html){
                     if(html){
                         $('.load-more').remove();
                        LastDiv.after(html); 
                        //alert(matched.length);
                         }
                    
                    //$('.WSWrapper').append(html);
                },
                complete: function(){
                    $('.load-more').remove();
                }
            });
        }
    });
});


    -->


<?php
// FRE-10: Determine thumbnail path server-side for progress tracking
$cwThumbPath = '';
if (!empty($filea)) {
    // Primary: same filename with .jpg extension
    $cwThumbPath = preg_replace('/\.[^.]+$/', '.jpg', $filea);
    if (!file_exists($cwThumbPath)) {
        // Fallback: subcategory folder .jpg in parent directory
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
<script src="js/login.js"></script>
<script src="js/avatar-bubble.js"></script>
