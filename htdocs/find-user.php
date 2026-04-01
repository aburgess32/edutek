<?php
/**
 * Find Your Name — Returning Student Login (FRE-11)
 *
 * Shows an A-Z strip + card grid of all registered students.
 * Clicking a card shows a confirmation overlay, then logs in.
 */



include_once __DIR__ . '/includes/auth.php';

// Already logged in? Go home
if (isLoggedIn()) {
    header('Location: /');
    exit;
}

$error = '';

// ── Handle POST (confirm login) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = filter_input(INPUT_POST, '_csrf_token', FILTER_DEFAULT) ?? '';
    $userId = (int) (filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT) ?? 0);

    if (!csrf_verify($token)) {
        $error = 'Invalid form submission. Please try again.';
    } elseif ($userId <= 0) {
        $error = 'Invalid user selection.';
    } else {
        $pdo  = getDbConnection();
        $stmt = $pdo->prepare(
            'SELECT id, display_name, avatar_name, avatar_color, user_type, age_range
             FROM users WHERE id = ? AND user_type != ?'
        );
        $stmt->execute([$userId, 'teacher']);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'User not found. Please try again.';
        } else {
            loginUser(
                (int) $user['id'],
                $user['display_name'],
                $user['avatar_name'] ?? '',
                $user['avatar_color'] ?? '#333',
                'student',
                $user['age_range'] ?? ''
            );
            updateLastActive($pdo, (int) $user['id']);
            header('Location: /');
            exit;
        }
    }
}

// ── Load all students ──
try {
    $pdo  = getDbConnection();
    $stmt = $pdo->query(
        "SELECT id, display_name, avatar_name, avatar_color
         FROM users
         WHERE user_type != 'teacher' AND avatar_name IS NOT NULL
         ORDER BY display_name ASC"
    );
    $students = $stmt->fetchAll();
} catch (Exception $e) {
    $students = [];
}

// Build letter index
$letters = [];
foreach ($students as $s) {
    $firstLetter = strtoupper(substr($s['display_name'], 0, 1));
    if (ctype_alpha($firstLetter)) {
        $letters[$firstLetter] = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Your Name - <?php echo SITE_TITLE; ?></title>
    <link href="vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/login.css" rel="stylesheet">
</head>
<body class="auth-page">

<div class="auth-container" style="max-width: 700px;">
    <a href="login.php" class="auth-back"><i class="fa fa-arrow-left"></i> Back</a>

    <div class="auth-card">
        <h2 class="auth-title">Find Your Name</h2>
        <p class="auth-subtitle">Tap your avatar to log in</p>

        <?php if ($error): ?>
        <div class="auth-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (empty($students)): ?>
        <div style="text-align: center; padding: 30px 0; color: #666;">
            <i class="fa fa-users" style="font-size: 40px; margin-bottom: 12px; display: block; opacity: 0.4;"></i>
            <p>No students registered yet.</p>
            <a href="register.php" class="auth-btn auth-btn--primary" style="display: inline-block; width: auto; padding: 10px 24px;">Create Your Avatar</a>
        </div>
        <?php else: ?>

        <!-- A-Z Strip -->
        <div class="az-strip">
            <?php for ($c = 65; $c <= 90; $c++): $letter = chr($c); ?>
            <a href="#letter-<?php echo $letter; ?>"
               class="az-letter<?php echo isset($letters[$letter]) ? '' : ' disabled'; ?>"><?php echo $letter; ?></a>
            <?php endfor; ?>
        </div>

        <!-- User Cards -->
        <div class="user-card-grid">
            <?php
            $currentLetter = '';
            foreach ($students as $s):
                $firstLetter = strtoupper(substr($s['display_name'], 0, 1));
                $initial = strtoupper(substr($s['avatar_name'] ?? $s['display_name'], 0, 1));
                $color = htmlspecialchars($s['avatar_color'] ?? '#333', ENT_QUOTES, 'UTF-8');
                $anchor = '';
                if ($firstLetter !== $currentLetter && ctype_alpha($firstLetter)) {
                    $anchor = ' id="letter-' . $firstLetter . '"';
                    $currentLetter = $firstLetter;
                }
            ?>
            <div class="user-card"<?php echo $anchor; ?>
                 data-uid="<?php echo (int) $s['id']; ?>"
                 data-name="<?php echo htmlspecialchars($s['avatar_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                 data-display="<?php echo htmlspecialchars($s['display_name'], ENT_QUOTES, 'UTF-8'); ?>"
                 data-color="<?php echo $color; ?>">
                <div class="user-card-avatar" style="background: <?php echo $color; ?>;">
                    <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <span class="user-card-name"><?php echo htmlspecialchars($s['avatar_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="user-card-display"><?php echo htmlspecialchars($s['display_name'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Confirm Login Overlay -->
<div class="confirm-overlay" id="confirm-overlay">
    <div class="confirm-box">
        <div class="avatar-reveal-badge" id="confirm-badge"></div>
        <h3 id="confirm-name"></h3>
        <p id="confirm-display"></p>
        <form method="post" action="find-user.php" id="confirm-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="user_id" id="confirm-uid" value="">
            <div class="confirm-actions">
                <button type="button" class="auth-btn confirm-cancel" id="confirm-cancel">Not me</button>
                <button type="submit" class="auth-btn auth-btn--primary">That's me!</button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    var overlay = document.getElementById('confirm-overlay');

    document.querySelectorAll('.user-card').forEach(function(card) {
        card.addEventListener('click', function() {
            var uid     = card.getAttribute('data-uid');
            var name    = card.getAttribute('data-name');
            var display = card.getAttribute('data-display');
            var color   = card.getAttribute('data-color');

            document.getElementById('confirm-uid').value = uid;
            document.getElementById('confirm-name').textContent = name;
            document.getElementById('confirm-display').textContent = display;

            var badge = document.getElementById('confirm-badge');
            badge.textContent = name.charAt(0);
            badge.style.background = color;

            overlay.classList.add('show');
        });
    });

    document.getElementById('confirm-cancel').addEventListener('click', function() {
        overlay.classList.remove('show');
    });

    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.classList.remove('show');
    });
})();
</script>

</body>
</html>
