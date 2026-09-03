<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Add City';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'cities', (int) $_GET['toggle']);
    header('Location: cities.php');
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'cities', (int) $_GET['delete']);
    header('Location: cities.php');
    exit;
}

$countries = $pdo->query("SELECT id, name FROM countries WHERE status='active' ORDER BY name")->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $stateId = (int) ($_POST['state_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if (!$stateId) $errors[] = 'Select a state.';
    if ($name === '') $errors[] = 'City name is required.';

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE cities SET state_id=?, name=?, status=? WHERE id=?')->execute([$stateId, $name, $status, $id]);
                flash('success', 'City updated.');
            } else {
                $pdo->prepare('INSERT INTO cities (state_id, name, status) VALUES (?,?,?)')->execute([$stateId, $name, $status]);
                flash('success', 'City added.');
            }
            log_activity($id ? 'city_edit' : 'city_add', $name);
            header('Location: cities.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'City already exists for this state.';
        }
    }
}

$edit = null;
$editCountryId = 0;
$statesForEdit = [];
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT ci.*, s.country_id FROM cities ci JOIN states s ON s.id = ci.state_id WHERE ci.id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
    if ($edit) {
        $editCountryId = (int) $edit['country_id'];
        $st = $pdo->prepare("SELECT id, name FROM states WHERE country_id = ? AND status='active' ORDER BY name");
        $st->execute([$editCountryId]);
        $statesForEdit = $st->fetchAll();
    }
}

$rows = $pdo->query('
    SELECT ci.*, s.name AS state_name, c.name AS country_name
    FROM cities ci
    JOIN states s ON s.id = ci.state_id
    JOIN countries c ON c.id = s.country_id
    ORDER BY c.name, s.name, ci.name
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit City' : 'Add City' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Country *</label>
                    <select id="country_id" required>
                        <option value="">— Select Country —</option>
                        <?php foreach ($countries as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $editCountryId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>State *</label>
                    <select name="state_id" id="state_id" required>
                        <option value="">— Select State —</option>
                        <?php foreach ($statesForEdit as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= ((int)($edit['state_id'] ?? 0) === (int)$s['id']) ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>City Name *</label>
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
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add City' ?></button>
                <?php if ($edit): ?><a href="cities.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>All Cities (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>City</th><th>State</th><th>Country</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="5">No cities yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= e($r['state_name']) ?></td>
                    <td><?= e($r['country_name']) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= action_buttons((int)$r['id'], 'Delete this city?', '', $r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
