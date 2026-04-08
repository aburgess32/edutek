<?php
/**
 * Captive Portal Handler
 * Redirects all non-EduTek requests to the homepage.
 * Responds to OS connectivity checks to trigger the captive portal popup.
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$host = $_SERVER['HTTP_HOST'] ?? '';
$localIP = '192.168.10.115';

// If already accessing the EduTek app, don't redirect
if ($host === $localIP || $host === 'localhost') {
    return false; // Let Apache handle normally
}

// Android connectivity check - return 302 to trigger captive portal
if (strpos($uri, 'generate_204') !== false) {
    header('HTTP/1.1 302 Found');
    header("Location: http://$localIP/");
    exit;
}

// iOS connectivity check
if (strpos($uri, 'hotspot-detect') !== false) {
    header('HTTP/1.1 302 Found');
    header("Location: http://$localIP/");
    exit;
}

// Windows connectivity check
if (strpos($uri, 'connecttest') !== false || strpos($uri, 'ncsi') !== false) {
    header('HTTP/1.1 302 Found');
    header("Location: http://$localIP/");
    exit;
}

// Everything else - redirect to homepage
header('HTTP/1.1 302 Found');
header("Location: http://$localIP/");
exit;
