<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Add State';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'states', (int) $_GET['toggle']);
    header('Location: states.php');
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'states', (int) $_GET['delete']);
    header('Location: states.php');
    exit;
}

$countries = $pdo->query("SELECT id, name FROM countries WHERE status='active' ORDER BY name")->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $countryId = (int) ($_POST['country_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if (!$countryId) $errors[] = 'Select a country.';
    if ($name === '') $errors[] = 'State name is required.';

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE states SET country_id=?, name=?, status=? WHERE id=?')->execute([$countryId, $name, $status, $id]);
                flash('success', 'State updated.');
            } else {
                $pdo->prepare('INSERT INTO states (country_id, name, status) VALUES (?,?,?)')->execute([$countryId, $name, $status]);
                flash('success', 'State added.');
            }
            log_activity($id ? 'state_edit' : 'state_add', $name);
            header('Location: states.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'State already exists for this country.';
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM states WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$rows = $pdo->query('
    SELECT s.*, c.name AS country_name,
           (SELECT COUNT(*) FROM cities ci WHERE ci.state_id = s.id) AS city_count
    FROM states s
    JOIN countries c ON c.id = s.country_id
    ORDER BY c.name, s.name
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit State' : 'Add State' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Country *</label>
                    <select name="country_id" id="country_id" required>
                        <option value="">— Select Country —</option>
                        <?php foreach ($countries as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= ((int)($edit['country_id'] ?? $_POST['country_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>State Name *</label>
                    <input type="text" name="name" value="<?= e($edit['name'] ?? $_POST['name'] ?? '') ?>" required>
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
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add State' ?></button>
                <?php if ($edit): ?><a href="states.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>All States (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>State</th><th>Country</th><th>Cities</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="5">No states yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= e($r['country_name']) ?></td>
                    <td><?= (int)$r['city_count'] ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= action_buttons((int)$r['id'], 'Delete this state and its cities?', '', $r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
