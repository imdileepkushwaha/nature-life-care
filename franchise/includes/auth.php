<?php
/**
 * Franchise Authentication & Session Management
 */
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/franchise.php';

// Ensure tables and columns exist
franchise_ensure_tables($pdo);

/**
 * Log out franchise user session
 */
function franchise_logout_session(): void
{
    unset(
        $_SESSION['franchise_id'],
        $_SESSION['franchise_code'],
        $_SESSION['franchise_name'],
        $_SESSION['franchise_type'],
        $_SESSION['franchise_login_by_admin']
    );
}

/**
 * Check and enforce active franchise authentication
 * @return array<string,mixed>
 */
function franchise_require_auth(PDO $pdo): array
{
    $loginUrl = 'login.php';

    if (!feature_enabled('feature_franchise_enabled')) {
        franchise_logout_session();
        flash('error', 'Franchise portal is currently disabled. Please contact administrator.');
        header("Location: $loginUrl");
        exit;
    }

    if (empty($_SESSION['franchise_id'])) {
        header("Location: $loginUrl");
        exit;
    }

    session_enforce_idle('franchise', $loginUrl);

    $id = (int) $_SESSION['franchise_id'];
    $stmt = $pdo->prepare('
        SELECT f.*, t.name AS type_name, t.code AS type_code,
               COALESCE(t.commission_percent, 0) AS commission_percent,
               COALESCE(t.hierarchy_level, 4) AS hierarchy_level,
               t.can_create_types,
               s.franchisee_code AS sponsor_code, s.name AS sponsor_name
        FROM franchisees f
        LEFT JOIN franchisee_types t ON t.id = f.type_id
        LEFT JOIN franchisees s ON s.id = f.sponsor_id
        WHERE f.id = ?
        LIMIT 1
    ');
    $stmt->execute([$id]);
    $franchise = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$franchise || ($franchise['status'] ?? '') !== 'active') {
        franchise_logout_session();
        flash('error', 'Your franchise account is inactive or not found. Please contact administrator.');
        header("Location: $loginUrl");
        exit;
    }

    // Refresh session data
    $_SESSION['franchise_code'] = $franchise['franchisee_code'];
    $_SESSION['franchise_name'] = $franchise['name'];
    $_SESSION['franchise_type'] = $franchise['type_name'] ?? 'Franchise';
    session_touch('franchise');

    return $franchise;
}
