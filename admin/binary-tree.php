<?php
require_once __DIR__ . '/../config/database.php';
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: tree-view.php' . ($qs !== '' ? '?' . $qs : ''));
exit;
