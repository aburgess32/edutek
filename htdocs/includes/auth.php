<?php

/**
 * EduPak Authentication & Session Helpers
 *
 * Provides session management, login/register functions, and
 * role-based access control for the Simple Name Login system (FRE-11).
 *
 * @package EduPak
 * @version 1.0.0
 */

// Note: strict_types removed — this file is included from other files

// Start output buffering FIRST so session_start works even after HTML output.
// Always start a buffer — do NOT skip when ob_get_level() > 0 because an
// implicit buffer (php.ini output_buffering) can overflow or flush before
// session_start() runs, causing "headers already sent" errors.
if (session_status() === PHP_SESSION_NONE) {
    ob_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';

// Session is started by security.php — no need to start again

// ──────────────────────────────────────────────────────────────────────────────
// Session Timeout (FRE-13)
// ──────────────────────────────────────────────────────────────────────────────

/** Session timeout in seconds — 8 hours for teacher sessions */
define('SESSION_TIMEOUT', 8 * 3600);

if (!empty($_SESSION['user_id']) && !empty($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        // Session expired — destroy and redirect
        $_SESSION = [];
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
        session_destroy();
        header('Location: /login.php?expired=1');
        exit;
    }
}

// Update last activity timestamp on every authenticated request
if (!empty($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
}

// ──────────────────────────────────────────────────────────────────────────────
// Layout Mode
// ──────────────────────────────────────────────────────────────────────────────

$GLOBALS['edupak_mode'] = 'phone';
$_allowed_modes = ['phone', 'tablet', 'screen'];

if (!empty($_SESSION['mode']) && in_array($_SESSION['mode'], $_allowed_modes, true)) {
    $GLOBALS['edupak_mode'] = $_SESSION['mode'];
} elseif (!empty($_COOKIE['edupak_mode']) && in_array($_COOKIE['edupak_mode'], $_allowed_modes, true)) {
    $GLOBALS['edupak_mode'] = $_COOKIE['edupak_mode'];
    $_SESSION['mode'] = $GLOBALS['edupak_mode'];
}

function getMode(): string
{
    return $GLOBALS['edupak_mode'] ?? 'phone';
}

// ──────────────────────────────────────────────────────────────────────────────
// PDO Database Connection
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Get a shared PDO database connection (singleton per request).
 *
 * @return PDO
 */
function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}

// ──────────────────────────────────────────────────────────────────────────────
// Access Control
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Redirect to login page if no user is logged in.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Redirect to home if user is not a teacher.
 */
function requireTeacher(): void
{
    if (!isTeacher()) {
        header('Location: /');
        exit;
    }
}

/**
 * Check whether a user (student or teacher) is logged in.
 */
function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']) && ($_SESSION['user_id'] > 0);
}

/**
 * Check whether the current user is a teacher.
 */
function isTeacher(): bool
{
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'teacher';
}

/**
 * Check whether the current session is a guest.
 */
function isGuest(): bool
{
    return ($_SESSION['user_role'] ?? '') === 'guest';
}

/**
 * Get session data for the current user.
 *
 * @return array|null Associative array with user_id, display_name, avatar_name, role, age_range.
 */
function getCurrentUser(): ?array
{
    if (!isLoggedIn() && !isGuest()) {
        return null;
    }

    return [
        'user_id'      => $_SESSION['user_id'] ?? 0,
        'display_name' => $_SESSION['display_name'] ?? '',
        'avatar_name'  => $_SESSION['avatar_name'] ?? '',
        'avatar_color' => $_SESSION['avatar_color'] ?? '',
        'role'         => $_SESSION['user_role'] ?? 'guest',
        'age_range'    => $_SESSION['age_range'] ?? '',
    ];
}

/**
 * Get a display-friendly name for the current user.
 * Students see their avatar name; teachers see their display name.
 */
function getUserDisplay(): string
{
    if (isTeacher()) {
        return $_SESSION['display_name'] ?? 'Teacher';
    }
    if (isLoggedIn()) {
        return $_SESSION['avatar_name'] ?? $_SESSION['display_name'] ?? 'Learner';
    }
    return 'Guest';
}

