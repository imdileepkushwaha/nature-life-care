<?php
/**
 * MySQL stored procedures used by critical MLM flows.
 * Auto-installed on first use (see ensure_mlm_procedures).
 */

function ensure_mlm_procedures(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    // Force reinstall when any required SP is missing
    $needed = [
        'sp_update_upline_counts',
        'sp_find_binary_placement',
        'sp_activate_member',
        'sp_approve_withdrawal',
        'sp_reject_withdrawal',
    ];
    $missing = false;
    try {
        foreach ($needed as $name) {
            $stmt = $pdo->prepare("SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name = ?");
            $stmt->execute([$name]);
            if (!$stmt->fetch()) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            $done = true;
            return;
        }
    } catch (Throwable $e) {
        $missing = true;
    }

    $procedures = [

        'sp_update_upline_counts' => "
CREATE PROCEDURE sp_update_upline_counts(
    IN p_placement_id INT,
    IN p_position VARCHAR(10)
)
BEGIN
    DECLARE v_current INT;
    DECLARE v_side VARCHAR(10);
    DECLARE v_guard INT DEFAULT 0;
    DECLARE v_parent INT;
    DECLARE v_pos VARCHAR(10);

    SET v_current = p_placement_id;
    SET v_side = LOWER(p_position);

    WHILE v_current IS NOT NULL AND v_current > 0 AND v_guard < 100 DO
        IF v_side = 'left' THEN
            UPDATE members SET left_count = left_count + 1 WHERE id = v_current;
        ELSE
            UPDATE members SET right_count = right_count + 1 WHERE id = v_current;
        END IF;

        SELECT placement_id, position INTO v_parent, v_pos
        FROM members WHERE id = v_current LIMIT 1;

        IF v_parent IS NULL OR v_parent = 0 THEN
            SET v_current = NULL;
        ELSE
            SET v_current = v_parent;
            IF v_pos IS NOT NULL AND v_pos <> '' THEN
                SET v_side = LOWER(v_pos);
            END IF;
        END IF;

        SET v_guard = v_guard + 1;
    END WHILE;
END
",

        'sp_find_binary_placement' => "
CREATE PROCEDURE sp_find_binary_placement(
    IN p_start_id INT,
    IN p_position VARCHAR(10),
    OUT p_parent_id INT
)
BEGIN
    DECLARE v_parent INT;
    DECLARE v_child INT;
    DECLARE v_i INT DEFAULT 0;
    DECLARE v_pos VARCHAR(10);

    SET v_parent = p_start_id;
    SET v_pos = LOWER(p_position);
    SET p_parent_id = NULL;

    WHILE v_i < 50 DO
        SET v_child = NULL;
        SELECT id INTO v_child
        FROM members
        WHERE placement_id = v_parent AND position = v_pos
        LIMIT 1;

        IF v_child IS NULL THEN
            SET p_parent_id = v_parent;
            SET v_i = 50;
        ELSE
            SET v_parent = v_child;
            SET v_i = v_i + 1;
        END IF;
    END WHILE;
END
",

        'sp_activate_member' => "
CREATE PROCEDURE sp_activate_member(
    IN p_member_id INT,
    IN p_package_id INT,
    OUT p_ok TINYINT,
    OUT p_message VARCHAR(255),
    OUT p_package_name VARCHAR(100)
)
BEGIN
    DECLARE v_pkg_amount DECIMAL(12,2) DEFAULT 0;
    DECLARE v_pkg_name VARCHAR(100);
    DECLARE v_sponsor_id INT;
    DECLARE v_member_code VARCHAR(20);
    DECLARE v_status VARCHAR(20);
    DECLARE v_has_pkg INT DEFAULT 0;
    DECLARE v_pct DECIMAL(8,2) DEFAULT 5;
    DECLARE v_comm DECIMAL(12,2) DEFAULT 0;
    DECLARE v_affected INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_ok = 0;
        SET p_message = 'Activation failed due to a database error.';
        SET p_package_name = NULL;
    END;

    SET p_ok = 0;
    SET p_message = 'Unknown error';
    SET p_package_name = NULL;

    SELECT package_id IS NOT NULL, status, sponsor_id, member_id
      INTO v_has_pkg, v_status, v_sponsor_id, v_member_code
    FROM members WHERE id = p_member_id LIMIT 1;

    IF v_member_code IS NULL THEN
        SET p_message = 'Member not found.';
    ELSEIF v_status = 'blocked' THEN
        SET p_message = 'Your account is blocked.';
    ELSEIF v_has_pkg = 1 THEN
        SET p_message = 'Your account is already activated.';
    ELSE
        SELECT name, amount INTO v_pkg_name, v_pkg_amount
        FROM packages
        WHERE id = p_package_id AND status = 'active'
        LIMIT 1;

        IF v_pkg_name IS NULL THEN
            SET p_message = 'Selected package is not available.';
        ELSE
            START TRANSACTION;

            UPDATE members
            SET package_id = p_package_id, status = 'active'
            WHERE id = p_member_id AND package_id IS NULL;

            SET v_affected = ROW_COUNT();

            IF v_affected < 1 THEN
                ROLLBACK;
                SET p_message = 'Your account is already activated.';
            ELSE
                SELECT CAST(setting_value AS DECIMAL(8,2)) INTO v_pct
                FROM settings
                WHERE setting_key = 'referral_commission_percent'
                LIMIT 1;

                IF v_pct IS NULL THEN
                    SET v_pct = 5;
                END IF;

                IF v_sponsor_id IS NOT NULL AND v_sponsor_id > 0 AND v_pkg_amount > 0 THEN
                    SET v_comm = ROUND(v_pkg_amount * v_pct / 100, 2);
                    IF v_comm > 0 THEN
                        INSERT INTO commissions (member_id, from_member_id, type, amount, description, status)
                        VALUES (
                            v_sponsor_id,
                            p_member_id,
                            'referral',
                            v_comm,
                            CONCAT('Referral bonus from ', v_member_code),
                            'paid'
                        );
                        UPDATE members
                        SET wallet_balance = wallet_balance + v_comm,
                            total_earnings = total_earnings + v_comm
                        WHERE id = v_sponsor_id;
                    END IF;
                END IF;

                COMMIT;
                SET p_ok = 1;
                SET p_message = 'Account activated successfully.';
                SET p_package_name = v_pkg_name;
            END IF;
        END IF;
    END IF;
END
",

        'sp_approve_withdrawal' => "
CREATE PROCEDURE sp_approve_withdrawal(
    IN p_withdrawal_id INT,
    IN p_admin_note TEXT,
    OUT p_ok TINYINT,
    OUT p_message VARCHAR(255)
)
BEGIN
    DECLARE v_member_id INT;
    DECLARE v_amount DECIMAL(12,2);
    DECLARE v_status VARCHAR(20);
    DECLARE v_wallet DECIMAL(12,2);
    DECLARE v_upd INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_ok = 0;
        SET p_message = 'Approval failed due to a database error.';
    END;

    SET p_ok = 0;
    SET p_message = 'Unknown error';

    SELECT member_id, amount, status
      INTO v_member_id, v_amount, v_status
    FROM withdrawals WHERE id = p_withdrawal_id LIMIT 1;

    IF v_member_id IS NULL THEN
        SET p_message = 'Withdrawal not found.';
    ELSEIF v_status <> 'pending' THEN
        SET p_message = 'Withdrawal is not pending.';
    ELSE
        SELECT wallet_balance INTO v_wallet FROM members WHERE id = v_member_id LIMIT 1;

        IF v_wallet IS NULL THEN
            SET p_message = 'Member not found.';
        ELSEIF v_wallet < v_amount THEN
            SET p_message = 'Insufficient wallet balance.';
        ELSE
            START TRANSACTION;

            UPDATE withdrawals
            SET status = 'approved',
                admin_note = p_admin_note,
                processed_at = NOW()
            WHERE id = p_withdrawal_id AND status = 'pending';

            SET v_upd = ROW_COUNT();

            IF v_upd < 1 THEN
                ROLLBACK;
                SET p_message = 'Withdrawal is not pending.';
            ELSE
                UPDATE members
                SET wallet_balance = wallet_balance - v_amount
                WHERE id = v_member_id AND wallet_balance >= v_amount;

                IF ROW_COUNT() < 1 THEN
                    ROLLBACK;
                    SET p_message = 'Insufficient wallet balance.';
                ELSE
                    COMMIT;
                    SET p_ok = 1;
                    SET p_message = 'Withdrawal approved. Wallet deducted.';
                END IF;
            END IF;
        END IF;
    END IF;
END
",

        'sp_reject_withdrawal' => "
CREATE PROCEDURE sp_reject_withdrawal(
    IN p_withdrawal_id INT,
    IN p_admin_note TEXT,
    OUT p_ok TINYINT,
    OUT p_message VARCHAR(255)
)
BEGIN
    DECLARE v_upd INT DEFAULT 0;

    SET p_ok = 0;
    SET p_message = 'Unknown error';

    UPDATE withdrawals
    SET status = 'rejected',
        admin_note = p_admin_note,
        processed_at = NOW()
    WHERE id = p_withdrawal_id AND status = 'pending';

    SET v_upd = ROW_COUNT();

    IF v_upd < 1 THEN
        SET p_message = 'Withdrawal not found or not pending.';
    ELSE
        SET p_ok = 1;
        SET p_message = 'Withdrawal rejected.';
    END IF;
END
",
    ];

    foreach ($procedures as $name => $ddl) {
        try {
            $pdo->exec("DROP PROCEDURE IF EXISTS `{$name}`");
            $pdo->exec($ddl);
        } catch (Throwable $e) {
            // leave missing; PHP fallbacks will be used
        }
    }

    $done = true;
}

