<?php
/**
 * Login Welcome Page (FRE-11)
 *
 * Entry point for authentication. Shows options:
 * - Create avatar (student registration)
 * - Find your name (returning student)
 * - Teacher login
 * - Continue as guest
 */



include_once __DIR__ . '/includes/auth.php';

// Already logged in? Go home
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome - <?php echo SITE_TITLE; ?></title>
    <link href="vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/login.css" rel="stylesheet">
</head>
<body class="login-page">

<div class="login-container">
    <div class="login-logo">
        <img src="assets/img/edutek-logo.jpg" alt="<?php echo htmlspecialchars(SITE_TITLE, ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <h1 class="login-heading">Start Learning</h1>
    <p class="login-sub">Choose how you want to begin</p>

    <?php if (isset($_GET['expired'])): ?>
    <div class="auth-error" style="margin: 0 auto 1.5rem; max-width: 400px; text-align: center;">
        Your session expired. Please sign in again.
    </div>
    <?php endif; ?>

    <div class="login-options">
        <a href="register.php" class="login-option login-option--primary">
            <i class="fa fa-star"></i>
            <span class="login-option-label">Create Your Avatar</span>
            <span class="login-option-desc">New learner? Pick a name and start exploring</span>
        </a>

        <a href="find-user.php" class="login-option login-option--secondary">
            <i class="fa fa-search"></i>
            <span class="login-option-label">Find Your Name</span>
            <span class="login-option-desc">Already have an avatar? Find it here</span>
        </a>

        <a href="teacher-login.php" class="login-option login-option--teacher">
            <i class="fa fa-graduation-cap"></i>
            <span class="login-option-label">Teacher Login</span>
            <span class="login-option-desc">Sign in with your teacher account</span>
        </a>

        <a href="guest.php" class="login-option login-option--guest">
            <i class="fa fa-eye"></i>
            <span class="login-option-label">Continue as Guest</span>
            <span class="login-option-desc">Browse without an account</span>
        </a>
    </div>
</div>

</body>
</html>
