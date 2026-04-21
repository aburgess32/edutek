<?php

/**
 * EduPak Configuration
 *
 * Application settings loaded from the .env file via the env() helper.
 * See .env.example for all available variables.
 *
 * Do NOT hardcode credentials here. Add them to your local .env file.
 *
 * @package EduPak
 * @version 1.0.0
 */

declare(strict_types=1);

// Load .env parser (also triggers auto-load of .env file)
require_once __DIR__ . '/env.php';

// ──────────────────────────────────────────────────────────
// Database
// ──────────────────────────────────────────────────────────
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'edupak'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_PORT', env('DB_PORT', '3306'));

// ──────────────────────────────────────────────────────────
// Application
// ──────────────────────────────────────────────────────────
define('APP_ENV', env('APP_ENV', 'dev'));
define('APP_DEBUG', env('APP_DEBUG', true));

// ──────────────────────────────────────────────────────────
// Logging
// ──────────────────────────────────────────────────────────
define('LOG_LEVEL', env('LOG_LEVEL', 'debug'));
define('LOG_PATH', dirname(__DIR__, 2) . '/logs');

// ──────────────────────────────────────────────────────────
// Content Encryption (BF-CBC link obfuscation)
// ──────────────────────────────────────────────────────────
// Key and IV used to obfuscate file path parameters in URLs.
// Not intended as strong encryption — prevents casual URL manipulation only.
// Override per-device via .env: CONTENT_CIPHER_KEY, CONTENT_CIPHER_IV
define('CONTENT_CIPHER',     'BF-CBC');
define('CONTENT_CIPHER_KEY', env('CONTENT_CIPHER_KEY', 'hfjfydjnvhbjfi'));
define('CONTENT_CIPHER_IV',  env('CONTENT_CIPHER_IV',  '91011121'));

define('SITE_TITLE', env('SITE_TITLE', 'EduPak Learning Hub'));
define('CONTENT_PATH', env('CONTENT_PATH', __DIR__ . '/../videos/'));

// ──────────────────────────────────────────────────────────
// PHP Error Display (based on APP_DEBUG)
// ──────────────────────────────────────────────────────────
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}
