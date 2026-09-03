<?php
require_once __DIR__ . '/../config/database.php';

$backToAdmin = !empty($_SESSION['member_login_by_admin']);

unset(
    $_SESSION['member_id'],
    $_SESSION['member_code'],
    $_SESSION['member_name'],
    $_SESSION['member_login_by_admin'],
    $_SESSION['member_login_admin_id']
);

if ($backToAdmin && !empty($_SESSION['admin_id'])) {
    header('Location: ../admin/direct-member-login.php');
} else {
    header('Location: ../admin/login.php');
}
exit;
