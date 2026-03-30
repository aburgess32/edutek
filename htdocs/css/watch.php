	<!-- Custom styles for this watch-->
  <link href="css/WatchVideos.css" rel="stylesheet">

<?php
	//nabvbar
	include_once"navbar.php";
	//nabvbar
	 $file='';
 $file = $_GET['videolink'];
 $file1='';
 $file1 = $_GET['videoname'];
 
 $filea='';
 $filea = $_GET['videolink1'];
 $file1b='';
 $file1b = $_GET['videoname1'];

 $dd2 =$file."/";
 $length = strlen($dd2);
 $ff2 =(glob($dd2."*", GLOB_BRACE));
 $ray = array();
 $video = array('mp4','mov','wmv','flv','avi','WebM','mkv');
?>
	
		<!--contens are here-->

		<!--//much vodes-->

	<?php
	
	if($filea==''){
		$files2 = (glob($dd2."*", GLOB_BRACE));
		$length = strlen($dd2);
		if(in_array(pathinfo(substr($files2[0], ($length)), PATHINFO_EXTENSION), $video)) {

	echo'<div class="Wwrapper">
		<video class="Wvideo"  src="'.$files2[0].'" autoplay controls></video>
		<p class="Wtitle">'.substr($files2[0], ($length)).'</p>
	</div>';
		}
}
else{
	if(in_array(pathinfo($file1b, PATHINFO_EXTENSION), $video)) {

	echo'<div class="Wwrapper">
		<video class="Wvideo"  src="'.$filea.'" autoplay controls></video>
		<p class="Wtitle">'.$file1b.'</p>
	</div>';
	}
}
	
	?>

	
	
		<!--Match Videos-->
	<div class="Swrapper">
	<hr>
		<h3><?php echo $file1; ?></h3>
		<hr>
					<?php
	#t=<?php print '20,40';
	if($filea!=''){
		if(in_array(pathinfo($file1b, PATHINFO_EXTENSION), $video)) {
		echo'<div class="WSWrapper">
			
				<div>
					<a href="watch.php?&videolink='.$file.'&videoname='.$file1.'&videolink1='.$filea.'&videoname1='.$file1b.'">';
						//<img src="images/sample.png" class="Contents">
						printf("<video src='".$file."/%s' class='Contents'></video>", basename($file1b.'#t=4,10'));
					echo '</a>
					</a>
				</div>
			<div class="foot">
				&nbsp;
				
				<a href="watch.php?&videolink='.$file.'&videoname='.$file1.'&videolink1='.$filea.'&videoname1='.$file1b.'">
						<p class="footp"><i class="fa fa-file-text-o "></i> '.$file1b.'
					</p>
				</a>
			</div>
		</div>';
}
	}

	$ff2 = array_slice($ff2,0,5);

 	foreach($ff2 as $value )

{

	

if(substr($value, ($length))!=$file1b){
	if(in_array(pathinfo(substr($value, ($length)), PATHINFO_EXTENSION), $video)) {
echo'<div class="WSWrapper">
			
				<div>
					<a href="watch.php?&videolink='.$file.'&videoname='.$file1.'&videolink1='.$value.'&videoname1='.substr($value, ($length)).'">';
						//<img src="images/sample.png" class="Contents">
						printf("<video src='".$file."/%s' class='Contents'></video>", basename(substr($value, ($length)).'#t=5,8'));
					echo'</a>
				</div>
			<div class="footp">
				&nbsp;
				
				<a href="watch.php?&videolink='.$file.'&videoname='.$file1.'&videolink1='.$value.'&videoname1='.substr($value, ($length)).'">
					<p>
						<i class="fa fa-file-text-o "></i> '.substr($value, ($length)).'
					</p>
				</a>
			</div>
		</div>';
}
}
}

?>
		
	</div>
	<!---/Contaner Fliud-->
	
	
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
	<!-----/Main Container-->

