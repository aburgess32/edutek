<?php
/**
 * Logout Handler (FRE-11)
 *
 * Destroys the session, clears the cookie, redirects to login.
 */

declare(strict_types=1);

// Start session if not already
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear all session data
$_SESSION = [];

// Delete the session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session
session_destroy();

// Redirect to login
header('Location: /login.php');
exit;
