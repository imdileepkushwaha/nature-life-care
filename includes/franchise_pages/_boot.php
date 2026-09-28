<?php
/**
 * Shared boot for franchise pages (admin + superadmin).
 * Expects $FRANCHISE_PANEL = 'admin'|'superadmin'
 */
$FRANCHISE_PANEL = ($FRANCHISE_PANEL ?? 'admin') === 'superadmin' ? 'superadmin' : 'admin';
$FRANCHISE_ROOT = dirname(__DIR__, 2);
require_once $FRANCHISE_ROOT . '/config/database.php';
require_once $FRANCHISE_ROOT . '/includes/utility.php';
require_once $FRANCHISE_ROOT . '/includes/franchise.php';
franchise_ensure_tables($pdo);

$franchise_role = $FRANCHISE_PANEL;
$franchise_actor_id = $FRANCHISE_PANEL === 'superadmin'
    ? (int) ($_SESSION['superadmin_id'] ?? 0)
    : (int) ($_SESSION['admin_id'] ?? 0);

$FRANCHISE_HEADER = $FRANCHISE_PANEL === 'superadmin'
    ? $FRANCHISE_ROOT . '/superadmin/includes/header.php'
    : $FRANCHISE_ROOT . '/includes/header.php';
$FRANCHISE_FOOTER = $FRANCHISE_PANEL === 'superadmin'
    ? $FRANCHISE_ROOT . '/superadmin/includes/footer.php'
    : $FRANCHISE_ROOT . '/includes/footer.php';

/**
 * Header/footer must pull globals — require() inside a function
 * does not see top-level $pdo / $pageTitle otherwise.
 */
function franchise_header(): void
{
    global $pdo, $pageTitle, $FRANCHISE_PANEL, $FRANCHISE_ROOT, $FRANCHISE_HEADER;
    require $FRANCHISE_HEADER;
}

function franchise_footer(): void
{
    global $FRANCHISE_PANEL, $FRANCHISE_ROOT, $FRANCHISE_FOOTER;
    require $FRANCHISE_FOOTER;
}
