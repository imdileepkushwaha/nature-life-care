<?php
/**
 * AJAX: sponsor lookup for registration
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/registration.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'sponsor';

if ($action === 'captcha') {
    echo json_encode(['ok' => true, 'code' => reg_captcha_generate()]);
    exit;
}

$code = trim($_GET['id'] ?? $_POST['id'] ?? '');
$sponsor = reg_lookup_sponsor($pdo, $code);

if (!$sponsor) {
    echo json_encode(['ok' => false, 'error' => 'Sponsor not found']);
    exit;
}

if (($sponsor['status'] ?? '') !== 'active') {
    echo json_encode(['ok' => false, 'error' => 'Sponsor account is not active']);
    exit;
}

echo json_encode([
    'ok' => true,
    'member_id' => $sponsor['member_id'],
    'full_name' => $sponsor['full_name'],
    'username' => $sponsor['username'],
]);
