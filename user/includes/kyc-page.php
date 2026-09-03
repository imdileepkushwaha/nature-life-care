<?php
/**
 * Shared KYC page processor + render helpers.
 * Expects $kycType to be set before include (pan|bank|aadhar|upi).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../includes/kyc.php';
require_user();

$user = current_user($pdo);
if (!$user || ($user['status'] ?? '') === 'blocked') {
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_code']);
    header('Location: login.php');
    exit;
}

$types = kyc_doc_types();
if (empty($kycType) || !isset($types[$kycType])) {
    header('Location: index.php');
    exit;
}

$meta = $types[$kycType];
$pageTitle = $meta['title'];
$memberId = (int) $user['id'];
$errors = [];
$uploadDir = BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'kyc';

$doc = kyc_get_doc($pdo, $memberId, $kycType);
$canEdit = kyc_can_edit($doc);
$allDocs = kyc_get_all($pdo, $memberId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        flash('error', 'This document is locked while under review or already approved.');
        header('Location: ' . $meta['page']);
        exit;
    }

    $fileRequired = $kycType !== 'upi' && empty($doc['document_file']);

    if ($kycType === 'pan') {
        $panNumber = strtoupper(preg_replace('/\s+/', '', trim($_POST['pan_number'] ?? '')));
        $panName = trim($_POST['pan_name'] ?? '');
        if ($panNumber === '' || !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $panNumber)) {
            $errors[] = 'Enter a valid PAN number (e.g. ABCDE1234F).';
        }
        if ($panName === '') {
            $errors[] = 'Name on PAN is required.';
        }
    } elseif ($kycType === 'bank') {
        $accountHolder = trim($_POST['account_holder'] ?? '');
        $accountNumber = preg_replace('/\s+/', '', trim($_POST['account_number'] ?? ''));
        $ifsc = strtoupper(preg_replace('/\s+/', '', trim($_POST['ifsc_code'] ?? '')));
        $bankName = trim($_POST['bank_name'] ?? '');
        $branchName = trim($_POST['branch_name'] ?? '');
        if ($accountHolder === '') $errors[] = 'Account holder name is required.';
        if ($accountNumber === '' || !preg_match('/^[0-9]{9,18}$/', $accountNumber)) {
            $errors[] = 'Enter a valid account number (9–18 digits).';
        }
        if ($ifsc === '' || !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
            $errors[] = 'Enter a valid IFSC code.';
        }
        if ($bankName === '') $errors[] = 'Bank name is required.';
    } elseif ($kycType === 'upi') {
        $upiName = trim($_POST['upi_name'] ?? '');
        $upiId = strtolower(preg_replace('/\s+/', '', trim($_POST['upi_id'] ?? '')));
        if ($upiName === '' || !in_array($upiName, kyc_upi_apps(), true)) {
            $errors[] = 'Please select your UPI app (GPay, Paytm, PhonePe, etc.).';
        }
        if ($upiId === '' || !preg_match('/^[a-z0-9.\-_]{2,256}@[a-z]{2,64}$/i', $upiId)) {
            $errors[] = 'Enter a valid UPI ID (e.g. name@oksbi or 9876543210@paytm).';
        }
    } else {
        $aadharNumber = preg_replace('/\s+/', '', trim($_POST['aadhar_number'] ?? ''));
        $addressLine = trim($_POST['address_line'] ?? '');
        if ($aadharNumber === '' || !preg_match('/^[0-9]{12}$/', $aadharNumber)) {
            $errors[] = 'Enter a valid 12-digit Aadhaar number.';
        }
        if ($addressLine === '' || strlen($addressLine) < 10) {
            $errors[] = 'Enter your full address (at least 10 characters).';
        }
    }

    $newPath = null;
    $hasNewFile = isset($_FILES['document']) && ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hasNewFile) {
        $up = kyc_store_document($_FILES['document'], $memberId, $kycType, $uploadDir);
        if (!$up['ok']) {
            $errors[] = $up['error'];
        } else {
            $newPath = $up['path'];
        }
    } elseif ($fileRequired) {
        $errors[] = 'Please upload a document (JPG, PNG, WebP or PDF, max 3MB).';
    }

    if (!$errors) {
        $oldFile = $doc['document_file'] ?? null;
        $filePath = $newPath ?: $oldFile;

        if ($kycType === 'pan') {
            $sql = 'UPDATE member_kyc_documents SET
                pan_number = ?, pan_name = ?, document_file = ?,
                status = \'pending\', admin_note = NULL, submitted_at = NOW(), reviewed_at = NULL
                WHERE member_id = ? AND doc_type = ?';
            $pdo->prepare($sql)->execute([$panNumber, $panName, $filePath, $memberId, $kycType]);
        } elseif ($kycType === 'bank') {
            $sql = 'UPDATE member_kyc_documents SET
                account_holder = ?, account_number = ?, ifsc_code = ?, bank_name = ?, branch_name = ?,
                document_file = ?, status = \'pending\', admin_note = NULL, submitted_at = NOW(), reviewed_at = NULL
                WHERE member_id = ? AND doc_type = ?';
            $pdo->prepare($sql)->execute([
                $accountHolder, $accountNumber, $ifsc, $bankName,
                $branchName !== '' ? $branchName : null,
                $filePath, $memberId, $kycType,
            ]);
        } elseif ($kycType === 'upi') {
            $sql = 'UPDATE member_kyc_documents SET
                upi_name = ?, upi_id = ?, document_file = ?,
                status = \'pending\', admin_note = NULL, submitted_at = NOW(), reviewed_at = NULL
                WHERE member_id = ? AND doc_type = ?';
            $pdo->prepare($sql)->execute([$upiName, $upiId, $filePath, $memberId, $kycType]);
        } else {
            $sql = 'UPDATE member_kyc_documents SET
                aadhar_number = ?, address_line = ?, document_file = ?,
                status = \'pending\', admin_note = NULL, submitted_at = NOW(), reviewed_at = NULL
                WHERE member_id = ? AND doc_type = ?';
            $pdo->prepare($sql)->execute([$aadharNumber, $addressLine, $filePath, $memberId, $kycType]);
        }

        if ($newPath && $oldFile && $oldFile !== $newPath) {
            kyc_delete_file($oldFile);
        }

        kyc_sync_member_status($pdo, $memberId);
        flash('success', $meta['title'] . ' submitted for admin approval.');
        header('Location: ' . $meta['page']);
        exit;
    }
}

$doc = kyc_get_doc($pdo, $memberId, $kycType);
$canEdit = kyc_can_edit($doc);
$allDocs = kyc_get_all($pdo, $memberId);
$status = strtolower((string) ($doc['status'] ?? 'not_submitted'));
$docUrl = kyc_doc_url($doc['document_file'] ?? null);

require_once __DIR__ . '/header.php';
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
                        <?php if ($kycType === 'pan'): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M8 15h3"/></svg>
                        <?php elseif ($kycType === 'bank'): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 10l9-7 9 7"/><path d="M5 10v8h14v-8"/><path d="M9 18v-4h6v4"/></svg>
                        <?php elseif ($kycType === 'upi'): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>
                        <?php else: ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M3 16c1.5-2 3.5-3 6-3s4.5 1 6 3"/></svg>
                        <?php endif; ?>
                    </span>
                    <div>
                        <span class="up-panel-kicker">Identity verification</span>
                        <h2><?= e($meta['title']) ?></h2>
                        <p>Submit details clearly. Admin will review and approve.</p>
                    </div>
                </div>
                <span class="kyc-status-pill <?= kyc_status_badge_class($status) ?>"><?= e(kyc_status_label($status)) ?></span>
            </div>

            <div class="kyc-form-body">
                <?php foreach ($errors as $err): ?>
                    <div class="up-alert up-alert-err"><?= e($err) ?></div>
                <?php endforeach; ?>

                <?php if ($status === 'pending'): ?>
                    <div class="up-alert up-alert-info">Your <?= e($meta['title']) ?> is under admin review. You cannot edit until a decision is made.</div>
                <?php elseif ($status === 'approved'): ?>
                    <div class="up-alert up-alert-ok">This document is approved. Contact support if you need a correction.</div>
                <?php elseif ($status === 'rejected'): ?>
                    <div class="up-alert up-alert-err">
                        Rejected<?= !empty($doc['admin_note']) ? ': ' . e($doc['admin_note']) : '.' ?> Please update and resubmit.
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="kyc-form"<?= $canEdit ? '' : ' onsubmit="return false;"' ?>>
                    <div class="up-form-grid">
                        <?php if ($kycType === 'pan'): ?>
                            <div class="up-field">
                                <label for="pan_number">PAN Number</label>
                                <input type="text" id="pan_number" name="pan_number" maxlength="10"
                                       value="<?= e($_POST['pan_number'] ?? ($doc['pan_number'] ?? '')) ?>"
                                       placeholder="ABCDE1234F" <?= $canEdit ? 'required' : 'disabled' ?>>
                            </div>
                            <div class="up-field">
                                <label for="pan_name">Name on PAN</label>
                                <input type="text" id="pan_name" name="pan_name"
                                       value="<?= e($_POST['pan_name'] ?? ($doc['pan_name'] ?? '')) ?>"
                                       <?= $canEdit ? 'required' : 'disabled' ?>>
                            </div>
                        <?php elseif ($kycType === 'bank'): ?>
                            <div class="up-field full">
                                <label for="account_holder">Account Holder Name</label>
                                <input type="text" id="account_holder" name="account_holder"
                                       value="<?= e($_POST['account_holder'] ?? ($doc['account_holder'] ?? '')) ?>"
                                       <?= $canEdit ? 'required' : 'disabled' ?>>
                            </div>
                            <div class="up-field">
                                <label for="account_number">Account Number</label>
                                <input type="text" id="account_number" name="account_number"
                                       value="<?= e($_POST['account_number'] ?? ($doc['account_number'] ?? '')) ?>"
                                       <?= $canEdit ? 'required' : 'disabled' ?>>
                            </div>
                            <div class="up-field">
                                <label for="ifsc_code">IFSC Code</label>
                                <input type="text" id="ifsc_code" name="ifsc_code" maxlength="11"
                                       value="<?= e($_POST['ifsc_code'] ?? ($doc['ifsc_code'] ?? '')) ?>"
                                       placeholder="SBIN0001234" <?= $canEdit ? 'required' : 'disabled' ?>>
                            </div>
                            <div class="up-field">
                                <label for="bank_name">Bank Name</label>
                                <input type="text" id="bank_name" name="bank_name"
                                       value="<?= e($_POST['bank_name'] ?? ($doc['bank_name'] ?? '')) ?>"
                                       <?= $canEdit ? 'required' : 'disabled' ?>>
                            </div>
                            <div class="up-field">
                                <label for="branch_name">Branch (optional)</label>
                                <input type="text" id="branch_name" name="branch_name"
                                       value="<?= e($_POST['branch_name'] ?? ($doc['branch_name'] ?? '')) ?>"
                                       <?= $canEdit ? '' : 'disabled' ?>>
                            </div>
                        <?php elseif ($kycType === 'upi'):
                            $selectedUpiName = (string) ($_POST['upi_name'] ?? ($doc['upi_name'] ?? ''));
                            ?>
                            <div class="up-field">
                                <label for="upi_name">UPI App / Name</label>
                                <select id="upi_name" name="upi_name" <?= $canEdit ? 'required' : 'disabled' ?>>
                                    <option value="">Select app</option>
                                    <?php foreach (kyc_upi_apps() as $app): ?>
                                        <option value="<?= e($app) ?>" <?= $selectedUpiName === $app ? 'selected' : '' ?>><?= e($app) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="up-field">
                                <label for="upi_id">UPI ID</label>
                                <input type="text" id="upi_id" name="upi_id" maxlength="100"
                                       value="<?= e($_POST['upi_id'] ?? ($doc['upi_id'] ?? '')) ?>"
                                       placeholder="yourname@upi" <?= $canEdit ? 'required' : 'disabled' ?>>
                            </div>
                            <div class="up-field full">
                                <p class="kyc-hint">Select your app (Google Pay, PhonePe, Paytm, etc.) and enter the UPI ID linked to your bank account.</p>
                            </div>
                        <?php else: ?>
                            <div class="up-field">
                                <label for="aadhar_number">Aadhaar Number</label>
                                <input type="text" id="aadhar_number" name="aadhar_number" maxlength="12"
                                       value="<?= e($_POST['aadhar_number'] ?? ($doc['aadhar_number'] ?? '')) ?>"
                                       placeholder="12 digits" <?= $canEdit ? 'required' : 'disabled' ?>>
                            </div>
                            <div class="up-field full">
                                <label for="address_line">Full Address</label>
                                <textarea id="address_line" name="address_line" rows="3" <?= $canEdit ? 'required' : 'disabled' ?>><?= e($_POST['address_line'] ?? ($doc['address_line'] ?? '')) ?></textarea>
                            </div>
                        <?php endif; ?>

                        <div class="up-field full">
                            <label><?= $kycType === 'upi' ? 'UPI QR / Screenshot (optional)' : 'Document File' ?><?= empty($doc['document_file']) ? '' : ' (optional to replace)' ?></label>
                            <?php
                            $dropTitle = match ($kycType) {
                                'pan' => 'Drag & drop PAN card image here',
                                'bank' => 'Drag & drop bank proof image here',
                                'upi' => 'Drag & drop UPI QR screenshot here',
                                default => 'Drag & drop Aadhaar / address proof here',
                            };
                            $dropHint = match ($kycType) {
                                'pan' => 'JPG, PNG, WEBP · Clear photo of front side',
                                'bank' => 'JPG, PNG, WEBP, PDF · Passbook / cancelled cheque',
                                'upi' => 'JPG, PNG, WEBP, PDF · Optional QR / payment app screenshot',
                                default => 'JPG, PNG, WEBP, PDF · Clear photo of front side',
                            };
                            $isPdfExisting = $docUrl && preg_match('/\.pdf$/i', (string) ($doc['document_file'] ?? ''));
                            ?>
                            <?php if ($canEdit): ?>
                                <div class="kyc-upload" id="kycUploadZone">
                                    <label class="kyc-drop<?= $docUrl ? ' has-file' : '' ?>" for="kycDocInput" id="kycDropzone">
                                        <input type="file" id="kycDocInput" name="document" accept="image/jpeg,image/png,image/webp,application/pdf" <?= ($kycType !== 'upi' && empty($doc['document_file'])) ? 'required' : '' ?>>

                                        <div class="kyc-drop-preview" id="kycDropPreview"<?= $docUrl ? '' : ' hidden' ?>>
                                            <?php if ($docUrl && !$isPdfExisting): ?>
                                                <img src="<?= e($docUrl) ?>" alt="Document preview" id="kycPreviewImg">
                                            <?php else: ?>
                                                <img src="" alt="Document preview" id="kycPreviewImg" hidden>
                                            <?php endif; ?>
                                            <div class="kyc-drop-pdf<?= ($docUrl && $isPdfExisting) ? '' : ' is-hidden' ?>" id="kycPreviewPdf">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 13h6M9 17h6"/></svg>
                                                <span id="kycPdfLabel"><?= $docUrl ? e(basename((string) $doc['document_file'])) : 'PDF document' ?></span>
                                            </div>
                                        </div>

                                        <div class="kyc-drop-empty" id="kycDropEmpty"<?= $docUrl ? ' hidden' : '' ?>>
                                            <span class="kyc-drop-ico" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 16.2A4.5 4.5 0 0018.5 8h-.6A6 6 0 005 9.5 4 4 0 005.5 17H19"/><polyline points="12 12 12 21"/><polyline points="8 15 12 11 16 15"/></svg>
                                            </span>
                                            <strong class="kyc-drop-title"><?= e($dropTitle) ?></strong>
                                            <span class="kyc-drop-browse">or <em>browse from device</em></span>
                                            <span class="kyc-drop-meta"><?= e($dropHint) ?></span>
                                        </div>
                                        <span class="kyc-file-name" id="kycFileName"<?= $docUrl ? '' : ' hidden' ?>><?= $docUrl ? e(basename((string) $doc['document_file'])) : '' ?></span>
                                    </label>
                                    <?php if ($docUrl): ?>
                                        <a class="kyc-view-doc" href="<?= e($docUrl) ?>" target="_blank" rel="noopener">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            View current document
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="kyc-upload is-locked">
                                    <?php if ($docUrl): ?>
                                        <div class="kyc-drop has-file is-readonly">
                                            <div class="kyc-drop-preview">
                                                <?php if ($isPdfExisting): ?>
                                                    <div class="kyc-drop-pdf">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 13h6M9 17h6"/></svg>
                                                        <span><?= e(basename((string) $doc['document_file'])) ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    <img src="<?= e($docUrl) ?>" alt="Uploaded document">
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <a class="kyc-view-doc" href="<?= e($docUrl) ?>" target="_blank" rel="noopener">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            View uploaded document
                                        </a>
                                    <?php else: ?>
                                        <input type="text" value="No document" disabled>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($canEdit): ?>
                    <div class="up-actions">
                        <button type="submit" class="up-btn up-btn-primary">Submit for Approval</button>
                        <a href="profile.php" class="up-btn up-btn-outline">Cancel</a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </section>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
