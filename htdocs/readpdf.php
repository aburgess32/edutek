<?php ob_start(); ?>
<link href="css/saved.css" rel="stylesheet">
<script src="ajax/pdfobject.min.js"></script>

<?php
include_once "navbar.php";

$cipher = CONTENT_CIPHER;
$options = 0;
$iv = CONTENT_CIPHER_IV;
$encryption_key = CONTENT_CIPHER_KEY;
$decryption_key = CONTENT_CIPHER_KEY;

function encParamName($plain, $cipher, $key, $opts, $iv) {
    return str_replace('=', '[equal]', base64_encode(openssl_encrypt($plain, $cipher, $key, $opts, $iv)));
}

function decQueryParam($queryKey, $cipher, $key, $opts, $iv) {
    if (!isset($_GET[$queryKey]) || $_GET[$queryKey] === '') {
        return '';
    }

    $raw = str_replace('[equal]', '=', $_GET[$queryKey]);
    $decoded = base64_decode($raw, true);
    if ($decoded === false) {
        return '';
    }

    $plain = openssl_decrypt($decoded, $cipher, $key, $opts, $iv);
    return ($plain !== false && $plain !== null) ? $plain : '';
}

$videolink1 = encParamName("videolink1", $cipher, $encryption_key, $options, $iv);
$videoname1 = encParamName("videoname1", $cipher, $encryption_key, $options, $iv);
$videolink  = encParamName("videolink",  $cipher, $encryption_key, $options, $iv);
$videoname  = encParamName("videoname",  $cipher, $encryption_key, $options, $iv);

$file  = decQueryParam($videolink,  $cipher, $decryption_key, $options, $iv);
$file1 = decQueryParam($videoname,  $cipher, $decryption_key, $options, $iv);
$filea = decQueryParam($videolink1, $cipher, $decryption_key, $options, $iv);
$file1b = decQueryParam($videoname1, $cipher, $decryption_key, $options, $iv);

$pdfPath = $filea;
$pdfName = $file1b !== '' ? $file1b : basename($filea);

$isValidPdf = false;
$pdfUrl = '';

if ($pdfPath !== '') {
    $ext = strtolower(pathinfo($pdfPath, PATHINFO_EXTENSION));
    if ($ext === 'pdf' && file_exists($pdfPath) && is_file($pdfPath)) {
        $isValidPdf = true;
        if (function_exists('resolveContentUrl')) {
            $pdfUrl = resolveContentUrl($pdfPath);
        } else {
            $pdfUrl = $pdfPath;
        }
    }
}
?>

<div class="SaveWrapper">
    <span class="fa fa-fw fa-bookmark"></span> Books
</div>

<div class="container-fluid" style="padding-top:20px; padding-bottom:30px; min-height:60vh;">
<?php if ($isValidPdf): ?>
    <div style="margin-bottom:15px; font-weight:600;">
        <?php echo htmlspecialchars($pdfName, ENT_QUOTES, 'UTF-8'); ?>
    </div>

    <embed
        src="<?php echo htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8'); ?>"
        type="application/pdf"
        width="100%"
        height="700px"
    />

    <div style="margin-top:12px;">
        <a href="<?php echo htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
            Open PDF in new tab
        </a>
    </div>
<?php else: ?>
    <div class="alert alert-warning" style="margin-top:10px;">
        PDF not found or invalid file.
    </div>

    <div style="font-size:14px; color:#666;">
        File path:
        <code><?php echo htmlspecialchars($pdfPath, ENT_QUOTES, 'UTF-8'); ?></code>
    </div>
<?php endif; ?>
</div>

<?php include_once "footer.php"; ?>