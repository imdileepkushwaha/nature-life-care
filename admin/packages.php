<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/package_products.php';
$pageTitle = 'Add Packages';

package_products_ensure_table($pdo);
$packagesLocked = client_packages_locked();
$showBinaryMetrics = plan_uses_binary();

// Ensure capping column exists
try {
    $col = $pdo->query("SHOW COLUMNS FROM packages LIKE 'capping'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE packages ADD COLUMN capping DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER bv");
    }
} catch (Throwable $e) {
    // ignore
}

$blockMutate = static function () use ($packagesLocked): void {
    if ($packagesLocked) {
        flash('error', 'Packages cannot be changed right now.');
        header('Location: packages.php');
        exit;
    }
};

// Delete
if (isset($_GET['delete'])) {
    $blockMutate();
    $id = (int) $_GET['delete'];
    $used = $pdo->prepare('SELECT COUNT(*) FROM members WHERE package_id = ?');
    $used->execute([$id]);
    if ((int) $used->fetchColumn() > 0) {
        flash('error', 'Cannot delete: package is assigned to members. Deactivate instead.');
    } else {
        $pdo->prepare('DELETE FROM packages WHERE id = ?')->execute([$id]);
        log_activity('package_delete', "Deleted package #$id");
        flash('success', 'Package deleted.');
    }
    header('Location: packages.php');
    exit;
}

