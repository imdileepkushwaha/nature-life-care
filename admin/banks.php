<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Add Bank';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'banks', (int) $_GET['toggle']);
    header('Location: banks.php');
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'banks', (int) $_GET['delete']);
    header('Location: banks.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $short = strtoupper(trim($_POST['short_code'] ?? ''));
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($name === '') $errors[] = 'Bank name is required.';

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE banks SET name=?, short_code=?, status=? WHERE id=?')->execute([$name, $short ?: null, $status, $id]);
                flash('success', 'Bank updated.');
            } else {
                $pdo->prepare('INSERT INTO banks (name, short_code, status) VALUES (?,?,?)')->execute([$name, $short ?: null, $status]);
                flash('success', 'Bank added.');
            }
            log_activity($id ? 'bank_edit' : 'bank_add', $name);
            header('Location: banks.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Bank already exists.';
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM banks WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$rows = $pdo->query('SELECT b.*, (SELECT COUNT(*) FROM bank_accounts a WHERE a.bank_id = b.id) AS acc_count FROM banks b ORDER BY b.name')->fetchAll();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit Bank' : 'Add Bank' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Bank Name *</label>
                    <input type="text" name="name" value="<?= e($edit['name'] ?? $_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Short Code</label>
                    <input type="text" name="short_code" maxlength="20" value="<?= e($edit['short_code'] ?? $_POST['short_code'] ?? '') ?>">
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
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add Bank' ?></button>
                <?php if ($edit): ?><a href="banks.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>All Banks (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Bank</th><th>Code</th><th>Accounts</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="5">No banks yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= e($r['short_code'] ?? '—') ?></td>
                    <td><?= (int)$r['acc_count'] ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= action_buttons((int)$r['id'], 'Delete this bank?', '', $r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
