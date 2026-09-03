<?php
/**
 * Member plan activation helpers
 */

require_once __DIR__ . '/procedures.php';
require_once __DIR__ . '/closing.php';

function activation_packages(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT * FROM packages WHERE status = 'active' ORDER BY amount ASC")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function activation_pay_referral(PDO $pdo, int $sponsorId, int $fromMemberId, string $memberCode, float $packageAmount): void
{
    if (!feature_enabled('feature_referral_income')) {
        return;
    }
    if ($sponsorId <= 0 || $packageAmount <= 0) {
        return;
    }
    $pct = (float) setting('referral_commission_percent', '5');
    $comm = round($packageAmount * $pct / 100, 2);
    if ($comm <= 0) {
        return;
    }
    $desc = "Referral bonus from $memberCode";
    $pdo->prepare('INSERT INTO commissions (member_id, from_member_id, type, amount, description, status) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$sponsorId, $fromMemberId, 'referral', $comm, $desc, 'paid']);
    $cid = (int) $pdo->lastInsertId();
    wallet_credit($pdo, $sponsorId, 'income', $comm, 'commission', $cid ?: null, $desc);
}

/**
 * Activate member with selected package. Uses SP when available, else PHP fallback.
 * After success: credits BV up the placement tree + pays level income on sponsor chain.
 * Returns ['ok'=>bool,'error'=>?string,'package'=>?array]
 */
function activation_apply(PDO $pdo, array $user, int $packageId): array
{
    if (
        !feature_enabled('feature_package_enabled')
        && !feature_enabled('feature_tpin_enabled')
        && !feature_enabled('feature_product_activates_package')
    ) {
        return ['ok' => false, 'error' => 'Package activation is disabled for this client.'];
    }
    if (!empty($user['package_id'])) {
        return ['ok' => false, 'error' => 'Your account is already activated.'];
    }
    if (($user['status'] ?? '') === 'blocked') {
        return ['ok' => false, 'error' => 'Your account is blocked.'];
    }

    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$packageId]);
    $pkg = $stmt->fetch();
    if (!$pkg) {
        return ['ok' => false, 'error' => 'Selected package is not available.'];
    }

    $uid = (int) $user['id'];
    closing_ensure_tables($pdo);

    // Prefer stored procedure only when referral income is enabled (SP always pays referral).
    $sp = ['message' => 'Procedure unavailable', 'ok' => false];
    if (feature_enabled('feature_referral_income')) {
        $sp = sp_call_activate_member($pdo, $uid, $packageId);
    }
    if ($sp['message'] !== 'Procedure unavailable') {
        if ($sp['ok']) {
            try {
                closing_on_activation($pdo, $user, $pkg);
            } catch (Throwable $e) {
                // Activation already committed; BV/level can be rebuilt from admin closing page
            }
            return ['ok' => true, 'error' => null, 'package' => $pkg];
        }
        return ['ok' => false, 'error' => $sp['message'] ?: 'Activation failed.'];
    }

    // PHP fallback
    $amount = (float) $pkg['amount'];
    $memberCode = (string) ($user['member_id'] ?? '');

    try {
        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE members SET package_id = ?, status = 'active' WHERE id = ? AND package_id IS NULL");
        $upd->execute([$packageId, $uid]);

        if ($upd->rowCount() < 1) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Your account is already activated.'];
        }

        if (!empty($user['sponsor_id'])) {
            activation_pay_referral($pdo, (int) $user['sponsor_id'], $uid, $memberCode, $amount);
        }

        closing_on_activation($pdo, $user, $pkg);

        $pdo->commit();
        return ['ok' => true, 'error' => null, 'package' => $pkg];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Activation failed. Please try again.'];
    }
}

