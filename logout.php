<?php
/** Sign out. POST only (CSRF-protected) so a stray link cannot log anyone out. */
declare(strict_types=1);
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
verify_csrf();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
}
session_destroy();

// Start a fresh session just to carry the goodbye message to the login page.
session_start();
flash('You have been signed out.', 'info');
redirect('login.php');
