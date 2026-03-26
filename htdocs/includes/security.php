<?php
/**
 * EduPak Security Helpers
 *
 * Provides CSRF protection, input sanitization, user type validation,
 * and a simple file-based rate limiter for offline XAMPP deployments.
 *
 * @package EduPak
 * @version 1.0.0
 */

declare(strict_types=1);

// Ensure sessions are started before using CSRF functions
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ──────────────────────────────────────────────────────────────────────────────
// CSRF Protection
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Generate a CSRF token and store it in the session.
 *
 * Generates a cryptographically secure random token using random_bytes().
 * The same token is reused within a session unless regenerated.
 *
 * @param  bool   $regenerate Force generation of a new token even if one exists.
 * @return string             The current (or newly generated) CSRF token.
 */
function csrf_token(bool $regenerate = false): string
{
    if ($regenerate || empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Output an HTML hidden input field containing the CSRF token.
 *
 * Usage in a form:
 *   <?= csrf_field() ?>
 *
 * @return string HTML hidden input element.
 */
function csrf_field(): string
{
    $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="_csrf_token" value="' . $token . '">';
}

/**
 * Validate a submitted CSRF token against the one stored in the session.
 *
 * Uses hash_equals() to prevent timing attacks.
 *
 * @param  string $token The token submitted with the request (e.g. $_POST['_csrf_token']).
 * @return bool          True if the token is valid; false otherwise.
 */
function csrf_verify(string $token): bool
{
    if (empty($_SESSION['_csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['_csrf_token'], $token);
}

// ──────────────────────────────────────────────────────────────────────────────
// Input Sanitization
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Sanitize general user input.
 *
 * Trims whitespace and encodes HTML special characters to prevent XSS.
 * Suitable for display output; do NOT use in place of prepared statements.
 *
 * @param  mixed  $input The raw input value (string coercible).
 * @return string        Trimmed and HTML-encoded string.
 */
function sanitize_input(mixed $input): string
{
    return htmlspecialchars(trim((string) $input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Sanitize a display name.
 *
 * Allows only alphanumeric characters, spaces, and hyphens.
 * Strips any other characters. Trims leading/trailing whitespace.
 * Collapses multiple consecutive spaces into one.
 *
 * @param  string $name The raw name input.
 * @return string       Sanitized name containing only [a-zA-Z0-9 \-].
 */
function sanitize_name(string $name): string
{
    // Strip disallowed characters
    $cleaned = preg_replace('/[^a-zA-Z0-9 \-]/', '', $name);
    // Collapse multiple spaces
    $cleaned = preg_replace('/\s{2,}/', ' ', $cleaned ?? '');
    return trim($cleaned ?? '');
}

// ──────────────────────────────────────────────────────────────────────────────
// User Type Validation
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Validate that a user type is one of the allowed enum values.
 *
 * Allowed values match the `user_type` ENUM in the `users` table:
 * 'kid', 'teen', 'adult', 'teacher'.
 *
 * @param  string $type The user type string to validate.
 * @return bool         True if valid; false otherwise.
 */
function validate_user_type(string $type): bool
{
    $allowed = ['kid', 'teen', 'adult', 'teacher'];
    return in_array(strtolower(trim($type)), $allowed, true);
}

// ──────────────────────────────────────────────────────────────────────────────
// File-Based Rate Limiter (no Redis required)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Enforce a simple file-based rate limit.
 *
 * Stores hit counts and timestamps in a JSON file under the system temp
 * directory. Suitable for single-server offline XAMPP deployments.
 *
 * Example usage (max 5 login attempts per 60 seconds per IP):
 *   if (rate_limit('login_' . $_SERVER['REMOTE_ADDR'], 5, 60)) {
 *       // too many attempts — block or slow down
 *   }
 *
 * @param  string $key    Unique identifier for the rate-limited action (e.g. "login_127.0.0.1").
 * @param  int    $max    Maximum number of allowed hits within the time window.
 * @param  int    $window Time window in seconds.
 * @return bool           True if the rate limit has been EXCEEDED; false if still under limit.
 */
function rate_limit(string $key, int $max, int $window): bool
{
    // Sanitize the key to be a safe filename component
    $safeKey  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
    $storeDir = sys_get_temp_dir() . '/edupak_ratelimit';

    // Create directory if it doesn't exist
    if (!is_dir($storeDir)) {
        mkdir($storeDir, 0700, true);
    }

    $filePath = $storeDir . '/' . $safeKey . '.json';
    $now      = time();

    // Load existing data
    $data = ['hits' => [], 'created' => $now];
    if (file_exists($filePath)) {
        $raw = file_get_contents($filePath);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
    }

    // Prune hits that are outside the current window
    $data['hits'] = array_filter(
        $data['hits'],
        fn(int $ts): bool => ($now - $ts) < $window
    );

    // Check if over limit BEFORE recording this hit
    if (count($data['hits']) >= $max) {
        return true; // Rate limit exceeded
    }

    // Record this hit
    $data['hits'][] = $now;

    // Persist to file (atomic write via temp + rename)
    $tmpFile = $filePath . '.tmp';
    file_put_contents($tmpFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmpFile, $filePath);

    return false; // Under limit — allow
}

/**
 * Clear a rate-limit key (e.g. after a successful login).
 *
 * @param  string $key The same key used in rate_limit().
 * @return void
 */
function rate_limit_clear(string $key): void
{
    $safeKey  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
    $filePath = sys_get_temp_dir() . '/edupak_ratelimit/' . $safeKey . '.json';

    if (file_exists($filePath)) {
        unlink($filePath);
    }
}
