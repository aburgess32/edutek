    <!-- Custom styles for this watch-->
  <link href="css/index.css" rel="stylesheet">
<?php
//equire_once('connect.php'); // Database connection file
//require_once('functions.php');  // PHP functions file

//$page_id = 1;
//$visitor_ip = $_SERVER['REMOTE_ADDR']; // stores IP address of visitor in variable

//add_view($conn, $visitor_ip, $page_id);
    ?>
<?php
    //nabvbar
    include_once"navhome.php";
    //nabvbar
    $check = array('Audiobooks','Books','Music','Comic Books');
$hostname = $_SERVER['HTTP_HOST'];


$cipher = "BF-CBC";
$iv_length = openssl_cipher_iv_length($cipher);
$options = 0;
$iv = "91011121";
$encryption_key = "hfjfydjnvhbjfi";
$decryption_iv = "91011121";
$decryption_key = "hfjfydjnvhbjfi";
?>

        
        <!--result Wrapper-->
        
    <div class="courses" id="all">
        <div class="">
        <div class="SavedWrapper">
            <a href="http://<?php echo $hostname; ?>:7862/" class="course-item">
                <span class="course-title">KIWIX KHAN</span>
            </a>
        </div>
        <div class="SavedWrapper">
            <a href="http://<?php echo $hostname; ?>:7863/" class="course-item">
                <span class="course-title">KIWIX KHMER</span>
            </a>
        </div>
        <div class="SavedWrapper">
            <a href="http://<?php echo $hostname; ?>:7864/" class="course-item">
                <span class="course-title">KIWIX MEDICAL</span>
            </a>
        </div>
        <div class="SavedWrapper">
            <a href="http://<?php echo $hostname; ?>:7865/" class="course-item">
                <span class="course-title">KIWIX WIKI</span>
            </a>
            </div>
        </div>
        <div class="">
        <div class="SavedWrapper">
            <a href="http://<?php echo $hostname; ?>:7860/" class="course-item">
                <span class="course-title">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;ARTIFICIAL INTELLIGENCE (AI)</span>
            </a>
            <!-- <a href="http://<?php echo $hostname; ?>:7860/" class="course-item">
                <span class="course-title">AI VOICE CLONING</span>
            </a> -->
            </div>
            <div class="SavedWrapper">
            <a href="http://<?php echo $hostname; ?>:7861/" class="course-item">
                <span class="course-title">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;AI IMAGE GENERATION</span>
            </a>
        </div>
        </div>
        <div class="">
        <div class="SavedWrapper">
            <a href="khan/" class="course-item">
                <span ><b>KHAN</b></span>
            </a>
        </div>
        <div class="SavedWrapper">
            <a href="http://<?php echo $hostname; ?>:9061/" class="course-item">
                <span ><b>KHAN INTERACTIVE</b></span>
            </a>
            </div>
        <div class="SavedWrapper">
            <a href="Wiki/" class="course-item">
                <span class="course-title">WIKI FOR SCHOOLS</span>
            </a>
            </div>
        </div>

        <?php
        $dd2 = "videos/";
        $ff2 = (glob($dd2 . "*"));
        $itemsPerRow = 3;
        $currentRow = 0;
        $itemCount = 0;

        foreach ($ff2 as $value) {
            if (count(glob($value . "*")) > 0) {
                if ($itemCount % $itemsPerRow == 0) {
                    if ($currentRow > 0) {
                        echo '</div>';
                    }
                    echo '<div class="">';
                    $currentRow++;
                }

                $title = strtoupper(substr($value, 7));
                $link = '#';

                switch (substr($value, 7)) {
                    case 'Audiobooks':
                        $link = 'audiobooks.php';
                        $title = 'AUDIO BOOKS';
                        break;
                    case 'Books':
                        $link = 'books.php';
                        $title = 'BOOKS';
                        break;
                    case 'Comic Books':
                        $link = 'Comic_books.php';
                        $title = 'COMIC BOOKS';
                        break;
                    case 'Music':
                        $link = 'music.php';
                        $title = 'LISTEN TO GOSPEL MUSIC';
                        break;
                    default:
                        $encryption = str_replace('=', '[equal]', base64_encode(openssl_encrypt(substr($value, 7), $cipher, $encryption_key, $options, $iv)));
                        $encryption1 = str_replace('=', '[equal]', base64_encode(openssl_encrypt("course", $cipher, $encryption_key, $options, $iv)));
                        $link = "tutorials.php?&{$encryption1}={$encryption}";
                }

                echo '<div class="SavedWrapper "><a href="' . $link . '" class="course-item">
						<span class="course-title">' . $title . '</span>
					</a></div>';

                $itemCount++;
            }
        }
        if ($itemCount % $itemsPerRow != 0) {
            echo '</div>';
        }
        ?>
    </div>

        <!--//contens are here-->




<?php
    include_once"footer.php";
?>
