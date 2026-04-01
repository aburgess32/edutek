<?php
/**
 * Teacher Registration Page (FRE-11)
 *
 * Name, email, password registration for teacher accounts.
 */

declare(strict_types=1);

include_once __DIR__ . '/includes/auth.php';

// Already logged in? Go home
if (isLoggedIn()) {
    header('Location: /');
    exit;
}

$error = '';
$formName  = '';
$formEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token           = filter_input(INPUT_POST, '_csrf_token', FILTER_DEFAULT) ?? '';
    $formName        = trim(filter_input(INPUT_POST, 'display_name', FILTER_DEFAULT) ?? '');
    $formEmail       = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
    $password        = filter_input(INPUT_POST, 'password', FILTER_DEFAULT) ?? '';
    $passwordConfirm = filter_input(INPUT_POST, 'password_confirm', FILTER_DEFAULT) ?? '';

    if (!csrf_verify($token)) {
        $error = 'Invalid form submission. Please try again.';
    } elseif (strlen($formName) < 2) {
        $error = 'Name must be at least 2 characters.';
    } elseif (!filter_var($formEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Passwords do not match.';
    } else {
        $pdo = getDbConnection();

        // Check email not taken
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$formEmail]);
        if ($stmt->fetch()) {
            $error = 'An account with this email already exists. <a href="teacher-login.php">Sign in instead?</a>';
        } else {
            try {
                $userId = createTeacher($pdo, sanitize_name($formName), $formEmail, $password);
                loginUser($userId, $formName, '', '#7C3AED', 'teacher');
                updateLastActive($pdo, $userId);
                header('Location: /');
                exit;
            } catch (PDOException $e) {
                if ((int) $e->getCode() === 23000) {
                    $error = 'An account with this email already exists.';
                } else {
                    $error = 'Something went wrong. Please try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Registration - <?php echo SITE_TITLE; ?></title>
    <link href="vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/login.css" rel="stylesheet">
</head>
<body class="auth-page">

<div class="auth-container">
    <a href="login.php" class="auth-back"><i class="fa fa-arrow-left"></i> Back</a>

    <div class="auth-card">
        <h2 class="auth-title"><i class="fa fa-graduation-cap"></i> Teacher Registration</h2>
        <p class="auth-subtitle">Create your teacher account</p>

        <?php if ($error): ?>
        <div class="auth-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="post" action="teacher-register.php">
            <?php echo csrf_field(); ?>

            <div class="auth-field">
                <label for="display_name">Full Name</label>
                <input type="text" name="display_name" id="display_name" placeholder="Your full name"
                       value="<?php echo htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'); ?>"
                       required minlength="2" maxlength="100">
            </div>

            <div class="auth-field">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" placeholder="you@school.edu"
                       value="<?php echo htmlspecialchars($formEmail, ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>

            <div class="auth-field">
                <label for="password">Password</label>
                <input type="password" name="password" id="password"
                       placeholder="At least 6 characters" required minlength="6">
            </div>

            <div class="auth-field">
                <label for="password_confirm">Confirm Password</label>
                <input type="password" name="password_confirm" id="password_confirm"
                       placeholder="Type your password again" required minlength="6">
            </div>

            <button type="submit" class="auth-btn auth-btn--teacher">
                Create Account <i class="fa fa-arrow-right"></i>
            </button>
        </form>

        <a href="teacher-login.php" class="auth-link">Already have an account? Sign in</a>
    </div>
</div>

</body>
</html>
