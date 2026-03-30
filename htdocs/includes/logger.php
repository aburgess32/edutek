<?php

/**
 * EduPak Logger
 *
 * Lightweight PSR-3-inspired logger for offline XAMPP deployments.
 * Writes daily rotating log files to the logs/ directory.
 * No external dependencies required.
 *
 * Log format: [YYYY-MM-DD HH:MM:SS] [LEVEL] [file:line] message | context_json
 *
 * @package EduPak
 * @version 1.0.0
 */

declare(strict_types=1);

namespace EduPak;

/**
 * EduPak Logger
 *
 * Usage:
 *   \EduPak\Logger::info('User logged in', ['user_id' => 42]);
 *   \EduPak\Logger::error('DB connection failed', ['exception' => $e->getMessage()]);
 */
class Logger
{
    // Log level constants (numeric for comparison)
    public const LEVEL_DEBUG = 0;
    public const LEVEL_INFO  = 1;
    public const LEVEL_WARN  = 2;
    public const LEVEL_ERROR = 3;

    // Level name map
    private const LEVEL_NAMES = [
        self::LEVEL_DEBUG => 'DEBUG',
        self::LEVEL_INFO  => 'INFO ',
        self::LEVEL_WARN  => 'WARN ',
        self::LEVEL_ERROR => 'ERROR',
    ];

    /** @var string Absolute path to the logs directory. */
    private static string $logDir = '';

    /** @var int Minimum level to record (set via LOG_LEVEL env var). */
    private static int $minLevel = self::LEVEL_DEBUG;

    /** @var int Days to keep log files before auto-rotation deletes them. */
    private static int $retainDays = 30;

    // ────────────────────────────────────────────────────────────────────────
    // Initialisation
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Initialise the logger (called lazily on first write).
     *
     * Resolves the logs/ directory relative to the project root,
     * and maps the LOG_LEVEL environment variable to a numeric level.
     *
     * @return void
     */
    private static function init(): void
    {
        if (self::$logDir !== '') {
            return; // Already initialised
        }

        // Place logs/ two levels above htdocs/includes/
        self::$logDir = rtrim(
            defined('LOG_PATH') ? LOG_PATH : dirname(__DIR__, 2) . '/logs',
            '/'
        );

        // Map LOG_LEVEL env var to numeric constant
        $envLevel = strtolower((string) (getenv('LOG_LEVEL') ?: 'debug'));
        self::$minLevel = match ($envLevel) {
            'error' => self::LEVEL_ERROR,
            'warn'  => self::LEVEL_WARN,
            'info'  => self::LEVEL_INFO,
            default => self::LEVEL_DEBUG,
        };

        // Ensure the directory exists
        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Public API
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Log a DEBUG level message.
     *
     * Use for detailed diagnostic information during development.
     *
     * @param  string               $message Human-readable log message.
     * @param  array<string, mixed> $context Optional key-value context data.
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        self::write(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log an INFO level message.
     *
     * Use for normal operational events (user actions, page loads).
     *
     * @param  string               $message Human-readable log message.
     * @param  array<string, mixed> $context Optional key-value context data.
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        self::write(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log a WARN level message.
     *
     * Use for unexpected situations that don't prevent operation
     * (e.g. deprecated usage, recoverable errors).
     *
     * @param  string               $message Human-readable log message.
     * @param  array<string, mixed> $context Optional key-value context data.
     * @return void
     */
    public static function warn(string $message, array $context = []): void
    {
        self::write(self::LEVEL_WARN, $message, $context);
    }

    /**
     * Log an ERROR level message.
     *
     * Use for errors that need immediate attention (exceptions, DB failures).
     *
     * @param  string               $message Human-readable log message.
     * @param  array<string, mixed> $context Optional key-value context data.
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        self::write(self::LEVEL_ERROR, $message, $context);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Core Write Logic
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Write a log entry to the daily log file.
     *
     * Skips entries below the configured minimum level.
     * Detects the calling file and line number via debug_backtrace().
     * Runs log rotation (deletes files older than $retainDays) on every write.
     *
     * @param  int                  $level   One of the LEVEL_* constants.
     * @param  string               $message Log message text.
     * @param  array<string, mixed> $context Additional structured data.
     * @return void
     */
    private static function write(int $level, string $message, array $context = []): void
    {
        self::init();

        // Honour minimum log level
        if ($level < self::$minLevel) {
            return;
        }

        // Resolve caller (skip Logger class frames)
        $trace      = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller     = $trace[1] ?? $trace[0] ?? [];
        $callerFile = isset($caller['file']) ? basename($caller['file']) : 'unknown';
        $callerLine = $caller['line'] ?? 0;

        // Build log line
        $timestamp   = date('Y-m-d H:i:s');
        $levelName   = self::LEVEL_NAMES[$level] ?? 'UNKNO';
        $jsonFlags   = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $contextJson = empty($context) ? '' : ' | ' . json_encode($context, $jsonFlags);
        $line        = "[{$timestamp}] [{$levelName}] [{$callerFile}:{$callerLine}] {$message}{$contextJson}" . PHP_EOL;

        // Write to daily log file
        $logFile = self::$logDir . '/edupak-' . date('Y-m-d') . '.log';
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

        // Run rotation (lightweight — only globs once per request via static flag)
        self::rotate();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Log Rotation
    // ────────────────────────────────────────────────────────────────────────

    /** @var bool Whether rotation has already run this request. */
    private static bool $rotated = false;

    /**
     * Delete log files older than $retainDays.
     *
     * Runs at most once per PHP request to avoid overhead on every log call.
     *
     * @return void
     */
    private static function rotate(): void
    {
        if (self::$rotated) {
            return;
        }
        self::$rotated = true;

        $cutoff = time() - (self::$retainDays * 86400);
        $files  = glob(self::$logDir . '/edupak-*.log');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Utility: Get current log path
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Return the absolute path to today's log file.
     *
     * @return string
     */
    public static function currentLogPath(): string
    {
        self::init();
        return self::$logDir . '/edupak-' . date('Y-m-d') . '.log';
    }
}

// ────────────────────────────────────────────────────────────────────────────
// Request Logging Middleware
// ────────────────────────────────────────────────────────────────────────────

/**
 * Log every page request with method, URI, user ID, and response time.
 *
 * Call log_request_start() at the top of index.php (or bootstrap) and
 * register log_request_end() as a shutdown function:
 *
 *   $requestStartTime = log_request_start();
 *   register_shutdown_function('log_request_end', $requestStartTime);
 *
 * @return float Microtime (float) of request start, for use with log_request_end().
 */
function log_request_start(): float
{
    return microtime(true);
}

/**
 * Log the completed request. Intended for use as a shutdown function.
 *
 * Captures method, URI, authenticated user ID (if available in session),
 * HTTP status code, and total wall-clock response time in milliseconds.
 *
 * @param  float $startTime The value returned by log_request_start().
 * @return void
 */
function log_request_end(float $startTime): void
{
    $elapsed  = round((microtime(true) - $startTime) * 1000, 2); // ms
    $method   = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
    $uri      = $_SERVER['REQUEST_URI']    ?? '/';
    $userId   = $_SESSION['user_id'] ?? null;
    $status   = http_response_code();

    Logger::info('HTTP Request', [
        'method'      => $method,
        'uri'         => $uri,
        'status'      => $status,
        'user_id'     => $userId,
        'duration_ms' => $elapsed,
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? 'cli',
    ]);
}
