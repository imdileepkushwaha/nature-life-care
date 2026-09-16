<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/income_tables.php';
$pageTitle = 'Commissions';

income_tables_ensure($pdo);

$manualTypes = ['other'];
if (feature_enabled('feature_binary_income') && plan_uses_binary()) {
    $manualTypes[] = 'binary';
}
if (feature_enabled('feature_referral_income')) {
    $manualTypes[] = 'referral';
}
if (feature_enabled('feature_matching_income') && plan_uses_binary()) {
    $manualTypes[] = 'matching';
}
if (feature_enabled('feature_dsi_income')) {
    $manualTypes[] = 'dsi';
}
if (feature_enabled('feature_ranks_enabled') && plan_uses_binary()) {
    $manualTypes[] = 'rank';
}
if (feature_enabled('feature_rewards_enabled') && plan_uses_binary()) {
    $manualTypes[] = 'reward';
}
if (feature_enabled('feature_level_income') && plan_uses_level()) {
    $manualTypes[] = 'level';
}
$filterTypes = $manualTypes;

// Manual commission add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $memberId = (int) ($_POST['member_id'] ?? 0);
    $type = $_POST['type'] ?? 'other';
    $amount = (float) ($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $creditWallet = isset($_POST['credit_wallet']);

    if ($memberId && $amount > 0 && in_array($type, $manualTypes, true)) {
        $status = $creditWallet ? 'paid' : 'pending';
        if ($type === 'dsi' || $type === 'matching') {
            $cid = income_split_insert($pdo, $type, $memberId, null, $amount, $description, $status);
            if ($creditWallet && $cid > 0) {
                wallet_credit(
                    $pdo,
                    $memberId,
                    'income',
                    $amount,
                    $type === 'dsi' ? 'income_dsi' : 'income_matching',
                    $cid,
                    $description !== '' ? $description : 'Manual ' . $type
                );
            }
        } else {
            $pdo->prepare('INSERT INTO commissions (member_id, type, amount, description, status) VALUES (?,?,?,?,?)')
                ->execute([$memberId, $type, $amount, $description, $status]);
            $cid = (int) $pdo->lastInsertId();
            if ($creditWallet) {
                wallet_credit($pdo, $memberId, 'income', $amount, 'commission', $cid ?: null, $description !== '' ? $description : 'Manual commission');
            }
        }
        log_activity('commission_add', "Added $amount commission to member #$memberId");
        flash('success', 'Commission added.');
    } else {
        flash('error', 'Invalid commission data.');
    }
    header('Location: commissions.php');
    exit;
}

