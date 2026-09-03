<?php
$pageTitle = 'Upload Photo';
require_once __DIR__ . '/includes/auth.php';
require_user();

$user = current_user($pdo);
if (!$user || ($user['status'] ?? '') === 'blocked') {
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_code']);
    header('Location: login.php');
    exit;
}

ensure_member_photo_column($pdo);

$errors = [];
$uploadDir = BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'members';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'upload';

    if ($action === 'remove') {
        if (!empty($user['photo'])) {
            user_delete_photo_file($user['photo']);
            $pdo->prepare('UPDATE members SET photo = NULL WHERE id = ?')->execute([(int) $user['id']]);
            flash('success', 'Profile photo removed.');
        } else {
            flash('error', 'No photo to remove.');
        }
        header('Location: upload-photo.php');
        exit;
    }

    $upload = user_store_photo($_FILES['photo'] ?? [], (int) $user['id'], $uploadDir);
    if (!$upload['ok']) {
        $errors[] = $upload['error'];
    } else {
        $old = $user['photo'] ?? null;
        $pdo->prepare('UPDATE members SET photo = ? WHERE id = ?')->execute([$upload['path'], (int) $user['id']]);
        if ($old && $old !== $upload['path']) {
            user_delete_photo_file($old);
        }
        flash('success', 'Profile photo updated successfully.');
        header('Location: upload-photo.php');
        exit;
    }
}

// Re-load user after possible failed attempt state / for display
$user = current_user($pdo, true) ?? $user;
$photoUrl = user_photo_url($user['photo'] ?? null);
$hasPhoto = $photoUrl !== null;
$initials = user_initials((string) ($user['full_name'] ?? 'User'));

require_once __DIR__ . '/includes/header.php';
?>
<div class="up-page-head">
    <div>
        <h1>Upload Photo</h1>
        <p>Add or change your profile picture. JPG, PNG or WebP — max 2MB.</p>
    </div>
    <a href="profile.php" class="up-btn up-btn-outline">View Profile</a>
</div>

<?php user_flash_render(); ?>

<div class="ph">
    <aside class="ph-side">
        <div class="ph-preview-card">
            <div class="ph-side-banner">
                <span class="ph-orb a" aria-hidden="true"></span>
                <span class="ph-orb b" aria-hidden="true"></span>
                <span class="ph-kicker">Live preview</span>
            </div>
            <div class="ph-side-body">
                <div class="ph-avatar<?= $hasPhoto ? ' has-photo' : '' ?>" id="phAvatarPreview">
                    <?php if ($hasPhoto): ?>
                        <img src="<?= e($photoUrl) ?>" alt="<?= e($user['full_name']) ?>" id="phPreviewImg">
                    <?php else: ?>
                        <span id="phPreviewInitials"><?= e($initials) ?></span>
                        <img src="" alt="" id="phPreviewImg" hidden>
                    <?php endif; ?>
                </div>
                <h2><?= e($user['full_name']) ?></h2>
                <p>@<?= e($user['username']) ?></p>
                <div class="ph-chips">
                    <span class="ph-chip"><?= e($user['member_id']) ?></span>
                    <span class="ph-chip <?= $hasPhoto ? 'is-ok' : 'is-muted' ?>" id="phStatusChip">
                        <?= $hasPhoto ? 'Photo set' : 'No photo' ?>
                    </span>
                </div>
                <ul class="ph-tips">
                    <li>Use a clear face photo</li>
                    <li>Square crop looks best</li>
                    <li>Max size 2MB</li>
                </ul>
            </div>
        </div>
    </aside>

    <section class="ph-main">
        <div class="ph-form-card">
            <div class="ph-form-head">
                <div class="up-panel-head-main">
                    <span class="up-panel-head-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    </span>
                    <div>
                        <span class="up-panel-kicker">My Profile</span>
                        <h2>Profile Photo</h2>
                        <p>Upload a new image or remove the current one anytime.</p>
                    </div>
                </div>
            </div>

            <div class="ph-form-body">
                <?php foreach ($errors as $err): ?>
                    <div class="up-alert up-alert-err"><?= e($err) ?></div>
                <?php endforeach; ?>

                <form method="post" enctype="multipart/form-data" class="ph-form" id="phUploadForm">
                    <input type="hidden" name="action" value="upload">

                    <label class="ph-drop" for="phPhotoInput" id="phDropzone">
                        <input type="file" name="photo" id="phPhotoInput" accept="image/jpeg,image/png,image/webp" required>
                        <span class="ph-drop-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        </span>
                        <strong>Click to choose a photo</strong>
                        <small>or drag &amp; drop here · JPG, PNG, WebP · up to 2MB</small>
                        <span class="ph-file-name" id="phFileName">No file selected</span>
                    </label>

                    <div class="ph-actions">
                        <button type="submit" class="up-btn up-btn-primary ph-save" id="phUploadBtn" disabled>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                            Upload Photo
                        </button>
                        <a href="profile.php" class="up-btn up-btn-outline">Cancel</a>
                    </div>
                </form>

                <?php if ($hasPhoto): ?>
                <form method="post" class="ph-remove-form" onsubmit="return confirm('Remove your profile photo?');">
                    <input type="hidden" name="action" value="remove">
                    <button type="submit" class="up-btn ph-remove-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                        Remove Photo
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
