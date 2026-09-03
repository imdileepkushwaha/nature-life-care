<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/wallet.php';
$pageTitle = 'Member Wallets';

wallet_ensure_schema($pdo);

$errors = [];
$q = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $memberId = (int) ($_POST['member_id'] ?? 0);
    $walletType = (string) ($_POST['wallet_type'] ?? 'income');
    $direction = (string) ($_POST['direction'] ?? 'credit');
    $amount = (float) ($_POST['amount'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));

    if ($memberId <= 0) {
        $errors[] = 'Select a valid member.';
    } elseif (!isset(wallet_types()[$walletType])) {
        $errors[] = 'Invalid wallet type.';
    } elseif ($amount <= 0) {
        $errors[] = 'Enter a valid amount.';
    } else {
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        if ($direction === 'debit') {
            $res = wallet_debit($pdo, $memberId, $walletType, $amount, 'admin_debit', null, $note !== '' ? $note : 'Admin debit', $adminId);
        } else {
            $res = wallet_credit($pdo, $memberId, $walletType, $amount, 'admin_credit', null, $note !== '' ? $note : 'Admin credit', $adminId);
        }
        if ($res['ok']) {
            log_activity('wallet_' . $direction, ucfirst($direction) . " {$walletType} wallet #{$memberId} amount {$amount}");
            flash('success', wallet_label($walletType) . ' ' . $direction . 'd successfully. New balance: ' . strip_tags(currency($res['balance'])) . '.');
            header('Location: wallets.php?q=' . urlencode($q));
            exit;
        }
        $errors[] = $res['error'] ?? 'Wallet update failed.';
    }
}

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(m.member_id LIKE ? OR m.username LIKE ? OR m.full_name LIKE ? OR m.email LIKE ? OR m.phone LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, $like, $like];
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM members m WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$stmt = $pdo->prepare("
    SELECT m.id, m.member_id, m.username, m.full_name, m.email, m.phone, m.status,
           m.wallet_balance, m.topup_wallet_balance, m.shopping_wallet_balance
    FROM members m
    WHERE $whereSql
    ORDER BY m.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$sumIncome = (float) $pdo->query('SELECT COALESCE(SUM(wallet_balance),0) FROM members')->fetchColumn();
$sumTopup = (float) $pdo->query('SELECT COALESCE(SUM(topup_wallet_balance),0) FROM members')->fetchColumn();
$sumShop = (float) $pdo->query('SELECT COALESCE(SUM(shopping_wallet_balance),0) FROM members')->fetchColumn();

$types = wallet_types();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card"><div class="label">Income Wallets</div><div class="value"><?= currency($sumIncome) ?></div></div>
    <div class="stat-card"><div class="label">Topup Wallets</div><div class="value"><?= currency($sumTopup) ?></div></div>
    <div class="stat-card"><div class="label">Shopping Wallets</div><div class="value"><?= currency($sumShop) ?></div></div>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2>Adjust Wallet</h2>
            <p class="members-sub">Credit or debit Income / Topup / Shopping wallet for a member</p>
        </div>
    </div>
    <div class="panel-body">
        <form method="post" class="filters" style="align-items:end">
            <div class="form-group">
                <label>Member ID (internal)</label>
                <input type="number" name="member_id" min="1" required placeholder="members.id" value="<?= e($_POST['member_id'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Wallet</label>
                <select name="wallet_type" required>
                    <?php foreach ($types as $key => $meta): ?>
                        <option value="<?= e($key) ?>" <?= (($_POST['wallet_type'] ?? 'income') === $key) ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Action</label>
                <select name="direction" required>
                    <option value="credit" <?= (($_POST['direction'] ?? '') === 'debit') ? '' : 'selected' ?>>Credit (+)</option>
                    <option value="debit" <?= (($_POST['direction'] ?? '') === 'debit') ? 'selected' : '' ?>>Debit (−)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Amount</label>
                <input type="number" name="amount" min="0.01" step="0.01" required value="<?= e($_POST['amount'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Note</label>
                <input type="text" name="note" maxlength="200" value="<?= e($_POST['note'] ?? '') ?>" placeholder="Optional note">
            </div>
            <button type="submit" class="btn btn-primary">Submit</button>
        </form>
    </div>
</div>

<div class="panel members-panel">
    <div class="panel-header members-toolbar">
        <div>
            <h2>Member Wallet Balances</h2>
        </div>
    </div>
    <div class="panel-body members-filters">
        <form class="members-filter-form" method="get">
            <div class="form-group">
                <label>Search</label>
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="Member ID, name, phone…">
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
            <a href="wallets.php" class="btn btn-outline">Reset</a>
        </form>
    </div>
    <div class="table-wrap">
        <table class="data members-table">
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Income</th>
                    <th>Topup</th>
                    <th>Shopping</th>
                    <th>Status</th>
                    <th>Quick</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6"><div class="empty-state"><strong>No members</strong></div></td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <div class="member-cell">
                            <strong><a href="member-view.php?id=<?= (int) $r['id'] ?>"><?= e($r['full_name']) ?></a></strong>
                            <span><?= e($r['member_id']) ?> · <?= e($r['username']) ?></span>
                            <span>ID #<?= (int) $r['id'] ?></span>
                        </div>
                    </td>
                    <td><?= currency((float) $r['wallet_balance']) ?></td>
                    <td><?= currency((float) ($r['topup_wallet_balance'] ?? 0)) ?></td>
                    <td><?= currency((float) ($r['shopping_wallet_balance'] ?? 0)) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td>
                        <button type="button" class="btn btn-outline btn-sm" onclick="document.querySelector('[name=member_id]').value='<?= (int) $r['id'] ?>'; window.scrollTo({top:0,behavior:'smooth'})">Adjust</button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="pagination members-pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a class="<?= $i === $page ? 'active' : '' ?>" href="?page=<?= $i ?>&q=<?= urlencode($q) ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
