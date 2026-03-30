<?php

/**
 * EduPak .env Parser
 *
 * A minimal, zero-dependency .env file parser.
 * Reads key=value pairs from a .env file and populates
 * $_ENV, $_SERVER, and makes values available via getenv().
 *
 * Supports:
 *  - Single-line comments starting with #
 *  - Empty lines (ignored)
 *  - Single-quoted values:  KEY='value with spaces'
 *  - Double-quoted values:  KEY="value with spaces"
 *  - Unquoted values:       KEY=value
 *  - Inline comments on unquoted values (# delimiter)
 *  - Export prefix:         export KEY=value
 *
 * Does NOT support:
 *  - Multi-line values (not needed for this project)
 *  - Variable interpolation within values
 *
 * Usage:
 *   require_once __DIR__ . '/env.php';
 *   load_env(dirname(__DIR__, 2) . '/.env');
 *   $debug = env('APP_DEBUG', false);
 *
 * @package EduPak
 * @version 1.0.0
 */

declare(strict_types=1);

/**
 * Parse and load a .env file into $_ENV, $_SERVER, and putenv().
 *
 * Safe to call multiple times — already-set variables are NOT overridden
 * unless $override = true.
 *
 * @param  string $filePath Absolute path to the .env file.
 * @param  bool   $override Whether to overwrite existing environment variables.
 * @return void
 * @throws RuntimeException If the file cannot be read.
 */
function load_env(string $filePath, bool $override = false): void
{
    if (!file_exists($filePath)) {
        // Silently skip — .env may not exist in production containers
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("Cannot read .env file: {$filePath}");
    }

    foreach ($lines as $lineNumber => $rawLine) {
        $line = trim($rawLine);

        // Skip blank lines and comments
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // Strip optional 'export ' prefix
        if (str_starts_with($line, 'export ')) {
            $line = ltrim(substr($line, 7));
        }

        // Must contain '='
        if (!str_contains($line, '=')) {
            continue;
        }

        [$rawKey, $rawValue] = explode('=', $line, 2);

        $key   = trim($rawKey);
        $value = parse_env_value(trim($rawValue));

        // Validate key (alphanumeric + underscore only)
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
            continue; // Skip malformed keys
        }

        // Set in environment
        if ($override || getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
}

/**
 * Parse an individual .env value string.
 *
 * Handles:
 *  - Single-quoted strings (literal, no escapes)
 *  - Double-quoted strings (basic escapes: \n, \t, \\, \")
 *  - Unquoted strings (trimmed, inline # comments stripped)
 *
 * @param  string $raw The raw value string (after the = sign).
 * @return string      Parsed value.
 */
function parse_env_value(string $raw): string
{
    if ($raw === '') {
        return '';
    }

    // Single-quoted: return content literally (no escape processing)
    if (str_starts_with($raw, "'") && str_ends_with($raw, "'") && strlen($raw) >= 2) {
        return substr($raw, 1, -1);
    }

    // Double-quoted: process basic escape sequences
    if (str_starts_with($raw, '"') && str_ends_with($raw, '"') && strlen($raw) >= 2) {
        $inner = substr($raw, 1, -1);
        return str_replace(['\\n', '\\t', '\\"', '\\\\'], ["\n", "\t", '"', '\\'], $inner);
    }

    // Unquoted: strip inline comments (# preceded by whitespace)
    $commentPos = strpos($raw, ' #');
    if ($commentPos !== false) {
        $raw = substr($raw, 0, $commentPos);
    }

    return trim($raw);
}

/**
 * Retrieve an environment variable with an optional default.
 *
 * Checks $_ENV, then getenv(), then returns the default.
 * Casts common boolean-like strings to actual booleans when $cast = true.
 *
 * @param  string $key     Environment variable name.
 * @param  mixed  $default Value to return if the key is not set.
 * @param  bool   $cast    Whether to cast 'true'/'false'/'null' strings.
 * @return mixed
 */
function env(string $key, mixed $default = null, bool $cast = true): mixed
{
    // Check $_ENV first (most reliable after load_env())
    if (array_key_exists($key, $_ENV)) {
        $value = $_ENV[$key];
    } else {
        $raw = getenv($key);
        if ($raw === false) {
            return $default;
        }
        $value = $raw;
    }

    if ($cast) {
        return match (strtolower((string) $value)) {
            'true',  '(true)'  => true,
            'false', '(false)' => false,
            'null',  '(null)'  => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }

    return $value;
}

// Auto-load the project .env on include (two levels up from htdocs/includes/)
load_env(dirname(__DIR__, 2) . '/.env');
