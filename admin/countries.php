<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Add Country';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'countries', (int) $_GET['toggle']);
    header('Location: countries.php');
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'countries', (int) $_GET['delete']);
    header('Location: countries.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($name === '') $errors[] = 'Country name is required.';

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE countries SET name=?, code=?, status=? WHERE id=?')->execute([$name, $code ?: null, $status, $id]);
                log_activity('country_edit', "Updated country #$id");
                flash('success', 'Country updated.');
            } else {
                $pdo->prepare('INSERT INTO countries (name, code, status) VALUES (?,?,?)')->execute([$name, $code ?: null, $status]);
                log_activity('country_add', "Added country $name");
                flash('success', 'Country added.');
            }
            header('Location: countries.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Country already exists or DB error.';
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM countries WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$rows = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM states s WHERE s.country_id = c.id) AS state_count FROM countries c ORDER BY c.name')->fetchAll();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit Country' : 'Add Country' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Country Name *</label>
                    <input type="text" name="name" value="<?= e($edit['name'] ?? $_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Code (e.g. IN)</label>
                    <input type="text" name="code" maxlength="10" value="<?= e($edit['code'] ?? $_POST['code'] ?? '') ?>">
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
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add Country' ?></button>
                <?php if ($edit): ?><a href="countries.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>All Countries (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Name</th><th>Code</th><th>States</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="5">No countries yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= e($r['code'] ?? '—') ?></td>
                    <td><?= (int)$r['state_count'] ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= action_buttons((int)$r['id'], 'Delete this country and its states/cities?', '', $r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
