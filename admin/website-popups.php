<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
require_once __DIR__ . '/../includes/website_popups.php';

$pageTitle = 'Add Popup';
website_popups_ensure_table($pdo);

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'website_popups', (int) $_GET['toggle']);
    header('Location: website-popups.php');
    exit;
}
if (isset($_GET['delete'])) {
    $delId = (int) $_GET['delete'];
    if ($delId > 0) {
        $st = $pdo->prepare('SELECT image_path FROM website_popups WHERE id = ? LIMIT 1');
        $st->execute([$delId]);
        $old = $st->fetch();
        if ($old && utility_delete($pdo, 'website_popups', $delId)) {
            website_popup_delete_file((string) ($old['image_path'] ?? ''));
        }
    }
    header('Location: website-popups.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $existingPath = '';

    if ($id > 0) {
        $st = $pdo->prepare('SELECT image_path FROM website_popups WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $existing = $st->fetch();
        if (!$existing) {
            $errors[] = 'Popup not found.';
        } else {
            $existingPath = (string) ($existing['image_path'] ?? '');
        }
    }

    $up = website_popup_store_image($_FILES['image'] ?? []);
    if (!$up['ok']) {
        $errors[] = $up['error'] ?? 'Image upload failed.';
    }

    $imagePath = $up['path'] ?: $existingPath;
    if ($imagePath === '') {
        $errors[] = 'Please upload a popup image.';
    }

    if (!$errors) {
        if ($id > 0) {
            $pdo->prepare('UPDATE website_popups SET image_path=?, status=?, title=\'\', content=NULL, cta_label=NULL, cta_url=NULL WHERE id=?')
                ->execute([$imagePath, $status, $id]);
            if (!empty($up['path']) && $existingPath !== '' && $existingPath !== $up['path']) {
                website_popup_delete_file($existingPath);
            }
            flash('success', 'Popup updated. Active image shows on website reload.');
        } else {
            $pdo->prepare('INSERT INTO website_popups (title, content, cta_label, cta_url, image_path, status) VALUES (\'\', NULL, NULL, NULL, ?, ?)')
                ->execute([$imagePath, $status]);
            flash('success', 'Popup added. Reload the website to see it.');
        }
        log_activity($id ? 'website_popup_edit' : 'website_popup_add', 'Popup image #' . ($id ?: 'new'));
        header('Location: website-popups.php');
        exit;
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM website_popups WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

$rows = $pdo->query('SELECT * FROM website_popups ORDER BY id DESC')->fetchAll();
$hasImage = $edit && !empty($edit['image_path']);
$activeCount = 0;
foreach ($rows as $r) {
    if (($r['status'] ?? '') === 'active' && !empty($r['image_path'])) {
        $activeCount++;
    }
}

$icoWeb = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 010 20M12 2a15.3 15.3 0 000 20"/></svg>';
$icoImg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="wp-admin-page">
    <header class="rpt-hero">
        <div class="rpt-hero-glow" aria-hidden="true"></div>
        <div class="rpt-hero-main">
            <span class="rpt-hero-ico"><?= $icoWeb ?></span>
            <div>
                <p class="rpt-kicker">Website Management</p>
                <h1><?= $edit ? 'Edit Popup' : 'Add Popup' ?></h1>
                <p class="rpt-sub">Upload one image. Active popup shows on home &amp; contact every page reload.</p>
            </div>
        </div>
        <div class="rpt-hero-actions">
            <?php if ($edit): ?>
                <a class="btn btn-outline btn-sm" href="website-popups.php">Back to list</a>
            <?php endif; ?>
            <a class="btn btn-outline btn-sm" href="../index.php" target="_blank" rel="noopener">Open website</a>
        </div>
    </header>

    <div class="rpt-stats">
        <article class="rpt-stat">
            <span class="rpt-stat-ico is-blue"><?= $icoImg ?></span>
            <div>
                <span class="rpt-stat-label">Total popups</span>
                <strong><?= count($rows) ?></strong>
            </div>
        </article>
        <article class="rpt-stat">
            <span class="rpt-stat-ico is-green"><?= $icoWeb ?></span>
            <div>
                <span class="rpt-stat-label">Active on site</span>
                <strong><?= (int) $activeCount ?></strong>
                <small>latest active image shows</small>
            </div>
        </article>
    </div>

    <div class="panel">
        <div class="panel-header">
            <h2><?= $edit ? 'Update popup image' : 'New website popup' ?></h2>
            <span class="muted" style="font-size:0.8rem;font-weight:600">JPG · PNG · WebP · GIF · max 3MB</span>
        </div>
        <div class="panel-body">
            <p class="muted" style="margin:0 0 1rem">Drag &amp; drop supported. Active image shows on home &amp; contact every page reload.</p>
            <?php if ($errors): ?>
                <div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="wp-popup-form">
                <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

                <div class="su-upload su-upload--popup<?= $hasImage ? ' is-filled' : '' ?>" id="popupImgUpload">
                    <div class="su-upload__card su-upload__card--wide">
                        <div class="su-upload__topline">
                            <span class="su-upload__label">Website popup banner</span>
                            <span class="su-upload__req"><?= $hasImage ? 'Optional replace' : 'Required' ?></span>
                        </div>

                        <div class="su-upload__row su-upload__row--popup">
                            <div class="su-upload__preview su-upload__preview--lg" id="popupImgPreview">
                                <?php if ($hasImage): ?>
                                    <img src="<?= e(website_popup_image_url((string) $edit['image_path'])) ?>" alt="Preview" id="popupImgThumb">
                                <?php else: ?>
                                    <span class="su-upload__ph" id="popupImgEmpty" aria-hidden="true"><?= $icoImg ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="su-upload__side">
                                <label class="su-upload__pick" for="popupImgInput" id="popupImgDrop">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 16l-4-4-4 4"/><path d="M12 12v9"/><path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/></svg>
                                    <span>Choose image or drop here</span>
                                </label>
                                <input type="file" name="image" id="popupImgInput" accept="image/jpeg,image/png,image/webp,image/gif" <?= $hasImage ? '' : 'required' ?> style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;clip:rect(0,0,0,0)">
                                <p class="su-upload__file" id="popupImgName"><?= $hasImage ? e(basename((string) $edit['image_path'])) : 'No file selected' ?></p>
                                <p class="su-upload__hint">Recommended: portrait or square promo · under 3MB</p>

                                <div class="wp-popup-status">
                                    <label for="popupStatus">Status</label>
                                    <select id="popupStatus" name="status">
                                        <option value="active" <?= (($edit['status'] ?? $_POST['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active — show on website</option>
                                        <option value="inactive" <?= (($edit['status'] ?? $_POST['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive — hidden</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions" style="margin-top:1.1rem">
                    <button type="submit" class="btn btn-primary"><?= $edit ? 'Update Popup' : 'Add Popup' ?></button>
                    <?php if ($edit): ?>
                        <a href="website-popups.php" class="btn btn-outline">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <h2>Popup list (<?= count($rows) ?>)</h2>
        </div>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>ID</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="5">No popups yet. Upload an image above — it will show on website reload when Active.</td></tr>
                <?php else: foreach ($rows as $r):
                    $img = (string) ($r['image_path'] ?? '');
                    ?>
                    <tr>
                        <td>
                            <?php if ($img !== ''): ?>
                                <img class="wp-popup-thumb" src="<?= e(website_popup_image_url($img)) ?>" alt="Popup #<?= (int) $r['id'] ?>">
                            <?php else: ?>
                                <span class="wp-popup-thumb is-empty">—</span>
                            <?php endif; ?>
                        </td>
                        <td><strong>#<?= (int) $r['id'] ?></strong></td>
                        <td><?= status_badge((string) $r['status']) ?></td>
                        <td><?= !empty($r['created_at']) ? e(date('d M Y H:i', strtotime((string) $r['created_at']))) : '—' ?></td>
                        <td><?= action_buttons((int) $r['id'], 'Delete this popup?', '', (string) $r['status']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
