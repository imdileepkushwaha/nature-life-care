<?php
/**
 * Topup PIN (T-Pin / E-Pin) — Type A package pins.
 * Admin generates; members hold, transfer, and use for instant activate/upgrade.
 */

require_once __DIR__ . '/activation.php';

function tpin_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS topup_pins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pin_code VARCHAR(32) NOT NULL,
            package_id INT NOT NULL,
            status ENUM('unused','used','blocked') NOT NULL DEFAULT 'unused',
            generated_by INT NULL,
            assigned_to INT NULL,
            used_by INT NULL,
            used_at DATETIME NULL,
            blocked_at DATETIME NULL,
            blocked_by INT NULL,
            batch_code VARCHAR(40) NULL,
            note VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tpin_code (pin_code),
            INDEX idx_tpin_status (status),
            INDEX idx_tpin_assigned (assigned_to),
            INDEX idx_tpin_package (package_id),
            INDEX idx_tpin_batch (batch_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS topup_pin_transfers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pin_id INT NOT NULL,
            from_member_id INT NOT NULL,
            to_member_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tpin_tr_pin (pin_id),
            INDEX idx_tpin_tr_from (from_member_id),
            INDEX idx_tpin_tr_to (to_member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $done = true;
}

function tpin_normalize_code(string $code): string
{
    $code = strtoupper(trim($code));
    $code = preg_replace('/[\s\-]+/', '', $code) ?? '';
    return $code;
}

function tpin_format_code(string $code): string
{
    $code = tpin_normalize_code($code);
    if (strlen($code) === 12) {
        return substr($code, 0, 4) . '-' . substr($code, 4, 4) . '-' . substr($code, 8, 4);
    }
    return $code;
}

function tpin_generate_code(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $raw = '';
        for ($i = 0; $i < 12; $i++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $stmt = $pdo->prepare('SELECT id FROM topup_pins WHERE pin_code = ? LIMIT 1');
        $stmt->execute([$raw]);
        if (!$stmt->fetch()) {
            return $raw;
        }
    }
    throw new RuntimeException('Could not generate unique T-Pin.');
}

/**
 * @return array{ok:bool,error:?string,created:int,batch:?string,pins:array}
 */
function tpin_generate(
    PDO $pdo,
    int $packageId,
    int $qty,
    ?int $assignToMemberId,
    ?int $adminId,
    string $note = ''
): array {
    if (!feature_enabled('feature_tpin_enabled')) {
        return ['ok' => false, 'error' => 'T-PIN module is disabled for this client.', 'created' => 0, 'batch' => null, 'pins' => []];
    }
    tpin_ensure_tables($pdo);

    if ($qty < 1 || $qty > 500) {
        return ['ok' => false, 'error' => 'Quantity must be between 1 and 500.', 'created' => 0, 'batch' => null, 'pins' => []];
    }

    $pkg = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND status = 'active' LIMIT 1");
    $pkg->execute([$packageId]);
    $package = $pkg->fetch();
    if (!$package) {
        return ['ok' => false, 'error' => 'Selected package is not available.', 'created' => 0, 'batch' => null, 'pins' => []];
    }

    if ($assignToMemberId !== null && $assignToMemberId > 0) {
        $m = $pdo->prepare("SELECT id FROM members WHERE id = ? AND status != 'blocked' LIMIT 1");
        $m->execute([$assignToMemberId]);
        if (!$m->fetch()) {
            return ['ok' => false, 'error' => 'Assign-to member not found or blocked.', 'created' => 0, 'batch' => null, 'pins' => []];
        }
    } else {
        $assignToMemberId = null;
    }

    $batch = 'B' . date('ymdHis') . strtoupper(bin2hex(random_bytes(2)));
    $created = [];

    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare('
            INSERT INTO topup_pins
                (pin_code, package_id, status, generated_by, assigned_to, batch_code, note)
            VALUES (?, ?, \'unused\', ?, ?, ?, ?)
        ');
        for ($i = 0; $i < $qty; $i++) {
            $code = tpin_generate_code($pdo);
            $ins->execute([
                $code,
                $packageId,
                $adminId,
                $assignToMemberId,
                $batch,
                $note !== '' ? $note : null,
            ]);
            $created[] = [
                'id' => (int) $pdo->lastInsertId(),
                'pin_code' => $code,
                'pin_display' => tpin_format_code($code),
            ];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not generate T-Pins. Please try again.', 'created' => 0, 'batch' => null, 'pins' => []];
    }

    return [
        'ok' => true,
        'error' => null,
        'created' => count($created),
        'batch' => $batch,
        'pins' => $created,
    ];
}

/**
 * @return array{ok:bool,error:?string}
 */
function tpin_block(PDO $pdo, int $pinId, ?int $adminId): array
{
    tpin_ensure_tables($pdo);
    $upd = $pdo->prepare("
        UPDATE topup_pins
        SET status = 'blocked', blocked_at = NOW(), blocked_by = ?
        WHERE id = ? AND status = 'unused'
    ");
    $upd->execute([$adminId, $pinId]);
    if ($upd->rowCount() < 1) {
        return ['ok' => false, 'error' => 'Pin not found or cannot be blocked (already used/blocked).'];
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Admin can assign only from company stock (unassigned).
 * Already-assigned pins move only via member transfer — not admin re-assign.
 * @return array{ok:bool,error:?string}
 */
function tpin_assign(PDO $pdo, int $pinId, int $memberId): array
{
    tpin_ensure_tables($pdo);
    $m = $pdo->prepare("SELECT id FROM members WHERE id = ? AND status != 'blocked' LIMIT 1");
    $m->execute([$memberId]);
    if (!$m->fetch()) {
        return ['ok' => false, 'error' => 'Member not found or blocked.'];
    }

    $pin = $pdo->prepare("SELECT id, status, assigned_to FROM topup_pins WHERE id = ? LIMIT 1");
    $pin->execute([$pinId]);
    $row = $pin->fetch();
    if (!$row) {
        return ['ok' => false, 'error' => 'Pin not found.'];
    }
    if (($row['status'] ?? '') !== 'unused') {
        return ['ok' => false, 'error' => 'Only unused pins can be assigned.'];
    }
    if (!empty($row['assigned_to'])) {
        return ['ok' => false, 'error' => 'Pin already assigned. Admin cannot re-assign — member can transfer from their wallet.'];
    }

    $upd = $pdo->prepare("
        UPDATE topup_pins SET assigned_to = ?
        WHERE id = ? AND status = 'unused' AND assigned_to IS NULL
    ");
    $upd->execute([$memberId, $pinId]);
    if ($upd->rowCount() < 1) {
        return ['ok' => false, 'error' => 'Pin was just assigned by someone else.'];
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Find member by member_id only (exact match).
 */
function tpin_find_member_by_code(PDO $pdo, string $memberCode): ?array
{
    $memberCode = trim($memberCode);
    if ($memberCode === '') {
        return null;
    }
    $stmt = $pdo->prepare('
        SELECT id, member_id, username, full_name, email, status, package_id
        FROM members
        WHERE member_id = ?
        LIMIT 1
    ');
    $stmt->execute([$memberCode]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Find member by member_id / username / email.
 */
function tpin_find_member(PDO $pdo, string $login): ?array
{
    $login = trim($login);
    if ($login === '') {
        return null;
    }
    $stmt = $pdo->prepare('
        SELECT id, member_id, username, full_name, email, status, package_id
        FROM members
        WHERE member_id = ? OR username = ? OR email = ?
        LIMIT 1
    ');
    $stmt->execute([$login, $login, $login]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * @return array{ok:bool,error:?string}
 */
function tpin_transfer(PDO $pdo, array $fromUser, string $pinCode, string $toLogin): array
{
    if (!feature_enabled('feature_tpin_enabled')) {
        return ['ok' => false, 'error' => 'T-PIN module is disabled for this client.'];
    }
    tpin_ensure_tables($pdo);
    $fromId = (int) ($fromUser['id'] ?? 0);
    if ($fromId <= 0) {
        return ['ok' => false, 'error' => 'Invalid sender.'];
    }
    if (($fromUser['status'] ?? '') === 'blocked') {
        return ['ok' => false, 'error' => 'Your account is blocked.'];
    }

    $code = tpin_normalize_code($pinCode);
    if ($code === '') {
        return ['ok' => false, 'error' => 'Enter a T-Pin code.'];
    }

    $to = tpin_find_member($pdo, $toLogin);
    if (!$to) {
        return ['ok' => false, 'error' => 'Receiver member not found.'];
    }
    if (($to['status'] ?? '') === 'blocked') {
        return ['ok' => false, 'error' => 'Receiver account is blocked.'];
    }
    $toId = (int) $to['id'];
    if ($toId === $fromId) {
        return ['ok' => false, 'error' => 'You cannot transfer a pin to yourself.'];
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            SELECT * FROM topup_pins
            WHERE pin_code = ? AND status = 'unused'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$code]);
        $pin = $stmt->fetch();
        if (!$pin) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'T-Pin not found or already used/blocked.'];
        }
        if ((int) ($pin['assigned_to'] ?? 0) !== $fromId) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'This T-Pin is not in your wallet.'];
        }

        $pdo->prepare('UPDATE topup_pins SET assigned_to = ? WHERE id = ?')
            ->execute([$toId, (int) $pin['id']]);
        $pdo->prepare('
            INSERT INTO topup_pin_transfers (pin_id, from_member_id, to_member_id)
            VALUES (?, ?, ?)
        ')->execute([(int) $pin['id'], $fromId, $toId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Transfer failed. Please try again.'];
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Load pin usable by member for given package (or any if packageId = 0 check later).
 */
function tpin_find_usable(PDO $pdo, string $pinCode, int $memberId): ?array
{
    tpin_ensure_tables($pdo);
    $code = tpin_normalize_code($pinCode);
    if ($code === '') {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT tp.*, p.name AS package_name, p.amount AS package_amount, p.bv AS package_bv, p.status AS package_status
        FROM topup_pins tp
        JOIN packages p ON p.id = tp.package_id
        WHERE tp.pin_code = ? AND tp.status = 'unused'
        LIMIT 1
    ");
    $stmt->execute([$code]);
    $pin = $stmt->fetch();
    if (!$pin) {
        return null;
    }
    $assigned = (int) ($pin['assigned_to'] ?? 0);
    if ($assigned > 0 && $assigned !== $memberId) {
        return null;
    }
    if (($pin['package_status'] ?? '') !== 'active') {
        return null;
    }
    return $pin;
}

/**
 * Activate / upgrade $target using a T-Pin from $owner's wallet.
 * Pin must be unused and assigned to $owner (not company stock).
 * @return array{ok:bool,error:?string,mode:?string,package:?array}
 */
function tpin_redeem_for_target(PDO $pdo, array $owner, array $target, string $pinCode): array
{
    require_once __DIR__ . '/activation.php';
    if (!feature_enabled('feature_tpin_enabled')) {
        return ['ok' => false, 'error' => 'T-PIN module is disabled for this client.', 'mode' => null, 'package' => null];
    }
    tpin_ensure_tables($pdo);

    $ownerId = (int) ($owner['id'] ?? 0);
    $targetId = (int) ($target['id'] ?? 0);
    if ($ownerId < 1 || $targetId < 1) {
        return ['ok' => false, 'error' => 'Invalid members.', 'mode' => null, 'package' => null];
    }
    if (($owner['status'] ?? '') === 'blocked' || ($target['status'] ?? '') === 'blocked') {
        return ['ok' => false, 'error' => 'Account is blocked.', 'mode' => null, 'package' => null];
    }
    if (!empty($target['package_id'])) {
        return ['ok' => false, 'error' => 'Member is already activated.', 'mode' => null, 'package' => null];
    }

    $pending = activation_pending_request($pdo, $targetId);
    if ($pending) {
        return [
            'ok' => false,
            'error' => 'This member already has a pending activation request.',
            'mode' => null,
            'package' => null,
        ];
    }

    $code = tpin_normalize_code($pinCode);
    if ($code === '') {
        return ['ok' => false, 'error' => 'Enter your T-Pin.', 'mode' => null, 'package' => null];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT tp.*, p.name AS package_name, p.amount, p.bv, p.status AS package_status, p.validity_days, p.description
            FROM topup_pins tp
            JOIN packages p ON p.id = tp.package_id
            WHERE tp.pin_code = ? AND tp.status = 'unused'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$code]);
        $pin = $stmt->fetch();
        if (!$pin) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Invalid, used, or blocked T-Pin.', 'mode' => null, 'package' => null];
        }

        $assigned = (int) ($pin['assigned_to'] ?? 0);
        if ($assigned !== $ownerId) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'This T-Pin is not in your pin wallet.', 'mode' => null, 'package' => null];
        }
        if (($pin['package_status'] ?? '') !== 'active') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Package linked to this T-Pin is inactive.', 'mode' => null, 'package' => null];
        }

        $packageId = (int) $pin['package_id'];
        $mark = $pdo->prepare("
            UPDATE topup_pins
            SET status = 'used', used_by = ?, used_at = NOW(), assigned_to = ?
            WHERE id = ? AND status = 'unused'
        ");
        $mark->execute([$targetId, $ownerId, (int) $pin['id']]);
        if ($mark->rowCount() < 1) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'T-Pin was just used by someone else.', 'mode' => null, 'package' => null];
        }

        $pdo->commit();

        $result = activation_apply($pdo, $target, $packageId);
        if (!$result['ok']) {
            tpin_restore_unused($pdo, (int) $pin['id'], $targetId, $ownerId);
            return [
                'ok' => false,
                'error' => $result['error'] ?? 'Activation failed.',
                'mode' => 'activation',
                'package' => null,
            ];
        }
        return ['ok' => true, 'error' => null, 'mode' => 'activation', 'package' => $result['package']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not activate with T-Pin. Try again.', 'mode' => null, 'package' => null];
    }
}

/**
 * Instant activate / upgrade using Type A T-Pin.
 * @return array{ok:bool,error:?string,mode:?string,package:?array}
 */
function tpin_redeem(PDO $pdo, array $user, string $pinCode, ?int $expectedPackageId = null): array
{
    if (!feature_enabled('feature_tpin_enabled')) {
        return ['ok' => false, 'error' => 'T-PIN module is disabled for this client.', 'mode' => null, 'package' => null];
    }
    tpin_ensure_tables($pdo);

    if (($user['status'] ?? '') === 'blocked') {
        return ['ok' => false, 'error' => 'Your account is blocked.', 'mode' => null, 'package' => null];
    }

    $pending = activation_pending_request($pdo, (int) $user['id']);
    if ($pending) {
        return [
            'ok' => false,
            'error' => 'You already have a pending activation/upgrade request. Wait for admin or cancel is not available — contact support.',
            'mode' => null,
            'package' => null,
        ];
    }

    $uid = (int) $user['id'];
    $code = tpin_normalize_code($pinCode);
    if ($code === '') {
        return ['ok' => false, 'error' => 'Enter your T-Pin.', 'mode' => null, 'package' => null];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT tp.*, p.name AS package_name, p.amount, p.bv, p.status AS package_status, p.validity_days, p.description
            FROM topup_pins tp
            JOIN packages p ON p.id = tp.package_id
            WHERE tp.pin_code = ? AND tp.status = 'unused'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$code]);
        $pin = $stmt->fetch();
        if (!$pin) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Invalid, used, or blocked T-Pin.', 'mode' => null, 'package' => null];
        }

        $assigned = (int) ($pin['assigned_to'] ?? 0);
        if ($assigned > 0 && $assigned !== $uid) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'This T-Pin belongs to another member.', 'mode' => null, 'package' => null];
        }
        if (($pin['package_status'] ?? '') !== 'active') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Package linked to this T-Pin is inactive.', 'mode' => null, 'package' => null];
        }

        $packageId = (int) $pin['package_id'];
        if ($expectedPackageId !== null && $expectedPackageId > 0 && $expectedPackageId !== $packageId) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'error' => 'Selected package does not match this T-Pin (' . ($pin['package_name'] ?? 'package') . ').',
                'mode' => null,
                'package' => null,
            ];
        }

        $pkgRow = [
            'id' => $packageId,
            'name' => $pin['package_name'],
            'amount' => $pin['amount'],
            'bv' => $pin['bv'],
            'validity_days' => $pin['validity_days'],
            'description' => $pin['description'] ?? '',
            'status' => 'active',
        ];

        $isUpgrade = !empty($user['package_id']);
        if ($isUpgrade) {
            $fromPkg = activation_member_package($pdo, $user);
            if (!$fromPkg) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Current package not found.', 'mode' => null, 'package' => null];
            }
            if ((float) $pkgRow['amount'] <= (float) $fromPkg['amount'] + 0.009) {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'error' => 'T-Pin package must be higher than your current plan for upgrade.',
                    'mode' => null,
                    'package' => null,
                ];
            }

            // Mark pin used first (still in outer transaction), then apply upgrade in nested-safe way
            $mark = $pdo->prepare("
                UPDATE topup_pins
                SET status = 'used', used_by = ?, used_at = NOW(), assigned_to = ?
                WHERE id = ? AND status = 'unused'
            ");
            $mark->execute([$uid, $uid, (int) $pin['id']]);
            if ($mark->rowCount() < 1) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'T-Pin was just used by someone else.', 'mode' => null, 'package' => null];
            }

            $pdo->commit();

            $result = activation_apply_upgrade($pdo, $user, $packageId, null);
            if (!$result['ok']) {
                tpin_restore_unused($pdo, (int) $pin['id'], $uid, $assigned > 0 ? $assigned : null);
                return [
                    'ok' => false,
                    'error' => $result['error'] ?? 'Upgrade failed.',
                    'mode' => 'upgrade',
                    'package' => null,
                ];
            }
            return ['ok' => true, 'error' => null, 'mode' => 'upgrade', 'package' => $result['package']];
        }

        if (!empty($user['package_id'])) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Account already activated.', 'mode' => null, 'package' => null];
        }

        $mark = $pdo->prepare("
            UPDATE topup_pins
            SET status = 'used', used_by = ?, used_at = NOW(), assigned_to = ?
            WHERE id = ? AND status = 'unused'
        ");
        $mark->execute([$uid, $uid, (int) $pin['id']]);
        if ($mark->rowCount() < 1) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'T-Pin was just used by someone else.', 'mode' => null, 'package' => null];
        }

        $pdo->commit();

        $result = activation_apply($pdo, $user, $packageId);
        if (!$result['ok']) {
            tpin_restore_unused($pdo, (int) $pin['id'], $uid, $assigned > 0 ? $assigned : null);
            return [
                'ok' => false,
                'error' => $result['error'] ?? 'Activation failed.',
                'mode' => 'activation',
                'package' => null,
            ];
        }
        return ['ok' => true, 'error' => null, 'mode' => 'activation', 'package' => $result['package']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not redeem T-Pin. Please try again.', 'mode' => null, 'package' => null];
    }
}