function activation_ensure_requests_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS activation_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            package_id INT NOT NULL,
            from_package_id INT NULL,
            request_type VARCHAR(20) NOT NULL DEFAULT 'activation',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(50) NOT NULL DEFAULT 'Bank Transfer',
            utr_reference VARCHAR(100) NOT NULL,
            payment_slip VARCHAR(255) NULL,
            note TEXT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            admin_note TEXT NULL,
            processed_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME NULL,
            INDEX idx_act_req_member (member_id),
            INDEX idx_act_req_status (status)
        ) ENGINE=InnoDB
    ");
    $alters = [
        'payment_slip' => "ALTER TABLE activation_requests ADD COLUMN payment_slip VARCHAR(255) NULL AFTER utr_reference",
        'from_package_id' => "ALTER TABLE activation_requests ADD COLUMN from_package_id INT NULL AFTER package_id",
        'request_type' => "ALTER TABLE activation_requests ADD COLUMN request_type VARCHAR(20) NOT NULL DEFAULT 'activation' AFTER from_package_id",
    ];
    foreach ($alters as $col => $sql) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM activation_requests LIKE " . $pdo->quote($col))->fetch();
            if (!$cols) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    $done = true;
}

/** @return array|null current package row for member */
function activation_member_package(PDO $pdo, array $user): ?array
{
    $pid = (int) ($user['package_id'] ?? 0);
    if ($pid <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM packages WHERE id = ? LIMIT 1');
    $stmt->execute([$pid]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Higher-priced packages only (upgrade targets). */
function activation_upgrade_packages(PDO $pdo, float $currentAmount): array
{
    $all = activation_packages($pdo);
    $out = [];
    foreach ($all as $pkg) {
        if ((float) $pkg['amount'] > $currentAmount + 0.009) {
            $out[] = $pkg;
        }
    }
    return $out;
}

function activation_can_upgrade(PDO $pdo, array $user): bool
{
    $cur = activation_member_package($pdo, $user);
    if (!$cur) {
        return false;
    }
    return count(activation_upgrade_packages($pdo, (float) $cur['amount'])) > 0;
}

function activation_diff_amount(array $fromPkg, array $toPkg): float
{
    return max(0.0, round((float) $toPkg['amount'] - (float) $fromPkg['amount'], 2));
}

function activation_diff_bv(array $fromPkg, array $toPkg): float
{
    return max(0.0, round((float) $toPkg['bv'] - (float) $fromPkg['bv'], 2));
}

/**
 * Store payment slip (JPG/PNG/WebP/PDF, max 3MB).
 * @return array{ok:bool,error:?string,path:?string}
 */
function activation_store_slip(array $file, int $memberId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Please upload your payment slip.', 'path' => null];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Slip upload failed. Please try again.', 'path' => null];
    }
    if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Payment slip must be under 3MB.', 'path' => null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, WebP or PDF slips are allowed.', 'path' => null];
    }

    if ($mime !== 'application/pdf' && @getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'Invalid image file.', 'path' => null];
    }

    $uploadDir = BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'activations';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'Could not create upload folder.', 'path' => null];
    }

    $name = 'slip_m' . $memberId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = $uploadDir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Could not save payment slip.', 'path' => null];
    }

    return ['ok' => true, 'error' => null, 'path' => 'uploads/activations/' . $name];
}

/** Public URL path for a stored slip (from user/ or admin/). */
function activation_slip_url(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }
    $path = str_replace('\\', '/', $path);
    if (str_starts_with($path, 'uploads/')) {
        return '../' . $path;
    }
    return $path;
}

