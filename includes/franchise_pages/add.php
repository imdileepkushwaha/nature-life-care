<?php
require_once __DIR__ . '/_boot.php';
$pageTitle = 'Franchisee Add';

// AJAX sponsor lookup
if (isset($_GET['ajax']) && $_GET['ajax'] === 'sponsor') {
    header('Content-Type: application/json; charset=utf-8');
    $code = strtoupper(trim((string) ($_GET['code'] ?? '')));
    $row = franchise_find_by_code($pdo, $code);
    if (!$row || ($row['status'] ?? '') !== 'active') {
        echo json_encode(['ok' => false, 'error' => 'Sponsor not found or inactive.']);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'id' => (int) $row['id'],
        'code' => $row['franchisee_code'],
        'name' => $row['name'],
        'type' => $row['type_name'] ?? '—',
    ]);
    exit;
}

$types = franchise_types($pdo, true);
$errors = [];
$editId = (int) ($_GET['edit'] ?? 0);
$edit = $editId > 0 ? franchise_get($pdo, $editId) : null;
$isEdit = !empty($edit['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $typeId = (int) ($_POST['type_id'] ?? 0);
    $sponsorCode = strtoupper(trim((string) ($_POST['sponsor_code'] ?? '')));
    $code = strtoupper(trim($_POST['franchisee_code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact_person'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $dob = trim($_POST['dob'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $aadhaarNo = trim($_POST['aadhaar_no'] ?? '');
    $panNo = strtoupper(trim($_POST['pan_no'] ?? ''));
    $gstNo = strtoupper(trim($_POST['gst_no'] ?? ''));
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $pincode = trim($_POST['pincode'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password_confirm'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    $sponsorId = null;
    if ($sponsorCode !== '') {
        $sp = franchise_find_by_code($pdo, $sponsorCode);
        if (!$sp || ($sp['status'] ?? '') !== 'active') {
            $errors[] = 'Invalid sponsor ID.';
        } elseif ($id > 0 && (int) $sp['id'] === $id) {
            $errors[] = 'Sponsor cannot be the same franchisee.';
        } else {
            $sponsorId = (int) $sp['id'];
        }
    }

    if ($typeId < 1) {
        $errors[] = 'Select a franchisee type.';
    }
    if ($name === '') {
        $errors[] = 'Full name is required.';
    }
    if ($phone === '') {
        $errors[] = 'Mobile number is required.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }
    if ($address === '') {
        $errors[] = 'Address is required.';
    }
    if ($city === '') {
        $errors[] = 'City is required.';
    }
    if ($state === '') {
        $errors[] = 'State is required.';
    }
    if ($username === '') {
        $errors[] = 'Username is required.';
    }
    if ($id < 1 && $password === '') {
        $errors[] = 'Password is required.';
    }
    if ($password !== '' && strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== '' && $password !== $password2) {
        $errors[] = 'Password and confirm password do not match.';
    }
    if ($code === '') {
        $code = franchise_next_code($pdo);
    }

    // Existing files when editing
    $existing = $id > 0 ? franchise_get($pdo, $id) : null;
    $aadhaarFile = $existing['aadhaar_file'] ?? null;
    $panFile = $existing['pan_file'] ?? null;
    $photoFile = $existing['photo_file'] ?? null;
    $gstFile = $existing['gst_file'] ?? null;

    $upA = franchise_store_doc($_FILES['aadhaar_file'] ?? [], 'aadhaar', $id);
    $upP = franchise_store_doc($_FILES['pan_file'] ?? [], 'pan', $id);
    $upPh = franchise_store_doc($_FILES['photo_file'] ?? [], 'photo', $id);
    $upG = franchise_store_doc($_FILES['gst_file'] ?? [], 'gst', $id);
    if (!$upA['ok']) {
        $errors[] = $upA['error'] ?? 'Aadhaar upload failed.';
    } elseif (!empty($upA['path'])) {
        $aadhaarFile = $upA['path'];
    }
    if (!$upP['ok']) {
        $errors[] = $upP['error'] ?? 'PAN upload failed.';
    } elseif (!empty($upP['path'])) {
        $panFile = $upP['path'];
    }
    if (!$upPh['ok']) {
        $errors[] = $upPh['error'] ?? 'Photo upload failed.';
    } elseif (!empty($upPh['path'])) {
        $photoFile = $upPh['path'];
    }
    if (!$upG['ok']) {
        $errors[] = $upG['error'] ?? 'GST document upload failed.';
    } elseif (!empty($upG['path'])) {
        $gstFile = $upG['path'];
    }

    if (!$errors) {
        try {
            $dobVal = ($dob !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) ? $dob : null;
            if ($id > 0) {
                $sql = 'UPDATE franchisees SET type_id=?, sponsor_id=?, franchisee_code=?, name=?, contact_person=?, gender=?, dob=?, phone=?, email=?, aadhaar_no=?, pan_no=?, gst_no=?, aadhaar_file=?, pan_file=?, photo_file=?, gst_file=?, address=?, city=?, state=?, pincode=?, username=?, status=?';
                $params = [
                    $typeId, $sponsorId, $code, $name,
                    $contact !== '' ? $contact : null,
                    $gender !== '' ? $gender : null,
                    $dobVal,
                    $phone !== '' ? $phone : null,
                    $email !== '' ? $email : null,
                    $aadhaarNo !== '' ? $aadhaarNo : null,
                    $panNo !== '' ? $panNo : null,
                    $gstNo !== '' ? $gstNo : null,
                    $aadhaarFile, $panFile, $photoFile, $gstFile,
                    $address !== '' ? $address : null,
                    $city !== '' ? $city : null,
                    $state !== '' ? $state : null,
                    $pincode !== '' ? $pincode : null,
                    $username !== '' ? $username : null,
                    $status,
                ];
                if ($password !== '') {
                    $sql .= ', password=?';
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                }
                $sql .= ' WHERE id=?';
                $params[] = $id;
                $pdo->prepare($sql)->execute($params);
                log_activity('franchise_edit', "Updated franchisee #$id");
                flash('success', 'Franchisee updated.');
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('
                    INSERT INTO franchisees
                        (type_id, sponsor_id, franchisee_code, name, contact_person, gender, dob, phone, email,
                         aadhaar_no, pan_no, gst_no, aadhaar_file, pan_file, photo_file, gst_file,
                         address, city, state, pincode, username, password, status, created_by_role, created_by_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ')->execute([
                    $typeId, $sponsorId, $code, $name,
                    $contact !== '' ? $contact : null,
                    $gender !== '' ? $gender : null,
                    $dobVal,
                    $phone !== '' ? $phone : null,
                    $email !== '' ? $email : null,
                    $aadhaarNo !== '' ? $aadhaarNo : null,
                    $panNo !== '' ? $panNo : null,
                    $gstNo !== '' ? $gstNo : null,
                    $aadhaarFile, $panFile, $photoFile, $gstFile,
                    $address !== '' ? $address : null,
                    $city !== '' ? $city : null,
                    $state !== '' ? $state : null,
                    $pincode !== '' ? $pincode : null,
                    $username, $hash, $status,
                    $franchise_role,
                    $franchise_actor_id ?: null,
                ]);
                log_activity('franchise_add', "Added franchisee $code");
                flash('success', 'Franchisee registered successfully.');
            }
            header('Location: franchisee-report.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Could not save. Code or username may already exist.';
        }
    }

    $edit = [
        'id' => $id,
        'type_id' => $typeId,
        'sponsor_id' => $sponsorId,
        'sponsor_code' => $sponsorCode,
        'sponsor_name' => $_POST['sponsor_name'] ?? '',
        'sponsor_type_name' => $_POST['sponsor_type'] ?? '',
        'franchisee_code' => $code,
        'name' => $name,
        'contact_person' => $contact,
        'gender' => $gender,
        'dob' => $dob,
        'phone' => $phone,
        'email' => $email,
        'aadhaar_no' => $aadhaarNo,
        'pan_no' => $panNo,
        'gst_no' => $gstNo,
        'aadhaar_file' => $aadhaarFile,
        'pan_file' => $panFile,
        'photo_file' => $photoFile,
        'gst_file' => $gstFile,
        'address' => $address,
        'city' => $city,
        'state' => $state,
        'pincode' => $pincode,
        'username' => $username,
        'status' => $status,
    ];
    $isEdit = $id > 0;
}

if (!$edit) {
    $edit = [
        'franchisee_code' => franchise_next_code($pdo),
        'status' => 'active',
        'gender' => '',
    ];
}

$sponsorCodeVal = (string) ($edit['sponsor_code'] ?? '');
$sponsorNameVal = (string) ($edit['sponsor_name'] ?? '');
$sponsorTypeVal = (string) ($edit['sponsor_type_name'] ?? '');
franchise_header();
?>

<style>
/* Franchisee Add Wizard Custom Premium Styles */
.fr-wiz-panel {
    background: #ffffff;
    border-radius: 18px;
    border: 1px solid #e8ecf4;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
    overflow: hidden;
}
.fr-header-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    padding: 1.25rem 1.75rem;
    border-bottom: 1px solid #edf2f7;
    background: linear-gradient(180deg, #ffffff 0%, #fbfcfe 100%);
}
.fr-title-group {
    display: flex;
    align-items: center;
    gap: 0.9rem;
}
.fr-title-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, rgba(225, 29, 72, 0.12) 0%, rgba(225, 29, 72, 0.04) 100%);
    color: var(--brand);
    display: grid;
    place-items: center;
    border: 1px solid rgba(225, 29, 72, 0.15);
}
.fr-title-icon svg { width: 22px; height: 22px; }
.fr-title-group h2 {
    margin: 0;
    font-size: 1.35rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.02em;
}
.fr-header-sub {
    margin: 0.2rem 0 0;
    font-size: 0.84rem;
    color: #64748b;
}

/* Wizard Body */
.fr-wiz {
    padding: 1.75rem;
}
.fr-wiz-note {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.85rem 1.15rem;
    border-radius: 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #475569;
    font-size: 0.88rem;
    margin-bottom: 1.75rem;
}
.fr-wiz-note svg { width: 18px; height: 18px; color: var(--brand); flex-shrink: 0; }

/* Step Indicator Navigation */
.fr-steps-wrapper {
    position: relative;
    margin-bottom: 2rem;
}
.fr-steps {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 0.75rem;
    position: relative;
    z-index: 2;
}
.fr-step {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 0.95rem 0.6rem;
    border-radius: 14px;
    border: 1.5px solid #e2e8f0;
    background: #ffffff;
    cursor: pointer;
    font: inherit;
    color: #64748b;
    transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.fr-step:hover {
    border-color: #cbd5e1;
    color: #1e293b;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(15, 23, 42, 0.05);
}
.fr-step-num {
    width: 32px;
    height: 32px;
    border-radius: 999px;
    display: grid;
    place-items: center;
    font-size: 0.85rem;
    font-weight: 800;
    background: #f1f5f9;
    color: #64748b;
    transition: all 0.22s ease;
}
.fr-step-label {
    font-size: 0.8rem;
    font-weight: 700;
    text-align: center;
    line-height: 1.25;
}
.fr-step.is-active {
    border-color: var(--brand);
    color: var(--brand);
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(225, 29, 72, 0.12), 0 8px 20px rgba(225, 29, 72, 0.08);
}
.fr-step.is-active .fr-step-num {
    background: var(--brand);
    color: #ffffff;
    box-shadow: 0 3px 10px rgba(225, 29, 72, 0.35);
}
.fr-step.is-done {
    border-color: #86efac;
    color: #15803d;
    background: #f0fdf4;
}
.fr-step.is-done .fr-step-num {
    background: #16a34a;
    color: #ffffff;
}

/* Panes */
.fr-pane {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #edf2f7;
    padding: 1.75rem;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.02);
    animation: frFadeIn 0.22s ease-out;
}
@keyframes frFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}
.fr-pane-title {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin: 0 0 1.5rem;
    padding-bottom: 0.9rem;
    border-bottom: 1px solid #f1f5f9;
    font-size: 1.05rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.01em;
}
.fr-pane-ico {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: grid;
    place-items: center;
    background: var(--accent-soft);
    color: var(--brand);
}
.fr-pane-ico svg { width: 20px; height: 20px; }

/* Input With Icon */
.fr-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1.25rem 1.5rem;
}
.fr-grid .form-group {
    margin-bottom: 0;
}
.form-group label {
    display: block;
    margin-bottom: 0.45rem;
    font-size: 0.85rem;
    font-weight: 700;
    color: #334155;
}
.fr-input-ico {
    position: relative;
    width: 100%;
}
.fr-input-ico > span {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    color: #94a3b8;
    pointer-events: none;
    display: grid;
    place-items: center;
}
.fr-input-ico > span svg { width: 18px; height: 18px; }
.fr-input-ico input,
.fr-input-ico select {
    width: 100%;
    min-height: 46px;
    padding: 0.65rem 1rem 0.65rem 2.85rem !important;
    border: 1.5px solid #e2e8f0;
    border-radius: 11px;
    font-size: 0.92rem;
    color: #0f172a;
    background: #ffffff;
    transition: all 0.18s ease;
}
.fr-input-ico input:focus,
.fr-input-ico select:focus {
    border-color: var(--brand);
    outline: none;
    box-shadow: 0 0 0 3px rgba(225, 29, 72, 0.12);
}
.fr-input-ico input[readonly] {
    background: #f8fafc;
    color: #475569;
    border-color: #e2e8f0;
    cursor: default;
}
.form-group input[type="text"],
.form-group input[type="email"],
.form-group input[type="password"],
.form-group input[type="date"],
.form-group select,
.form-group textarea {
    width: 100%;
    min-height: 46px;
    padding: 0.65rem 1rem;
    border: 1.5px solid #e2e8f0;
    border-radius: 11px;
    font-size: 0.92rem;
    color: #0f172a;
    background: #ffffff;
    transition: all 0.18s ease;
}
.form-group textarea {
    min-height: 85px;
    line-height: 1.5;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: var(--brand);
    outline: none;
    box-shadow: 0 0 0 3px rgba(225, 29, 72, 0.12);
}
.field-hint {
    display: block;
    margin-top: 0.35rem;
    font-size: 0.78rem;
    color: #64748b;
}

/* Sponsor Lookup Pill */
.fr-sponsor-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.25rem 0.6rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    margin-top: 0.4rem;
}

/* Step 3 - Documents */
.fr-doc-lead {
    margin: -0.35rem 0 1.25rem;
    color: #64748b;
    font-size: 0.88rem;
    line-height: 1.5;
}
.fr-doc-ids {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1.15rem;
    margin-bottom: 1.5rem;
    padding: 1.15rem 1.25rem;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
}
.fr-doc-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1.15rem;
}
.fr-doc-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    padding: 1.1rem;
    border-radius: 16px;
    border: 2px dashed #cbd5e1;
    background: #ffffff;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.fr-doc-card:hover,
.fr-doc-card.is-drag {
    border-color: var(--brand);
    background: #fff8f9;
    box-shadow: 0 10px 24px rgba(225, 29, 72, 0.08);
    transform: translateY(-2px);
}
.fr-doc-card.has-file {
    border-style: solid;
    border-color: #86efac;
    background: #f0fdf4;
}
.fr-doc-input {
    position: absolute;
    width: 1px;
    height: 1px;
    opacity: 0;
    pointer-events: none;
}
.fr-doc-preview {
    position: relative;
    width: 100%;
    aspect-ratio: 4 / 3;
    border-radius: 12px;
    overflow: hidden;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}
.fr-doc-empty,
.fr-doc-img,
.fr-doc-pdf {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}
.fr-doc-empty {
    display: grid;
    place-items: center;
    color: var(--brand);
    background: radial-gradient(60% 60% at 50% 40%, rgba(225, 29, 72, 0.08), transparent 70%), #fff1f3;
}
.fr-doc-empty svg { width: 36px; height: 36px; }
.fr-doc-img {
    object-fit: cover;
    display: block;
    background: #0f172a08;
}
.fr-doc-pdf {
    display: grid;
    place-content: center;
    justify-items: center;
    gap: 0.35rem;
    color: #be123c;
    background: linear-gradient(160deg, #ffe4e6, #ffffff);
    font-weight: 800;
    font-size: 0.8rem;
    letter-spacing: 0.08em;
}
.fr-doc-pdf svg { width: 34px; height: 34px; }
.fr-doc-empty[hidden],
.fr-doc-img[hidden],
.fr-doc-pdf[hidden] { display: none !important; }
.fr-doc-meta {
    display: grid;
    gap: 0.25rem;
}
.fr-doc-title {
    font-size: 0.92rem;
    font-weight: 800;
    color: #0f172a;
}
.fr-doc-name {
    font-size: 0.78rem;
    color: #64748b;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.fr-doc-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    margin-top: 0.35rem;
}

/* Password Toggle Icon */
.fr-pw-wrap {
    position: relative;
    width: 100%;
}
.fr-pw-toggle {
    position: absolute;
    right: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: none;
    cursor: pointer;
    color: #94a3b8;
    padding: 0.35rem;
    display: grid;
    place-items: center;
    border-radius: 6px;
}
.fr-pw-toggle:hover {
    color: #334155;
    background: #f1f5f9;
}
.fr-pw-toggle svg { width: 18px; height: 18px; }

/* Wizard Footer Navigation */
.fr-wiz-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid #eef2f6;
}
.fr-wiz-nav {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.fr-wiz-nav .btn {
    min-height: 44px;
    padding: 0.65rem 1.5rem;
    border-radius: 11px;
    font-weight: 700;
    font-size: 0.92rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}
.fr-wiz-nav .btn-primary {
    background: linear-gradient(135deg, #e11d48 0%, #be123c 100%);
    box-shadow: 0 4px 14px rgba(225, 29, 72, 0.28);
}
.fr-wiz-nav .btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(225, 29, 72, 0.38);
}

/* Notification Banner */
.fr-toast {
    position: fixed;
    bottom: 2rem;
    right: 2rem;
    padding: 0.9rem 1.4rem;
    background: #0f172a;
    color: #ffffff;
    border-radius: 12px;
    font-size: 0.88rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.65rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25);
    z-index: 99999;
    animation: frSlideUp 0.25s ease-out;
}
.fr-toast.is-err {
    background: #e11d48;
}
@keyframes frSlideUp {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 1024px) {
    .fr-doc-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .fr-steps { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 768px) {
    .fr-grid { grid-template-columns: 1fr; }
    .fr-doc-ids { grid-template-columns: 1fr; }
    .fr-doc-grid { grid-template-columns: 1fr; }
    .fr-steps { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .fr-wiz-actions { flex-direction: column-reverse; align-items: stretch; }
    .fr-wiz-nav { width: 100%; }
    .fr-wiz-nav .btn { flex: 1; justify-content: center; }
}
</style>

<div class="panel fr-wiz-panel">
    <div class="panel-header fr-header-bar">
        <div class="fr-title-group">
            <div class="fr-title-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6m3-3h-6"/></svg>
            </div>
            <div>
                <h2><?= $isEdit ? 'Edit Franchisee Profile' : 'Register New Franchisee' ?></h2>
                <p class="fr-header-sub"><?= $isEdit ? 'Modify franchise details, KYC documents, and account status' : 'Step-by-step onboarding wizard for multi-tier franchise partners' ?></p>
            </div>
        </div>
        <a href="franchisee-report.php" class="btn btn-outline btn-sm">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;margin-right:4px"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to List
        </a>
    </div>

    <div class="panel-body fr-wiz">
        <?php if (!$types): ?>
            <div class="alert alert-info">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;display:inline-block;vertical-align:middle;margin-right:6px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                No franchisee types configured yet. Please configure a <a href="franchisee-types.php"><strong>Franchisee Type</strong></a> first.
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;display:inline-block;vertical-align:middle;margin-right:6px"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <?= e(implode(' ', $errors)) ?>
            </div>
        <?php endif; ?>

        <div class="fr-wiz-note">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            Complete all 5 steps to <?= $isEdit ? 'update the franchisee record' : 'register a new franchise partner' ?>. All mandatory fields (*) are validated before proceeding.
        </div>

        <!-- Step Navigation Bar -->
        <div class="fr-steps-wrapper">
            <nav class="fr-steps" id="frSteps" aria-label="Registration steps">
                <button type="button" class="fr-step is-active" data-step="1">
                    <span class="fr-step-num">1</span>
                    <span class="fr-step-label">Sponsor &amp; Type</span>
                </button>
                <button type="button" class="fr-step" data-step="2">
                    <span class="fr-step-num">2</span>
                    <span class="fr-step-label">Personal Details</span>
                </button>
                <button type="button" class="fr-step" data-step="3">
                    <span class="fr-step-num">3</span>
                    <span class="fr-step-label">KYC Documents</span>
                </button>
                <button type="button" class="fr-step" data-step="4">
                    <span class="fr-step-num">4</span>
                    <span class="fr-step-label">Address</span>
                </button>
                <button type="button" class="fr-step" data-step="5">
                    <span class="fr-step-num">5</span>
                    <span class="fr-step-label">Account &amp; Security</span>
                </button>
            </nav>
        </div>

        <form method="post" enctype="multipart/form-data" id="frWizForm" autocomplete="off"<?= !$types ? ' class="is-disabled"' : '' ?>>
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <input type="hidden" name="franchisee_code" value="<?= e($edit['franchisee_code'] ?? '') ?>">
            <input type="hidden" name="sponsor_name" id="sponsorNameHidden" value="<?= e($sponsorNameVal) ?>">
            <input type="hidden" name="sponsor_type" id="sponsorTypeHidden" value="<?= e($sponsorTypeVal) ?>">

            <!-- Step 1: Sponsor & Franchisee Type -->
            <section class="fr-pane is-active" data-pane="1">
                <h3 class="fr-pane-title">
                    <span class="fr-pane-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                    Step 1: Sponsor &amp; Franchisee Tier
                </h3>
                <div class="fr-grid">
                    <div class="form-group">
                        <label for="frType">Franchisee Type / Level *</label>
                        <div class="fr-input-ico">
                            <span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v4M12 11L5 17M12 11l7 6"/></svg></span>
                            <select name="type_id" id="frType" required>
                                <option value="">— Select franchise type —</option>
                                <?php foreach ($types as $t): ?>
                                <option value="<?= (int) $t['id'] ?>" <?= ((int) ($edit['type_id'] ?? 0) === (int) $t['id']) ? 'selected' : '' ?>>
                                    <?= e($t['name']) ?> (Commission: <?= number_format((float) ($t['commission_percent'] ?? 0), 2) ?>%)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <small class="field-hint">Defines margin %, hierarchy tier, and sub-franchise privileges</small>
                    </div>

                    <div class="form-group">
                        <label for="frSponsorCode">Sponsor Franchise ID (Upline)</label>
                        <div class="fr-input-ico">
                            <span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg></span>
                            <input type="text" name="sponsor_code" id="frSponsorCode" value="<?= e($sponsorCodeVal) ?>" placeholder="e.g. FR0001" autocomplete="off" style="text-transform:uppercase">
                        </div>
                        <small class="field-hint" id="frSponsorHint">Optional — leave blank for Direct Company / Root partner</small>
                    </div>

                    <div class="form-group">
                        <label for="frSponsorName">Sponsor Franchisee Name</label>
                        <div class="fr-input-ico">
                            <span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                            <input type="text" id="frSponsorName" value="<?= e($sponsorNameVal) ?>" readonly placeholder="Auto-verified on code input">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="frSponsorType">Sponsor Franchise Tier</label>
                        <div class="fr-input-ico">
                            <span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></span>
                            <input type="text" id="frSponsorType" value="<?= e($sponsorTypeVal) ?>" readonly placeholder="Auto-filled tier">
                        </div>
                    </div>
                </div>
            </section>

            <!-- Step 2: Personal Details -->
            <section class="fr-pane" data-pane="2" hidden>
                <h3 class="fr-pane-title">
                    <span class="fr-pane-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                    Step 2: Partner / Business Personal Details
                </h3>
                <div class="fr-grid">
                    <div class="form-group">
                        <label for="frName">Franchise / Firm / Full Name *</label>
                        <input type="text" name="name" id="frName" value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. Bharat Seva Mart Patna" required>
                    </div>

                    <div class="form-group">
                        <label for="frContact">Contact Person</label>
                        <input type="text" name="contact_person" id="frContact" value="<?= e($edit['contact_person'] ?? '') ?>" placeholder="e.g. Ramesh Kumar">
                    </div>

                    <div class="form-group">
                        <label for="frGender">Gender</label>
                        <select name="gender" id="frGender">
                            <option value="">— Select Gender —</option>
                            <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
                            <option value="<?= $g ?>" <?= (($edit['gender'] ?? '') === $g) ? 'selected' : '' ?>><?= $g ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="frDob">Date of Birth</label>
                        <input type="date" name="dob" id="frDob" value="<?= e($edit['dob'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="frPhone">Mobile Number *</label>
                        <input type="tel" name="phone" id="frPhone" value="<?= e($edit['phone'] ?? '') ?>" placeholder="10-digit mobile number" maxlength="15" required>
                    </div>

                    <div class="form-group">
                        <label for="frEmail">Email Address</label>
                        <input type="email" name="email" id="frEmail" value="<?= e($edit['email'] ?? '') ?>" placeholder="partner@example.com">
                    </div>
                </div>
            </section>

            <!-- Step 3: KYC Documents -->
            <section class="fr-pane" data-pane="3" hidden>
                <h3 class="fr-pane-title">
                    <span class="fr-pane-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                    Step 3: KYC Numbers &amp; Document Uploads
                </h3>
                <p class="fr-doc-lead">Enter government identification numbers and upload clear scans/photos (JPG, PNG, WebP or PDF — max 2MB each).</p>

                <div class="fr-doc-ids">
                    <div class="form-group">
                        <label for="frAadhaarNo">Aadhaar Number</label>
                        <div class="fr-input-ico">
                            <span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></span>
                            <input type="text" name="aadhaar_no" id="frAadhaarNo" value="<?= e($edit['aadhaar_no'] ?? '') ?>" maxlength="14" placeholder="12-digit Aadhaar" inputmode="numeric">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="frPanNo">PAN Card Number</label>
                        <div class="fr-input-ico">
                            <span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 13h6"/></svg></span>
                            <input type="text" name="pan_no" id="frPanNo" value="<?= e($edit['pan_no'] ?? '') ?>" maxlength="10" placeholder="ABCDE1234F" style="text-transform:uppercase">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="frGstNo">GSTIN / Registration Number</label>
                        <div class="fr-input-ico">
                            <span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></span>
                            <input type="text" name="gst_no" id="frGstNo" value="<?= e($edit['gst_no'] ?? '') ?>" maxlength="20" placeholder="22AAAAA0000A1Z5" style="text-transform:uppercase">
                        </div>
                    </div>
                </div>

                <div class="fr-doc-grid">
                    <?php
                    $docCards = [
                        [
                            'key' => 'aadhaar',
                            'name' => 'aadhaar_file',
                            'title' => 'Aadhaar Card',
                            'hint' => 'Front / back copy or PDF',
                            'accept' => '.jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf',
                            'file' => $edit['aadhaar_file'] ?? null,
                            'photo' => false,
                        ],
                        [
                            'key' => 'pan',
                            'name' => 'pan_file',
                            'title' => 'PAN Card',
                            'hint' => 'Clear card image or PDF',
                            'accept' => '.jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf',
                            'file' => $edit['pan_file'] ?? null,
                            'photo' => false,
                        ],
                        [
                            'key' => 'photo',
                            'name' => 'photo_file',
                            'title' => 'Partner Photo',
                            'hint' => 'Passport size photo',
                            'accept' => '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp',
                            'file' => $edit['photo_file'] ?? null,
                            'photo' => true,
                        ],
                        [
                            'key' => 'gst',
                            'name' => 'gst_file',
                            'title' => 'GST Certificate',
                            'hint' => 'GST Registration copy',
                            'accept' => '.jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf',
                            'file' => $edit['gst_file'] ?? null,
                            'photo' => false,
                        ],
                    ];
                    foreach ($docCards as $card):
                        $url = !empty($card['file']) ? franchise_doc_url((string) $card['file']) : null;
                        $isPdf = $url && preg_match('/\.pdf$/i', (string) $card['file']);
                        $has = (bool) $url;
                    ?>
                    <div class="fr-doc-card<?= $has ? ' has-file' : '' ?>" data-fr-doc="<?= e($card['key']) ?>">
                        <input type="file" name="<?= e($card['name']) ?>" id="frDoc_<?= e($card['key']) ?>" class="fr-doc-input" accept="<?= e($card['accept']) ?>">
                        <div class="fr-doc-preview">
                            <div class="fr-doc-empty"<?= $has ? ' hidden' : '' ?>>
                                <?php if ($card['photo']): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                <?php endif; ?>
                            </div>
                            <img class="fr-doc-img" alt="" <?= ($has && !$isPdf) ? 'src="' . e($url) . '"' : 'hidden' ?>>
                            <div class="fr-doc-pdf"<?= ($has && $isPdf) ? '' : ' hidden' ?>>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                <span>PDF DOCUMENT</span>
                            </div>
                        </div>
                        <div class="fr-doc-meta">
                            <strong class="fr-doc-title"><?= e($card['title']) ?></strong>
                            <span class="fr-doc-name"><?= $has ? e(basename((string) $card['file'])) : e($card['hint']) ?></span>
                            <div class="fr-doc-actions">
                                <button type="button" class="btn btn-primary btn-sm fr-doc-browse"><?= $has ? 'Replace' : 'Upload' ?></button>
                                <?php if ($has): ?>
                                <a class="btn btn-outline btn-sm" href="<?= e($url) ?>" target="_blank" rel="noopener">View</a>
                                <?php endif; ?>
                                <button type="button" class="btn btn-outline btn-sm fr-doc-clear" hidden>Clear</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Step 4: Address Details -->
            <section class="fr-pane" data-pane="4" hidden>
                <h3 class="fr-pane-title">
                    <span class="fr-pane-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
                    Step 4: Franchise Location &amp; Physical Address
                </h3>
                <div class="fr-grid">
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="frAddress">Shop / Office Street Address *</label>
                        <textarea name="address" id="frAddress" rows="3" placeholder="Plot / Shop No., Building, Street, Landmark" required><?= e($edit['address'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="frCity">City / Town *</label>
                        <input type="text" name="city" id="frCity" value="<?= e($edit['city'] ?? '') ?>" placeholder="e.g. Patna" required>
                    </div>

                    <div class="form-group">
                        <label for="frState">State *</label>
                        <input type="text" name="state" id="frState" value="<?= e($edit['state'] ?? '') ?>" placeholder="e.g. Bihar" required>
                    </div>

                    <div class="form-group">
                        <label for="frPincode">Postal PIN Code</label>
                        <input type="text" name="pincode" id="frPincode" value="<?= e($edit['pincode'] ?? '') ?>" placeholder="e.g. 800001" maxlength="10">
                    </div>
                </div>
            </section>

            <!-- Step 5: Account & Security -->
            <section class="fr-pane" data-pane="5" hidden>
                <h3 class="fr-pane-title">
                    <span class="fr-pane-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg></span>
                    Step 5: Portal Login Credentials &amp; Status
                </h3>
                <div class="fr-grid">
                    <div class="form-group">
                        <label for="frCodeDisplay">Franchisee Code (System ID)</label>
                        <div class="fr-input-ico">
                            <span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg></span>
                            <input type="text" id="frCodeDisplay" value="<?= e($edit['franchisee_code'] ?? '') ?>" readonly style="font-weight:700; letter-spacing:0.04em;">
                        </div>
                        <small class="field-hint">Auto-generated partner identity code</small>
                    </div>

                    <div class="form-group">
                        <label for="frUsername">Portal Username *</label>
                        <div class="fr-input-ico">
                            <span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                            <input type="text" name="username" id="frUsername" value="<?= e($edit['username'] ?? '') ?>" placeholder="e.g. patnamart" required autocomplete="off">
                        </div>
                        <small class="field-hint">Unique username for franchise login terminal</small>
                    </div>

                    <div class="form-group">
                        <label for="frPassword">Password <?= $isEdit ? '(Leave empty to keep current)' : '*' ?></label>
                        <div class="fr-pw-wrap">
                            <input type="password" name="password" id="frPassword" <?= $isEdit ? '' : 'required' ?> autocomplete="new-password" placeholder="<?= $isEdit ? '••••••••' : 'Min 6 characters' ?>">
                            <button type="button" class="fr-pw-toggle" data-target="frPassword" title="Show/Hide Password" aria-label="Toggle password visibility">
                                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="frPassword2">Confirm Password <?= $isEdit ? '' : '*' ?></label>
                        <div class="fr-pw-wrap">
                            <input type="password" name="password_confirm" id="frPassword2" <?= $isEdit ? '' : 'required' ?> autocomplete="new-password" placeholder="Re-enter password">
                            <button type="button" class="fr-pw-toggle" data-target="frPassword2" title="Show/Hide Password" aria-label="Toggle password visibility">
                                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="frStatus">Account Status</label>
                        <select name="status" id="frStatus" style="max-width:320px">
                            <option value="active" <?= (($edit['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active (Can login &amp; bill orders)</option>
                            <option value="inactive" <?= (($edit['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive (Portal access disabled)</option>
                        </select>
                    </div>
                </div>
            </section>

            <!-- Bottom Actions Bar -->
            <div class="fr-wiz-actions">
                <a href="franchisee-report.php" class="btn btn-outline">Cancel</a>
                <div class="fr-wiz-nav">
                    <button type="button" class="btn btn-outline" id="frPrev" hidden>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                        Previous
                    </button>
                    <button type="button" class="btn btn-primary" id="frNext" <?= !$types ? 'disabled' : '' ?>>
                        Continue Next
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </button>
                    <button type="submit" class="btn btn-primary" id="frSubmit" hidden <?= !$types ? 'disabled' : '' ?>>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg>
                        <?= $isEdit ? 'Update Franchisee' : 'Register Franchisee' ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var step = 1;
    var max = 5;
    var steps = document.querySelectorAll('#frSteps .fr-step');
    var panes = document.querySelectorAll('.fr-pane');
    var prevBtn = document.getElementById('frPrev');
    var nextBtn = document.getElementById('frNext');
    var submitBtn = document.getElementById('frSubmit');
    var sponsorTimer = null;

    function toast(msg, isErr) {
        var existing = document.querySelector('.fr-toast');
        if (existing) existing.remove();
        var t = document.createElement('div');
        t.className = 'fr-toast' + (isErr ? ' is-err' : '');
        t.innerHTML = (isErr ? '⚠ ' : '✓ ') + msg;
        document.body.appendChild(t);
        setTimeout(function () {
            t.style.opacity = '0';
            t.style.transition = 'opacity 0.3s';
            setTimeout(function () { t.remove(); }, 300);
        }, 3000);
    }

    function showStep(n) {
        step = n;
        steps.forEach(function (el) {
            var s = parseInt(el.getAttribute('data-step'), 10);
            el.classList.toggle('is-active', s === step);
            el.classList.toggle('is-done', s < step);
        });
        panes.forEach(function (pane) {
            var p = parseInt(pane.getAttribute('data-pane'), 10);
            var on = p === step;
            pane.hidden = !on;
            pane.classList.toggle('is-active', on);
        });
        prevBtn.hidden = step === 1;
        nextBtn.hidden = step === max;
        submitBtn.hidden = step !== max;
        prevBtn.style.display = step === 1 ? 'none' : 'inline-flex';
        nextBtn.style.display = step === max ? 'none' : 'inline-flex';
        submitBtn.style.display = step !== max ? 'none' : 'inline-flex';

        // Scroll smoothly to wizard top
        var panel = document.querySelector('.fr-wiz-panel');
        if (panel) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function validateStep(n) {
        if (n === 1) {
            var type = document.getElementById('frType');
            if (!type || !type.value) {
                toast('Please select a Franchisee Type to continue.', true);
                if (type) type.focus();
                return false;
            }
            return true;
        }
        if (n === 2) {
            var name = document.getElementById('frName');
            var phone = document.getElementById('frPhone');
            if (!name || !name.value.trim()) {
                toast('Full Name is required.', true);
                if (name) name.focus();
                return false;
            }
            if (!phone || !phone.value.trim()) {
                toast('Mobile Number is required.', true);
                if (phone) phone.focus();
                return false;
            }
            var email = document.getElementById('frEmail');
            if (email && email.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
                toast('Please enter a valid email address.', true);
                email.focus();
                return false;
            }
            return true;
        }
        if (n === 4) {
            var address = document.getElementById('frAddress');
            var city = document.getElementById('frCity');
            var state = document.getElementById('frState');
            if (!address || !address.value.trim()) {
                toast('Address is required.', true);
                if (address) address.focus();
                return false;
            }
            if (!city || !city.value.trim()) {
                toast('City is required.', true);
                if (city) city.focus();
                return false;
            }
            if (!state || !state.value.trim()) {
                toast('State is required.', true);
                if (state) state.focus();
                return false;
            }
            return true;
        }
        if (n === 5) {
            var user = document.getElementById('frUsername');
            var pass = document.getElementById('frPassword');
            var pass2 = document.getElementById('frPassword2');
            var isEdit = <?= $isEdit ? 'true' : 'false' ?>;
            if (!user || !user.value.trim()) {
                toast('Username is required for portal access.', true);
                if (user) user.focus();
                return false;
            }
            if (!isEdit && (!pass || !pass.value)) {
                toast('Password is required for new registration.', true);
                if (pass) pass.focus();
                return false;
            }
            if (pass && pass.value && pass.value.length < 6) {
                toast('Password must be at least 6 characters.', true);
                pass.focus();
                return false;
            }
            if (pass && pass.value && pass2 && pass.value !== pass2.value) {
                toast('Passwords do not match.', true);
                pass2.focus();
                return false;
            }
            return true;
        }
        return true;
    }

    nextBtn.addEventListener('click', function () {
        if (!validateStep(step)) return;
        if (step < max) showStep(step + 1);
    });
    prevBtn.addEventListener('click', function () {
        if (step > 1) showStep(step - 1);
    });
    steps.forEach(function (el) {
        el.addEventListener('click', function () {
            var target = parseInt(el.getAttribute('data-step'), 10);
            if (target < step) { showStep(target); return; }
            for (var i = step; i < target; i++) {
                if (!validateStep(i)) return;
            }
            showStep(target);
        });
    });

    document.getElementById('frWizForm').addEventListener('submit', function (e) {
        if (!validateStep(5)) {
            e.preventDefault();
        }
    });

    // Sponsor AJAX lookup
    function lookupSponsor() {
        var code = (document.getElementById('frSponsorCode').value || '').trim();
        var nameEl = document.getElementById('frSponsorName');
        var typeEl = document.getElementById('frSponsorType');
        var hint = document.getElementById('frSponsorHint');
        var hName = document.getElementById('sponsorNameHidden');
        var hType = document.getElementById('sponsorTypeHidden');
        if (!code) {
            nameEl.value = ''; typeEl.value = '';
            hName.value = ''; hType.value = '';
            hint.textContent = 'Optional — leave blank for Direct Company / Root partner';
            hint.style.color = '';
            return;
        }
        hint.textContent = 'Verifying sponsor code...';
        hint.style.color = '#64748b';
        fetch('franchisee-add.php?ajax=sponsor&code=' + encodeURIComponent(code), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok) {
                    nameEl.value = data.name || '';
                    typeEl.value = data.type || '';
                    hName.value = nameEl.value;
                    hType.value = typeEl.value;
                    hint.textContent = '✓ Verified: ' + data.name + ' (' + (data.type || 'Franchise') + ')';
                    hint.style.color = '#059669';
                } else {
                    nameEl.value = ''; typeEl.value = '';
                    hName.value = ''; hType.value = '';
                    hint.textContent = '✗ ' + ((data && data.error) ? data.error : 'Sponsor not found');
                    hint.style.color = '#e11d48';
                }
            })
            .catch(function () {
                hint.textContent = 'Could not verify sponsor ID';
                hint.style.color = '#e11d48';
            });
    }

    var sponsorInput = document.getElementById('frSponsorCode');
    if (sponsorInput) {
        sponsorInput.addEventListener('input', function () {
            clearTimeout(sponsorTimer);
            sponsorTimer = setTimeout(lookupSponsor, 350);
        });
        sponsorInput.addEventListener('blur', lookupSponsor);
        if (sponsorInput.value.trim()) {
            lookupSponsor();
        }
    }

    // Password visibility toggles
    document.querySelectorAll('.fr-pw-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-target');
            var input = document.getElementById(targetId);
            if (!input) return;
            var isPass = input.type === 'password';
            input.type = isPass ? 'text' : 'password';
            btn.innerHTML = isPass
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        });
    });

    // Document upload cards
    document.querySelectorAll('[data-fr-doc]').forEach(function (card) {
        var input = card.querySelector('.fr-doc-input');
        var browse = card.querySelector('.fr-doc-browse');
        var clearBtn = card.querySelector('.fr-doc-clear');
        var empty = card.querySelector('.fr-doc-empty');
        var img = card.querySelector('.fr-doc-img');
        var pdf = card.querySelector('.fr-doc-pdf');
        var nameEl = card.querySelector('.fr-doc-name');
        var objectUrl = null;

        function resetPreview() {
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            if (img) { img.hidden = true; img.removeAttribute('src'); }
            if (pdf) pdf.hidden = true;
            if (empty) empty.hidden = false;
            card.classList.remove('has-file');
            if (browse) browse.textContent = 'Upload';
            if (clearBtn) clearBtn.hidden = true;
        }

        function showFile(file) {
            if (!file) return;
            var isPdf = /\.pdf$/i.test(file.name) || file.type === 'application/pdf';
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            if (empty) empty.hidden = true;
            if (isPdf) {
                if (img) { img.hidden = true; img.removeAttribute('src'); }
                if (pdf) pdf.hidden = false;
            } else {
                objectUrl = URL.createObjectURL(file);
                if (pdf) pdf.hidden = true;
                if (img) { img.hidden = false; img.src = objectUrl; }
            }
            card.classList.add('has-file');
            if (nameEl) nameEl.textContent = file.name;
            if (browse) browse.textContent = 'Replace';
            if (clearBtn) clearBtn.hidden = false;
        }

        if (browse && input) {
            browse.addEventListener('click', function (e) {
                e.preventDefault();
                input.click();
            });
        }
        if (input) {
            input.addEventListener('change', function () {
                var f = input.files && input.files[0];
                if (f) showFile(f);
            });
        }
        if (clearBtn && input) {
            clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                input.value = '';
                resetPreview();
                if (nameEl) nameEl.textContent = nameEl.getAttribute('data-hint') || 'No file selected';
            });
            if (nameEl && !nameEl.getAttribute('data-hint')) {
                nameEl.setAttribute('data-hint', nameEl.textContent);
            }
        }

        ['dragenter', 'dragover'].forEach(function (ev) {
            card.addEventListener(ev, function (e) {
                e.preventDefault();
                e.stopPropagation();
                card.classList.add('is-drag');
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            card.addEventListener(ev, function (e) {
                e.preventDefault();
                e.stopPropagation();
                card.classList.remove('is-drag');
            });
        });
        card.addEventListener('drop', function (e) {
            var files = e.dataTransfer && e.dataTransfer.files;
            if (!files || !files.length || !input) return;
            var dt = new DataTransfer();
            dt.items.add(files[0]);
            input.files = dt.files;
            showFile(files[0]);
        });
    });

    showStep(1);
})();
</script>
<?php franchise_footer(); ?>
