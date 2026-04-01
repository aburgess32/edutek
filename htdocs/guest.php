<?php
/**
 * Guest Access Handler (FRE-11)
 *
 * Sets guest session and redirects to homepage.
 */

declare(strict_types=1);

include_once __DIR__ . '/includes/auth.php';

loginAsGuest();
header('Location: /');
exit;