// Toggle status
if (isset($_GET['toggle'])) {
    $blockMutate();
    $id = (int) $_GET['toggle'];
    $pdo->prepare("UPDATE packages SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([$id]);
    flash('success', 'Package status updated.');
    header('Location: packages.php' . (!empty($_GET['edit']) ? '?edit=' . (int) $_GET['edit'] : ''));
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $blockMutate();
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $amount = (float) ($_POST['amount'] ?? 0);
    $bv = (float) ($_POST['bv'] ?? 0);
    $capping = (float) ($_POST['capping'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if (!$showBinaryMetrics) {
        if ($id > 0) {
            $keep = $pdo->prepare('SELECT bv, capping FROM packages WHERE id = ?');
            $keep->execute([$id]);
            $keepRow = $keep->fetch();
            if ($keepRow) {
                $bv = (float) $keepRow['bv'];
                $capping = (float) $keepRow['capping'];
            }
        } else {
            $bv = $amount > 0 ? $amount : 0;
            $capping = 0;
        }
    }

    if ($name === '') {
        $errors[] = 'Plan name is required.';
    }
    if ($amount <= 0) {
        $errors[] = 'Investment amount must be greater than 0.';
    }
    if ($showBinaryMetrics) {
        if ($bv < 0) {
            $errors[] = 'BV cannot be negative.';
        }
        if ($bv === 0.0 && $amount > 0) {
            $bv = $amount;
        }
        if ($capping < 0) {
            $errors[] = 'Capping cannot be negative.';
        }
    }

    if (!$errors) {
        if ($id > 0) {
            $pdo->prepare('UPDATE packages SET name=?, amount=?, bv=?, capping=?, description=?, status=? WHERE id=?')
                ->execute([$name, $amount, $bv, $capping, $description, $status, $id]);
            log_activity('package_edit', "Updated package #$id");
            flash('success', 'Package updated.');
        } else {
            $pdo->prepare('INSERT INTO packages (name, amount, bv, capping, daily_roi, validity_days, description, status) VALUES (?,?,?,?,0,30,?,?)')
                ->execute([$name, $amount, $bv, $capping, $description, $status]);
            log_activity('package_add', "Added package $name");
            flash('success', 'Package added.');
        }
        header('Location: packages.php');
        exit;
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM packages WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$packages = $pdo->query('
    SELECT p.*,
           (SELECT COUNT(*) FROM members m WHERE m.package_id = p.id) AS member_count,
           (SELECT COUNT(*) FROM package_products pp WHERE pp.package_id = p.id) AS product_count
    FROM packages p
    ORDER BY p.amount
')->fetchAll();

$formName = $edit['name'] ?? $_POST['name'] ?? '';
$formAmount = $edit['amount'] ?? $_POST['amount'] ?? '';
$formBv = $edit['bv'] ?? $_POST['bv'] ?? '';
$formCapping = $edit['capping'] ?? $_POST['capping'] ?? '';
$formDesc = $edit['description'] ?? $_POST['description'] ?? '';
$formStatus = $edit['status'] ?? $_POST['status'] ?? 'active';

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($packagesLocked): ?>
<div class="alert alert-info">
    <strong>Packages are locked.</strong>
    You can view plans below. Add / edit / delete is not available right now.
</div>
<?php endif; ?>

<?php if (!$packagesLocked): ?>
<div class="panel pkg-form-panel">
    <div class="panel-header">
        <div>
            <h2><?= $edit ? 'Edit Package' : 'Add Package' ?></h2>
            <p class="members-sub"><?= $showBinaryMetrics ? 'Plan name, investment, BV, capping and description' : 'Plan name, investment and description' ?></p>
        </div>
        <?php if ($edit): ?>
        <a href="packages.php" class="btn btn-outline btn-sm">Cancel edit</a>
        <?php endif; ?>
    </div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <div class="form-grid pkg-form-grid">
                <div class="form-group">
                    <label>Plan Name *</label>
                    <input type="text" name="name" value="<?= e((string) $formName) ?>" placeholder="e.g. Starter Plan" required>
                </div>
                <div class="form-group">
                    <label>Investment Amount (₹) *</label>
                    <input type="number" step="0.01" min="0" name="amount" value="<?= e((string) $formAmount) ?>" placeholder="1000" required>
                </div>
                <?php if ($showBinaryMetrics): ?>
                <div class="form-group">
                    <label>BV (Business Volume)</label>
                    <input type="number" step="0.01" min="0" name="bv" value="<?= e((string) $formBv) ?>" placeholder="Same as amount if blank">
                    <small class="field-hint">Leave blank to use investment amount</small>
                </div>
                <div class="form-group">
                    <label>Capping (₹)</label>
                    <input type="number" step="0.01" min="0" name="capping" value="<?= e((string) $formCapping) ?>" placeholder="e.g. 50000">
                    <small class="field-hint">Max earning limit for this package (0 = no limit)</small>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?= $formStatus === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $formStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="form-group pkg-form-desc">
                    <label>Description</label>
                    <input type="text" name="description" value="<?= e((string) $formDesc) ?>" placeholder="Short plan benefits…">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update Package' : 'Add Package' ?></button>
                <?php if ($edit): ?><a href="packages.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="pkg-cards-head">
    <h2>All Packages</h2>
    <span><?= count($packages) ?> plan<?= count($packages) === 1 ? '' : 's' ?></span>
</div>

<div class="pkg-cards">
<?php if (!$packages): ?>
    <div class="empty-state" style="grid-column:1/-1">
        <strong>No packages yet</strong>
        <span>Add your first investment plan above.</span>
    </div>
<?php else: foreach ($packages as $p):
    $isActive = ($p['status'] === 'active');
    $cap = (float) ($p['capping'] ?? 0);
?>
    <article class="pkg-card <?= $isActive ? '' : 'is-inactive' ?>">
        <div class="pkg-card-top">
            <span class="pkg-id">ID #<?= (int) $p['id'] ?></span>
            <span class="pkg-status <?= $isActive ? 'on' : 'off' ?>">
                <span class="pkg-status-dot"></span>
                <?= $isActive ? 'ACTIVE' : 'INACTIVE' ?>
            </span>
        </div>
        <h3 class="pkg-name"><?= e($p['name']) ?></h3>
        <div class="pkg-price-box">
            <div class="pkg-price"><?= currency((float) $p['amount']) ?></div>
            <div class="pkg-price-label">One-time Investment</div>
        </div>
        <div class="pkg-metrics">
            <?php if ($showBinaryMetrics): ?>
            <div class="pkg-metric">
                <span class="pkg-metric-ico blue">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                </span>
                <div>
                    <strong><?= number_format((float) $p['bv'], 0) ?></strong>
                    <span>BV</span>
                </div>
            </div>
            <div class="pkg-metric">
                <span class="pkg-metric-ico green">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1v22M4 8h8a4 4 0 010 8H4"/><path d="M4 12h16"/></svg>
                </span>
                <div>
                    <strong><?= $cap > 0 ? currency($cap) : 'No limit' ?></strong>
                    <span>Capping</span>
                </div>
            </div>
            <?php endif; ?>
            <div class="pkg-metric">
                <span class="pkg-metric-ico purple">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                </span>
                <div>
                    <strong><?= (int) ($p['product_count'] ?? 0) ?></strong>
                    <span><?= (int) ($p['product_count'] ?? 0) === 1 ? 'Product' : 'Products' ?></span>
                </div>
            </div>
            <div class="pkg-metric">
                <span class="pkg-metric-ico orange">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                </span>
                <div>
                    <strong><?= (int) ($p['member_count'] ?? 0) ?></strong>
                    <span>Members</span>
                </div>
            </div>
        </div>
        <p class="pkg-desc"><?= e($p['description'] ?: 'No description') ?></p>
        <?php if (!$packagesLocked): ?>
        <div class="pkg-actions">
            <a href="?edit=<?= (int) $p['id'] ?>" class="pkg-btn edit">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                Edit
            </a>
            <a href="?toggle=<?= (int) $p['id'] ?>" class="pkg-btn <?= $isActive ? 'disable' : 'enable' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v10"/><path d="M18.36 6.64A9 9 0 1112 3"/></svg>
                <?= $isActive ? 'Disable' : 'Enable' ?>
            </a>
        </div>
        <?php endif; ?>
    </article>
<?php endforeach; endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
