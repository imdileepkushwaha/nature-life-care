<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Add Color';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'product_colors', (int) $_GET['toggle']);
    header('Location: product-colors.php');
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'product_colors', (int) $_GET['delete']);
    header('Location: product-colors.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $hexCode = trim($_POST['hex_code'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($name === '') $errors[] = 'Color name is required.';
    if ($hexCode !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $hexCode)) {
        $errors[] = 'Hex code must be like #RRGGBB.';
    }

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE product_colors SET name=?, hex_code=?, status=? WHERE id=?')
                    ->execute([$name, $hexCode ?: null, $status, $id]);
                log_activity('product_color_edit', "Updated color #$id");
                flash('success', 'Color updated.');
            } else {
                $pdo->prepare('INSERT INTO product_colors (name, hex_code, status) VALUES (?,?,?)')
                    ->execute([$name, $hexCode ?: null, $status]);
                log_activity('product_color_add', "Added color $name");
                flash('success', 'Color added.');
            }
            header('Location: product-colors.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Color already exists or DB error.';
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM product_colors WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$rows = $pdo->query('SELECT * FROM product_colors ORDER BY name')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit Color' : 'Add Color' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Color Name *</label>
                    <input type="text" name="name" value="<?= e($edit['name'] ?? $_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="hexCodeInput">Color Code</label>
                    <?php
                    $hexVal = trim((string)($edit['hex_code'] ?? $_POST['hex_code'] ?? ''));
                    if ($hexVal === '' || !preg_match('/^#[0-9A-Fa-f]{6}$/i', $hexVal)) {
                        $pickerVal = '#000000';
                    } else {
                        $pickerVal = $hexVal;
                    }
                    ?>
                    <div class="hex-code-group">
                        <span class="hex-code-prefix" aria-hidden="true">#</span>
                        <label class="hex-code-swatch" for="hexColorPicker" title="Pick color">
                            <span class="hex-code-swatch-fill" id="hexSwatchFill" style="background: <?= e($pickerVal) ?>"></span>
                            <input type="color" id="hexColorPicker" value="<?= e($pickerVal) ?>" aria-label="Color picker">
                        </label>
                        <input type="text" name="hex_code" id="hexCodeInput" maxlength="7" placeholder="#FFFFFF" value="<?= e($hexVal) ?>" autocomplete="off">
                    </div>
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
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add Color' ?></button>
                <?php if ($edit): ?><a href="product-colors.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>All Colors (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Swatch</th><th>Name</th><th>Hex</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="5">No colors yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <span style="display:inline-block;width:28px;height:28px;border-radius:8px;border:1px solid rgba(0,0,0,.12);vertical-align:middle;background:<?= e($r['hex_code'] ?: '#ccc') ?>"></span>
                    </td>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= e($r['hex_code'] ?? '—') ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= action_buttons((int)$r['id'], 'Delete this color?', '', $r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
