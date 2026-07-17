<?php
$folder = isset($_GET['folder']) ? $_GET['folder'] : '';
$basePath = '/content/Books';

if ($folder === '') {
    http_response_code(404);
    exit;
}

$folder = basename($folder);

$possibleFiles = [
    $basePath . '/' . $folder . '.jpg',
    $basePath . '/' . $folder . '.jpeg',
    $basePath . '/' . $folder . '.png'
];

foreach ($possibleFiles as $file) {
    if (is_file($file)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($ext === 'jpg' || $ext === 'jpeg') {
            header('Content-Type: image/jpeg');
        } elseif ($ext === 'png') {
            header('Content-Type: image/png');
        } else {
            http_response_code(415);
            exit;
        }

        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
}

http_response_code(404);
exit;