$typeFilter = trim((string) ($_GET['type'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

// Mark paid
if (isset($_GET['pay'])) {
    $id = (int) $_GET['pay'];
    $src = trim((string) ($_GET['src'] ?? 'commission'));
    $paid = false;
    if ($src === 'dsi' || $src === 'matching') {
        $table = income_split_table($src);
        if ($table) {
            $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ? AND status = 'pending'");
            $stmt->execute([$id]);
            $c = $stmt->fetch();
            if ($c) {
                $pdo->prepare("UPDATE {$table} SET status = 'paid' WHERE id = ?")->execute([$id]);
                wallet_credit(
                    $pdo,
                    (int) $c['member_id'],
                    'income',
                    (float) $c['amount'],
                    $src === 'dsi' ? 'income_dsi' : 'income_matching',
                    $id,
                    (string) ($c['description'] ?? ucfirst($src) . ' paid')
                );
                $paid = true;
            }
        }
    } else {
        $stmt = $pdo->prepare("SELECT * FROM commissions WHERE id = ? AND status = 'pending'");
        $stmt->execute([$id]);
        $c = $stmt->fetch();
        if ($c) {
            $pdo->prepare("UPDATE commissions SET status = 'paid' WHERE id = ?")->execute([$id]);
            wallet_credit(
                $pdo,
                (int) $c['member_id'],
                'income',
                (float) $c['amount'],
                'commission',
                $id,
                (string) ($c['description'] ?? 'Commission paid')
            );
            $paid = true;
        }
    }
    flash($paid ? 'success' : 'error', $paid ? 'Commission marked as paid and credited to Income Wallet.' : 'Pending commission not found.');
    header('Location: commissions.php?' . http_build_query(array_filter([
        'type' => $typeFilter !== '' ? $typeFilter : null,
        'status' => $statusFilter !== '' ? $statusFilter : null,
        'page' => $page > 1 ? $page : null,
    ])));
    exit;
}

// Cancel (clawback wallet if already paid)
if (isset($_GET['cancel'])) {
    $id = (int) $_GET['cancel'];
    $src = trim((string) ($_GET['src'] ?? 'commission'));
    $done = false;
    $wasPaid = false;
    if ($src === 'dsi' || $src === 'matching') {
        $table = income_split_table($src);
        if ($table) {
            $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ? AND status IN ('pending','paid')");
            $stmt->execute([$id]);
            $c = $stmt->fetch();
            if ($c) {
                $pdo->prepare("UPDATE {$table} SET status = 'cancelled' WHERE id = ?")->execute([$id]);
                $wasPaid = ($c['status'] === 'paid');
                if ($wasPaid) {
                    $amt = (float) $c['amount'];
                    $mid = (int) $c['member_id'];
                    $bal = wallet_balance($pdo, $mid, 'income');
                    $claw = min($amt, $bal);
                    if ($claw > 0) {
                        wallet_debit($pdo, $mid, 'income', $claw, $src === 'dsi' ? 'income_dsi' : 'income_matching', $id, ucfirst($src) . ' #' . $id . ' cancelled');
                    }
                    try {
                        $pdo->prepare('UPDATE members SET total_earnings = GREATEST(0, total_earnings - ?) WHERE id = ?')
                            ->execute([$amt, $mid]);
                    } catch (Throwable $e) {
                        // ignore
                    }
                }
                $done = true;
                log_activity('commission_cancel', "Cancelled {$src} #{$id}");
            }
        }
    } else {
        $stmt = $pdo->prepare("SELECT * FROM commissions WHERE id = ? AND status IN ('pending','paid')");
        $stmt->execute([$id]);
        $c = $stmt->fetch();
        if ($c) {
            $pdo->prepare("UPDATE commissions SET status = 'cancelled' WHERE id = ?")->execute([$id]);
            $wasPaid = ($c['status'] === 'paid');
            if ($wasPaid) {
                $amt = (float) $c['amount'];
                $mid = (int) $c['member_id'];
                $bal = wallet_balance($pdo, $mid, 'income');
                $claw = min($amt, $bal);
                if ($claw > 0) {
                    wallet_debit($pdo, $mid, 'income', $claw, 'commission', $id, 'Commission #' . $id . ' cancelled');
                }
                try {
                    $pdo->prepare('UPDATE members SET total_earnings = GREATEST(0, total_earnings - ?) WHERE id = ?')
                        ->execute([$amt, $mid]);
                } catch (Throwable $e) {
                    // ignore
                }
            }
            $done = true;
            log_activity('commission_cancel', "Cancelled commission #$id");
        }
    }
    flash($done ? 'success' : 'error', $done
        ? ('Commission cancelled' . ($wasPaid ? ' and Income Wallet adjusted.' : '.'))
        : 'Commission not found.');
    header('Location: commissions.php?' . http_build_query(array_filter([
        'type' => $typeFilter !== '' ? $typeFilter : null,
        'status' => $statusFilter !== '' ? $statusFilter : null,
        'page' => $page > 1 ? $page : null,
    ])));
    exit;
}

$perPage = admin_per_page();
$offset = ($page - 1) * $perPage;

$statusSql = '';
$statusParams = [];
if (in_array($statusFilter, ['pending', 'paid', 'cancelled'], true)) {
    $statusSql = ' AND c.status = ?';
    $statusParams[] = $statusFilter;
}

$unions = [];
$params = [];

$includeCommission = $typeFilter === '' || (!in_array($typeFilter, ['dsi', 'matching'], true) && in_array($typeFilter, $filterTypes, true));
$includeMatching = ($typeFilter === '' || $typeFilter === 'matching') && in_array('matching', $manualTypes, true);
$includeDsi = ($typeFilter === '' || $typeFilter === 'dsi') && in_array('dsi', $manualTypes, true);

if ($includeCommission) {
    $typeSql = '';
    $typeParams = [];
    if ($typeFilter !== '' && !in_array($typeFilter, ['dsi', 'matching'], true)) {
        $typeSql = ' AND c.type = ?';
        $typeParams[] = $typeFilter;
    }
    $unions[] = "
        SELECT c.id, c.member_id, c.type, c.amount, c.description, c.status, c.created_at, 'commission' AS src
        FROM commissions c
        WHERE 1=1 {$typeSql} {$statusSql}
    ";
    $params = array_merge($params, $typeParams, $statusParams);
}
if ($includeMatching) {
    $unions[] = "
        SELECT c.id, c.member_id, 'matching' AS type, c.amount, c.description, c.status, c.created_at, 'matching' AS src
        FROM income_matching c
        WHERE 1=1 {$statusSql}
    ";
    $params = array_merge($params, $statusParams);
}
if ($includeDsi) {
    $unions[] = "
        SELECT c.id, c.member_id, 'dsi' AS type, c.amount, c.description, c.status, c.created_at, 'dsi' AS src
        FROM income_dsi c
        WHERE 1=1 {$statusSql}
    ";
    $params = array_merge($params, $statusParams);
}

if (!$unions) {
    $rows = [];
    $total = 0;
    $totalPages = 1;
} else {
    $unionSql = implode(' UNION ALL ', $unions);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$unionSql}) AS t");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));

    $stmt = $pdo->prepare("
        SELECT t.*, m.full_name, m.member_id AS mid
        FROM ({$unionSql}) AS t
        JOIN members m ON m.id = t.member_id
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

$members = $pdo->query("SELECT id, member_id, full_name FROM members WHERE status = 'active' ORDER BY id")->fetchAll();
$totals = income_admin_wallet_totals($pdo);
$sumPaid = (float) $totals['paid'];
$sumPending = (float) $totals['pending'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card accent"><div class="label">Paid Total</div><div class="value"><?= currency($sumPaid) ?></div></div>
    <div class="stat-card"><div class="label">Pending Total</div><div class="value"><?= currency($sumPending) ?></div></div>
</div>

<div class="panel">
    <div class="panel-header"><h2>Add Commission</h2></div>
    <div class="panel-body">
        <form method="post">
            <input type="hidden" name="action" value="add">
            <div class="form-grid">
                <div class="form-group">
                    <label>Member *</label>
                    <select name="member_id" required>
                        <option value="">— Select —</option>
                        <?php foreach ($members as $m): ?>
                        <option value="<?= (int)$m['id'] ?>"><?= e($m['member_id'] . ' — ' . $m['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="type">
                        <?php foreach ($manualTypes as $t): ?>
                        <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Amount *</label>
                    <input type="number" step="0.01" name="amount" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="description">
                </div>
            </div>
            <label style="display:flex;align-items:center;gap:0.5rem;margin-top:0.75rem;font-size:0.9rem">
                <input type="checkbox" name="credit_wallet" value="1" checked> Credit to wallet immediately
            </label>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Add Commission</button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>Commission List (<?= $total ?>)</h2></div>
    <div class="panel-body">
        <form class="filters" method="get">
            <div class="form-group">
                <label>Type</label>
                <select name="type">
                    <option value="">All</option>
                    <?php foreach ($filterTypes as $t): ?>
                    <option value="<?= $t ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="">All</option>
                    <?php foreach (['pending','paid','cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr><th>ID</th><th>Member</th><th>Type</th><th>Amount</th><th>Description</th><th>Status</th><th>Date</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8">No commissions found.</td></tr>
            <?php else: foreach ($rows as $r):
                $src = (string) ($r['src'] ?? 'commission');
                $payQs = http_build_query(['pay' => (int) $r['id'], 'src' => $src, 'type' => $typeFilter, 'status' => $statusFilter, 'page' => $page]);
                $cancelQs = http_build_query(['cancel' => (int) $r['id'], 'src' => $src, 'type' => $typeFilter, 'status' => $statusFilter, 'page' => $page]);
            ?>
                <tr>
                    <td>#<?= (int)$r['id'] ?></td>
                    <td><?= e($r['full_name']) ?><br><small><?= e($r['mid']) ?></small></td>
                    <td><?= e(ucfirst($r['type'])) ?></td>
                    <td><?= currency((float)$r['amount']) ?></td>
                    <td><?= e($r['description'] ?? '') ?></td>
                    <td><?= status_badge((string) $r['status']) ?></td>
                    <td><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td>
                    <td>
                        <div class="action-icons">
                        <?php if ($r['status'] === 'pending'): ?>
                        <a href="?<?= e($payQs) ?>" class="btn-icon btn-icon-paid" title="Pay" aria-label="Pay" data-confirm="Mark as paid and credit wallet?"><?= icon_svg('paid') ?></a>
                        <a href="?<?= e($cancelQs) ?>" class="btn-icon btn-icon-reject" title="Cancel" aria-label="Cancel" data-confirm="Cancel this pending commission?"><?= icon_svg('x') ?></a>
                        <?php elseif ($r['status'] === 'paid'): ?>
                        <a href="?<?= e($cancelQs) ?>" class="btn-icon btn-icon-reject" title="Cancel / clawback" aria-label="Cancel" data-confirm="Cancel and claw back from wallet?"><?= icon_svg('x') ?></a>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php admin_pagination($page, $totalPages, ['type' => $typeFilter, 'status' => $statusFilter]); ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
