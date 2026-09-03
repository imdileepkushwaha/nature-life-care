<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/plan_incentives.php';
require_admin();
feature_guard_admin_page('rewards');
$pageTitle = 'Rewards';

$adminId = (int) ($_SESSION['admin_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $note = trim((string) ($_POST['admin_note'] ?? ''));
    if ($action === 'fulfill' || $action === 'fulfill_cash') {
        $res = plan_reward_fulfill($pdo, $id, $adminId, $action === 'fulfill_cash', $note);
        if ($res['ok']) {
            log_activity('reward_fulfill', 'Fulfilled reward #' . $id);
            flash('success', $res['message']);
        } else {
            flash('error', $res['message']);
        }
    } elseif ($action === 'cancel' && $id > 0) {
        $pdo->prepare("UPDATE member_rewards SET status = 'cancelled', admin_note = ? WHERE id = ? AND status = 'eligible'")
            ->execute([$note !== '' ? $note : 'Cancelled by admin', $id]);
        log_activity('reward_cancel', 'Cancelled reward #' . $id);
        flash('success', 'Reward cancelled.');
    }
    header('Location: rewards.php');
    exit;
}

$status = trim((string) ($_GET['status'] ?? 'eligible'));
if (!in_array($status, ['eligible', 'fulfilled', 'cancelled', 'all'], true)) {
    $status = 'eligible';
}

$where = ['1=1'];
$params = [];
if ($status !== 'all') {
    $where[] = 'mr.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT mr.*, m.member_id AS mid, m.full_name, m.lifetime_pairs,
           pr.title, pr.gift_label, pr.cash_value, pr.rank_key, pr.pairs_required
    FROM member_rewards mr
    JOIN members m ON m.id = mr.member_id
    JOIN plan_rewards pr ON pr.reward_key = mr.reward_key
    WHERE $whereSql
    ORDER BY mr.id DESC
    LIMIT 300
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pendingCount = 0;
try {
    $pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM member_rewards WHERE status = 'eligible'")->fetchColumn();
} catch (Throwable $e) {
    $pendingCount = 0;
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="stats-grid">
    <div class="stat-card accent"><div class="label">Pending fulfillment</div><div class="value"><?= (int) $pendingCount ?></div></div>
</div>

<div class="panel">
    <div class="panel-header">
        <h2>Business rewards</h2>
        <p class="muted" style="margin:0;font-size:0.85rem">Physical gifts and cash benefits stay subject to tax, stock and written terms. Credit cash only when you intend to pay the listed cash value.</p>
    </div>
    <div class="panel-body">
        <form class="filters" method="get">
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <?php foreach (['eligible' => 'Eligible', 'fulfilled' => 'Fulfilled', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $lab): ?>
                    <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($lab) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Milestone</th>
                    <th>Benefit</th>
                    <th>Pairs</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6">No rewards in this filter.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <strong><?= e((string) $r['full_name']) ?></strong>
                        <br><small><a href="member-view.php?id=<?= (int) $r['member_id'] ?>"><?= e((string) $r['mid']) ?></a></small>
                    </td>
                    <td>
                        <strong><?= e((string) $r['title']) ?></strong>
                        <br><small><?= e(plan_rank_title($pdo, (string) $r['rank_key'])) ?></small>
                    </td>
                    <td>
                        <?= e((string) $r['gift_label']) ?>
                        <?php if ((float) $r['cash_value'] > 0): ?>
                        <br><small><?= currency((float) $r['cash_value']) ?> cash option</small>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format((float) $r['pairs_at'], 0) ?></td>
                    <td><?= status_badge((string) $r['status']) ?></td>
                    <td>
                        <?php if (($r['status'] ?? '') === 'eligible'): ?>
                        <form method="post" class="filters" style="margin:0;gap:0.4rem">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <input type="text" name="admin_note" placeholder="Note" style="min-width:8rem">
                            <button type="submit" name="action" value="fulfill" class="btn btn-outline btn-sm" data-confirm="Mark this gift as fulfilled (no wallet credit)?">Fulfill gift</button>
                            <?php if ((float) $r['cash_value'] > 0): ?>
                            <button type="submit" name="action" value="fulfill_cash" class="btn btn-primary btn-sm" data-confirm="Credit <?= e(strip_tags(currency((float) $r['cash_value']))) ?> to Income Wallet and mark fulfilled?">Credit cash</button>
                            <?php endif; ?>
                            <button type="submit" name="action" value="cancel" class="btn btn-outline btn-sm" data-confirm="Cancel this reward?">Cancel</button>
                        </form>
                        <?php elseif (!empty($r['admin_note'])): ?>
                        <small class="muted"><?= e((string) $r['admin_note']) ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