/** @return array|null pending request for member */
function activation_pending_request(PDO $pdo, int $memberId): ?array
{
    activation_ensure_requests_table($pdo);
    $stmt = $pdo->prepare("
        SELECT r.*, p.name AS package_name, fp.name AS from_package_name
        FROM activation_requests r
        LEFT JOIN packages p ON p.id = r.package_id
        LEFT JOIN packages fp ON fp.id = r.from_package_id
        WHERE r.member_id = ? AND r.status = 'pending'
        ORDER BY r.id DESC
        LIMIT 1
    ");
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Shared payment validation for activation / upgrade submit.
 * @return array{ok:bool,error:?string,payment_method:string,utr:string,slip:?string}
 */
function activation_validate_payment(string $paymentMethod, string $utr, ?string $slipPath): array
{
    $allowed = ['Bank Transfer', 'UPI', 'Cash', 'Other'];
    if (!in_array($paymentMethod, $allowed, true)) {
        $paymentMethod = 'Bank Transfer';
    }

    $isCash = ($paymentMethod === 'Cash');
    $utr = trim($utr);
    if ($isCash) {
        $utr = $utr !== '' ? $utr : 'CASH';
        $slipPath = null;
    } else {
        if (strlen($utr) < 4) {
            return ['ok' => false, 'error' => 'Enter a valid UTR / transaction reference.', 'payment_method' => $paymentMethod, 'utr' => $utr, 'slip' => null];
        }
        if ($slipPath === null || $slipPath === '') {
            return ['ok' => false, 'error' => 'Please upload your payment slip.', 'payment_method' => $paymentMethod, 'utr' => $utr, 'slip' => null];
        }
    }

    return ['ok' => true, 'error' => null, 'payment_method' => $paymentMethod, 'utr' => $utr, 'slip' => $slipPath];
}

/**
 * Submit paid activation request (awaiting admin approval).
 * @return array{ok:bool,error:?string,request_id:?int}
 */
function activation_submit_request(
    PDO $pdo,
    array $user,
    int $packageId,
    string $paymentMethod,
    string $utr,
    string $note = '',
    ?string $slipPath = null
): array {
    if (!feature_enabled('feature_utr_activation_enabled')) {
        return ['ok' => false, 'error' => 'UTR activation is disabled for this client.', 'request_id' => null];
    }
    if (!feature_enabled('feature_package_enabled')) {
        return ['ok' => false, 'error' => 'Package activation is disabled for this client.', 'request_id' => null];
    }
    activation_ensure_requests_table($pdo);

    if (!empty($user['package_id'])) {
        return ['ok' => false, 'error' => 'Your account is already activated. Use plan upgrade instead.', 'request_id' => null];
    }
    if (($user['status'] ?? '') === 'blocked') {
        return ['ok' => false, 'error' => 'Your account is blocked.', 'request_id' => null];
    }

    $uid = (int) $user['id'];
    if (activation_pending_request($pdo, $uid)) {
        return ['ok' => false, 'error' => 'You already have a pending activation request.', 'request_id' => null];
    }

    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$packageId]);
    $pkg = $stmt->fetch();
    if (!$pkg) {
        return ['ok' => false, 'error' => 'Selected package is not available.', 'request_id' => null];
    }

    $pay = activation_validate_payment($paymentMethod, $utr, $slipPath);
    if (!$pay['ok']) {
        return ['ok' => false, 'error' => $pay['error'], 'request_id' => null];
    }

    $pdo->prepare('
        INSERT INTO activation_requests
            (member_id, package_id, from_package_id, request_type, amount, payment_method, utr_reference, payment_slip, note, status)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ')->execute([
        $uid,
        $packageId,
        null,
        'activation',
        (float) $pkg['amount'],
        $pay['payment_method'],
        $pay['utr'],
        $pay['slip'],
        $note !== '' ? $note : null,
        'pending',
    ]);

    return ['ok' => true, 'error' => null, 'request_id' => (int) $pdo->lastInsertId()];
}

/**
 * Submit package upgrade request — payable amount is difference only.
 * @return array{ok:bool,error:?string,request_id:?int}
 */
