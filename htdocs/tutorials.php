    <!-- Custom styles for this watch-->
  <link href="css/saved.css" rel="stylesheet">
<style type = "text/css">
         #scroll {
            display:block;
            border: 1px solid red;
            padding:5px;
            margin-top:5px;
            width:80%;
            height:60%;
            overflow:scroll;
             text-align: left;
         }
         @media screen and (min-width: 992px){
            #scroll {
            display:block;
            border: 1px solid red;
            padding:5px;
            margin-top:5px;
            width:70%;
            height:100px;
            overflow:scroll;
             text-align: left;
         } 
         }
         @media screen and (max-width: 1366px) and (min-width: 1115px){
             #scroll {
            display:block;
            border: 1px solid red;
            padding:5px;
            margin-top:5px;
            width:50%;
            height:100px;
            overflow:scroll;
             text-align: left;
         }
         }
         @media only screen and (max-width: 600px) {
             #scroll {
            display:block;
            border: 1px solid red;
            padding:5%;
            margin-top:5%;
            margin-left:5%;
            width:90%;
            height:33%;
            overflow:scroll;
             text-align: left;
         }
         }
         @media screen and (max-width: 873px) and (min-width: 770px){
             #scroll {
            display:block;
            border: 1px solid red;
            padding:5px;
            margin-top:5px;
            width:50%;
            height:100px;
            overflow:scroll;
             text-align: left;
         }
         }
         @media screen and (max-width: 1024px) and (min-width: 873px){
             #scroll {
            display:block;
            border: 1px solid red;
            padding:5px;
            margin-top:5px;
            width:70%;
            height:60%;
            overflow:scroll;
             text-align: left;
         } 
         }
 .links{
  background-color: #2596be;
  color: #ffffff;
  border-radius: 1px;
  box-shadow: 0 0 10px rgba(0, 0, 0, 0.2);
  display:block;
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
    //nabvbar
    $encryption2 = str_replace('=', '[equal]', base64_encode(openssl_encrypt(
        "course",
        $cipher,
        $encryption_key,
        $options,
        $iv
    )));
    $file = $_GET[$encryption2];
    $decryption = openssl_decrypt(base64_decode(str_replace('[equal]', '=', $file)), $cipher, $decryption_key, $options, $iv);

        $videolink1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt(
            "videolink1",
            $cipher,
            $encryption_key,
            $options,
            $iv
        )));
        $videoname1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt(
            "videoname1",
            $cipher,
            $encryption_key,
            $options,
            $iv
        )));
        $videolink = str_replace('=', '[equal]', base64_encode(openssl_encrypt(
            "videolink",
            $cipher,
            $encryption_key,
            $options,
            $iv
        )));
        $videoname = str_replace('=', '[equal]', base64_encode(openssl_encrypt(
            "videoname",
            $cipher,
            $encryption_key,
            $options,
            $iv
        )));
    //$file = $_GET['course'];
        ?>
    
        <!--contens are here-->
        
        <!--result found number-->
    <div class="SaveWrapper">
        <span class="fa fa-fw fa-bookmark"></span><?php echo $decryption;?> Tutorials
    </div>
        <!--//result found number-->
        
        <?php

        $dd2 = "videos/" . $decryption . "/";
        $length = strlen($dd2);
        $video = array('mp4','mov','wmv','flv','avi','WebM','mkv');
        $ff2 = (glob($dd2 . "*"));

        foreach ($ff2 as $value) {
            if (is_dir($value)) {
                $f2a = (scandir($value));
       //echo count($ff2);
       //$folders = 0;
                $files1 = 0;
                foreach ($f2a as $vala) {
                         $exta = pathinfo($vala, PATHINFO_EXTENSION);

                    if ($exta == 'mp4' or $exta == 'flv' or $exta == 'mov' or $exta == 'avi' or $exta == 'f4v') {
                        $files1++;
                    }
                }
                if ($files1 > 0) {
                           $videoname12 = str_replace('=', '[equal]', base64_encode(openssl_encrypt(
                               substr($value, $length),
                               $cipher,
                               $encryption_key,
                               $options,
                               $iv
                           )));
                           $value1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt(
                               $value,
                               $cipher,
                               $encryption_key,
                               $options,
                               $iv
                           )));
                           echo'<div class="SavedWrapper">
		<a href="watch.php?&' . $videolink1 . '=&' . $videoname1 . '=&' . $videolink . '=' . $value1 . '&' . $videoname . '=' . $videoname12 . '">';
                                 $files = $value . "/";
                                   $files2 = (glob($files . "*", GLOB_BRACE));
                                   $length1 = strlen($files);
                    if (file_exists($dd2 . substr($value, $length))) {
                        echo'<img src="' . $dd2 . substr($value, $length) . '.jpg" class="rimage">';
                    } else {
                        echo'<img src="images/sample.png" class="rimage">';
                    }
                    echo'</a>
		<div class="rcontents">
			&nbsp;&nbsp;&nbsp;&nbsp;
			<a href="watch.php?&' . $videolink1 . '=&' . $videoname1 . '=&' . $videolink . '=' . $value1 . '&' . $videoname . '=' . $videoname12 . '">
				<p>
					<b class="rtitle"><b>' . strtoupper(substr($value, $length)) . '</b></b>
				</p>
			</a>
				<label class="rdesc" id="scroll">
				<h6><B>TOPICS</B></h6>
				';
                       $files12 = $value . "/";
                    $files22 = (glob($files12 . "*"));
                    $length12 = strlen($files12);
               //foreach($files22 as $value9 ){
                    for ($i = 0; $i < count($files22); $i++) {
                        $encryptfile = str_replace('=', '[equal]', base64_encode(openssl_encrypt($files12, $cipher, $encryption_key, $options, $iv)));
                        $encryptfile1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt(substr($value, $length), $cipher, $encryption_key, $options, $iv)));
                        $encryptfilea = str_replace('=', '[equal]', base64_encode(openssl_encrypt($files22[$i], $cipher, $encryption_key, $options, $iv)));
                        $encryptfile1b = str_replace('=', '[equal]', base64_encode(openssl_encrypt(substr($files22[$i], $length12), $cipher, $encryption_key, $options, $iv)));
                        if (stristr($files22[$i], "@eaDir") == true or stristr($files22[$i], ".DS_Store") == true or stristr($files22[$i], "Thumbs.db") == true) {
                                unlink($files22[$i]);
                        } else {
                              echo'
				<a class="links" href="watch.php?&' . $videolink . '=' . $encryptfile . '&' . $videoname . '=' . $encryptfile1 . '&' . $videolink1 . '=' . $encryptfilea . '&' . $videoname1 . '=' . $encryptfile1b . '">
						
					<b>' . substr($files22[$i], $length12) . '</b>
				</a>&nbsp;&nbsp;';
                        }
                     //}
                    }
                /*
                if (unlink($filename)) {
           echo 'The file ' . $filename . ' was deleted successfully!';
                 }
                $dd22 =$value."/";
                $length2 = strlen($dd22);
                $ff22 =(glob($dd22."*", GLOB_BRACE));
                foreach($ff22 as $vala2 )
               {
              echo'<small>('.substr($vala2, ($length2)).')</small>';
               <small>('.$files1.' Parts)</small>
              }
              <small>('.substr($value, $length).' Parts)</small>
                */
                    echo'
					
				</label>
			
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
