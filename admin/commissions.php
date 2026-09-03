<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/wallet.php';
$pageTitle = 'Commissions';

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
        $pdo->prepare('INSERT INTO commissions (member_id, type, amount, description, status) VALUES (?,?,?,?,?)')
            ->execute([$memberId, $type, $amount, $description, $status]);
        $cid = (int) $pdo->lastInsertId();
        if ($creditWallet) {
            wallet_credit($pdo, $memberId, 'income', $amount, 'commission', $cid ?: null, $description !== '' ? $description : 'Manual commission');
        }
        log_activity('commission_add', "Added $amount commission to member #$memberId");
        flash('success', 'Commission added.');
    } else {
        flash('error', 'Invalid commission data.');
    }
    header('Location: commissions.php');
    exit;
}

// Mark paid
if (isset($_GET['pay'])) {
    $id = (int) $_GET['pay'];
    $stmt = $pdo->prepare('SELECT * FROM commissions WHERE id = ? AND status = ?');
    $stmt->execute([$id, 'pending']);
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
        flash('success', 'Commission marked as paid and credited to Income Wallet.');
    }
    header('Location: commissions.php');
    exit;
}

// Cancel (clawback wallet if already paid)
if (isset($_GET['cancel'])) {
    $id = (int) $_GET['cancel'];
    $stmt = $pdo->prepare('SELECT * FROM commissions WHERE id = ? AND status IN (?, ?)');
    $stmt->execute([$id, 'pending', 'paid']);
    $c = $stmt->fetch();
    if ($c) {
        $pdo->prepare("UPDATE commissions SET status = 'cancelled' WHERE id = ?")->execute([$id]);
        if ($c['status'] === 'paid') {
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
        log_activity('commission_cancel', "Cancelled commission #$id");
        flash('success', 'Commission cancelled' . ($c['status'] === 'paid' ? ' and Income Wallet adjusted.' : '.'));
    }
    header('Location: commissions.php');
    exit;
}

$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];
if (in_array($typeFilter, $filterTypes, true)) {
    $where[] = 'c.type = ?';
    $params[] = $typeFilter;
}
if (in_array($statusFilter, ['pending', 'paid', 'cancelled'], true)) {
    $where[] = 'c.status = ?';
    $params[] = $statusFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM commissions c WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$stmt = $pdo->prepare("
    SELECT c.*, m.full_name, m.member_id AS mid
    FROM commissions c
    JOIN members m ON m.id = c.member_id
    WHERE $whereSql
    ORDER BY c.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$members = $pdo->query("SELECT id, member_id, full_name FROM members WHERE status = 'active' ORDER BY id")->fetchAll();
$sumPaid = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status = 'paid'")->fetchColumn();
$sumPending = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status = 'pending'")->fetchColumn();

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
            <?php else: foreach ($rows as $r): ?>
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
                        <a href="?pay=<?= (int)$r['id'] ?>" class="btn-icon btn-icon-paid" title="Pay" aria-label="Pay" data-confirm="Mark as paid and credit wallet?"><?= icon_svg('paid') ?></a>
                        <a href="?cancel=<?= (int)$r['id'] ?>" class="btn-icon btn-icon-reject" title="Cancel" aria-label="Cancel" data-confirm="Cancel this pending commission?"><?= icon_svg('x') ?></a>
                        <?php elseif ($r['status'] === 'paid'): ?>
                        <a href="?cancel=<?= (int)$r['id'] ?>" class="btn-icon btn-icon-reject" title="Cancel / clawback" aria-label="Cancel" data-confirm="Cancel and claw back from wallet?"><?= icon_svg('x') ?></a>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?><span class="current"><?= $i ?></span>
            <?php else: ?><a href="?page=<?= $i ?>&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
