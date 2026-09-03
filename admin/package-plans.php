<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Package Plan Master';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'package_plans', (int) $_GET['toggle']);
    header('Location: package-plans.php' . (!empty($_GET['plan_id']) ? '?plan_id=' . (int)$_GET['plan_id'] : ''));
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'package_plans', (int) $_GET['delete']);
    header('Location: package-plans.php');
    exit;
}

$plans = $pdo->query("SELECT id, name FROM plans WHERE status='active' ORDER BY name")->fetchAll();
$packages = $pdo->query("SELECT id, name, amount, bv FROM packages WHERE status='active' ORDER BY amount")->fetchAll();
$filterPlan = (int) ($_GET['plan_id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $planId = (int) ($_POST['plan_id'] ?? 0);
    $packageId = (int) ($_POST['package_id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);
    $bv = (float) ($_POST['bv'] ?? 0);
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if (!$planId) $errors[] = 'Select a plan.';
    if (!$packageId) $errors[] = 'Select a package.';
    if ($amount < 0) $errors[] = 'Amount cannot be negative.';

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE package_plans SET plan_id=?, package_id=?, amount=?, bv=?, status=? WHERE id=?')
                    ->execute([$planId, $packageId, $amount, $bv, $status, $id]);
                flash('success', 'Package plan updated.');
            } else {
                $pdo->prepare('INSERT INTO package_plans (plan_id, package_id, amount, bv, status) VALUES (?,?,?,?,?)')
                    ->execute([$planId, $packageId, $amount, $bv, $status]);
                flash('success', 'Package linked to plan.');
            }
            log_activity($id ? 'package_plan_edit' : 'package_plan_add', "plan $planId package $packageId");
            header('Location: package-plans.php?plan_id=' . $planId);
            exit;
        } catch (PDOException $e) {
            $errors[] = 'This package is already linked to the selected plan.';
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM package_plans WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
    if ($edit) $filterPlan = (int) $edit['plan_id'];
}

$sql = '
    SELECT pp.*, pl.name AS plan_name, pk.name AS package_name
    FROM package_plans pp
    JOIN plans pl ON pl.id = pp.plan_id
    JOIN packages pk ON pk.id = pp.package_id
';
$params = [];
if ($filterPlan) {
    $sql .= ' WHERE pp.plan_id = ?';
    $params[] = $filterPlan;
}
$sql .= ' ORDER BY pp.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit Package Plan' : 'Link Package to Plan' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Plan *</label>
                    <select name="plan_id" required>
                        <option value="">— Select Plan —</option>
                        <?php foreach ($plans as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= ((int)($edit['plan_id'] ?? $filterPlan ?: ($_POST['plan_id'] ?? 0)) === (int)$p['id']) ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Package *</label>
                    <select name="package_id" required>
                        <option value="">— Select Package —</option>
                        <?php foreach ($packages as $pk): ?>
                        <option value="<?= (int)$pk['id'] ?>" <?= ((int)($edit['package_id'] ?? $_POST['package_id'] ?? 0) === (int)$pk['id']) ? 'selected' : '' ?>>
                            <?= e($pk['name']) ?> (<?= currency((float)$pk['amount']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Amount</label>
                    <input type="number" step="0.01" name="amount" value="<?= e((string)($edit['amount'] ?? $_POST['amount'] ?? '0')) ?>">
                </div>
                <div class="form-group">
                    <label>BV</label>
                    <input type="number" step="0.01" name="bv" value="<?= e((string)($edit['bv'] ?? $_POST['bv'] ?? '0')) ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?= (($edit['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= (($edit['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add Mapping' ?></button>
                <?php if ($edit): ?><a href="package-plans.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <h2>Package Plan List (<?= count($rows) ?>)</h2>
        <form method="get" class="filters" style="margin:0">
            <div class="form-group">
                <select name="plan_id" onchange="this.form.submit()">
                    <option value="">All Plans</option>
                    <?php foreach ($plans as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $filterPlan === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Plan</th><th>Package</th><th>Amount</th><th>BV</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="6">No mappings yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['plan_name']) ?></td>
                    <td><strong><?= e($r['package_name']) ?></strong></td>
                    <td><?= currency((float)$r['amount']) ?></td>
                    <td><?= number_format((float)$r['bv'], 2) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= action_buttons((int)$r['id'], 'Delete this mapping?', 'plan_id=' . (int)$filterPlan, $r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
