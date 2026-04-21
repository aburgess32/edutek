<?php ob_start(); ?>
    <!-- Custom styles for this readpdf.php-->
 <link href="css/saved.css" rel="stylesheet">
 <script src="ajax/pdfobject.min.js"></script>

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


    ?>
    
        <!--contens are here-->
        
        <!--result found number-->
    <div class="SaveWrapper">
        <span class="fa fa-fw fa-bookmark"></span> Books
    </div>

<embed src="<?php echo $filea ;?>" type="application/pdf" width="100%" height="600px" />

        <?php


/*

&nbsp;&nbsp;&nbsp;&nbsp;';
            $pdf = file_get_contents($filea);
    header('Content-Type: application/pdf');
    header('Cache-Control: public, must-revalidate, max-age=0'); // HTTP/1.1
    header('Pragma: public');
    header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
    header('Content-Length: '.strlen($pdf));
    header('Content-Disposition: inline; filename="'.basename($filea).'";');
    ob_clean();
    flush();
    echo $pdf;

*/

//echo '<script>PDFObject.embed("'.$filea.'");</script>';
//echo "<embed src='$filea' type='application/pdf' width='100%' height='100%' />";



        include_once"footer.php";
        ?>
