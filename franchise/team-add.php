<?php
/**
 * Franchise Portal - Register Sub-Franchise / Downline Member
 */
$pageTitle = 'Add Sub-Franchise';
require_once __DIR__ . '/includes/header.php';

$userHierarchy = (int) ($currentFranchise['hierarchy_level'] ?? 4);
if ($userHierarchy >= 4) {
    flash('error', 'Retailers cannot register sub-franchisees.');
    header('Location: index.php');
    exit;
}

$allowedTypes = franchise_allowed_child_types($pdo, $userHierarchy, $currentFranchise['can_create_types'] ?? null);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $typeId = (int) ($_POST['type_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact_person'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $pincode = trim($_POST['pincode'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password_confirm'] ?? '');

    // Validate type is in allowed child types
    $validType = false;
    foreach ($allowedTypes as $at) {
        if ((int)$at['id'] === $typeId) {
            $validType = true;
            break;
        }
    }
    if (!$validType) {
        $errors[] = 'Please select a valid franchise type you are authorized to create.';
    }

    if ($name === '') {
        $errors[] = 'Franchise / business name is required.';
    }
    if ($phone === '' || !preg_match('/^[0-9]{10}$/', $phone)) {
        $errors[] = 'Enter a valid 10-digit mobile number.';
    }
    if ($city === '') {
        $errors[] = 'City is required.';
    }
    if ($state === '') {
        $errors[] = 'State is required.';
    }
    if ($username === '') {
        $errors[] = 'Login username is required.';
    }
    if ($password === '' || strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $password2) {
        $errors[] = 'Password and confirm password do not match.';
    }

    if (empty($errors)) {
        try {
            $code = franchise_next_code($pdo);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $sponsorId = (int) $currentFranchise['id'];

            $sql = '
                INSERT INTO franchisees 
                    (type_id, sponsor_id, franchisee_code, name, contact_person, phone, email, address, city, state, pincode, username, password, status, created_by_role, created_by_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active", "franchise", ?)
            ';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $typeId,
                $sponsorId,
                $code,
                $name,
                $contact !== '' ? $contact : null,
                $phone,
                $email !== '' ? $email : null,
                $address !== '' ? $address : null,
                $city,
                $state,
                $pincode !== '' ? $pincode : null,
                $username,
                $hash,
                $sponsorId
            ]);

            log_activity('franchise_create_sub', "Franchise {$currentFranchise['franchisee_code']} created sub-franchise $code ($name)");
            flash('success', "Sub-Franchise $code ($name) successfully created!");
            header('Location: team-report.php');
            exit;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'uk_franchisee_username') !== false) {
                $errors[] = 'This username is already taken. Please choose another.';
            } else {
                $errors[] = 'Could not register franchise: ' . $e->getMessage();
            }
        }
    }
}
?>

