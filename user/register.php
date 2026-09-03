<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/registration.php';

if (!client_license_ok()) {
    flash('error', client_license_message() ?: 'Registration is unavailable.');
    header('Location: login.php');
    exit;
}

if (is_maintenance_mode()) {
    flash('error', 'Portal is under maintenance. Registration is temporarily disabled.');
    header('Location: login.php');
    exit;
}

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

ensure_member_registration_columns($pdo);

$company = setting('company_name', 'Binary MLM');
$errors = [];
$success = false;
$createdCode = '';
$useBinaryPlacement = feature_registration_uses_binary_placement();
$useMatrixPlacement = feature_registration_uses_matrix_placement();
$matrixWidth = matrix_width();

$ref = trim($_GET['ref'] ?? $_POST['sponsor_id'] ?? '');
$pos = strtolower(trim($_GET['pos'] ?? $_POST['position'] ?? 'left'));
if ($useBinaryPlacement) {
    if (!in_array($pos, ['left', 'right'], true)) {
        $pos = 'left';
    }
} else {
    $pos = 'left';
}

$prefillSponsor = $ref !== '' ? reg_lookup_sponsor($pdo, $ref) : null;

$form = [
    'sponsor_id' => $prefillSponsor['member_id'] ?? $ref,
    'sponsor_name' => $prefillSponsor['full_name'] ?? '',
    'position' => $pos,
    'name_title' => $_POST['name_title'] ?? 'Mr',
    'full_name' => trim($_POST['full_name'] ?? ''),
    'gender' => $_POST['gender'] ?? '',
    'email' => trim($_POST['email'] ?? ''),
    'phone' => trim($_POST['phone'] ?? ''),
    'dob_y' => reg_form_dob_value($_POST['dob_y'] ?? '', 'y'),
    'dob_m' => reg_form_dob_value($_POST['dob_m'] ?? '', 'm'),
    'dob_d' => reg_form_dob_value($_POST['dob_d'] ?? '', 'd'),
    'agree' => isset($_POST['agree']),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sponsorCode = trim($_POST['sponsor_id'] ?? '');
    $position = strtolower(trim($_POST['position'] ?? ''));
    $nameTitle = trim($_POST['name_title'] ?? 'Mr');
    $fullName = trim($_POST['full_name'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';
    $captchaIn = trim($_POST['captcha'] ?? '');
    $dobY = (int) ($_POST['dob_y'] ?? 0);
    $dobM = (int) ($_POST['dob_m'] ?? 0);
    $dobD = (int) ($_POST['dob_d'] ?? 0);

    $form = [
        'sponsor_id' => $sponsorCode,
        'sponsor_name' => trim($_POST['sponsor_name'] ?? ''),
        'position' => in_array($position, ['left', 'right'], true) ? $position : 'left',
        'name_title' => $nameTitle,
        'full_name' => $fullName,
        'gender' => $gender,
        'email' => $email,
        'phone' => $phone,
        'dob_y' => reg_form_dob_value($_POST['dob_y'] ?? '', 'y'),
        'dob_m' => reg_form_dob_value($_POST['dob_m'] ?? '', 'm'),
        'dob_d' => reg_form_dob_value($_POST['dob_d'] ?? '', 'd'),
        'agree' => isset($_POST['agree']),
    ];

    $sponsor = reg_lookup_sponsor($pdo, $sponsorCode);
    if (!$sponsor) {
        $errors[] = 'Enter a valid Sponsor ID.';
    } elseif (!reg_sponsor_is_active($sponsor)) {
        $errors[] = 'Sponsor account is not activated yet.';
    }

    if ($useBinaryPlacement && !in_array($position, ['left', 'right'], true)) {
        $errors[] = 'Select Left or Right placement.';
    }
    if (!$useBinaryPlacement) {
        $position = null;
    }
    if ($fullName === '' || mb_strlen($fullName) < 2) {
        $errors[] = 'Full name is required.';
    }
    if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
        $errors[] = 'Select your gender.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($phone === '' || !preg_match('/^[0-9+\-\s]{8,20}$/', $phone)) {
        $errors[] = 'Enter a valid mobile number.';
    }
    $dob = null;
    if ($dobY && $dobM && $dobD && checkdate($dobM, $dobD, $dobY)) {
        $dob = sprintf('%04d-%02d-%02d', $dobY, $dobM, $dobD);
        $age = (int) ((new DateTime($dob))->diff(new DateTime('today'))->y);
        if ($age < 18) {
            $errors[] = 'You must be at least 18 years old.';
        }
    } else {
        $errors[] = 'Select a valid date of birth.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Password and confirm password do not match.';
    }
    if (!reg_captcha_valid($captchaIn)) {
        $errors[] = 'Security code is incorrect.';
    }
    if (empty($_POST['agree'])) {
        $errors[] = 'Please agree to the E-Contract.';
    }

    if (!$errors) {
        $dup = $pdo->prepare('SELECT id FROM members WHERE email = ? LIMIT 1');
        $dup->execute([$email]);
        if ($dup->fetch()) {
            $errors[] = 'This email is already registered.';
        }
    }

    if (!$errors && reg_phone_exists($pdo, $phone)) {
        $errors[] = 'This mobile number is already registered.';
    }

    $placementId = null;
    if (!$errors && $sponsor) {
        if ($useBinaryPlacement) {
            $parentId = (int) $sponsor['id'];
            $slot = $pdo->prepare('SELECT id FROM members WHERE placement_id = ? AND position = ? LIMIT 1');
            $slot->execute([$parentId, $position]);
            if ($slot->fetch()) {
                $placementId = reg_find_binary_placement($pdo, $parentId, $position);
                if (!$placementId) {
                    $errors[] = 'Could not find a free placement slot on this side.';
                }
            } else {
                $placementId = $parentId;
            }
        } elseif ($useMatrixPlacement) {
            members_ensure_flexible_position($pdo);
            $found = reg_find_matrix_placement($pdo, (int) $sponsor['id'], $matrixWidth);
            if (!$found) {
                $errors[] = 'Could not find a free matrix slot under this sponsor.';
            } else {
                $placementId = (int) $found['placement_id'];
                $position = (string) $found['position'];
            }
        } else {
            // Level / Unilevel: sponsor chain only — no tree placement legs.
            $placementId = null;
            $position = null;
        }
    }

    if (!$errors && $sponsor && (($useBinaryPlacement || $useMatrixPlacement) ? $placementId : true)) {
        $memberCode = reg_unique_member_id($pdo);
        $username = reg_unique_username($pdo, $fullName);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $allowedTitles = ['Mr', 'Mrs', 'Ms', 'Miss', 'Dr'];
        if (!in_array($nameTitle, $allowedTitles, true)) {
            $nameTitle = 'Mr';
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('
                INSERT INTO members (
                    member_id, username, email, password, full_name, name_title, gender, date_of_birth, phone,
                    sponsor_id, placement_id, position, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $memberCode,
                $username,
                $email,
                $hash,
                $fullName,
                $nameTitle,
                $gender,
                $dob,
                $phone,
                (int) $sponsor['id'],
                $placementId,
                $position,
                'inactive',
            ]);
            if ($useBinaryPlacement && $placementId && $position) {
                reg_update_upline_counts($pdo, $placementId, $position);
            }
            $pdo->commit();

            unset($_SESSION['reg_captcha']);
            $_SESSION['reg_success'] = [
                'member_id' => $memberCode,
                'username' => $username,
                'password' => $password,
                'full_name' => $fullName,
                'name_title' => $nameTitle,
            ];
            header('Location: register-success.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Registration failed. Please try again.';
        }
    }

    reg_captcha_generate();
}

$captcha = reg_captcha_code();
$years = range((int) date('Y') - 18, (int) date('Y') - 80);
$months = [
    '01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
    '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Aug',
    '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | <?= e($company) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/user.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/user.css') ?>">
</head>
<body class="ureg-body">
<div class="ureg">
    <header class="ureg-top">
        <a href="login.php" class="ureg-brand"><?= e($company) ?></a>
        <a href="login.php" class="ureg-top-link">Already have an account? <strong>Sign In</strong></a>
    </header>

    <main class="ureg-main">
        <div class="ureg-intro">
            <h1>Create Your Account</h1>
            <p>Join <?= e($company) ?> and start building your network.</p>
        </div>

        <nav class="ureg-steps" aria-label="Registration steps">
            <div class="ureg-step is-on" data-step="1">
                <span class="ureg-step-num">1</span>
                <span class="ureg-step-label">Sponsor</span>
            </div>
            <span class="ureg-step-line" aria-hidden="true"></span>
            <div class="ureg-step" data-step="2">
                <span class="ureg-step-num">2</span>
                <span class="ureg-step-label">Personal</span>
            </div>
            <span class="ureg-step-line" aria-hidden="true"></span>
            <div class="ureg-step" data-step="3">
                <span class="ureg-step-num">3</span>
                <span class="ureg-step-label">Security</span>
            </div>
        </nav>

        <?php if ($errors): ?>
            <div class="up-alert up-alert-err ureg-alert"><?= e(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <form method="post" class="ureg-form" id="uregForm" autocomplete="off" novalidate>
            <!-- 1. Sponsor -->
            <section class="ureg-card" id="uregSection1" data-ureg-section="1">
                <div class="ureg-card-head">
                    <span class="ureg-card-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </span>
                    <div>
                        <h2>Sponsor Information</h2>
                        <p>Enter your sponsor details to get started.</p>
                    </div>
                </div>

                <div class="ureg-grid">
                    <div class="ureg-field">
                        <label for="sponsor_id">Sponsor ID</label>
                        <div class="ureg-input-ico">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <input type="text" id="sponsor_id" name="sponsor_id" value="<?= e($form['sponsor_id']) ?>" placeholder="Enter Sponsor ID" required>
                        </div>
                    </div>
                    <div class="ureg-field">
                        <label for="sponsor_name">Sponsor Name</label>
                        <div class="ureg-input-ico">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <input type="text" id="sponsor_name" name="sponsor_name" value="<?= e($form['sponsor_name']) ?>" placeholder="Auto-filled sponsor name" readonly>
                        </div>
                    </div>
                </div>

                <?php if ($useBinaryPlacement): ?>
                <div class="ureg-pos">
                    <div class="ureg-pos-head">
                        <strong>Select Position</strong>
                        <span>Choose your placement in the sponsor's team structure.</span>
                    </div>
                    <div class="ureg-pos-grid" role="radiogroup" aria-label="Binary position">
                        <label class="ureg-pos-card<?= $form['position'] === 'left' ? ' is-on' : '' ?>">
                            <input type="radio" name="position" value="left" <?= $form['position'] === 'left' ? 'checked' : '' ?>>
                            <span class="ureg-pos-check" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><polyline points="20 6 9 17 4 12"/></svg>
                            </span>
                            <span class="ureg-pos-side">Left</span>
                            <span class="ureg-pos-hint">Left leg of sponsor</span>
                        </label>
                        <label class="ureg-pos-card<?= $form['position'] === 'right' ? ' is-on' : '' ?>">
                            <input type="radio" name="position" value="right" <?= $form['position'] === 'right' ? 'checked' : '' ?>>
                            <span class="ureg-pos-check" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><polyline points="20 6 9 17 4 12"/></svg>
                            </span>
                            <span class="ureg-pos-side">Right</span>
                            <span class="ureg-pos-hint">Right leg of sponsor</span>
                        </label>
                    </div>
                </div>
                <?php elseif ($useMatrixPlacement): ?>
                <input type="hidden" name="position" value="auto">
                <div class="up-alert up-alert-info" style="margin-top:0.75rem">
                    Placement is auto-assigned by spillover under your sponsor (Matrix <?= (int) $matrixWidth ?>×).
                </div>
                <?php else: ?>
                <input type="hidden" name="position" value="left">
                <div class="up-alert up-alert-info" style="margin-top:0.75rem">
                    You will join under your sponsor (no Left/Right placement).
                </div>
                <?php endif; ?>
            </section>

            <!-- 2. Personal -->
            <section class="ureg-card" id="uregSection2" data-ureg-section="2">
                <div class="ureg-card-head">
                    <span class="ureg-card-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                    </span>
                    <div>
                        <h2>Personal Details</h2>
                        <p>Tell us a bit about yourself.</p>
                    </div>
                </div>

                <div class="ureg-grid">
                    <div class="ureg-field">
                        <label for="full_name">Full Name</label>
                        <div class="ureg-name-split">
                            <select id="name_title" name="name_title" aria-label="Title">
                                <?php foreach (['Mr', 'Mrs', 'Ms', 'Miss', 'Dr'] as $t): ?>
                                    <option value="<?= $t ?>" <?= $form['name_title'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="ureg-input-ico grow">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <input type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" placeholder="Enter full name" required>
                            </div>
                        </div>
                    </div>
                    <div class="ureg-field">
                        <label for="gender">Gender</label>
                        <div class="ureg-input-ico">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M6 21v-2a4 4 0 014-4h4a4 4 0 014 4v2"/></svg>
                            <select id="gender" name="gender" required>
                                <option value="">Select Gender</option>
                                <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
                                    <option value="<?= $g ?>" <?= $form['gender'] === $g ? 'selected' : '' ?>><?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="ureg-field">
                        <label for="email">Email Address</label>
                        <div class="ureg-input-ico">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            <input type="email" id="email" name="email" value="<?= e($form['email']) ?>" placeholder="you@email.com" required>
                        </div>
                    </div>
                    <div class="ureg-field">
                        <label for="phone">Mobile Number</label>
                        <div class="ureg-input-ico">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6A19.79 19.79 0 012.12 4.18 2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                            <input type="tel" id="phone" name="phone" value="<?= e($form['phone']) ?>" placeholder="Mobile number" required>
                        </div>
                    </div>
                </div>

                <div class="ureg-field ureg-dob">
                    <label>Date of Birth</label>
                    <div class="ureg-dob-grid">
                        <div class="ureg-input-ico">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            <select name="dob_y" id="dob_y" aria-label="Year" data-reg-dob="<?= e($form['dob_y']) ?>" required>
                                <option value="">Year</option>
                                <?php foreach ($years as $y): ?>
                                    <option value="<?= $y ?>" <?= reg_dob_option_selected((string) $form['dob_y'], (string) $y, 'y') ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ureg-input-ico">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            <select name="dob_m" id="dob_m" aria-label="Month" data-reg-dob="<?= e($form['dob_m']) ?>" required>
                                <option value="">Month</option>
                                <?php foreach ($months as $mv => $ml): ?>
                                    <option value="<?= $mv ?>" <?= reg_dob_option_selected((string) $form['dob_m'], (string) $mv, 'm') ? 'selected' : '' ?>><?= $ml ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ureg-input-ico">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            <select name="dob_d" id="dob_d" aria-label="Day" data-reg-dob="<?= e($form['dob_d']) ?>" required>
                                <option value="">Day</option>
                                <?php for ($d = 1; $d <= 31; $d++):
                                    $ds = sprintf('%02d', $d); ?>
                                    <option value="<?= $ds ?>" <?= reg_dob_option_selected((string) $form['dob_d'], (string) $ds, 'd') ? 'selected' : '' ?>><?= $d ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 3. Security -->
            <section class="ureg-card" id="uregSection3" data-ureg-section="3">
                <div class="ureg-card-head">
                    <span class="ureg-card-ico is-green" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </span>
                    <div>
                        <h2>Account Security</h2>
                        <p>Create a secure password for your account.</p>
                    </div>
                </div>

                <div class="ureg-grid">
                    <div class="ureg-field">
                        <label for="password">Password</label>
                        <div class="ureg-input-ico has-eye">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                            <input type="password" id="password" name="password" placeholder="Create password" required>
                            <button type="button" class="up-eye" data-password-toggle aria-label="Show password">
                                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="ureg-field">
                        <label for="password_confirm">Confirm Password</label>
                        <div class="ureg-input-ico has-eye">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                            <input type="password" id="password_confirm" name="password_confirm" placeholder="Confirm password" required>
                            <button type="button" class="up-eye" data-password-toggle aria-label="Show password">
                                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="ureg-captcha">
                    <label for="captcha">Security Code</label>
                    <div class="ureg-captcha-row">
                        <div class="ureg-captcha-code" id="uregCaptchaCode" aria-hidden="true"><?= e($captcha) ?></div>
                        <button type="button" class="ureg-captcha-refresh" id="uregCaptchaRefresh" title="Refresh code" aria-label="Refresh security code">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
                        </button>
                        <div class="ureg-input-ico grow">
                            <input type="text" id="captcha" name="captcha" placeholder="TYPE CODE" autocomplete="off" required maxlength="8">
                        </div>
                    </div>
                </div>

                <label class="ureg-agree">
                    <input type="checkbox" name="agree" value="1" <?= !empty($form['agree']) ? 'checked' : '' ?>>
                    <span>I agree to the <a href="#e-contract" id="uregContractLink">E-Contract</a></span>
                </label>
            </section>

            <div class="ureg-actions">
                <button type="submit" class="ureg-submit">Create Account</button>
                <p class="ureg-foot">Already have an account? <a href="login.php">Sign In</a></p>
            </div>
        </form>
    </main>
</div>

<dialog class="ureg-dialog" id="uregContract" aria-labelledby="uregContractTitle">
    <div class="ureg-dialog-panel">
        <header class="ureg-dialog-head">
            <div class="ureg-dialog-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </div>
            <div>
                <p class="ureg-dialog-kicker">Member agreement</p>
                <h3 id="uregContractTitle">E-Contract</h3>
            </div>
            <button type="button" class="ureg-dialog-x" id="uregContractClose" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </header>
        <div class="ureg-dialog-body">
            <p>By creating an account with <strong><?= e($company) ?></strong>, you confirm that:</p>
            <ul>
                <li>You will follow company policies and the compensation plan rules.</li>
                <li>Your profile, KYC and bank details will be kept accurate and up to date.</li>
                <li>Commissions, bonuses and withdrawals are subject to plan settings and admin approval.</li>
                <li>You will not misuse the platform, referral links, or payment systems.</li>
                <li>Accounts found in violation may be suspended or blocked without notice.</li>
            </ul>
            <p class="ureg-dialog-note">This electronic agreement is binding upon successful registration.</p>
        </div>
        <footer class="ureg-dialog-foot">
            <button type="button" class="ureg-dialog-btn ghost" id="uregContractDismiss">Close</button>
            <button type="button" class="ureg-dialog-btn primary" id="uregContractAccept">I Agree</button>
        </footer>
    </div>
</dialog>

<script src="assets/js/user.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/user.js') ?>"></script>
<script>
(function () {
    const sponsorId = document.getElementById('sponsor_id');
    const sponsorName = document.getElementById('sponsor_name');
    let timer = null;

    function lookupSponsor() {
        const id = (sponsorId.value || '').trim();
        if (id.length < 2) {
            sponsorName.value = '';
            return;
        }
        fetch('ajax-sponsor.php?id=' + encodeURIComponent(id))
            .then((r) => r.json())
            .then((data) => {
                sponsorName.value = data.ok ? (data.full_name || '') : '';
                if (!data.ok) sponsorName.placeholder = data.error || 'Sponsor not found';
                else sponsorName.placeholder = 'Auto-filled sponsor name';
            })
            .catch(() => { sponsorName.value = ''; });
    }

    if (sponsorId) {
        sponsorId.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(lookupSponsor, 350);
        });
        sponsorId.addEventListener('blur', lookupSponsor);
        if (sponsorId.value) lookupSponsor();
    }

    document.querySelectorAll('.ureg-pos-card input').forEach((input) => {
        input.addEventListener('change', () => {
            document.querySelectorAll('.ureg-pos-card').forEach((c) => c.classList.toggle('is-on', c.querySelector('input').checked));
        });
    });

    const caps = document.getElementById('uregCaptchaCode');
    const refresh = document.getElementById('uregCaptchaRefresh');
    if (refresh && caps) {
        refresh.addEventListener('click', () => {
            fetch('ajax-sponsor.php?action=captcha')
                .then((r) => r.json())
                .then((data) => { if (data.ok) caps.textContent = data.code; });
        });
    }

    const dlg = document.getElementById('uregContract');
    const link = document.getElementById('uregContractLink');
    const agreeBox = document.querySelector('input[name="agree"]');
    const closeBtn = document.getElementById('uregContractClose');
    const dismissBtn = document.getElementById('uregContractDismiss');
    const acceptBtn = document.getElementById('uregContractAccept');

    function openContract(e) {
        if (e) e.preventDefault();
        if (dlg && typeof dlg.showModal === 'function') dlg.showModal();
    }
    function closeContract() {
        if (dlg && dlg.open) dlg.close();
    }

    if (dlg && link) {
        link.addEventListener('click', openContract);
    }
    if (closeBtn) closeBtn.addEventListener('click', closeContract);
    if (dismissBtn) dismissBtn.addEventListener('click', closeContract);
    if (acceptBtn) {
        acceptBtn.addEventListener('click', () => {
            if (agreeBox) agreeBox.checked = true;
            closeContract();
        });
    }
    if (dlg) {
        dlg.addEventListener('click', (e) => {
            if (e.target === dlg) closeContract();
        });
    }

    document.querySelectorAll('[data-reg-dob]').forEach((sel) => {
        const saved = sel.getAttribute('data-reg-dob') || '';
        if (saved && [...sel.options].some((o) => o.value === saved)) {
            sel.value = saved;
        }
    });

    const syncSelectVisual = (sel) => {
        sel.classList.toggle('has-value', sel.value !== '');
    };
    document.querySelectorAll('.ureg-form select').forEach((sel) => {
        syncSelectVisual(sel);
        sel.addEventListener('change', () => syncSelectVisual(sel));
    });

    /* Highlight step on scroll */
    const steps = document.querySelectorAll('.ureg-step');
    const sections = document.querySelectorAll('[data-ureg-section]');
    if ('IntersectionObserver' in window && sections.length) {
        const io = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                const n = entry.target.getAttribute('data-ureg-section');
                steps.forEach((s) => s.classList.toggle('is-on', s.getAttribute('data-step') === n));
            });
        }, { rootMargin: '-30% 0px -50% 0px', threshold: 0.1 });
        sections.forEach((s) => io.observe(s));
    }
})();
</script>
</body>
</html>
