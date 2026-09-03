<?php
/**
 * Topup Wallet fund requests (user Add Money → admin approve).
 */

require_once __DIR__ . '/wallet.php';

function wallet_topup_payment_modes(): array
{
    return [
        'Online' => 'Online',
        'UPI' => 'UPI',
        'Cash' => 'Cash',
    ];
}

function wallet_topup_ensure_requests_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    wallet_ensure_schema($pdo);

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS wallet_topup_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                payment_mode VARCHAR(30) NOT NULL DEFAULT 'UPI',
                utr_reference VARCHAR(100) NOT NULL DEFAULT '',
                payment_proof VARCHAR(255) NULL,
                note TEXT NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                admin_note TEXT NULL,
                processed_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME NULL,
                INDEX idx_wtr_member (member_id),
                INDEX idx_wtr_status (status),
                INDEX idx_wtr_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }

    $done = true;
}

/**
 * @return array{ok:bool,error:?string,path:?string}
 */
function wallet_topup_store_proof(array $file, int $memberId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Please upload payment proof image.', 'path' => null];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Proof upload failed. Please try again.', 'path' => null];
    }
    if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Payment proof must be under 3MB.', 'path' => null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG or WebP images are allowed.', 'path' => null];
    }
    if (@getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'Invalid image file.', 'path' => null];
    }

    $uploadDir = BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'wallet-topup';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'Could not create upload folder.', 'path' => null];
    }

    $name = 'topup_m' . $memberId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = $uploadDir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Could not save payment proof.', 'path' => null];
    }

    return ['ok' => true, 'error' => null, 'path' => 'uploads/wallet-topup/' . $name];
}

function wallet_topup_proof_url(?string $path): ?string
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

/**
 * @return array{ok:bool,error:?string,id:?int}
 */