<div class="panel">
    <div class="panel-header">
        <h2>Register Sub-Franchise / Downline Point</h2>
        <p style="color:#64748b;font-size:0.88rem;margin-top:0.25rem">
            As a <strong><?= e($currentFranchise['type_name'] ?? '') ?></strong>, you can appoint distributors and retailers under your channel.
        </p>
    </div>

    <div class="panel-body">
        <?php if ($errors): ?>
            <div class="alert alert-danger" style="margin-bottom:1.5rem">
                <ul style="margin:0;padding-left:1.2rem">
                    <?php foreach ($errors as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="team-add.php">
            <!-- Premium Sponsor Authority Card (Locked Uplink) -->
            <div style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 60%, #0c4a6e 100%); border-radius: 16px; padding: 1.5rem; margin-bottom: 1.75rem; color: #fff; box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.25), 0 8px 10px -6px rgba(15, 23, 42, 0.2); position: relative; overflow: hidden; border: 1px solid rgba(255, 255, 255, 0.1);">
                <!-- Decorative background elements -->
                <div style="position: absolute; right: -20px; top: -20px; width: 140px; height: 140px; background: radial-gradient(circle, rgba(56, 189, 248, 0.2) 0%, transparent 70%); border-radius: 50%; pointer-events: none;"></div>
                <div style="position: absolute; right: 80px; bottom: -30px; width: 120px; height: 120px; background: radial-gradient(circle, rgba(14, 165, 233, 0.15) 0%, transparent 70%); border-radius: 50%; pointer-events: none;"></div>

                <!-- Card Header -->
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; padding-bottom: 1.25rem; border-bottom: 1px solid rgba(255, 255, 255, 0.12); margin-bottom: 1.25rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div style="width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg, #0284c7 0%, #38bdf8 100%); display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.4);">
                            <svg style="width: 22px; height: 22px; stroke: #fff; fill: none;" viewBox="0 0 24 24" stroke-width="2">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                <path d="m9 12 2 2 4-4"/>
                            </svg>
                        </div>
                        <div>
                            <span style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.08em; color: #94a3b8; font-weight: 600;">Authorized Appointer</span>
                            <h3 style="font-size: 1.1rem; color: #f8fafc; font-weight: 700; margin: 0; line-height: 1.2;">1. Sponsor &amp; Uplink Information</h3>
                        </div>
                    </div>
                    <div style="display: inline-flex; align-items: center; gap: 0.45rem; background: rgba(16, 185, 129, 0.18); border: 1px solid rgba(16, 185, 129, 0.35); padding: 0.35rem 0.85rem; border-radius: 9999px; font-size: 0.78rem; font-weight: 600; color: #34d399;">
                        <svg style="width: 14px; height: 14px; stroke: currentColor; fill: none;" viewBox="0 0 24 24" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        <span>Direct Channel Parent Locked</span>
                    </div>
                </div>

                <!-- Card Details Grid -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
                    <!-- Franchise Code Card -->
                    <div style="background: rgba(255, 255, 255, 0.06); backdrop-filter: blur(8px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 1rem; display: flex; flex-direction: column; justify-content: center;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.35rem;">
                            <span style="font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Sponsor Franchise Code</span>
                            <svg style="width: 16px; height: 16px; stroke: #38bdf8; fill: none;" viewBox="0 0 24 24" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="7" y1="8" x2="17" y2="8"/><line x1="7" y1="12" x2="13" y2="12"/></svg>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <span style="font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: 1.15rem; font-weight: 800; color: #38bdf8; letter-spacing: 0.04em;">
                                <?= e($currentFranchise['franchisee_code']) ?>
                            </span>
                            <span style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; font-size: 0.68rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 6px;">ID LOCK</span>
                        </div>
                    </div>

                    <!-- Business / Owner Name Card -->
                    <div style="background: rgba(255, 255, 255, 0.06); backdrop-filter: blur(8px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 1rem; display: flex; flex-direction: column; justify-content: center;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.35rem;">
                            <span style="font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Franchise / Owner Name</span>
                            <svg style="width: 16px; height: 16px; stroke: #a78bfa; fill: none;" viewBox="0 0 24 24" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </div>
                        <div style="font-size: 1.05rem; font-weight: 700; color: #f8fafc; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <?= e($currentFranchise['name']) ?>
                        </div>
                        <?php if (!empty($currentFranchise['contact_person'])): ?>
                            <small style="color: #94a3b8; font-size: 0.78rem;">Contact: <?= e($currentFranchise['contact_person']) ?></small>
                        <?php endif; ?>
                    </div>

                    <!-- Authority Role & Tier Card -->
                    <div style="background: rgba(255, 255, 255, 0.06); backdrop-filter: blur(8px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 1rem; display: flex; flex-direction: column; justify-content: center;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.35rem;">
                            <span style="font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Your Tier &amp; Authority</span>
                            <svg style="width: 16px; height: 16px; stroke: #fbbf24; fill: none;" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;">
                            <span style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color: #fff; font-size: 0.85rem; font-weight: 700; padding: 0.25rem 0.75rem; border-radius: 9999px; box-shadow: 0 2px 6px rgba(2, 132, 199, 0.3);">
                                <?= e($currentFranchise['type_name'] ?? 'Franchise') ?>
                            </span>
                            <?php if ((float)($currentFranchise['commission_percent'] ?? 0) > 0): ?>
                                <span style="background: rgba(251, 191, 36, 0.18); border: 1px solid rgba(251, 191, 36, 0.4); color: #fde047; font-size: 0.78rem; font-weight: 700; padding: 0.2rem 0.55rem; border-radius: 6px;">
                                    <?= number_format((float)$currentFranchise['commission_percent'], 0) ?>% Margin
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 1rem; padding-top: 0.85rem; border-top: 1px solid rgba(255, 255, 255, 0.08); font-size: 0.78rem; color: #94a3b8; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                    <span>The new sub-franchise created below will be automatically bound under your network hierarchy for overriding commissions.</span>
                    <span style="color: #cbd5e1; font-weight: 600;">Status: <span style="color: #34d399;">Active Uplink</span></span>
                </div>
            </div>

            <!-- Role & Business Information -->
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1.25rem;margin-bottom:1.5rem">
                <h3 style="font-size:0.95rem;color:#0f172a;margin-bottom:0.75rem">2. Appointee Business Details</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Appoint As Role / Type *</label>
                        <select name="type_id" required>
                            <option value="">-- Select Franchise Role --</option>
                            <?php foreach ($allowedTypes as $at): ?>
                                <option value="<?= (int) $at['id'] ?>" <?= ((int)($_POST['type_id'] ?? 0) === (int)$at['id']) ? 'selected' : '' ?>>
                                    <?= e($at['name']) ?> (Margin: <?= number_format((float)($at['commission_percent'] ?? 0), 0) ?>%)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color:#64748b">Only roles below your tier are available</small>
                    </div>

                    <div class="form-group">
                        <label>Franchise / Store Name *</label>
                        <input type="text" name="name" value="<?= e($_POST['name'] ?? '') ?>" placeholder="e.g. Shyam Store / City Agency" required>
                    </div>

                    <div class="form-group">
                        <label>Contact Person Name</label>
                        <input type="text" name="contact_person" value="<?= e($_POST['contact_person'] ?? '') ?>" placeholder="e.g. Ramesh Kumar">
                    </div>

                    <div class="form-group">
                        <label>Mobile Number (10 Digits) *</label>
                        <input type="tel" name="phone" value="<?= e($_POST['phone'] ?? '') ?>" placeholder="9876543210" pattern="[0-9]{10}" required>
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" placeholder="franchise@example.com">
                    </div>

                    <div class="form-group">
                        <label>City *</label>
                        <input type="text" name="city" value="<?= e($_POST['city'] ?? '') ?>" placeholder="City" required>
                    </div>

                    <div class="form-group">
                        <label>State *</label>
                        <input type="text" name="state" value="<?= e($_POST['state'] ?? '') ?>" placeholder="State" required>
                    </div>

                    <div class="form-group">
                        <label>Pincode</label>
                        <input type="text" name="pincode" value="<?= e($_POST['pincode'] ?? '') ?>" placeholder="Pincode">
                    </div>

                    <div class="form-group" style="grid-column:1/-1">
                        <label>Full Address</label>
                        <textarea name="address" rows="2" placeholder="Shop / Office address..."><?= e($_POST['address'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Login Credentials -->
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1.25rem;margin-bottom:1.5rem">
                <h3 style="font-size:0.95rem;color:#0f172a;margin-bottom:0.75rem">3. Portal Login Credentials</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Login Username *</label>
                        <input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" placeholder="e.g. shyamstore" required autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label>Login Password *</label>
                        <input type="password" name="password" placeholder="At least 6 characters" required autocomplete="new-password">
                    </div>

                    <div class="form-group">
                        <label>Confirm Password *</label>
                        <input type="password" name="password_confirm" placeholder="Repeat password" required autocomplete="new-password">
                    </div>
                </div>
            </div>

            <div class="form-actions" style="margin-top:1.5rem">
                <button type="submit" class="btn btn-primary" style="padding:0.65rem 1.8rem;font-size:0.95rem">Create Sub-Franchise</button>
                <a href="team-report.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
