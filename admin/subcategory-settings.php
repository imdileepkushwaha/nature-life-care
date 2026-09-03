<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Sub-Category Settings';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'subcategory_settings', (int) $_GET['toggle']);
    header('Location: subcategory-settings.php');
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'subcategory_settings', (int) $_GET['delete']);
    header('Location: subcategory-settings.php');
    exit;
}

$subcategories = $pdo->query("
    SELECT s.id, s.name, c.name AS category_name
    FROM product_subcategories s
    JOIN product_categories c ON c.id = s.category_id
    WHERE s.status='active'
    ORDER BY c.name, s.name
")->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $subcategoryId = (int) ($_POST['subcategory_id'] ?? 0);
    $commissionPercent = (float) ($_POST['commission_percent'] ?? 0);
    $minStockAlert = (int) ($_POST['min_stock_alert'] ?? 5);
    $allowPurchase = isset($_POST['allow_purchase']) ? 1 : 0;
    $notes = trim($_POST['notes'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if (!$subcategoryId) $errors[] = 'Select a sub-category.';
    if ($commissionPercent < 0) $errors[] = 'Commission percent cannot be negative.';
    if ($minStockAlert < 0) $errors[] = 'Min stock alert cannot be negative.';

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE subcategory_settings SET subcategory_id=?, commission_percent=?, min_stock_alert=?, allow_purchase=?, notes=?, status=? WHERE id=?')
                    ->execute([$subcategoryId, $commissionPercent, $minStockAlert, $allowPurchase, $notes ?: null, $status, $id]);
                log_activity('subcategory_settings_edit', "Updated settings #$id");
                flash('success', 'Settings updated.');
            } else {
                $pdo->prepare('
                    INSERT INTO subcategory_settings (subcategory_id, commission_percent, min_stock_alert, allow_purchase, notes, status)
                    VALUES (?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        commission_percent = VALUES(commission_percent),
                        min_stock_alert = VALUES(min_stock_alert),
                        allow_purchase = VALUES(allow_purchase),
                        notes = VALUES(notes),
                        status = VALUES(status)
                ')->execute([$subcategoryId, $commissionPercent, $minStockAlert, $allowPurchase, $notes ?: null, $status]);
                log_activity('subcategory_settings_save', "Saved settings for subcategory #$subcategoryId");
                flash('success', 'Settings saved.');
            }
            header('Location: subcategory-settings.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Could not save settings. Sub-category may already have settings.';
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM subcategory_settings WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$rows = $pdo->query('
    SELECT ss.*, s.name AS subcategory_name, c.name AS category_name
    FROM subcategory_settings ss
    JOIN product_subcategories s ON s.id = ss.subcategory_id
    JOIN product_categories c ON c.id = s.category_id
    ORDER BY c.name, s.name
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit Settings' : 'Sub-Category Settings' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Sub-Category *</label>
                    <select name="subcategory_id" required>
                        <option value="">— Select Sub-Category —</option>
                        <?php foreach ($subcategories as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= ((int)($edit['subcategory_id'] ?? $_POST['subcategory_id'] ?? 0) === (int)$s['id']) ? 'selected' : '' ?>>
                            <?= e($s['category_name'] . ' / ' . $s['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Commission %</label>
                    <input type="number" step="0.01" name="commission_percent" value="<?= e((string)($edit['commission_percent'] ?? $_POST['commission_percent'] ?? '0')) ?>">
                </div>
                <div class="form-group">
                    <label>Min Stock Alert</label>
                    <input type="number" name="min_stock_alert" value="<?= (int)($edit['min_stock_alert'] ?? $_POST['min_stock_alert'] ?? 5) ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?= (($edit['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= (($edit['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:.5rem;margin-top:1.6rem">
                        <input type="checkbox" name="allow_purchase" value="1" <?= ((int)($edit['allow_purchase'] ?? 1) === 1) ? 'checked' : '' ?>>
                        Allow Purchase
                    </label>
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label>Notes</label>
                    <textarea name="notes" rows="3"><?= e($edit['notes'] ?? $_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Save Settings' ?></button>
                <?php if ($edit): ?><a href="subcategory-settings.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>All Settings (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Sub-Category</th>
                    <th>Category</th>
                    <th>Commission %</th>
                    <th>Min Alert</th>
                    <th>Allow Purchase</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="7">No settings yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['subcategory_name']) ?></strong></td>
                    <td><?= e($r['category_name']) ?></td>
                    <td><?= e(number_format((float)$r['commission_percent'], 2)) ?></td>
                    <td><?= (int)$r['min_stock_alert'] ?></td>
                    <td><?= (int)$r['allow_purchase'] ? 'Yes' : 'No' ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= action_buttons((int)$r['id'], 'Delete these settings?', '', $r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
