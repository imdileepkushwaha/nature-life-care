<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Commodity Prices';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'commodity_prices', (int) $_GET['toggle']);
    header('Location: commodity-prices.php');
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'commodity_prices', (int) $_GET['delete']);
    header('Location: commodity-prices.php');
    exit;
}

$products = $pdo->query("SELECT id, name, sku FROM products WHERE status='active' ORDER BY name")->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $productId = (int) ($_POST['product_id'] ?? 0) ?: null;
    $commodityName = trim($_POST['commodity_name'] ?? '');
    $price = (float) ($_POST['price'] ?? 0);
    $effectiveDate = trim($_POST['effective_date'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($commodityName === '') $errors[] = 'Commodity name is required.';
    if ($price < 0) $errors[] = 'Price cannot be negative.';
    if ($effectiveDate === '') $errors[] = 'Effective date is required.';

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE commodity_prices SET product_id=?, commodity_name=?, price=?, effective_date=?, note=?, status=? WHERE id=?')
                    ->execute([$productId, $commodityName, $price, $effectiveDate, $note ?: null, $status, $id]);
                log_activity('commodity_price_edit', "Updated commodity #$id");
                flash('success', 'Commodity price updated.');
            } else {
                $pdo->prepare('INSERT INTO commodity_prices (product_id, commodity_name, price, effective_date, note, status) VALUES (?,?,?,?,?,?)')
                    ->execute([$productId, $commodityName, $price, $effectiveDate, $note ?: null, $status]);
                log_activity('commodity_price_add', "Added commodity $commodityName");
                flash('success', 'Commodity price added.');
            }
            header('Location: commodity-prices.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Could not save commodity price.';
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM commodity_prices WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$rows = $pdo->query('
    SELECT cp.*, p.name AS product_name, p.sku
    FROM commodity_prices cp
    LEFT JOIN products p ON p.id = cp.product_id
    ORDER BY cp.effective_date DESC, cp.id DESC
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit Commodity Price' : 'Add Commodity Price' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Commodity Name *</label>
                    <input type="text" name="commodity_name" value="<?= e($edit['commodity_name'] ?? $_POST['commodity_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Linked Product (optional)</label>
                    <select name="product_id">
                        <option value="">— None —</option>
                        <?php foreach ($products as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= ((int)($edit['product_id'] ?? $_POST['product_id'] ?? 0) === (int)$p['id']) ? 'selected' : '' ?>>
                            <?= e($p['name'] . ($p['sku'] ? ' (' . $p['sku'] . ')' : '')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Price *</label>
                    <input type="number" step="0.01" name="price" value="<?= e((string)($edit['price'] ?? $_POST['price'] ?? '0')) ?>" required>
                </div>
                <div class="form-group">
                    <label>Effective Date *</label>
                    <input type="date" name="effective_date" value="<?= e($edit['effective_date'] ?? $_POST['effective_date'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?= (($edit['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= (($edit['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label>Note</label>
                    <textarea name="note" rows="3"><?= e($edit['note'] ?? $_POST['note'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add Price' ?></button>
                <?php if ($edit): ?><a href="commodity-prices.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>All Commodity Prices (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Commodity</th>
                    <th>Product</th>
                    <th>Price</th>
                    <th>Effective</th>
                    <th>Note</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="7">No commodity prices yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['commodity_name']) ?></strong></td>
                    <td><?= e($r['product_name'] ?? '—') ?></td>
                    <td><?= currency((float)$r['price']) ?></td>
                    <td><?= e($r['effective_date']) ?></td>
                    <td><?= e($r['note'] ?? '—') ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= action_buttons((int)$r['id'], 'Delete this commodity price?', '', $r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
