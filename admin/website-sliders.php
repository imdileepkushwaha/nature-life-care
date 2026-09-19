<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
require_once __DIR__ . '/../includes/website_sliders.php';

$pageTitle = 'Add Slider';
website_sliders_ensure_table($pdo);

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'website_sliders', (int) $_GET['toggle']);
    header('Location: website-sliders.php');
    exit;
}
if (isset($_GET['delete'])) {
    $delId = (int) $_GET['delete'];
    if ($delId > 0) {
        $st = $pdo->prepare('SELECT image_path FROM website_sliders WHERE id = ? LIMIT 1');
        $st->execute([$delId]);
        $old = $st->fetch();
        if ($old && utility_delete($pdo, 'website_sliders', $delId)) {
            website_slider_delete_file((string) ($old['image_path'] ?? ''));
        }
    }
    header('Location: website-sliders.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $heading = trim((string) ($_POST['heading'] ?? ''));
    $lead = trim((string) ($_POST['lead'] ?? ''));
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $existingPath = '';

    if (strlen($heading) > 200) {
        $errors[] = 'Heading must be 200 characters or less.';
    }
    if (strlen($lead) > 400) {
        $errors[] = 'Lead text must be 400 characters or less.';
    }

    if ($id > 0) {
        $st = $pdo->prepare('SELECT image_path FROM website_sliders WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $existing = $st->fetch();
        if (!$existing) {
            $errors[] = 'Slider not found.';
        } else {
            $existingPath = (string) ($existing['image_path'] ?? '');
        }
    }

    $up = website_slider_store_image($_FILES['image'] ?? []);
    if (!$up['ok']) {
        $errors[] = $up['error'] ?? 'Image upload failed.';
    }

    $imagePath = $up['path'] ?: $existingPath;
    if ($imagePath === '') {
        $errors[] = 'Please upload a slider image.';
    }

    if (!$errors) {
        if ($id > 0) {
            $pdo->prepare('UPDATE website_sliders SET heading=?, lead=?, image_path=?, sort_order=?, status=? WHERE id=?')
                ->execute([$heading, $lead, $imagePath, $sortOrder, $status, $id]);
            if (!empty($up['path']) && $existingPath !== '' && $existingPath !== $up['path']) {
                website_slider_delete_file($existingPath);
            }
            flash('success', 'Slider updated. Reload the home page to see it.');
        } else {
            $pdo->prepare('INSERT INTO website_sliders (heading, lead, image_path, sort_order, status) VALUES (?,?,?,?,?)')
                ->execute([$heading, $lead, $imagePath, $sortOrder, $status]);
            flash('success', 'Slider added. Active slides show on the home hero.');
        }
        log_activity($id ? 'website_slider_edit' : 'website_slider_add', 'Slider #' . ($id ?: 'new'));
        header('Location: website-sliders.php');
        exit;
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM website_sliders WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

$rows = $pdo->query('SELECT * FROM website_sliders ORDER BY sort_order ASC, id DESC')->fetchAll();
$hasImage = $edit && !empty($edit['image_path']);
$activeCount = 0;
foreach ($rows as $r) {
    if (($r['status'] ?? '') === 'active' && !empty($r['image_path'])) {
        $activeCount++;
    }
}
$nextSort = 0;
if (!$edit) {
    foreach ($rows as $r) {
        $nextSort = max($nextSort, (int) ($r['sort_order'] ?? 0) + 1);
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
                <h1><?= $edit ? 'Edit Slider' : 'Add Slider' ?></h1>
                <p class="rpt-sub">Active slides show on the home page hero. If none are active, the default slides stay.</p>
            </div>
        </div>
        <div class="rpt-hero-actions">
            <?php if ($edit): ?>
                <a class="btn btn-outline btn-sm" href="website-sliders.php">Back to list</a>
            <?php endif; ?>
            <a class="btn btn-outline btn-sm" href="../index.php" target="_blank" rel="noopener">Open website</a>
        </div>
    </header>

    <div class="rpt-stats">
        <article class="rpt-stat">
            <span class="rpt-stat-ico is-blue"><?= $icoImg ?></span>
            <div>
                <span class="rpt-stat-label">Total sliders</span>
                <strong><?= count($rows) ?></strong>
            </div>
        </article>
        <article class="rpt-stat">
            <span class="rpt-stat-ico is-green"><?= $icoWeb ?></span>
            <div>
                <span class="rpt-stat-label">Active on home</span>
                <strong><?= (int) $activeCount ?></strong>
                <small><?= $activeCount ? 'shown on home hero' : 'defaults used until you add one' ?></small>
            </div>
        </article>
    </div>

    <div class="panel">
        <div class="panel-header">
            <h2><?= $edit ? 'Update hero slide' : 'New home slider' ?></h2>
            <span class="muted" style="font-size:0.8rem;font-weight:600">JPG · PNG · WebP · GIF · max 3MB</span>
        </div>
        <div class="panel-body">
            <p class="muted" style="margin:0 0 1rem">Image is required. Heading and lead are optional. Sort order is lowest first.</p>
            <?php if ($errors): ?>
                <div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="wp-popup-form">
                <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="sliderHeading">Heading</label>
                        <textarea id="sliderHeading" name="heading" rows="2" maxlength="200" placeholder="Nurturing Health&#10;Growing Prosperity"><?= e($edit['heading'] ?? $_POST['heading'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="sliderLead">Lead text</label>
                        <textarea id="sliderLead" name="lead" rows="2" maxlength="400" placeholder="स्वास्थ्य भी · रोजगार भी · सम्मान भी"><?= e($edit['lead'] ?? $_POST['lead'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="sliderSort">Sort order</label>
                        <input type="number" id="sliderSort" name="sort_order" min="0" step="1" value="<?= (int) ($edit['sort_order'] ?? $_POST['sort_order'] ?? $nextSort) ?>">
                    </div>
                    <div class="form-group">
                        <label for="sliderStatus">Status</label>
                        <select id="sliderStatus" name="status">
                            <option value="active" <?= (($edit['status'] ?? $_POST['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active — show on home</option>
                            <option value="inactive" <?= (($edit['status'] ?? $_POST['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive — hidden</option>
                        </select>
                    </div>
                </div>

                <div class="su-upload su-upload--popup<?= $hasImage ? ' is-filled' : '' ?>" id="sliderImgUpload" style="margin-top:1.1rem">
                    <div class="su-upload__card su-upload__card--wide">
                        <div class="su-upload__topline">
                            <span class="su-upload__label">Hero slider image</span>
                            <span class="su-upload__req"><?= $hasImage ? 'Optional replace' : 'Required' ?></span>
                        </div>

                        <div class="su-upload__row su-upload__row--popup">
                            <div class="su-upload__preview su-upload__preview--lg" id="sliderImgPreview">
                                <?php if ($hasImage): ?>
                                    <img src="<?= e(website_slider_image_url((string) $edit['image_path'])) ?>" alt="Preview" id="sliderImgThumb">
                                <?php else: ?>
                                    <span class="su-upload__ph" id="sliderImgEmpty" aria-hidden="true"><?= $icoImg ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="su-upload__side">
                                <label class="su-upload__pick" for="sliderImgInput" id="sliderImgDrop">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 16l-4-4-4 4"/><path d="M12 12v9"/><path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/></svg>
                                    <span>Choose image or drop here</span>
                                </label>
                                <input type="file" name="image" id="sliderImgInput" accept="image/jpeg,image/png,image/webp,image/gif" <?= $hasImage ? '' : 'required' ?> style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;clip:rect(0,0,0,0)">
                                <p class="su-upload__file" id="sliderImgName"><?= $hasImage ? e(basename((string) $edit['image_path'])) : 'No file selected' ?></p>
                                <p class="su-upload__hint">Recommended: landscape 4:3 · under 3MB</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions" style="margin-top:1.1rem">
                    <button type="submit" class="btn btn-primary"><?= $edit ? 'Update Slider' : 'Add Slider' ?></button>
                    <?php if ($edit): ?>
                        <a href="website-sliders.php" class="btn btn-outline">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <h2>Slider list (<?= count($rows) ?>)</h2>
        </div>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Heading</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="6">No sliders yet. Upload an image above — active slides show on the home hero.</td></tr>
                <?php else: foreach ($rows as $r):
                    $img = (string) ($r['image_path'] ?? '');
                    $heading = trim((string) ($r['heading'] ?? ''));
                    ?>
                    <tr>
                        <td>
                            <?php if ($img !== ''): ?>
                                <img class="wp-popup-thumb" src="<?= e(website_slider_image_url($img)) ?>" alt="Slider #<?= (int) $r['id'] ?>">
                            <?php else: ?>
                                <span class="wp-popup-thumb is-empty">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= $heading !== '' ? e($heading) : '—' ?></strong>
                            <?php if (trim((string) ($r['lead'] ?? '')) !== ''): ?>
                                <div class="muted" style="font-size:0.78rem;margin-top:0.2rem"><?= e((string) $r['lead']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) ($r['sort_order'] ?? 0) ?></td>
                        <td><?= status_badge((string) $r['status']) ?></td>
                        <td><?= !empty($r['created_at']) ? e(date('d M Y H:i', strtotime((string) $r['created_at']))) : '—' ?></td>
                        <td><?= action_buttons((int) $r['id'], 'Delete this slider?', '', (string) $r['status']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
