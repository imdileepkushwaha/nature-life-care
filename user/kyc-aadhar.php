<?php
$pageTitle = 'Address Proof / Aadhaar';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/kyc.php';
require_once __DIR__ . '/../includes/geo.php';
require_user();

$user = current_user($pdo);
if (!$user || ($user['status'] ?? '') === 'blocked') {
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_code']);
    header('Location: login.php');
    exit;
}

$kycType = 'aadhar';
$types = kyc_doc_types();
$meta = $types[$kycType];
$memberId = (int) $user['id'];
$errors = [];
$uploadDir = BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'kyc';

$doc = kyc_get_doc($pdo, $memberId, $kycType);
$canEdit = kyc_can_edit($doc);

ensure_india_geo_seed($pdo);

$countries = [];
try {
    $countries = $pdo->query("SELECT id, name FROM countries WHERE status = 'active' ORDER BY name")->fetchAll();
} catch (Throwable $e) {
    $countries = [];
}

$form = [
    'aadhar_number' => $_POST['aadhar_number'] ?? ($doc['aadhar_number'] ?? ''),
    'address_line' => $_POST['address_line'] ?? ($doc['address_line'] ?? ''),
    'country' => $_POST['country'] ?? ($doc['country'] ?? ''),
    'state' => $_POST['state'] ?? ($doc['state'] ?? ''),
    'city' => $_POST['city'] ?? ($doc['city'] ?? ''),
    'area' => $_POST['area'] ?? ($doc['area'] ?? ''),
    'pincode' => $_POST['pincode'] ?? ($doc['pincode'] ?? ''),
    'country_id' => (int) ($_POST['country_id'] ?? 0),
    'state_id' => (int) ($_POST['state_id'] ?? 0),
    'city_id' => (int) ($_POST['city_id'] ?? 0),
];

// Resolve selected country/state ids from saved names for dropdowns
if (!$form['country_id'] && $form['country'] !== '') {
    foreach ($countries as $c) {
        if (strcasecmp($c['name'], $form['country']) === 0) {
            $form['country_id'] = (int) $c['id'];
            break;
        }
    }
}
// Default to India when nothing selected yet
if (!$form['country_id']) {
    foreach ($countries as $c) {
        if (strcasecmp($c['name'], 'India') === 0) {
            $form['country_id'] = (int) $c['id'];
            $form['country'] = $c['name'];
            break;
        }
    }
}

