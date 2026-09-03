<?php
require_once __DIR__ . '/../config/database.php';

if (!empty($_SESSION['admin_id'])) {
    log_activity('logout', 'Admin logged out');
}

$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
