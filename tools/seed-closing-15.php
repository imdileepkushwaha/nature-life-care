<?php
/**
 * Local-only: seed 15 activated Starter members for binary closing QA.
 * Usage (CLI): php tools/seed-closing-15.php
 *
 * Asymmetric tree (so 1:2 ratio still pays — equal L/R would pay 0):
 * More volume on LEFT of root than RIGHT.
 *
 * Placement map (child → parent/side):
 *  02→01/L  03→01/R
 *  04→02/L  05→02/R  06→03/L
 *  07→04/L  08→04/R  09→05/L  10→05/R
 *  11→07/L  12→07/R  13→08/L  14→08/R  15→09/L
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/registration.php';
require_once dirname(__DIR__) . '/includes/activation.php';
require_once dirname(__DIR__) . '/includes/wallet.php';

if (!app_is_local()) {
    fwrite(STDERR, "Refusing: not a local environment.\n");
    exit(1);
}

$tag = 'CLOSINGTEST';
$pass = 'Test@1234';
$hash = password_hash($pass, PASSWORD_DEFAULT);

$pkg = $pdo->query("SELECT * FROM packages WHERE status = 'active' AND (name LIKE '%Starter%' OR name LIKE '%starter%') ORDER BY amount ASC LIMIT 1")->fetch();
if (!$pkg) {
    $pkg = $pdo->query("SELECT * FROM packages WHERE status = 'active' ORDER BY amount ASC LIMIT 1")->fetch();
}
if (!$pkg) {
    fwrite(STDERR, "No active package found.\n");
    exit(1);
}
$packageId = (int) $pkg['id'];
$packageBv = (float) $pkg['bv'];

echo "Package: {$pkg['name']} (id={$packageId}, BV={$packageBv})\n";
echo "Password for all test IDs: {$pass}\n\n";

// Remove previous CLOSINGTEST batch (safe: tagged emails only)
$old = $pdo->query("SELECT id, member_id FROM members WHERE email LIKE 'closingtest%@local.test'")->fetchAll();
if ($old) {
    $ids = array_map(static fn ($r) => (int) $r['id'], $old);
    $in = implode(',', $ids);
    echo "Removing previous test batch (" . count($ids) . ")...\n";
    // Clear wallet / income refs that may block deletes
    try {
        $pdo->exec("DELETE FROM wallet_ledger WHERE member_id IN ($in)");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("DELETE FROM wallet_balances WHERE member_id IN ($in)");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("DELETE FROM commissions WHERE member_id IN ($in) OR from_member_id IN ($in)");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("DELETE FROM bv_credits WHERE member_id IN ($in)");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("DELETE FROM bv_lots WHERE member_id IN ($in)");
    } catch (Throwable $e) {
    }
    try {
        require_once dirname(__DIR__) . '/includes/income_tables.php';
        income_tables_ensure($pdo);
        $pdo->exec("DELETE FROM income_dsi WHERE member_id IN ($in) OR from_member_id IN ($in)");
        $pdo->exec("DELETE FROM income_matching WHERE member_id IN ($in) OR from_member_id IN ($in)");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("DELETE FROM activation_requests WHERE member_id IN ($in)");
    } catch (Throwable $e) {
    }
    // Detach children first
    $pdo->exec("UPDATE members SET sponsor_id = NULL, placement_id = NULL WHERE id IN ($in)");
    $pdo->exec("DELETE FROM members WHERE id IN ($in)");
}

/** @var array<int, array{0:int,1:string}> child index => [parent index, left|right] */
$placeMap = [
    2 => [1, 'left'],
    3 => [1, 'right'],
    4 => [2, 'left'],
    5 => [2, 'right'],
    6 => [3, 'left'],
    7 => [4, 'left'],
    8 => [4, 'right'],
    9 => [5, 'left'],
    10 => [5, 'right'],
    11 => [7, 'left'],
    12 => [7, 'right'],
    13 => [8, 'left'],
    14 => [8, 'right'],
    15 => [9, 'left'],
];

$created = []; // index 1..15 => member row id

$pdo->beginTransaction();
try {
    for ($i = 1; $i <= 15; $i++) {
        $code = reg_unique_member_id($pdo);
        $uname = 'closetest' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $email = 'closingtest' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . '@local.test';
        $phone = '90000' . str_pad((string) $i, 5, '0', STR_PAD_LEFT);
        $name = "Closing Test {$i}";

        $sponsorId = null;
        $placementId = null;
        $position = null;

        if ($i === 1) {
            $sponsorId = null;
            $placementId = null;
            $position = null;
        } else {
            [$parentIdx, $position] = $placeMap[$i];
            $parentDbId = $created[$parentIdx];
            $sponsorId = $created[1]; // all sponsored by test root
            $placementId = $parentDbId;
        }

        $pdo->prepare("
            INSERT INTO members (
                member_id, username, email, password, full_name, phone,
                sponsor_id, placement_id, position, status
            ) VALUES (?,?,?,?,?,?,?,?,?,'inactive')
        ")->execute([
            $code,
            $uname,
            $email,
            $hash,
            $name,
            $phone,
            $sponsorId,
            $placementId,
            $position,
        ]);
        $dbId = (int) $pdo->lastInsertId();
        $created[$i] = $dbId;

        if ($placementId && $position) {
            reg_update_upline_counts($pdo, (int) $placementId, (string) $position);
        }

        echo sprintf(
            "#%02d %s  user=%s  place=%s/%s\n",
            $i,
            $code,
            $uname,
            $placementId ? (string) $placementId : 'ROOT',
            $position ?: '-'
        );
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Insert failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nActivating all with {$pkg['name']}...\n";
$ok = 0;
$fail = 0;
for ($i = 1; $i <= 15; $i++) {
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
    $stmt->execute([$created[$i]]);
    $user = $stmt->fetch();
    if (!$user) {
        $fail++;
        continue;
    }
    $res = activation_apply($pdo, $user, $packageId);
    if (!empty($res['ok'])) {
        $ok++;
        echo "  OK  {$user['member_id']}\n";
    } else {
        $fail++;
        echo "  FAIL {$user['member_id']}: " . ($res['error'] ?? 'unknown') . "\n";
    }
}

echo "\nActivated: {$ok} / failed: {$fail}\n";
echo "Ratio setting: " . setting('binary_match_ratio', '1:2') . "\n";
echo "Binary %: " . setting('binary_commission_percent', '15') . "\n\n";

$rows = $pdo->query("
    SELECT member_id, full_name, left_bv, right_bv, left_count, right_count, package_id
    FROM members
    WHERE email LIKE 'closingtest%@local.test'
    ORDER BY id
")->fetchAll();

echo "BV snapshot (after activation):\n";
echo str_pad('ID', 14) . str_pad('L BV', 12) . str_pad('R BV', 12) . "L/R cnt\n";
foreach ($rows as $r) {
    echo str_pad((string) $r['member_id'], 14)
        . str_pad(number_format((float) $r['left_bv'], 2, '.', ''), 12)
        . str_pad(number_format((float) $r['right_bv'], 2, '.', ''), 12)
        . $r['left_count'] . '/' . $r['right_count'] . "\n";
}

echo "\nDone. Open admin → Binary Closing → Preview.\n";
echo "Login any: closetest01 … closetest15 / {$pass}\n";