/** Undo pin consumption if activation/upgrade failed after mark. */
function tpin_restore_unused(PDO $pdo, int $pinId, int $usedBy, ?int $previousAssigned): void
{
    try {
        $pdo->prepare("
            UPDATE topup_pins
            SET status = 'unused', used_by = NULL, used_at = NULL, assigned_to = ?
            WHERE id = ? AND status = 'used' AND used_by = ?
        ")->execute([$previousAssigned, $pinId, $usedBy]);
    } catch (Throwable $e) {
        // ignore — admin can unblock manually if needed
    }
}

/**
 * Unused pin counts by package for a member wallet.
 * @return list<array{package_id:int,name:string,amount:float,available:int}>
 */
function tpin_member_package_availability(PDO $pdo, int $memberId): array
{
    tpin_ensure_tables($pdo);
    if ($memberId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT p.id AS package_id, p.name, p.amount, COUNT(tp.id) AS available
        FROM topup_pins tp
        JOIN packages p ON p.id = tp.package_id
        WHERE tp.assigned_to = ? AND tp.status = 'unused'
        GROUP BY p.id, p.name, p.amount
        HAVING available > 0
        ORDER BY p.amount ASC
    ");
    $stmt->execute([$memberId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            'package_id' => (int) $r['package_id'],
            'name' => (string) $r['name'],
            'amount' => (float) $r['amount'],
            'available' => (int) $r['available'],
        ];
    }
    return $rows;
}

/**
 * Transfer N unused pins of a package from one member to another.
 * @return array{ok:bool,error:?string,transferred:int}
 */
function tpin_admin_bulk_transfer(
    PDO $pdo,
    int $fromMemberId,
    int $toMemberId,
    int $packageId,
    int $qty,
    ?int $adminId = null
): array {
    tpin_ensure_tables($pdo);

    if ($qty < 1 || $qty > 500) {
        return ['ok' => false, 'error' => 'Quantity must be between 1 and 500.', 'transferred' => 0];
    }
    if ($fromMemberId <= 0 || $toMemberId <= 0) {
        return ['ok' => false, 'error' => 'From and To members are required.', 'transferred' => 0];
    }
    if ($fromMemberId === $toMemberId) {
        return ['ok' => false, 'error' => 'Cannot transfer to the same member.', 'transferred' => 0];
    }

    $from = $pdo->prepare("SELECT id, member_id, status FROM members WHERE id = ? LIMIT 1");
    $from->execute([$fromMemberId]);
    $fromRow = $from->fetch();
    if (!$fromRow || ($fromRow['status'] ?? '') === 'blocked') {
        return ['ok' => false, 'error' => 'From member not found or blocked.', 'transferred' => 0];
    }

    $to = $pdo->prepare("SELECT id, member_id, status FROM members WHERE id = ? LIMIT 1");
    $to->execute([$toMemberId]);
    $toRow = $to->fetch();
    if (!$toRow || ($toRow['status'] ?? '') === 'blocked') {
        return ['ok' => false, 'error' => 'Receiver member not found or blocked.', 'transferred' => 0];
    }

    $pkg = $pdo->prepare("SELECT id FROM packages WHERE id = ? LIMIT 1");
    $pkg->execute([$packageId]);
    if (!$pkg->fetch()) {
        return ['ok' => false, 'error' => 'Invalid package / amount selected.', 'transferred' => 0];
    }

    try {
        $pdo->beginTransaction();
        $sel = $pdo->prepare("
            SELECT id FROM topup_pins
            WHERE assigned_to = ? AND package_id = ? AND status = 'unused'
            ORDER BY id ASC
            LIMIT {$qty}
            FOR UPDATE
        ");
        $sel->execute([$fromMemberId, $packageId]);
        $pins = $sel->fetchAll(PDO::FETCH_COLUMN);
        if (count($pins) < $qty) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'error' => 'Not enough available T-Pins. Available: ' . count($pins) . ', requested: ' . $qty . '.',
                'transferred' => 0,
            ];
        }

        $upd = $pdo->prepare("
            UPDATE topup_pins SET assigned_to = ?
            WHERE id = ? AND assigned_to = ? AND status = 'unused'
        ");
        $ins = $pdo->prepare('
            INSERT INTO topup_pin_transfers (pin_id, from_member_id, to_member_id)
            VALUES (?, ?, ?)
        ');
        $done = 0;
        foreach ($pins as $pinId) {
            $upd->execute([$toMemberId, (int) $pinId, $fromMemberId]);
            if ($upd->rowCount() < 1) {
                continue;
            }
            $ins->execute([(int) $pinId, $fromMemberId, $toMemberId]);
            $done++;
        }
        if ($done < $qty) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Could not transfer all pins. Please try again.', 'transferred' => 0];
        }
        $pdo->commit();
        return ['ok' => true, 'error' => null, 'transferred' => $done];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Transfer failed. Please try again.', 'transferred' => 0];
    }
}

/**
 * Admin move: company stock → member, or member → member (unused pins only).
 * @return array{ok:bool,error:?string,mode:?string}
 */
function tpin_admin_transfer(PDO $pdo, int $pinId, int $toMemberId, ?int $adminId = null): array
{
    tpin_ensure_tables($pdo);

    $m = $pdo->prepare("SELECT id, member_id, full_name, status FROM members WHERE id = ? LIMIT 1");
    $m->execute([$toMemberId]);
    $to = $m->fetch();
    if (!$to) {
        return ['ok' => false, 'error' => 'Receiver member not found.', 'mode' => null];
    }
    if (($to['status'] ?? '') === 'blocked') {
        return ['ok' => false, 'error' => 'Receiver account is blocked.', 'mode' => null];
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            SELECT * FROM topup_pins
            WHERE id = ? AND status = 'unused'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$pinId]);
        $pin = $stmt->fetch();
        if (!$pin) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'T-Pin not found or already used/blocked.', 'mode' => null];
        }

        $fromId = (int) ($pin['assigned_to'] ?? 0);
        if ($fromId === $toMemberId) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Pin is already with this member.', 'mode' => null];
        }

        $upd = $pdo->prepare("
            UPDATE topup_pins SET assigned_to = ?
            WHERE id = ? AND status = 'unused'
        ");
        $upd->execute([$toMemberId, $pinId]);
        if ($upd->rowCount() < 1) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Could not transfer this T-Pin.', 'mode' => null];
        }

        if ($fromId > 0) {
            $pdo->prepare('
                INSERT INTO topup_pin_transfers (pin_id, from_member_id, to_member_id)
                VALUES (?, ?, ?)
            ')->execute([$pinId, $fromId, $toMemberId]);
            $mode = 'member';
        } else {
            $mode = 'company';
        }

        $pdo->commit();
        return ['ok' => true, 'error' => null, 'mode' => $mode];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Transfer failed. Please try again.', 'mode' => null];
    }
}

