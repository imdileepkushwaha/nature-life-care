<?php
require_once __DIR__ . '/includes/auth.php';
require_user();
header('Location: income-matching.php');
exit;
