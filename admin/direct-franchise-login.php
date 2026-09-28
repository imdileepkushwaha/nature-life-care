<?php
/**
 * Direct Franchise Login from Admin
 */
require_once __DIR__ . '/../config/database.php';
$pageTitle = 'Direct Franchise Login';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$frId = (int) ($_POST['franchisee_id'] ?? $_GET['id'] ?? 0);

if ($frId > 0) {
    $stmt = $pdo->prepare("
        SELECT f.*, t.name AS type_name
        FROM franchisees f
        LEFT JOIN franchisee_types t ON t.id = f.type_id
        WHERE f.id = ? AND f.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$frId]);
    $franchise = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$franchise) {
        flash('error', 'Active franchise not found.');
        header('Location: franchisee-report.php');
        exit;
    }

    // Set franchise session
    $_SESSION['franchise_id'] = (int) $franchise['id'];
    $_SESSION['franchise_code'] = $franchise['franchisee_code'];
    $_SESSION['franchise_name'] = $franchise['name'];
    $_SESSION['franchise_type'] = $franchise['type_name'] ?? 'Franchise';
    $_SESSION['franchise_login_by_admin'] = true;
    session_touch('franchise');

    // Keep admin session active
    session_touch('admin');

    log_activity('direct_franchise_login', 'Admin logged in as Franchise ' . $franchise['franchisee_code']);
    header('Location: ../franchise/index.php');
    exit;
} else {
    header('Location: franchisee-report.php');
    exit;
}
