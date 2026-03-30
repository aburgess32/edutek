<?php
  $lastid=0;
  $firstid=0;
  $file = '';
  $file1='';
  $playing='';
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
  $a=array();
  
    $lastid = $_POST["lastid"] ; // save the posted value in a variable
    $firstid = $_POST["firstid"]; // save the posted value in a variable
    $file = $_POST["filea"]; // save the posted value in a variable
    $file1 = $_POST["file1a"]; // save the posted value in a variable
    $playing = $_POST["current"]; // save the posted value in a variable
   // $count = $_POST["count"]; // save the posted value in a variable
   
	$dd2 =$file."/";
 $video = array('mp4','mov','wmv','flv','avi','WebM','mkv');	
 $length = strlen($dd2);
 $ff4 =(glob($dd2."*", GLOB_BRACE));
 $ff5 = array();

 if($firstid >= 1 and $firstid <= (count($ff4)+1)){
$ff5 = array_slice($ff4,$firstid,1);

    if(substr($ff5[0], ($length))!=$playing){
	if(in_array(pathinfo(substr($ff5[0], ($length)), PATHINFO_EXTENSION), $video)) {
		if(in_array(substr($ff5[0], ($length)), $a)){
		}
		else{
		$encryptfile = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file, $cipher,$encryption_key, $options, $iv))); 
	    $encryptfile1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt($file1,$cipher,$encryption_key, $options, $iv))); 
	    $encryptvalue = str_replace('=', '[equal]', base64_encode(openssl_encrypt($ff5[0],$cipher,$encryption_key, $options, $iv))); 
		$encryptname = str_replace('=', '[equal]', base64_encode(openssl_encrypt(substr($ff5[0], ($length)), $cipher,$encryption_key, $options, $iv)));
		
?>

<div class="WSWrapper" lid="<?php echo $lastid +1; ?>" counter="<?php echo count($ff4);?>" sid="<?php echo $firstid +1 ; ?>">
		
				<div class="trying" files2="<?php echo $file1; ?>">
				<a href="watch.php?&<?php echo $videolink.'='.$encryptfile.'&'.$videoname.'='.$encryptfile1.'&'.$videolink1.'='.$encryptvalue.'&'.$videoname1.'='.$encryptname;?>">
					
					<!--a href="watch.php?&videolink=<?php //echo $file;?>&videoname=<?php //echo $file1;?>
					&videolink1=<?php //echo $ff5[0];?>&videoname1=<?php// echo substr($ff5[0], ($length));?>"-->
						
				<?php printf("<video src='".$file."/%s' class='Contents'></video>", basename(substr($ff5[0], ($length)).'#t=6,12'));?>
					</a>
				</div>
			<div class="footp"  d1="<?php echo $file; ?>" >
				&nbsp;
				<a href="watch.php?&<?php echo $videolink.'='.$encryptfile.'&'.$videoname.'='.$encryptfile1.'&'.$videolink1.'='.$encryptvalue.'&'.$videoname1.'='.$encryptname;?>">
				
					<p class="playing"  ex="<?php echo $file1b;?>">
						 <?php echo substr($ff5[0], ($length));?>
					</p>
				</a>
				&nbsp;
				<button>
				<a href="<?php echo $file.'/'.substr($ff5[0], ($length)); ?>" download="<?php echo substr($ff5[0], ($length));?>"><i class="fa fa-download "> Download</i></a>
			</button>
			
			</div>
					<?php	
					//if($lastid == ((count($ff4)))){
	?>

					
<?php
//}
//echo"<script type=\"text/javascript\">$('#loader-icon').hide();</script>";
?>
		<div class="load-more" style="display: none;">
        <img src="loader.gif" />
    </div>
    </div>

<?php 
	}
array_push($a,substr($ff5[0], ($length)));
}

}
$lastid=0;
  $firstid=0;
}
?>