function sp_available(PDO $pdo, string $name): bool
{
    ensure_mlm_procedures($pdo);
    try {
        $stmt = $pdo->prepare("SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name = ?");
        $stmt->execute([$name]);
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array{ok:bool,message:string,package_name:?string} */
function sp_call_activate_member(PDO $pdo, int $memberId, int $packageId): array
{
    if (!sp_available($pdo, 'sp_activate_member')) {
        return ['ok' => false, 'message' => 'Procedure unavailable', 'package_name' => null];
    }
    try {
        $stmt = $pdo->prepare('CALL sp_activate_member(?, ?, @p_ok, @p_message, @p_package_name)');
        $stmt->execute([$memberId, $packageId]);
        // flush any result sets
        while ($stmt->nextRowset()) {
            // no-op
        }
        $row = $pdo->query('SELECT @p_ok AS ok, @p_message AS message, @p_package_name AS package_name')->fetch();
        return [
            'ok' => (int) ($row['ok'] ?? 0) === 1,
            'message' => (string) ($row['message'] ?? 'Activation failed.'),
            'package_name' => $row['package_name'] ?? null,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Activation failed.', 'package_name' => null];
    }
}

/** @return array{ok:bool,message:string} */
function sp_call_approve_withdrawal(PDO $pdo, int $withdrawalId, string $note): array
{
    if (!sp_available($pdo, 'sp_approve_withdrawal')) {
        return ['ok' => false, 'message' => 'Procedure unavailable'];
    }
    try {
        $stmt = $pdo->prepare('CALL sp_approve_withdrawal(?, ?, @p_ok, @p_message)');
        $stmt->execute([$withdrawalId, $note]);
        while ($stmt->nextRowset()) {
        }
        $row = $pdo->query('SELECT @p_ok AS ok, @p_message AS message')->fetch();
        return [
            'ok' => (int) ($row['ok'] ?? 0) === 1,
            'message' => (string) ($row['message'] ?? 'Approval failed.'),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Approval failed.'];
    }
}

/** @return array{ok:bool,message:string} */
function sp_call_reject_withdrawal(PDO $pdo, int $withdrawalId, string $note): array
{
    if (!sp_available($pdo, 'sp_reject_withdrawal')) {
        return ['ok' => false, 'message' => 'Procedure unavailable'];
    }
    try {
        $stmt = $pdo->prepare('CALL sp_reject_withdrawal(?, ?, @p_ok, @p_message)');
        $stmt->execute([$withdrawalId, $note]);
        while ($stmt->nextRowset()) {
        }
        $row = $pdo->query('SELECT @p_ok AS ok, @p_message AS message')->fetch();
        return [
            'ok' => (int) ($row['ok'] ?? 0) === 1,
            'message' => (string) ($row['message'] ?? 'Reject failed.'),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Reject failed.'];
    }
}

function sp_call_update_upline_counts(PDO $pdo, int $placementId, string $position): bool
{
    if (!sp_available($pdo, 'sp_update_upline_counts')) {
        return false;
    }
    try {
        $stmt = $pdo->prepare('CALL sp_update_upline_counts(?, ?)');
        $stmt->execute([$placementId, $position]);
        while ($stmt->nextRowset()) {
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function sp_call_find_binary_placement(PDO $pdo, int $startId, string $position): ?int
{
    if (!sp_available($pdo, 'sp_find_binary_placement')) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('CALL sp_find_binary_placement(?, ?, @p_parent_id)');
        $stmt->execute([$startId, $position]);
        while ($stmt->nextRowset()) {
        }
        $row = $pdo->query('SELECT @p_parent_id AS parent_id')->fetch();
        $id = isset($row['parent_id']) ? (int) $row['parent_id'] : 0;
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        return null;
    }
}