$states = [];
$cities = [];
if ($form['country_id'] > 0) {
    try {
        $st = $pdo->prepare("SELECT id, name FROM states WHERE country_id = ? AND status = 'active' ORDER BY name");
        $st->execute([$form['country_id']]);
        $states = $st->fetchAll();
    } catch (Throwable $e) {
        $states = [];
    }
}
if (!$form['state_id'] && $form['state'] !== '') {
    foreach ($states as $s) {
        if (strcasecmp($s['name'], $form['state']) === 0) {
            $form['state_id'] = (int) $s['id'];
            break;
        }
    }
}
if ($form['state_id'] > 0) {
    try {
        $ct = $pdo->prepare("SELECT id, name FROM cities WHERE state_id = ? AND status = 'active' ORDER BY name");
        $ct->execute([$form['state_id']]);
        $cities = $ct->fetchAll();
    } catch (Throwable $e) {
        $cities = [];
    }
}
if (!$form['city_id'] && $form['city'] !== '') {
    foreach ($cities as $c) {
        if (strcasecmp($c['name'], $form['city']) === 0) {
            $form['city_id'] = (int) $c['id'];
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        flash('error', 'This document is locked while under review or already approved.');
        header('Location: kyc-aadhar.php');
        exit;
    }

    $aadharNumber = preg_replace('/\s+/', '', trim($_POST['aadhar_number'] ?? ''));
    $addressLine = trim($_POST['address_line'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $area = trim($_POST['area'] ?? '');
    $pincode = preg_replace('/\s+/', '', trim($_POST['pincode'] ?? ''));
    $countryId = (int) ($_POST['country_id'] ?? 0);
    $stateId = (int) ($_POST['state_id'] ?? 0);
    $cityId = (int) ($_POST['city_id'] ?? 0);
    if ($countryId > 0) {
        foreach ($countries as $c) {
            if ((int) $c['id'] === $countryId) {
                $country = $c['name'];
                break;
            }
        }
    }
    if ($stateId > 0) {
        try {
            $s = $pdo->prepare('SELECT name FROM states WHERE id = ? LIMIT 1');
            $s->execute([$stateId]);
            $state = (string) ($s->fetchColumn() ?: $state);
        } catch (Throwable $e) {
        }
    }
    if ($cityId > 0) {
        try {
            $c = $pdo->prepare('SELECT name FROM cities WHERE id = ? LIMIT 1');
            $c->execute([$cityId]);
            $city = (string) ($c->fetchColumn() ?: $city);
        } catch (Throwable $e) {
        }
    }

    // Use Area when Address box left empty or too short.
    if ($addressLine === '' && $area !== '') {
        $addressLine = $area;
    } elseif (mb_strlen($addressLine) < 5 && $area !== '') {
        $addressLine = trim($addressLine . ($addressLine !== '' ? ', ' : '') . $area);
    }

    if ($aadharNumber === '' || !preg_match('/^[0-9]{12}$/', $aadharNumber)) {
        $errors[] = 'Enter a valid 12-digit Aadhaar number.';
    }
    if ($addressLine === '' || mb_strlen($addressLine) < 5) {
        $errors[] = 'Enter house / flat / street in the Address field (at least 5 characters).';
    }
    if ($country === '') $errors[] = 'Please select country.';
    if ($state === '') $errors[] = 'Please select state.';
    if ($city === '') $errors[] = 'Please select city.';
    if ($pincode === '' || !preg_match('/^[0-9]{6}$/', $pincode)) {
        $errors[] = 'Enter a valid 6-digit pincode.';
    }

    $frontUp = kyc_store_document_optional($_FILES['document_front'] ?? [], $memberId, 'aadhar_front', $uploadDir);
    $backUp = kyc_store_document_optional($_FILES['document_back'] ?? [], $memberId, 'aadhar_back', $uploadDir);
    if (!$frontUp['ok']) $errors[] = 'Front: ' . $frontUp['error'];
    if (!$backUp['ok']) $errors[] = 'Back: ' . $backUp['error'];

    $frontPath = $frontUp['path'] ?: ($doc['document_file'] ?? null);
    $backPath = $backUp['path'] ?: ($doc['document_back'] ?? null);

    if (!$frontPath) $errors[] = 'Please upload Aadhaar front side image.';
    if (!$backPath) $errors[] = 'Please upload Aadhaar back side image.';

    if (!$errors) {
        $oldFront = $doc['document_file'] ?? null;
        $oldBack = $doc['document_back'] ?? null;

        $pdo->prepare('UPDATE member_kyc_documents SET
            aadhar_number = ?, address_line = ?, country = ?, state = ?, city = ?, area = ?, pincode = ?,
            document_file = ?, document_back = ?,
            status = \'pending\', admin_note = NULL, submitted_at = NOW(), reviewed_at = NULL
            WHERE member_id = ? AND doc_type = ?'
        )->execute([
            $aadharNumber, $addressLine, $country, $state, $city,
            $area !== '' ? $area : null, $pincode,
            $frontPath, $backPath,
            $memberId, $kycType,
        ]);

        if ($frontUp['path'] && $oldFront && $oldFront !== $frontUp['path']) {
            kyc_delete_file($oldFront);
        }
        if ($backUp['path'] && $oldBack && $oldBack !== $backUp['path']) {
            kyc_delete_file($oldBack);
        }

        kyc_sync_member_status($pdo, $memberId);
        flash('success', 'Aadhaar & address details submitted for admin approval.');
        header('Location: kyc-aadhar.php');
        exit;
    }

    $form = [
        'aadhar_number' => $aadharNumber,
        'address_line' => $addressLine,
        'country' => $country,
        'state' => $state,
        'city' => $city,
        'area' => $area,
        'pincode' => $pincode,
        'country_id' => $countryId,
        'state_id' => $stateId,
        'city_id' => $cityId,
    ];
}

$doc = kyc_get_doc($pdo, $memberId, $kycType);
$canEdit = kyc_can_edit($doc);
$allDocs = kyc_get_all($pdo, $memberId);
$status = strtolower((string) ($doc['status'] ?? 'not_submitted'));
$frontUrl = kyc_doc_url($doc['document_file'] ?? null);
$backUrl = kyc_doc_url($doc['document_back'] ?? null);

require_once __DIR__ . '/includes/header.php';

$cloudIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 16.2A4.5 4.5 0 0018.5 8h-.6A6 6 0 005 9.5 4 4 0 005.5 17H19"/><polyline points="12 12 12 21"/><polyline points="8 15 12 11 16 15"/></svg>';
?>
<div class="up-page-head">
    <div>
        <h1><?= e($meta['title']) ?></h1>
        <p>Upload Aadhaar front &amp; back and complete your residential address.</p>
    </div>
    <a href="profile.php" class="up-btn up-btn-outline">Back to Profile</a>
</div>

<div class="kyc kyc-aadhar-layout">
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
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M3 16c1.5-2 3.5-3 6-3s4.5 1 6 3"/></svg>
                    </span>
                    <div>
                        <span class="up-panel-kicker">Identity verification</span>
                        <h2>Aadhaar Update</h2>
                        <p>Front &amp; back images plus residential address are required.</p>
                    </div>
                </div>
                <span class="kyc-status-pill <?= kyc_status_badge_class($status) ?>"><?= e(kyc_status_label($status)) ?></span>
            </div>

            <div class="kyc-form-body">
                <?php foreach ($errors as $err): ?>
                    <div class="up-alert up-alert-err"><?= e($err) ?></div>
                <?php endforeach; ?>

                <?php if ($status === 'pending'): ?>
                    <div class="up-alert up-alert-info">Under admin review. Editing is locked until a decision is made.</div>
                <?php elseif ($status === 'approved'): ?>
                    <div class="up-alert up-alert-ok">This Aadhaar KYC is approved.</div>
                <?php elseif ($status === 'rejected'): ?>
                    <div class="up-alert up-alert-err">
                        Rejected<?= !empty($doc['admin_note']) ? ': ' . e($doc['admin_note']) : '.' ?> Please update and resubmit.
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="ad-form" id="aadharKycForm"<?= $canEdit ? '' : ' onsubmit="return false;"' ?>>
                    <div class="ad-top-grid">
                        <div class="up-field">
                            <label><span class="ad-lab-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span> User ID</label>
                            <input type="text" value="<?= e($user['member_id']) ?>" readonly disabled>
                        </div>
                        <div class="up-field">
                            <label><span class="ad-lab-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span> User Name</label>
                            <input type="text" value="<?= e($user['username']) ?>" readonly disabled>
                        </div>
                        <div class="up-field">
                            <label>Approval Status</label>
                            <div class="ad-status-wrap">
                                <span class="ad-status <?= kyc_status_badge_class($status) ?>"><?= e(strtoupper(str_replace('_', ' ', $status === 'not_submitted' ? 'not submitted' : $status))) ?></span>
                            </div>
                        </div>
                        <div class="up-field">
                            <label for="aadhar_number"><span class="ad-lab-ico" aria-hidden="true">#</span> Aadhaar Number</label>
                            <input type="text" id="aadhar_number" name="aadhar_number" maxlength="12"
                                   value="<?= e($form['aadhar_number']) ?>"
                                   placeholder="12-digit Aadhaar number" <?= $canEdit ? 'required' : 'disabled' ?>>
                        </div>
                    </div>

                    <div class="ad-upload-row">
                        <div class="ad-upload-col">
                            <div class="ad-side-label">Front Side</div>
                            <?php if ($canEdit): ?>
                            <label class="kyc-drop ad-drop<?= $frontUrl ? ' has-file' : '' ?>" for="aadharFrontInput" id="aadharFrontDrop" data-kyc-side="front">
                                <input type="file" id="aadharFrontInput" name="document_front" accept="image/jpeg,image/png,image/webp,application/pdf" <?= $frontUrl ? '' : 'required' ?>>
                            <?php else: ?>
                            <div class="kyc-drop ad-drop has-file is-readonly" id="aadharFrontDrop" data-kyc-side="front">
                            <?php endif; ?>
                                <div class="kyc-drop-preview ad-side-preview" id="aadharFrontPreview"<?= $frontUrl ? '' : ' hidden' ?>>
                                    <?php if ($frontUrl && !preg_match('/\.pdf$/i', (string) ($doc['document_file'] ?? ''))): ?>
                                        <img src="<?= e($frontUrl) ?>" alt="Front" id="aadharFrontImg">
                                    <?php else: ?>
                                        <img src="" alt="Front" id="aadharFrontImg" hidden>
                                    <?php endif; ?>
                                    <div class="kyc-drop-pdf<?= ($frontUrl && preg_match('/\.pdf$/i', (string) ($doc['document_file'] ?? ''))) ? '' : ' is-hidden' ?>" id="aadharFrontPdf">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        <span>PDF</span>
                                    </div>
                                </div>
                                <div class="kyc-drop-empty" id="aadharFrontEmpty"<?= $frontUrl ? ' hidden' : '' ?>>
                                    <span class="kyc-drop-ico"><?= $cloudIcon ?></span>
                                    <strong class="kyc-drop-title">Upload front side</strong>
                                    <span class="kyc-drop-browse">or <em>browse</em></span>
                                </div>
                            <?= $canEdit ? '</label>' : '</div>' ?>
                        </div>
                        <div class="ad-upload-col">
                            <div class="ad-side-label">Back Side</div>
                            <?php if ($canEdit): ?>
                            <label class="kyc-drop ad-drop<?= $backUrl ? ' has-file' : '' ?>" for="aadharBackInput" id="aadharBackDrop" data-kyc-side="back">
                                <input type="file" id="aadharBackInput" name="document_back" accept="image/jpeg,image/png,image/webp,application/pdf" <?= $backUrl ? '' : 'required' ?>>
                            <?php else: ?>
                            <div class="kyc-drop ad-drop has-file is-readonly" id="aadharBackDrop" data-kyc-side="back">
                            <?php endif; ?>
                                <div class="kyc-drop-preview ad-side-preview" id="aadharBackPreview"<?= $backUrl ? '' : ' hidden' ?>>
                                    <?php if ($backUrl && !preg_match('/\.pdf$/i', (string) ($doc['document_back'] ?? ''))): ?>
                                        <img src="<?= e($backUrl) ?>" alt="Back" id="aadharBackImg">
                                    <?php else: ?>
                                        <img src="" alt="Back" id="aadharBackImg" hidden>
                                    <?php endif; ?>
                                    <div class="kyc-drop-pdf<?= ($backUrl && preg_match('/\.pdf$/i', (string) ($doc['document_back'] ?? ''))) ? '' : ' is-hidden' ?>" id="aadharBackPdf">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        <span>PDF</span>
                                    </div>
                                </div>
                                <div class="kyc-drop-empty" id="aadharBackEmpty"<?= $backUrl ? ' hidden' : '' ?>>
                                    <span class="kyc-drop-ico"><?= $cloudIcon ?></span>
                                    <strong class="kyc-drop-title">Upload back side</strong>
                                    <span class="kyc-drop-browse">or <em>browse</em></span>
                                </div>
                            <?= $canEdit ? '</label>' : '</div>' ?>
                        </div>
                    </div>

                    <div class="ad-addr-section">
                        <div class="ad-addr-head">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            <span>Residential Address</span>
                        </div>

                        <div class="up-field full">
                            <label for="address_line"><span class="ad-lab-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span> House / Flat / Street</label>
                            <textarea id="address_line" name="address_line" rows="3" placeholder="e.g. 12, Green Park, MG Road" minlength="5" <?= $canEdit ? 'required' : 'disabled' ?>><?= e($form['address_line']) ?></textarea>
                            <small class="ad-field-hint">Required — enter your house, flat or street. Area/locality goes in the field below.</small>
                        </div>

                        <div class="ad-addr-grid">
                            <div class="up-field">
                                <label for="country_id"><span class="ad-lab-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 010 20M12 2a15.3 15.3 0 000 20"/></svg></span> Country</label>
                                <select id="country_id" name="country_id" data-geo="country" <?= $canEdit ? 'required' : 'disabled' ?>>
                                    <option value="">Select country</option>
                                    <?php foreach ($countries as $c): ?>
                                        <option value="<?= (int) $c['id'] ?>" <?= $form['country_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="country" id="country_name" value="<?= e($form['country']) ?>">
                            </div>
                            <div class="up-field">
                                <label for="state_id"><span class="ad-lab-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7h6l2 3h10v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg></span> State</label>
                                <select id="state_id" name="state_id" data-geo="state" <?= $canEdit ? 'required' : 'disabled' ?>>
                                    <option value="">Select state</option>
                                    <?php foreach ($states as $s): ?>
                                        <option value="<?= (int) $s['id'] ?>" <?= $form['state_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="state" id="state_name" value="<?= e($form['state']) ?>">
                            </div>
                            <div class="up-field">
                                <label for="city_id"><span class="ad-lab-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="10" width="16" height="10" rx="1"/><path d="M9 10V7a3 3 0 016 0v3"/></svg></span> City</label>
                                <select id="city_id" name="city_id" data-geo="city" <?= $canEdit ? 'required' : 'disabled' ?>>
                                    <option value="">Select city</option>
                                    <?php foreach ($cities as $c): ?>
                                        <option value="<?= (int) $c['id'] ?>" <?= ($form['city_id'] ?? 0) === (int) $c['id'] || (($form['city_id'] ?? 0) === 0 && strcasecmp($c['name'], $form['city']) === 0) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="city" id="city_name" value="<?= e($form['city']) ?>">
                            </div>
                            <div class="up-field">
                                <label for="area"><span class="ad-lab-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></span> Area / Other</label>
                                <input type="text" id="area" name="area" value="<?= e($form['area']) ?>" placeholder="Area / locality" <?= $canEdit ? '' : 'disabled' ?>>
                            </div>
                            <div class="up-field">
                                <label for="pincode"><span class="ad-lab-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v16H4z"/><path d="M4 10h16"/></svg></span> Pincode</label>
                                <input type="text" id="pincode" name="pincode" maxlength="6" value="<?= e($form['pincode']) ?>" placeholder="6-digit pincode" <?= $canEdit ? 'required' : 'disabled' ?>>
                            </div>
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

    <aside class="ad-preview-col">
        <div class="ad-preview-card">
            <div class="ad-preview-title">Front Side</div>
            <button type="button" class="ad-preview-box" data-enlarge data-side="front" <?= $frontUrl ? 'data-src="' . e($frontUrl) . '"' : '' ?>>
                <?php if ($frontUrl && !preg_match('/\.pdf$/i', (string) ($doc['document_file'] ?? ''))): ?>
                    <img src="<?= e($frontUrl) ?>" alt="Front preview" id="aadharFrontThumb">
                <?php else: ?>
                    <div class="ad-preview-empty" id="aadharFrontThumbEmpty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                        <span>No image</span>
                    </div>
                    <img src="" alt="Front preview" id="aadharFrontThumb" hidden>
                <?php endif; ?>
            </button>
            <small>Click to enlarge</small>
        </div>
        <div class="ad-preview-card">
            <div class="ad-preview-title">Back Side</div>
            <button type="button" class="ad-preview-box" data-enlarge data-side="back" <?= $backUrl ? 'data-src="' . e($backUrl) . '"' : '' ?>>
                <?php if ($backUrl && !preg_match('/\.pdf$/i', (string) ($doc['document_back'] ?? ''))): ?>
                    <img src="<?= e($backUrl) ?>" alt="Back preview" id="aadharBackThumb">
                <?php else: ?>
                    <div class="ad-preview-empty" id="aadharBackThumbEmpty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                        <span>No image</span>
                    </div>
                    <img src="" alt="Back preview" id="aadharBackThumb" hidden>
                <?php endif; ?>
            </button>
            <small>Click to enlarge</small>
        </div>
    </aside>
</div>

<div class="ad-lightbox" id="aadharLightbox" hidden>
    <button type="button" class="ad-lightbox-close" id="aadharLightboxClose" aria-label="Close">&times;</button>
    <img src="" alt="Enlarged document" id="aadharLightboxImg">
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
