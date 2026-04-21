<?php

/**
 * Application Configuration (FRE-13)
 *
 * Contains app-level settings that may vary per deployment.
 * This file returns an associative array — consume with:
 *   $config = require __DIR__ . '/../config/app.php';
 *
 * Must be included after includes/config.php so env() is available.
 */

// Ensure env() is available (config.php loads env.php)
if (!function_exists('env')) {
    require_once __DIR__ . '/../includes/env.php';
}

return [
    // Passphrase required during teacher registration.
    // Override via .env: TEACHER_PASSPHRASE
    'teacher_passphrase' => env('TEACHER_PASSPHRASE', 'teachwithedutek'),
];
