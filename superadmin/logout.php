<?php
require_once __DIR__ . '/../config/database.php';
session_clear_scope('superadmin');
flash('success', 'Logged out successfully.');
header('Location: login.php');
exit;