function activation_submit_upgrade_request(
    PDO $pdo,
    array $user,
    int $packageId,
    string $paymentMethod,
    string $utr,
    string $note = '',
    ?string $slipPath = null
): array {
    if (!feature_enabled('feature_utr_activation_enabled')) {
        return ['ok' => false, 'error' => 'UTR activation is disabled for this client.', 'request_id' => null];
    }
    if (!feature_enabled('feature_package_enabled')) {
        return ['ok' => false, 'error' => 'Package activation is disabled for this client.', 'request_id' => null];
    }
    activation_ensure_requests_table($pdo);

    if (empty($user['package_id'])) {
        return ['ok' => false, 'error' => 'Activate your account first before upgrading.', 'request_id' => null];
    }
    if (($user['status'] ?? '') === 'blocked') {
        return ['ok' => false, 'error' => 'Your account is blocked.', 'request_id' => null];
    }

    $uid = (int) $user['id'];
    if (activation_pending_request($pdo, $uid)) {
        return ['ok' => false, 'error' => 'You already have a pending request.', 'request_id' => null];
    }

    $fromPkg = activation_member_package($pdo, $user);
    if (!$fromPkg) {
        return ['ok' => false, 'error' => 'Current package not found.', 'request_id' => null];
    }

    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$packageId]);
    $toPkg = $stmt->fetch();
    if (!$toPkg) {
        return ['ok' => false, 'error' => 'Selected package is not available.', 'request_id' => null];
    }

    if ((int) $toPkg['id'] === (int) $fromPkg['id']) {
        return ['ok' => false, 'error' => 'You are already on this package.', 'request_id' => null];
    }

    $diff = activation_diff_amount($fromPkg, $toPkg);
    if ($diff <= 0) {
        return ['ok' => false, 'error' => 'You can only upgrade to a higher package.', 'request_id' => null];
    }

    $pay = activation_validate_payment($paymentMethod, $utr, $slipPath);
    if (!$pay['ok']) {
        return ['ok' => false, 'error' => $pay['error'], 'request_id' => null];
    }

    $pdo->prepare('
        INSERT INTO activation_requests
            (member_id, package_id, from_package_id, request_type, amount, payment_method, utr_reference, payment_slip, note, status)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ')->execute([
        $uid,
        (int) $toPkg['id'],
        (int) $fromPkg['id'],
        'upgrade',
        $diff,
        $pay['payment_method'],
        $pay['utr'],
        $pay['slip'],
        $note !== '' ? $note : null,
        'pending',
    ]);

    return ['ok' => true, 'error' => null, 'request_id' => (int) $pdo->lastInsertId()];
}

/**
 * Apply package upgrade (difference BV / referral / level).
 * @return array{ok:bool,error:?string,package:?array,diff:float}
 */
function activation_apply_upgrade(PDO $pdo, array $user, int $newPackageId, ?int $requestId = null): array
{
    if (!feature_enabled('feature_package_enabled') && !feature_enabled('feature_tpin_enabled')) {
        return ['ok' => false, 'error' => 'Package upgrade is disabled for this client.', 'package' => null, 'diff' => 0.0];
    }
    if (empty($user['package_id'])) {
        return ['ok' => false, 'error' => 'Member is not activated yet.', 'package' => null, 'diff' => 0.0];
    }
    if (($user['status'] ?? '') === 'blocked') {
        return ['ok' => false, 'error' => 'Member account is blocked.', 'package' => null, 'diff' => 0.0];
    }

    $fromPkg = activation_member_package($pdo, $user);
    if (!$fromPkg) {
        return ['ok' => false, 'error' => 'Current package not found.', 'package' => null, 'diff' => 0.0];
    }

    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$newPackageId]);
    $toPkg = $stmt->fetch();
    if (!$toPkg) {
        return ['ok' => false, 'error' => 'Selected package is not available.', 'package' => null, 'diff' => 0.0];
    }

    $diff = activation_diff_amount($fromPkg, $toPkg);
    if ($diff <= 0) {
        return ['ok' => false, 'error' => 'Target package is not higher than the current plan.', 'package' => null, 'diff' => 0.0];
    }

    $uid = (int) $user['id'];
    $memberCode = (string) ($user['member_id'] ?? '');
    closing_ensure_tables($pdo);

    try {
        $pdo->beginTransaction();
        $upd = $pdo->prepare('UPDATE members SET package_id = ?, status = \'active\' WHERE id = ? AND package_id = ?');
        $upd->execute([$newPackageId, $uid, (int) $fromPkg['id']]);
        if ($upd->rowCount() < 1) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Package changed meanwhile. Refresh and try again.', 'package' => null, 'diff' => 0.0];
        }

        if (!empty($user['sponsor_id'])) {
            activation_pay_referral($pdo, (int) $user['sponsor_id'], $uid, $memberCode, $diff);
        }

        $eventKey = $requestId ? ('upgrade:' . $requestId) : ('upgrade:' . $uid . ':' . $newPackageId);
        closing_on_upgrade($pdo, $user, $fromPkg, $toPkg, $eventKey);

        $pdo->commit();
        return ['ok' => true, 'error' => null, 'package' => $toPkg, 'diff' => $diff];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Upgrade failed. Please try again.', 'package' => null, 'diff' => 0.0];
    }
}

