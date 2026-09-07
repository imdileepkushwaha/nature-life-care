<?php
require_once __DIR__ . '/includes/auth.php';

$backToAdmin = !empty($_SESSION['user_login_by_admin']) && !empty($_SESSION['admin_id']);

user_logout_session();

if ($backToAdmin) {
    header('Location: ../admin/direct-member-login.php');
    exit;
}

flash('success', 'You have been logged out.');
header('Location: login.php');
exit;
