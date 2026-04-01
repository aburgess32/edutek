<?php
/**
 * Teacher Login Page (FRE-11)
 *
 * Email + password authentication for teacher accounts.
 */



include_once __DIR__ . '/includes/auth.php';

// Already logged in? Go to teacher hub
if (isLoggedIn()) {
    header('Location: /teacher.php');
    exit;
}

// First-time setup: if no teachers exist, redirect to registration
$pdo = getDbConnection();
$teacherCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE user_type = 'teacher'")->fetchColumn();
if ($teacherCount === 0) {
    header('Location: /teacher-register.php?first=1');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = filter_input(INPUT_POST, '_csrf_token', FILTER_DEFAULT) ?? '';
    $email    = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
    $password = filter_input(INPUT_POST, 'password', FILTER_DEFAULT) ?? '';

    if (!csrf_verify($token)) {
        $error = 'Invalid form submission. Please try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } elseif (rate_limit('teacher_login_' . $_SERVER['REMOTE_ADDR'], 5, 60)) {
        $error = 'Too many login attempts. Please wait a minute.';
    } else {
        $pdo  = getDbConnection();
        $stmt = $pdo->prepare(
            "SELECT id, display_name, password_hash FROM users WHERE email = ? AND user_type = 'teacher'"
        );
        $stmt->execute([$email]);
        $teacher = $stmt->fetch();

        if ($teacher && password_verify($password, $teacher['password_hash'])) {
            rate_limit_clear('teacher_login_' . $_SERVER['REMOTE_ADDR']);
            loginUser(
                (int) $teacher['id'],
                $teacher['display_name'],
                '',
                '#7C3AED',
                'teacher'
            );
            updateLastActive($pdo, (int) $teacher['id']);
            header('Location: /teacher.php');
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Login - <?php echo SITE_TITLE; ?></title>
    <link href="vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/login.css" rel="stylesheet">
</head>
<body class="auth-page">

<div class="auth-container">
    <a href="login.php" class="auth-back"><i class="fa fa-arrow-left"></i> Back</a>

    <div class="auth-card">
        <h2 class="auth-title"><i class="fa fa-graduation-cap"></i> Teacher Login</h2>
        <p class="auth-subtitle">Sign in with your teacher account</p>

        <?php if ($error): ?>
        <div class="auth-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post" action="teacher-login.php">
            <?php echo csrf_field(); ?>

            <div class="auth-field">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" placeholder="you@school.edu"
                       value="<?php echo htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>

            <div class="auth-field">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" placeholder="Your password" required>
            </div>

            <button type="submit" class="auth-btn auth-btn--teacher">
                Sign In <i class="fa fa-arrow-right"></i>
            </button>
        </form>

        <a href="teacher-register.php" class="auth-link">New teacher? Create an account</a>
    </div>
</div>

</body>
</html>