function wallet_topup_submit_request(
    PDO $pdo,
    int $memberId,
    float $amount,
    string $paymentMode,
    string $utr,
    ?string $proofPath,
    ?string $note = null
): array {
    if (!feature_enabled('feature_wallet_topup_enabled')) {
        return ['ok' => false, 'error' => 'Wallet topup is disabled for this client.', 'id' => null];
    }
    wallet_topup_ensure_requests_table($pdo);

    $amount = round($amount, 2);
    $modes = wallet_topup_payment_modes();
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Enter a valid amount.', 'id' => null];
    }
    if ($amount < 1) {
        return ['ok' => false, 'error' => 'Minimum topup amount is 1.', 'id' => null];
    }
    if (!isset($modes[$paymentMode])) {
        return ['ok' => false, 'error' => 'Select a valid payment mode.', 'id' => null];
    }

    $utr = trim($utr);
    $isCash = ($paymentMode === 'Cash');
    if (!$isCash && $utr === '') {
        return ['ok' => false, 'error' => 'Enter UTR / transaction number.', 'id' => null];
    }
    if ($utr === '') {
        $utr = 'CASH-' . date('YmdHis');
    }
    if (strlen($utr) < 4) {
        return ['ok' => false, 'error' => 'UTR / reference looks too short.', 'id' => null];
    }
    if (!$isCash && ($proofPath === null || $proofPath === '')) {
        return ['ok' => false, 'error' => 'Upload payment proof image.', 'id' => null];
    }

    // Prevent duplicate open UTR from same member
    try {
        $dup = $pdo->prepare("
            SELECT id FROM wallet_topup_requests
            WHERE member_id = ? AND utr_reference = ? AND status = 'pending'
            LIMIT 1
        ");
        $dup->execute([$memberId, $utr]);
        if ($dup->fetch()) {
            return ['ok' => false, 'error' => 'A pending request with this UTR already exists.', 'id' => null];
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $pdo->prepare("
            INSERT INTO wallet_topup_requests
                (member_id, amount, payment_mode, utr_reference, payment_proof, note, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ")->execute([
            $memberId,
            $amount,
            $paymentMode,
            $utr,
            $proofPath,
            $note !== null && $note !== '' ? $note : null,
        ]);
        return ['ok' => true, 'error' => null, 'id' => (int) $pdo->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not submit request. Please try again.', 'id' => null];
    }
}

/**
 * @return list<array>
 */
function wallet_topup_member_requests(PDO $pdo, int $memberId, int $limit = 50): array
{
    wallet_topup_ensure_requests_table($pdo);
    $stmt = $pdo->prepare('SELECT * FROM wallet_topup_requests WHERE member_id = ? ORDER BY id DESC LIMIT ' . (int) $limit);
    $stmt->execute([$memberId]);
    return $stmt->fetchAll() ?: [];
}

function wallet_topup_pending_count(PDO $pdo): int
{
    wallet_topup_ensure_requests_table($pdo);
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM wallet_topup_requests WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function wallet_topup_member_pending_sum(PDO $pdo, int $memberId): float
{
    wallet_topup_ensure_requests_table($pdo);
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_topup_requests WHERE member_id = ? AND status = 'pending'");
        $stmt->execute([$memberId]);
        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * @return array{ok:bool,message:string}
 */
function wallet_topup_approve_request(PDO $pdo, int $requestId, int $adminId, ?string $adminNote = null): array
{
    wallet_topup_ensure_requests_table($pdo);

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM wallet_topup_requests WHERE id = ? AND status = 'pending' FOR UPDATE");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();
        if (!$req) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Request not found or already processed.'];
        }

        $amount = (float) $req['amount'];
        $memberId = (int) $req['member_id'];
        $credit = wallet_credit(
            $pdo,
            $memberId,
            'topup',
            $amount,
            'topup_request',
            $requestId,
            'Topup request #' . $requestId . ' approved',
            $adminId > 0 ? $adminId : null
        );
        if (!$credit['ok']) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => $credit['error'] ?: 'Could not credit Topup Wallet.'];
        }

        $pdo->prepare("
            UPDATE wallet_topup_requests
            SET status = 'approved', admin_note = ?, processed_by = ?, processed_at = NOW()
            WHERE id = ? AND status = 'pending'
        ")->execute([$adminNote, $adminId > 0 ? $adminId : null, $requestId]);

        $pdo->commit();
        return ['ok' => true, 'message' => 'Approved. ' . strip_tags(currency($amount)) . ' credited to Topup Wallet.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Approval failed. Please try again.'];
    }
}

/**
 * @return array{ok:bool,message:string}
 */
function wallet_topup_reject_request(PDO $pdo, int $requestId, int $adminId, ?string $adminNote = null): array
{
    wallet_topup_ensure_requests_table($pdo);
    $note = ($adminNote !== null && trim($adminNote) !== '') ? trim($adminNote) : 'Rejected by admin';

    $upd = $pdo->prepare("
        UPDATE wallet_topup_requests
        SET status = 'rejected', admin_note = ?, processed_by = ?, processed_at = NOW()
        WHERE id = ? AND status = 'pending'
    ");
    $upd->execute([$note, $adminId > 0 ? $adminId : null, $requestId]);
    if ($upd->rowCount() < 1) {
        return ['ok' => false, 'message' => 'Request not found or already processed.'];
    }
    return ['ok' => true, 'message' => 'Topup request rejected.'];
}

/**
 * Pay package amount from Topup Wallet and activate/upgrade a member (self or sponsored).
 * Payer is the logged-in sponsor/user whose topup wallet is debited.
 *
 * @return array{ok:bool,error:?string,package:?array,mode:?string}
 */
function wallet_topup_pay_and_activate(PDO $pdo, array $payer, array $target, int $packageId): array
{
    require_once __DIR__ . '/activation.php';

    if (!feature_enabled('feature_wallet_topup_enabled')) {
        return ['ok' => false, 'error' => 'Wallet topup activation is disabled for this client.', 'package' => null, 'mode' => null];
    }
    if (!feature_enabled('feature_package_enabled')) {
        return ['ok' => false, 'error' => 'Package activation is disabled for this client.', 'package' => null, 'mode' => null];
    }

    $payerId = (int) ($payer['id'] ?? 0);
    $targetId = (int) ($target['id'] ?? 0);
    if ($payerId <= 0 || $targetId <= 0) {
        return ['ok' => false, 'error' => 'Invalid member.', 'package' => null, 'mode' => null];
    }
    if (($payer['status'] ?? '') === 'blocked' || ($target['status'] ?? '') === 'blocked') {
        return ['ok' => false, 'error' => 'Account is blocked.', 'package' => null, 'mode' => null];
    }

    // Self OR direct sponsor may pay
    $isSelf = ($payerId === $targetId);
    $isSponsor = ((int) ($target['sponsor_id'] ?? 0) === $payerId);
    if (!$isSelf && !$isSponsor) {
        return ['ok' => false, 'error' => 'You can only activate yourself or members you sponsored.', 'package' => null, 'mode' => null];
    }

    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$packageId]);
    $pkg = $stmt->fetch();
    if (!$pkg) {
        return ['ok' => false, 'error' => 'Selected package is not available.', 'package' => null, 'mode' => null];
    }

    $isUpgrade = !empty($target['package_id']);
    $payAmount = (float) $pkg['amount'];
    $mode = 'activation';

    if ($isUpgrade) {
        if ($isSelf) {
            $fromPkg = activation_member_package($pdo, $target);
            if (!$fromPkg) {
                return ['ok' => false, 'error' => 'Current package not found.', 'package' => null, 'mode' => null];
            }
            if ((float) $pkg['amount'] <= (float) $fromPkg['amount'] + 0.009) {
                return ['ok' => false, 'error' => 'Select a higher package to upgrade.', 'package' => null, 'mode' => null];
            }
            $payAmount = activation_diff_amount($fromPkg, $pkg);
            $mode = 'upgrade';
        } else {
            return ['ok' => false, 'error' => 'This member is already activated.', 'package' => null, 'mode' => null];
        }
    }

    if ($payAmount <= 0) {
        return ['ok' => false, 'error' => 'Invalid payable amount.', 'package' => null, 'mode' => null];
    }

    $bal = wallet_balance($pdo, $payerId, 'topup');
    if ($bal + 0.00001 < $payAmount) {
        return [
            'ok' => false,
            'error' => 'Insufficient Topup Wallet balance. Need ' . strip_tags(currency($payAmount)) . ', available ' . strip_tags(currency($bal)) . '.',
            'package' => null,
            'mode' => null,
        ];
    }

    $debitNote = ($mode === 'upgrade' ? 'Upgrade' : 'Activate') . ' ' . ($target['member_id'] ?? '') . ' via Topup Wallet';
    $debit = wallet_debit($pdo, $payerId, 'topup', $payAmount, 'activation', $packageId, $debitNote);
    if (!$debit['ok']) {
        return ['ok' => false, 'error' => $debit['error'] ?: 'Could not debit Topup Wallet.', 'package' => null, 'mode' => null];
    }

    try {
        if ($mode === 'upgrade') {
            $result = activation_apply_upgrade($pdo, $target, $packageId, null);
        } else {
            $result = activation_apply($pdo, $target, $packageId);
        }
    } catch (Throwable $e) {
        wallet_credit($pdo, $payerId, 'topup', $payAmount, 'activation_refund', $packageId, 'Refund: activation failed');
        return ['ok' => false, 'error' => 'Activation failed. Amount refunded to Topup Wallet.', 'package' => null, 'mode' => null];
    }

    if (!$result['ok']) {
        wallet_credit(
            $pdo,
            $payerId,
            'topup',
            $payAmount,
            'activation_refund',
            $packageId,
            'Refund: ' . ($result['error'] ?? 'activation failed')
        );
        return ['ok' => false, 'error' => $result['error'] ?? 'Activation failed.', 'package' => null, 'mode' => null];
    }

    return [
        'ok' => true,
        'error' => null,
        'package' => $result['package'] ?? $pkg,
        'mode' => $mode,
    ];
}
