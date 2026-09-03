<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/geo.php';
require_admin();
header('Content-Type: application/json');

ensure_india_geo_seed($pdo);

$type = $_GET['type'] ?? '';

if ($type === 'states') {
    $cid = (int) ($_GET['country_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, name FROM states WHERE country_id = ? AND status = 'active' ORDER BY name");
    $stmt->execute([$cid]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($type === 'cities') {
    $sid = (int) ($_GET['state_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, name FROM cities WHERE state_id = ? AND status = 'active' ORDER BY name");
    $stmt->execute([$sid]);
    echo json_encode($stmt->fetchAll());
    exit;
}

echo json_encode([]);