/**
 * Unused pins for admin transfer picker.
 * @return list<array>
 */
function tpin_admin_unused_pins(PDO $pdo, ?int $fromMemberId = null, int $limit = 200): array
{
    tpin_ensure_tables($pdo);
    $sql = '
        SELECT tp.*, p.name AS package_name, p.amount AS package_amount,
               am.member_id AS assigned_code, am.full_name AS assigned_name
        FROM topup_pins tp
        JOIN packages p ON p.id = tp.package_id
        LEFT JOIN members am ON am.id = tp.assigned_to
        WHERE tp.status = \'unused\'
    ';
    $params = [];
    if ($fromMemberId === 0) {
        $sql .= ' AND tp.assigned_to IS NULL';
    } elseif ($fromMemberId !== null && $fromMemberId > 0) {
        $sql .= ' AND tp.assigned_to = ?';
        $params[] = $fromMemberId;
    }
    $sql .= ' ORDER BY tp.id DESC LIMIT ' . max(1, min(500, $limit));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Transfer history for admin report.
 * @return list<array>
 */
function tpin_admin_transfers(PDO $pdo, array $filters = [], int $limit = 300): array
{
    tpin_ensure_tables($pdo);
    $where = ['1=1'];
    $params = [];

    $q = trim((string) ($filters['q'] ?? ''));
    $fromDate = trim((string) ($filters['from'] ?? ''));
    $toDate = trim((string) ($filters['to'] ?? ''));
    $packageId = (int) ($filters['package_id'] ?? 0);

    if ($q !== '') {
        $where[] = '(tp.pin_code LIKE ? OR fm.member_id LIKE ? OR tm.member_id LIKE ? OR fm.full_name LIKE ? OR tm.full_name LIKE ? OR fm.username LIKE ? OR tm.username LIKE ?)';
        $likeCode = '%' . tpin_normalize_code($q) . '%';
        $like = '%' . $q . '%';
        $params[] = $likeCode;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($fromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
        $where[] = 'DATE(t.created_at) >= ?';
        $params[] = $fromDate;
    }
    if ($toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
        $where[] = 'DATE(t.created_at) <= ?';
        $params[] = $toDate;
    }
    if ($packageId > 0) {
        $where[] = 'tp.package_id = ?';
        $params[] = $packageId;
    }

    $sql = '
        SELECT t.*, tp.pin_code, tp.status AS pin_status, tp.batch_code,
               p.name AS package_name, p.amount AS package_amount,
               fm.member_id AS from_code, fm.full_name AS from_name, fm.username AS from_username,
               tm.member_id AS to_code, tm.full_name AS to_name, tm.username AS to_username
        FROM topup_pin_transfers t
        JOIN topup_pins tp ON tp.id = t.pin_id
        JOIN packages p ON p.id = tp.package_id
        JOIN members fm ON fm.id = t.from_member_id
        JOIN members tm ON tm.id = t.to_member_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY t.id DESC
        LIMIT ' . max(1, min(1000, $limit));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Pins in member wallet (unused). */
function tpin_member_unused(PDO $pdo, int $memberId): array
{
    tpin_ensure_tables($pdo);
    $stmt = $pdo->prepare("
        SELECT tp.*, p.name AS package_name, p.amount AS package_amount
        FROM topup_pins tp
        JOIN packages p ON p.id = tp.package_id
        WHERE tp.assigned_to = ? AND tp.status = 'unused'
        ORDER BY tp.id DESC
    ");
    $stmt->execute([$memberId]);
    return $stmt->fetchAll();
}

function tpin_member_used(PDO $pdo, int $memberId, int $limit = 50): array
{
    tpin_ensure_tables($pdo);
    $stmt = $pdo->prepare("
        SELECT tp.*, p.name AS package_name, p.amount AS package_amount
        FROM topup_pins tp
        JOIN packages p ON p.id = tp.package_id
        WHERE tp.used_by = ?
        ORDER BY tp.used_at DESC, tp.id DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $memberId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function tpin_member_transfers(PDO $pdo, int $memberId, int $limit = 50): array
{
    tpin_ensure_tables($pdo);
    $stmt = $pdo->prepare("
        SELECT t.*, tp.pin_code, p.name AS package_name,
               fm.member_id AS from_code, fm.full_name AS from_name,
               tm.member_id AS to_code, tm.full_name AS to_name
        FROM topup_pin_transfers t
        JOIN topup_pins tp ON tp.id = t.pin_id
        JOIN packages p ON p.id = tp.package_id
        JOIN members fm ON fm.id = t.from_member_id
        JOIN members tm ON tm.id = t.to_member_id
        WHERE t.from_member_id = ? OR t.to_member_id = ?
        ORDER BY t.id DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $memberId, PDO::PARAM_INT);
    $stmt->bindValue(2, $memberId, PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function tpin_unused_count_for_member(PDO $pdo, int $memberId): int
{
    tpin_ensure_tables($pdo);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM topup_pins WHERE assigned_to = ? AND status = 'unused'");
    $stmt->execute([$memberId]);
    return (int) $stmt->fetchColumn();
}
