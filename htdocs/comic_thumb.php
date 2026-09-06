<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$cipher = CONTENT_CIPHER;
$options = 0;
$iv = CONTENT_CIPHER_IV;
$encryptionKey = CONTENT_CIPHER_KEY;

function comicThumbEncryptParameterName(
    string $plain,
    string $cipher,
    string $encryptionKey,
    int $options,
    string $iv
): string {
    return str_replace(
        '=',
        '[equal]',
        base64_encode(
            openssl_encrypt(
                $plain,
                $cipher,
                $encryptionKey,
                $options,
                $iv
            )
        )
    );
}

function comicThumbDecryptValue(
    string $value,
    string $cipher,
    string $encryptionKey,
    int $options,
    string $iv
): string {
    $value = str_replace('[equal]', '=', $value);

    $decoded = base64_decode($value, true);

    if ($decoded === false) {
        return '';
    }

    $decrypted = openssl_decrypt(
        $decoded,
        $cipher,
        $encryptionKey,
        $options,
        $iv
    );

    return is_string($decrypted) ? $decrypted : '';
}

function comicThumbSendPlaceholder(): void
{
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: public, max-age=86400');

    echo <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="240" height="340" viewBox="0 0 240 340" role="img" aria-label="Comic Books cover">
  <defs>
    <linearGradient id="comicBackground" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#312e81"/>
      <stop offset="100%" stop-color="#7c3aed"/>
    </linearGradient>
  </defs>
  <rect width="240" height="340" rx="10" fill="url(#comicBackground)"/>
  <rect x="16" y="16" width="208" height="308" rx="8" fill="none" stroke="#c4b5fd" stroke-width="2"/>
  <text x="120" y="148" text-anchor="middle" font-size="54">📚</text>
  <text x="120" y="208" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-size="22" font-weight="700">COMIC</text>
  <text x="120" y="238" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-size="22" font-weight="700">BOOKS</text>
</svg>
SVG;

    exit;
}

$encryptedFileKey = comicThumbEncryptParameterName(
    'file',
    $cipher,
    $encryptionKey,
    $options,
    $iv
);

$encryptedPdfPath = isset($_GET[$encryptedFileKey])
    ? (string) $_GET[$encryptedFileKey]
    : '';

$pdfPath = comicThumbDecryptValue(
    $encryptedPdfPath,
    $cipher,
    $encryptionKey,
    $options,
    $iv
);

$booksRoot = realpath('/content/Books');

if (
    $booksRoot === false ||
    $pdfPath === '' ||
    strtolower(pathinfo($pdfPath, PATHINFO_EXTENSION)) !== 'pdf'
) {
    comicThumbSendPlaceholder();
}

$realPdfPath = realpath($pdfPath);

if (
    $realPdfPath === false ||
    strpos($realPdfPath, $booksRoot . DIRECTORY_SEPARATOR) !== 0
) {
    comicThumbSendPlaceholder();
}

$imageBasePath = dirname($realPdfPath)
    . DIRECTORY_SEPARATOR
    . pathinfo($realPdfPath, PATHINFO_FILENAME);

$imageCandidates = [
    $imageBasePath . '.jpg',
    $imageBasePath . '.jpeg',
    $imageBasePath . '.png',
    $imageBasePath . '.webp',
];

$contentTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];

foreach ($imageCandidates as $imagePath) {
    if (!is_file($imagePath) || !is_readable($imagePath)) {
        continue;
    }

    $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

    if (!isset($contentTypes[$extension])) {
        continue;
    }

    header('Content-Type: ' . $contentTypes[$extension]);
    header('Content-Length: ' . (string) filesize($imagePath));
    header('Cache-Control: public, max-age=86400');

    readfile($imagePath);
    exit;
}

comicThumbSendPlaceholder();