<?php
$pageTitle = 'UPI Details';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/kyc.php';
require_user();

$user = current_user($pdo);
if (!$user || ($user['status'] ?? '') === 'blocked') {
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_code']);
    header('Location: login.php');
    exit;
}

$kycType = 'upi';
$types = kyc_doc_types();
$meta = $types[$kycType];
$memberId = (int) $user['id'];
$errors = [];
$uploadDir = BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'kyc';
$maxUpis = 10;

ensure_kyc_upi_table($pdo);
$allDocs = kyc_get_all($pdo, $memberId);
$doc = $allDocs['upi'] ?? kyc_get_doc($pdo, $memberId, 'upi');
$status = strtolower((string) ($doc['status'] ?? 'not_submitted'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete') {
        $upiRowId = (int) ($_POST['upi_row_id'] ?? 0);
        $row = kyc_upi_get($pdo, $upiRowId, $memberId);
        if (!$row) {
            flash('error', 'UPI entry not found.');
        } elseif (($row['status'] ?? '') === 'approved') {
            flash('error', 'Approved UPI cannot be deleted. Contact support if you need a change.');
        } else {
            kyc_delete_file($row['document_file'] ?? null);
            $pdo->prepare('DELETE FROM member_kyc_upi WHERE id = ? AND member_id = ?')->execute([$upiRowId, $memberId]);
            kyc_upi_sync_parent($pdo, $memberId);
            flash('success', 'UPI entry removed.');
        }
        header('Location: kyc-upi.php');
        exit;
    }

    $upiName = trim($_POST['upi_name'] ?? '');
    $upiId = strtolower(preg_replace('/\s+/', '', trim($_POST['upi_id'] ?? '')));
    $existing = kyc_upi_list($pdo, $memberId);

    if (count($existing) >= $maxUpis) {
        $errors[] = 'You can add a maximum of ' . $maxUpis . ' UPI IDs.';
    }
    if ($upiName === '' || !in_array($upiName, kyc_upi_apps(), true)) {
        $errors[] = 'Please select your UPI app (GPay, Paytm, PhonePe, etc.).';
    }
    if ($upiId === '' || !preg_match('/^[a-z0-9.\-_]{2,256}@[a-z]{2,64}$/i', $upiId)) {
        $errors[] = 'Enter a valid UPI ID (e.g. name@oksbi or 9876543210@paytm).';
    } else {
        foreach ($existing as $ex) {
            if (strtolower((string) $ex['upi_id']) === $upiId) {
                $errors[] = 'This UPI ID is already added.';
                break;
            }
        }
    }

    $newPath = null;
    $hasNewFile = isset($_FILES['document']) && ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hasNewFile) {
        $up = kyc_store_document($_FILES['document'], $memberId, 'upi', $uploadDir);
        if (!$up['ok']) {
            $errors[] = $up['error'];
        } else {
            $newPath = $up['path'];
        }
    }

    if (!$errors) {
        try {
            $pdo->prepare('INSERT INTO member_kyc_upi
                (member_id, upi_name, upi_id, document_file, status, admin_note, submitted_at, reviewed_at)
                VALUES (?, ?, ?, ?, \'pending\', NULL, NOW(), NULL)')
                ->execute([$memberId, $upiName, $upiId, $newPath]);
            kyc_upi_sync_parent($pdo, $memberId);
            flash('success', 'UPI ID added and submitted for admin approval.');
            header('Location: kyc-upi.php');
            exit;
        } catch (Throwable $e) {
            if ($newPath) {
                kyc_delete_file($newPath);
            }
            $errors[] = 'Could not save UPI details. Please try again.';
        }
    }
}

$upiList = kyc_upi_list($pdo, $memberId);
$allDocs = kyc_get_all($pdo, $memberId);
$doc = $allDocs['upi'] ?? null;
$status = strtolower((string) ($doc['status'] ?? 'not_submitted'));
$formUpiName = (string) ($_POST['upi_name'] ?? '');
$formUpiId = (string) ($_POST['upi_id'] ?? '');

require_once __DIR__ . '/includes/header.php';
?>
<div class="up-page-head">
    <div>
        <h1><?= e($meta['title']) ?></h1>
        <p><?= e($meta['desc']) ?></p>
    </div>
    <a href="profile.php" class="up-btn up-btn-outline">Back to Profile</a>
</div>

