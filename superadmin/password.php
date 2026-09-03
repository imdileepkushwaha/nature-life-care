<?php
/**
 * Change Password moved into Settings → Security.
 */
require_once __DIR__ . '/../config/database.php';
require_superadmin();
header('Location: settings.php?tab=security');
exit;
