<?php
/**
 * Franchise Logout
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

franchise_logout_session();
flash('success', 'You have been successfully logged out.');
header('Location: login.php');
exit;
