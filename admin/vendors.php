<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Vendor Master';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'product_vendors', (int) $_GET['toggle']);
    header('Location: vendors.php');
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'product_vendors', (int) $_GET['delete']);
    header('Location: vendors.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($name === '') $errors[] = 'Vendor name is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE product_vendors SET name=?, contact_person=?, phone=?, email=?, address=?, status=? WHERE id=?')
                    ->execute([$name, $contactPerson ?: null, $phone ?: null, $email ?: null, $address ?: null, $status, $id]);
                log_activity('vendor_edit', "Updated vendor #$id");
                flash('success', 'Vendor updated.');
            } else {
                $pdo->prepare('INSERT INTO product_vendors (name, contact_person, phone, email, address, status) VALUES (?,?,?,?,?,?)')
                    ->execute([$name, $contactPerson ?: null, $phone ?: null, $email ?: null, $address ?: null, $status]);
                log_activity('vendor_add', "Added vendor $name");
                flash('success', 'Vendor added.');
            }
            header('Location: vendors.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Vendor already exists or DB error.';
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM product_vendors WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$rows = $pdo->query('SELECT * FROM product_vendors ORDER BY name')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit Vendor' : 'Add Vendor' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Vendor Name *</label>
                    <input type="text" name="name" value="<?= e($edit['name'] ?? $_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Contact Person</label>
                    <input type="text" name="contact_person" value="<?= e($edit['contact_person'] ?? $_POST['contact_person'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" value="<?= e($edit['phone'] ?? $_POST['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= e($edit['email'] ?? $_POST['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?= (($edit['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= (($edit['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label>Address</label>
                    <textarea name="address" rows="3"><?= e($edit['address'] ?? $_POST['address'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add Vendor' ?></button>
                <?php if ($edit): ?><a href="vendors.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>All Vendors (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Contact</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="6">No vendors yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= e($r['contact_person'] ?? '—') ?></td>
                    <td><?= e($r['phone'] ?? '—') ?></td>
                    <td><?= e($r['email'] ?? '—') ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= action_buttons((int)$r['id'], 'Delete this vendor?', '', $r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
