<?php
/**
 * Logs the user out. Only accepts POST (the Log out button sends a form with
 * a CSRF token), so another site cannot log users out with a plain link.
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (is_post()) {
    logout_user();
}

$user = current_user();
redirect($user ? home_path($user) : 'index.php');