<div class="kyc">
    <aside class="kyc-side">
        <div class="kyc-side-card">
            <div class="kyc-side-head">
                <div class="kyc-side-head-main">
                    <span class="kyc-side-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/></svg>
                    </span>
                    <div>
                        <span class="kyc-side-kicker">KYC checklist</span>
                        <h2>Documents</h2>
                    </div>
                </div>
            </div>
            <ul class="kyc-check-list">
                <?php foreach ($types as $type => $info):
                    $row = $allDocs[$type] ?? null;
                    $st = strtolower((string) ($row['status'] ?? 'not_submitted'));
                    ?>
                    <li class="<?= $type === $kycType ? 'is-current' : '' ?>">
                        <a href="<?= e($info['page']) ?>">
                            <span class="kyc-check-dot <?= kyc_status_badge_class($st) ?>"></span>
                            <span class="kyc-check-copy">
                                <strong><?= e($info['label']) ?></strong>
                                <small><?= e(kyc_status_label($st)) ?></small>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </aside>

    <section class="kyc-main">
        <div class="kyc-form-card">
            <div class="kyc-form-head">
                <div class="up-panel-head-main">
                    <span class="up-panel-head-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>
                    </span>
                    <div>
                        <span class="up-panel-kicker">Identity verification</span>
                        <h2><?= e($meta['title']) ?></h2>
                        <p>Add multiple UPI IDs. Each entry is reviewed by admin.</p>
                    </div>
                </div>
                <span class="kyc-status-pill <?= kyc_status_badge_class($status) ?>"><?= e(kyc_status_label($status)) ?></span>
            </div>

            <div class="kyc-form-body">
                <?php foreach ($errors as $err): ?>
                    <div class="up-alert up-alert-err"><?= e($err) ?></div>
                <?php endforeach; ?>

                <?php if (count($upiList) < $maxUpis): ?>
                <form method="post" enctype="multipart/form-data" class="kyc-form upi-add-form">
                    <input type="hidden" name="action" value="add">
                    <div class="upi-add-head">
                        <h3>Add new UPI</h3>
                        <p>Select app, enter UPI ID, and optionally upload QR screenshot.</p>
                    </div>
                    <div class="up-form-grid">
                        <div class="up-field">
                            <label for="upi_name">UPI App / Name</label>
                            <select id="upi_name" name="upi_name" required>
                                <option value="">Select app</option>
                                <?php foreach (kyc_upi_apps() as $app): ?>
                                    <option value="<?= e($app) ?>" <?= $formUpiName === $app ? 'selected' : '' ?>><?= e($app) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="up-field">
                            <label for="upi_id">UPI ID</label>
                            <input type="text" id="upi_id" name="upi_id" maxlength="100"
                                   value="<?= e($formUpiId) ?>"
                                   placeholder="yourname@upi" required>
                        </div>
                        <div class="up-field full">
                            <label>UPI QR / Screenshot (optional)</label>
                            <div class="kyc-upload" id="kycUploadZone">
                                <label class="kyc-drop" for="kycDocInput" id="kycDropzone">
                                    <input type="file" id="kycDocInput" name="document" accept="image/jpeg,image/png,image/webp,application/pdf">
                                    <div class="kyc-drop-preview" id="kycDropPreview" hidden>
                                        <img src="" alt="Document preview" id="kycPreviewImg" hidden>
                                        <div class="kyc-drop-pdf is-hidden" id="kycPreviewPdf">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 13h6M9 17h6"/></svg>
                                            <span id="kycPdfLabel">PDF document</span>
                                        </div>
                                    </div>
                                    <div class="kyc-drop-empty" id="kycDropEmpty">
                                        <span class="kyc-drop-ico" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 16.2A4.5 4.5 0 0018.5 8h-.6A6 6 0 005 9.5 4 4 0 005.5 17H19"/><polyline points="12 12 12 21"/><polyline points="8 15 12 11 16 15"/></svg>
                                        </span>
                                        <strong class="kyc-drop-title">Drag &amp; drop UPI QR screenshot here</strong>
                                        <span class="kyc-drop-browse">or <em>browse from device</em></span>
                                        <span class="kyc-drop-meta">JPG, PNG, WEBP, PDF · Optional</span>
                                    </div>
                                    <span class="kyc-file-name" id="kycFileName" hidden></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="up-actions">
                        <button type="submit" class="up-btn up-btn-primary">Add UPI ID</button>
                    </div>
                </form>
                <?php else: ?>
                    <div class="up-alert up-alert-info">Maximum of <?= (int) $maxUpis ?> UPI IDs reached. Remove a pending/rejected entry to add another.</div>
                <?php endif; ?>

                <div class="upi-saved">
                    <div class="upi-saved-head">
                        <h3>Saved UPI IDs</h3>
                        <span><?= count($upiList) ?> / <?= (int) $maxUpis ?></span>
                    </div>
                    <?php if (!$upiList): ?>
                        <div class="upi-empty">No UPI ID added yet. Use the form above to add your first one.</div>
                    <?php else: ?>
                        <div class="upi-table-wrap">
                            <table class="upi-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>UPI App</th>
                                        <th>UPI ID</th>
                                        <th>Submitted</th>
                                        <th>Status</th>
                                        <th>File</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($upiList as $i => $item):
                                    $itemStatus = strtolower((string) ($item['status'] ?? 'pending'));
                                    $itemUrl = kyc_doc_url($item['document_file'] ?? null);
                                    $canDelete = $itemStatus !== 'approved';
                                    ?>
                                    <tr>
                                        <td><?= $i + 1 ?></td>
                                        <td><strong><?= e($item['upi_name'] ?: 'UPI') ?></strong></td>
                                        <td><span class="upi-id"><?= e($item['upi_id']) ?></span></td>
                                        <td>
                                            <?= !empty($item['submitted_at']) ? e(date('d M Y, h:i A', strtotime($item['submitted_at']))) : '—' ?>
                                            <?php if ($itemStatus === 'rejected' && !empty($item['admin_note'])): ?>
                                                <div class="upi-note"><?= e($item['admin_note']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="kyc-status-pill <?= kyc_status_badge_class($itemStatus) ?>"><?= e(kyc_status_label($itemStatus)) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($itemUrl): ?>
                                                <a class="upi-file-link" href="<?= e($itemUrl) ?>" target="_blank" rel="noopener">View</a>
                                            <?php else: ?>
                                                <span class="upi-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($canDelete): ?>
                                                <form method="post" class="upi-del-form" onsubmit="return confirm('Remove this UPI ID?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="upi_row_id" value="<?= (int) $item['id'] ?>">
                                                    <button type="submit" class="upi-del-btn" title="Remove">Remove</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="upi-muted">Locked</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