// ──────────────────────────────────────────────────────────────────────────────
// Session Helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Update the last_active timestamp for a user in the database.
 */
function updateLastActive(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('UPDATE users SET last_active = NOW() WHERE id = ?');
    $stmt->execute([$userId]);
}

/**
 * Set session variables and log a user in.
 */
function loginUser(int $userId, string $displayName, string $avatarName, string $avatarColor, string $role, string $ageRange = ''): void
{
    session_regenerate_id(true);

    $_SESSION['user_id']       = $userId;
    $_SESSION['display_name']  = $displayName;
    $_SESSION['avatar_name']   = $avatarName;
    $_SESSION['avatar_color']  = $avatarColor;
    $_SESSION['user_role']     = $role;
    $_SESSION['age_range']     = $ageRange;
    $_SESSION['last_activity'] = time();
}

/**
 * Set session as a guest user (no DB record).
 */
function loginAsGuest(): void
{
    session_regenerate_id(true);

    $_SESSION['user_id']      = 0;
    $_SESSION['display_name'] = 'Guest';
    $_SESSION['avatar_name']  = '';
    $_SESSION['avatar_color'] = '';
    $_SESSION['user_role']    = 'guest';
    $_SESSION['age_range']    = '';
}

// ──────────────────────────────────────────────────────────────────────────────
// Avatar Names
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Load avatar names from JSON, filter out ones already taken in the DB.
 *
 * @return array List of available avatar entries [{name, color, origin, meaning}, ...]
 */
function getAvailableAvatarNames(PDO $pdo): array
{
    $jsonPath = __DIR__ . '/../config/avatar-names.json';
    $raw = file_get_contents($jsonPath);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['names'])) {
        return [];
    }

    // Get all avatar names currently in use
    $stmt = $pdo->query('SELECT avatar_name FROM users WHERE avatar_name IS NOT NULL');
    $taken = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $takenSet = array_flip($taken);

    // Filter out taken names
    $available = [];
    foreach ($data['names'] as $entry) {
        if (!isset($takenSet[$entry['name']])) {
            $available[] = $entry;
        }
    }

    return $available;
}

/**
 * Load all avatar names from the JSON file (without filtering).
 *
 * @return array Full list of avatar entries.
 */
function getAllAvatarNames(): array
{
    $jsonPath = __DIR__ . '/../config/avatar-names.json';
    $raw = file_get_contents($jsonPath);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return (is_array($data) && !empty($data['names'])) ? $data['names'] : [];
}

/**
 * Look up a single avatar entry by name.
 *
 * @return array|null The avatar entry or null if not found.
 */
function getAvatarByName(string $name): ?array
{
    foreach (getAllAvatarNames() as $entry) {
        if (strcasecmp($entry['name'], $name) === 0) {
            return $entry;
        }
    }
    return null;
}

// ──────────────────────────────────────────────────────────────────────────────
// Registration
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Create a new student user in the database.
 *
 * @param  PDO    $pdo
 * @param  string $name      Display name (2+ chars)
 * @param  string $ageRange  One of: under_10, 10_14, 15_19, 20_plus
 * @param  string $avatarName The chosen avatar name (must be available)
 * @return int    The new user ID
 * @throws PDOException if avatar_name is already taken (UNIQUE constraint)
 */
function createStudent(PDO $pdo, string $name, string $ageRange, string $avatarName): int
{
    $avatar = getAvatarByName($avatarName);
    $color  = $avatar['color'] ?? '#333333';

    $stmt = $pdo->prepare(
        'INSERT INTO users (display_name, avatar_name, avatar_color, user_type, age_range)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $name,
        $avatarName,
        $color,
        'student',
        $ageRange,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Create a new teacher user in the database.
 *
 * @param  PDO    $pdo
 * @param  string $name     Display name
 * @param  string $email    Email address (must be unique)
 * @param  string $password Plain-text password (will be hashed)
 * @return int    The new user ID
 * @throws PDOException if email is already taken (UNIQUE constraint)
 */
function createTeacher(PDO $pdo, string $name, string $email, string $password): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO users (display_name, email, password_hash, user_type)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $name,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        'teacher',
    ]);

    return (int) $pdo->lastInsertId();
}
