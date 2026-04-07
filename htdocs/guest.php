<?php
/**
 * Guest Access Handler (FRE-11)
 *
 * Sets guest session and redirects to homepage.
 */



include_once __DIR__ . '/includes/auth.php';

loginAsGuest();
header('Location: index.php');
exit;
