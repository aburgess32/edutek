	<!-- Custom styles for this listen-->
  <link href="css/saved.css" rel="stylesheet">

<?php
	//nabvbar
	include_once"navbar.php";
	//nabvbar
?>
	
		<!--contens are here-->
		
		<!--result found number-->
	<div class="SaveWrapper">
		<span class="fa fa-fw fa-bookmark"></span>Gosple Music
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

        $videolink1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink1", $cipher,$encryption_key, $options, $iv))); 
	    $videoname1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname1",$cipher,$encryption_key, $options, $iv))); 
	    $videolink = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videolink", $cipher,$encryption_key, $options, $iv))); 
		$videoname = str_replace('=', '[equal]', base64_encode(openssl_encrypt("videoname",$cipher,$encryption_key, $options, $iv))); 
	
 $dd2 ="videos/Music/";

 $ff2 =(glob($dd2."*"));
  $length = strlen($dd2);
 	foreach($ff2 as $value )
{
	 if (is_dir($value)) {
	$f2a =(scandir($value));
 //echo count($ff2);
 //$folders = 0; 
$files1 = 0; 
 	foreach($f2a as $vala )
{
	$exta = pathinfo($vala, PATHINFO_EXTENSION);

	 if($exta == 'mp3' or $exta == 'wav' or $exta == 'wma' or $exta == 'mp3' or $exta == 'm4a'){ 
	
            $files1++; 
        }
}
if($files1 > 0){	

$encryptvalue =str_replace('=', '[equal]', base64_encode(openssl_encrypt($value, $cipher,$encryption_key, $options, $iv))); 
$value1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt(substr($value, $length), $cipher,$encryption_key, $options, $iv))); 

echo'<div class="SavedWrapper">
		<a href="listen.php?&'.$videolink1.'=&'.$videoname1.'=&'.$videolink.'='.$encryptvalue.'&'.$videoname.'='.$value1.'">
			<img src="images/sample.png" class="rimage">
		</a>
		<div class="rcontents">
			&nbsp;&nbsp;&nbsp;&nbsp;
			<a href="listen.php?&'.$videolink1.'=&'.$videoname1.'=&'.$videolink.'='.$encryptvalue.'&'.$videoname.'='.$value1.'">
				<p>
					<b class="rtitle"><b>'.strtoupper(substr($value, $length)).'</b></b>
				</p>
				<label class="rdesc">
					<small>('.count(glob($value."/*", GLOB_BRACE)).' Parts)</small>
				</label>
			</a>
		</div>
	</div>';
}

else{


}
}
}
?>

<?php
	include_once"footer.php";
?>