<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Add Category';

$uploadDir = dirname(__DIR__) . '/uploads/categories';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

function category_upload_image(array $file, string $uploadDir): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Image upload failed.'];
    }
    if (($file['size'] ?? 0) > 1 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Image must be under 1MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Only PNG, JPG, SVG or WebP images allowed.'];
    }

    $name = 'cat_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Could not save uploaded image.'];
    }

    return ['ok' => true, 'path' => 'uploads/categories/' . $name];
}

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'product_categories', (int) $_GET['toggle']);
    header('Location: product-categories.php');
    exit;
}
if (isset($_GET['delete'])) {
    $delId = (int) $_GET['delete'];
    $imgStmt = $pdo->prepare('SELECT image FROM product_categories WHERE id = ?');
    $imgStmt->execute([$delId]);
    $oldImg = $imgStmt->fetchColumn();
    if (utility_delete($pdo, 'product_categories', $delId) && $oldImg) {
        $full = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', (string) $oldImg), '/');
        if (is_file($full)) {
            @unlink($full);
        }
    }
    header('Location: product-categories.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $removeImage = isset($_POST['remove_image']);

    if ($name === '') {
        $errors[] = 'Category name is required.';
    }

    $upload = category_upload_image($_FILES['image'] ?? [], $uploadDir);
    if (!$upload['ok']) {
        $errors[] = $upload['error'];
    }

    if (!$errors) {
        try {
            $currentImage = null;
            if ($id > 0) {
                $cur = $pdo->prepare('SELECT image FROM product_categories WHERE id = ?');
                $cur->execute([$id]);
                $currentImage = $cur->fetchColumn() ?: null;
            }

            $imagePath = $currentImage;
            if (!empty($upload['path'])) {
                $imagePath = $upload['path'];
                if ($currentImage) {
                    $full = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', (string) $currentImage), '/');
                    if (is_file($full)) {
                        @unlink($full);
                    }
                }
            } elseif ($removeImage && $id > 0) {
                if ($currentImage) {
                    $full = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', (string) $currentImage), '/');
                    if (is_file($full)) {
                        @unlink($full);
                    }
                }
                $imagePath = null;
            }

            if ($id > 0) {
                $pdo->prepare('UPDATE product_categories SET name=?, image=?, status=? WHERE id=?')
                    ->execute([$name, $imagePath, $status, $id]);
                log_activity('product_category_edit', "Updated category #$id");
                flash('success', 'Category updated.');
            } else {
                $pdo->prepare('INSERT INTO product_categories (name, image, status) VALUES (?,?,?)')
                    ->execute([$name, $imagePath, $status]);
                log_activity('product_category_add', "Added category $name");
                flash('success', 'Category added.');
            }
            header('Location: product-categories.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Category already exists or DB error.';
            if (!empty($upload['path'])) {
                $full = dirname(__DIR__) . '/' . ltrim($upload['path'], '/');
                if (is_file($full)) {
                    @unlink($full);
                }
            }
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM product_categories WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$rows = $pdo->query('
    SELECT c.*,
           (SELECT COUNT(*) FROM product_subcategories s WHERE s.category_id = c.id) AS sub_count
    FROM product_categories c
    ORDER BY c.name
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit Category' : 'Add Category' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Category Name *</label>
                    <input type="text" name="name" value="<?= e($edit['name'] ?? $_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?= (($edit['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= (($edit['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div style="grid-column:1/-1">
                    <?php $hasImage = !empty($edit['image']); ?>
                    <div class="su-upload<?= $hasImage ? ' is-filled' : '' ?>" id="catImgUpload">
                        <div class="su-upload__head">
                            <span class="su-upload__badge" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                            </span>
                            <h3 class="su-upload__title">Category Image</h3>
                        </div>

                        <div class="su-upload__card">
                            <div class="su-upload__topline">
                                <span class="su-upload__label">Category Icon / Image</span>
                                <span class="su-upload__req">Required</span>
                            </div>

                            <div class="su-upload__row">
                                <div class="su-upload__preview" id="catImgPreview">
                                    <?php if ($hasImage): ?>
                                        <img src="../<?= e($edit['image']) ?>" alt="Preview" id="catImgThumb">
                                    <?php else: ?>
                                        <span class="su-upload__ph" id="catImgEmpty" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="su-upload__side">
                                    <label class="su-upload__pick" for="catImgInput" id="catImgDrop">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 16l-4-4-4 4"/><path d="M12 12v9"/><path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/></svg>
                                        <span>Choose image</span>
                                    </label>
                                    <input type="file" name="image" id="catImgInput" accept="image/png,image/jpeg,image/webp,image/svg+xml" <?= $hasImage ? '' : 'required' ?> style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;clip:rect(0,0,0,0)">
                                    <p class="su-upload__file" id="catImgName"><?= $hasImage ? e(basename($edit['image'])) : 'No file selected' ?></p>
                                    <p class="su-upload__hint">PNG, JPG, SVG or WebP · max 1 MB</p>
                                    <?php if ($hasImage): ?>
                                    <label class="inline-check" style="margin-top:0.55rem">
                                        <input type="checkbox" name="remove_image" value="1"> Remove current image
                                    </label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add Category' ?></button>
                <?php if ($edit): ?><a href="product-categories.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>All Categories (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Image</th><th>Name</th><th>Sub-Categories</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="5">No categories yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <?php if (!empty($r['image'])): ?>
                            <img class="cat-thumb" src="../<?= e($r['image']) ?>" alt="">
                        <?php else: ?>
                            <span class="cat-thumb empty">—</span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= (int)$r['sub_count'] ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= action_buttons((int)$r['id'], 'Delete this category and its sub-categories?', '', $r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