/**
 * Admin approves pending activation / upgrade request.
 * @return array{ok:bool,message:string}
 */
function activation_approve_request(PDO $pdo, int $requestId, ?int $adminId = null, string $adminNote = ''): array
{
    activation_ensure_requests_table($pdo);
    $stmt = $pdo->prepare("SELECT * FROM activation_requests WHERE id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
    if (!$req) {
        return ['ok' => false, 'message' => 'Request not found or already processed.'];
    }

    $mStmt = $pdo->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
    $mStmt->execute([(int) $req['member_id']]);
    $member = $mStmt->fetch();
    if (!$member) {
        return ['ok' => false, 'message' => 'Member not found.'];
    }

    $type = (string) ($req['request_type'] ?? 'activation');
    if ($type === 'upgrade') {
        if (empty($member['package_id'])) {
            return ['ok' => false, 'message' => 'Member is not activated; cannot approve upgrade.'];
        }
        $result = activation_apply_upgrade($pdo, $member, (int) $req['package_id'], $requestId);
        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['error'] ?? 'Upgrade failed.'];
        }

        $pdo->prepare("
            UPDATE activation_requests
            SET status = 'approved', admin_note = ?, processed_by = ?, processed_at = NOW()
            WHERE id = ? AND status = 'pending'
        ")->execute([$adminNote !== '' ? $adminNote : null, $adminId, $requestId]);

        $diff = currency((float) ($result['diff'] ?? $req['amount']));
        $pkgName = (string) ($result['package']['name'] ?? 'package');
        return ['ok' => true, 'message' => "Upgrade approved to {$pkgName}. Difference credited: {$diff}."];
    }

    if (!empty($member['package_id'])) {
        $pdo->prepare("
            UPDATE activation_requests
            SET status = 'approved', admin_note = ?, processed_by = ?, processed_at = NOW()
            WHERE id = ? AND status = 'pending'
        ")->execute([
            $adminNote !== '' ? $adminNote : 'Member already had a package; request closed.',
            $adminId,
            $requestId,
        ]);
        return ['ok' => true, 'message' => 'Member was already activated. Request marked approved.'];
    }

    $result = activation_apply($pdo, $member, (int) $req['package_id']);
    if (!$result['ok']) {
        return ['ok' => false, 'message' => $result['error'] ?? 'Activation failed.'];
    }

    $pdo->prepare("
        UPDATE activation_requests
        SET status = 'approved', admin_note = ?, processed_by = ?, processed_at = NOW()
        WHERE id = ? AND status = 'pending'
    ")->execute([$adminNote !== '' ? $adminNote : null, $adminId, $requestId]);

    return ['ok' => true, 'message' => 'Activation approved and package assigned.'];
}

/**
 * Admin rejects pending activation request.
 * @return array{ok:bool,message:string}
 */
function activation_reject_request(PDO $pdo, int $requestId, ?int $adminId = null, string $adminNote = ''): array
{
    activation_ensure_requests_table($pdo);
    $upd = $pdo->prepare("
        UPDATE activation_requests
        SET status = 'rejected', admin_note = ?, processed_by = ?, processed_at = NOW()
        WHERE id = ? AND status = 'pending'
    ");
    $upd->execute([$adminNote !== '' ? $adminNote : 'Rejected by admin', $adminId, $requestId]);
    if ($upd->rowCount() < 1) {
        return ['ok' => false, 'message' => 'Request not found or already processed.'];
    }
    return ['ok' => true, 'message' => 'Request rejected.'];
}

function activation_pending_count(PDO $pdo): int
{
    try {
        activation_ensure_requests_table($pdo);
        return (int) $pdo->query("SELECT COUNT(*) FROM activation_requests WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
