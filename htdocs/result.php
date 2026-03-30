	<!-- Custom styles for this watch-->
   <link href="css/saved.css" rel="stylesheet">

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

        $videolink1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink1", $cipher, $encryption_key, $options, $iv))); 
	    $videoname1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname1", $cipher,$encryption_key, $options, $iv))); 
	    $videolink =str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink", $cipher,$encryption_key, $options, $iv))); 
		$videoname = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname", $cipher,$encryption_key, $options, $iv))); 
		
if(isset($_POST['submit']))
{
	$searching = ($_POST['search']);
?>
	
		<!--contens are here-->
		
		<!--result found number-->
	<div class="Rwrapper">
		<span class="fa fa-fw fa-navicon"></span> 
	</div>
		<!--//result found number-->
		
		
		
		<!--result Wrapper-->
							<?php
$acceptedFormats = array('pdf');
$audio = array('wav','wma','mp3','m4a');
$video = array('mp4','mov','wmv','flv','fl4','avi','WebM','mkv');

$gfg_folderpath = "videos/"; 
$filesIn=array();
$filesIn1=array();
// CHECKING WHETHER PATH IS A DIRECTORY OR NOT 
if (is_dir($gfg_folderpath)) { 
    // GETING INTO DIRECTORY 
    $firstfolder= opendir($gfg_folderpath); { 
        // CHECKING FOR SMOOTH OPENING OF DIRECTORY 
        if ($firstfolder) { 
            //READING NAMES OF EACH ELEMENT INSIDE THE DIRECTORY  
            while (($gfg_subfolder1 = readdir($firstfolder)) != FALSE) { 
                // CHECKING FOR FILENAME ERRORS 
             if ($gfg_subfolder1 != '.' && $gfg_subfolder1 != '..') { 
                   /*echo "SUBFOLDER--" .$gfg_subfolder1 . "<br> 
                    "."Files in ".$gfg_subfolder1."--<br>"; */
                      
                $dirpath1 = "videos/" . $gfg_subfolder1 . "/"; 
                    // GETING INSIDE EACH ANOTHER SUBFOLDERS 
                  
			$secondfolder = opendir($dirpath1); { 
        // CHECKING FOR SMOOTH OPENING OF DIRECTORY 
        if ($secondfolder) { 
            //READING NAMES OF EACH ELEMENT INSIDE THE DIRECTORY  
            while (($gfg_subfolder2 = readdir($secondfolder)) != FALSE) { 
                // CHECKING FOR FILENAME ERRORS 
             if ($gfg_subfolder2 != '.' && $gfg_subfolder2 != '..') { 
                    /*echo "SUBFOLDER--" .$gfg_subfolder2 . "<br> 
                    "."Files in ".$gfg_subfolder2."--<br>"; */
                      
                $dirpath2 = "videos/". $gfg_subfolder1 . "/" . $gfg_subfolder2 . "/"; 
                    // GETING INSIDE EACH SUBFOLDERS 
                  if (is_dir($dirpath2)) { 
                        $file = opendir($dirpath2); { 
                            if ($file) { 
                //READING NAMES OF EACH FILE INSIDE SUBFOLDERS 
               while (($gfg_filename = readdir($file)) != FALSE) { 
                if ($gfg_filename != '.' && $gfg_filename != '..') { 
				$filesIn=array($gfg_filename);
				$filesIn1=array($dirpath2.$gfg_filename);
				if (stristr(pathinfo($gfg_filename, PATHINFO_FILENAME), $searching) != false) {
				$id = array_search($searching,$filesIn,true);
				if(substr($filesIn[$id], 0, 2) != '._' ){
			 if(in_array(pathinfo($filesIn[$id], PATHINFO_EXTENSION), $audio)) {
            
			$encryptfile = str_replace('=', '[equal]', base64_encode(openssl_encrypt($dirpath2, $cipher,$encryption_key, $options, $iv))); 
	    $encryptfile1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($gfg_subfolder2, $cipher,$encryption_key, $options, $iv))); 
	    $encryptfilea = str_replace('=', '[equal]', base64_encode(openssl_encrypt($filesIn1[$id],$cipher,$encryption_key, $options, $iv))); 
		$encryptfile1b = str_replace('=', '[equal]', base64_encode(openssl_encrypt($filesIn[$id], $cipher,$encryption_key, $options, $iv)));
		
					echo'<div class="SavedWrapper">
		<a href="listen.php?&'.$videolink.'='.$encryptfile.'&'.$videoname.'='.$encryptfile1.'&'.$videolink1.'='.$encryptfilea.'&'.$videoname1.'='.$encryptfile1b.'">';
			if (file_exists($dirpath2.$gfg_subfolder2.'.jpg')) {
			echo'<img src="'.$dirpath2.$gfg_subfolder2.'.jpg" class="rimage">';
		}
		else{
			echo'<img src="images/music.jpg" class="rimage">';
		}
		echo'</a>
		<div class="rcontents">
			<a href="listen.php?&'.$videolink.'='.$encryptfile.'&'.$videoname.'='.$encryptfile1.'&'.$videolink1.'='.$encryptfilea.'&'.$videoname1.'='.$encryptfile1b.'">
				<p>
					<b class="rtitle">'.$filesIn[$id].'</b>
				</p>
			</a>
			 <button>
				<a href="'.$filesIn1[$id].'" download="'.$filesIn[$id].'"><i class="fa fa-download "> Download</i></a>
			</button>
		</div>
	</div>';
				 }
             else if(in_array(pathinfo($filesIn[$id], PATHINFO_EXTENSION), $acceptedFormats)) {
	    $encryptfile = str_replace('=', '[equal]', base64_encode(openssl_encrypt($dirpath2, $cipher,$encryption_key, $options, $iv))); 
	    $encryptfile1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($gfg_subfolder2, $cipher,$encryption_key, $options, $iv))); 
	    $encryptvalue = str_replace('=', '[equal]', base64_encode(openssl_encrypt($filesIn1[$id],$cipher,$encryption_key, $options, $iv))); 
		$encryptvalue1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($filesIn[$id], $cipher,$encryption_key, $options, $iv)));
	
			//echo"<img src='".substr_replace($dirpath2 ,"",-1).".jpg' class='rimage'>";
		   echo'<div class="SavedWrapper">
		<a href="readpdf.php?&'.$videolink.'='.substr_replace($dirpath2 ,"",-1).'&'.$videoname.'='.$encryptfile1.'&'.$videolink1.'='.$encryptvalue.'&'.$videoname1.'='.$encryptvalue1.'">';
		if (file_exists(substr_replace($dirpath2 ,"",-1).'.jpg')) {
			echo'<img src="'.substr_replace($dirpath2 ,"",-1).'.jpg" class="rimage">';
		}
		else{
			echo'<img src="images/books.jpg" class="rimage">';
		}
		echo'</a>
		
		<div class="rcontents">
			<a href="readpdf.php?&'.$videolink.'='.$encryptfile.'&'.$videoname.'='.$encryptfile1.'&'.$videolink1.'='.$encryptvalue.'&'.$videoname1.'='.$encryptvalue1.'">
				<p>
					<b class="rtitle">'.$filesIn[$id].'</b>
				</p>
			</a>
			 <button>
				<a href="'.$filesIn1[$id].'" download="'.$filesIn[$id].'"><i class="fa fa-download "> Download</i></a>
			</button>
		</div>
	</div>';  
		   
	 }
	   else if(in_array(pathinfo($filesIn[$id], PATHINFO_EXTENSION), $video)) {
		/*
		$files2 = (glob($dirpath2."*", GLOB_BRACE));
		$length1 = strlen($dirpath2);   
		$encryptdirpath2 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($dirpath2, $cipher,$encryption_key, $options, $iv))); 
	    $encryptgfg_subfolder2 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($gfg_subfolder2, $cipher,$encryption_key, $options, $iv))); 
	    $encryptfilesIn1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($filesIn1[$id],$cipher,$encryption_key, $options, $iv))); 
		$encrypt$filesIn = str_replace('=', '[equal]', base64_encode(openssl_encrypt($filesIn[$id], $cipher,$encryption_key, $options, $iv))); 
		//videolinkvideoname
		 echo'<div class="SavedWrapper">
		<a href="watch.php?&'.$videolink.'='.$encryptdirpath2.'&'.$videoname.'='.$encryptgfg_subfolder2.'&'.$videolink1.'='.$encryptfilesIn1.
		'&'.$videoname1.'='.$encrypt$filesIn.'">
		*/
		$files2 = (glob($dirpath2."*", GLOB_BRACE));
		$length1 = strlen($dirpath2);   
		
		$encryptdirpath2 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($dirpath2, $cipher,$encryption_key, $options, $iv))); 
	    $encryptgfg_subfolder2 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($gfg_subfolder2, $cipher,$encryption_key, $options, $iv))); 
	    $encryptfilesIn1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($filesIn1[$id],$cipher,$encryption_key, $options, $iv))); 
		$encryptfilesIn = str_replace('=', '[equal]', base64_encode(openssl_encrypt($filesIn[$id], $cipher,$encryption_key, $options, $iv))); 
		
		 echo'<div class="SavedWrapper">
		<a href="watch.php?&'.$videolink.'='.$encryptdirpath2.'&'.$videoname.'='.$encryptgfg_subfolder2.'&'.$videolink1.'='.$encryptfilesIn1.
		'&'.$videoname1.'='.$encryptfilesIn.'">
			';
			//if(in_array(pathinfo(substr($files2[0], ($length1)), PATHINFO_EXTENSION), $video)) {
			echo"<img src='".substr_replace($dirpath2 ,"",-1).".jpg' class='rimage'>";
			
		//}
echo'
		</a>
		<div class="rcontents">
			<a href="watch.php?&'.$videolink.'='.$encryptdirpath2.'&'.$videoname.'='.$encryptgfg_subfolder2.'&'.$videolink1.'='.$encryptfilesIn1.
		'&'.$videoname1.'='.$encryptfilesIn.'">
				<p>
					<b class="rtitle">'.$filesIn[$id].'</b>
				</p>
			</a>
			 <button>
				<a href="'.$filesIn1[$id].'" download="'.$filesIn[$id].'"><i class="fa fa-download "> Download</i></a>
			</button>
		</div>
	</div>';
	   }
			
				}
}
			   }                  } 
                         } 
                      } 
                   } 
               }
                 
                } 
            } 
        } 
    }
                } 
            } 
        } 
    } 
	

	?>
		<!--///result Wrapper-->
		
		
		<!--//contens are here-->




<?php
}
	include_once"footer.php";
?>


