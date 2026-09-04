<?php
/**
 * Nominee designation + death settlement of accrued income-wallet benefits.
 */

require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/withdrawal.php';

function nominee_relations(): array
{
    return ['Spouse', 'Son', 'Daughter', 'Father', 'Mother', 'Brother', 'Sister', 'Other'];
}

function nominee_doc_types(): array
{
    return [
        'death_certificate' => 'Death certificate',
        'legal_heir' => 'Legal heir / succession / indemnity',
        'nominee_id' => 'Nominee ID (PAN / Aadhaar)',
        'nominee_bank' => 'Nominee bank proof',
    ];
}

function nominee_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE members MODIFY COLUMN status ENUM('active','inactive','blocked','deceased') DEFAULT 'active'");
    } catch (Throwable $e) {
        // ignore
    }

    $cols = [
        'nominee_name' => 'VARCHAR(120) NULL',
        'nominee_relation' => 'VARCHAR(40) NULL',
        'nominee_phone' => 'VARCHAR(20) NULL',
        'nominee_email' => 'VARCHAR(120) NULL',
        'nominee_address' => 'VARCHAR(255) NULL',
    ];
    foreach ($cols as $name => $def) {
        try {
            $chk = $pdo->query('SHOW COLUMNS FROM members LIKE ' . $pdo->quote($name));
            if ($chk && !$chk->fetch()) {
                $pdo->exec("ALTER TABLE members ADD COLUMN {$name} {$def}");
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS member_nominee_settlements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                status ENUM('open','docs_pending','verified','settled','rejected') NOT NULL DEFAULT 'open',
                death_date DATE NULL,
                nominee_name VARCHAR(120) NULL,
                nominee_relation VARCHAR(40) NULL,
                nominee_phone VARCHAR(20) NULL,
                nominee_email VARCHAR(120) NULL,
                nominee_address VARCHAR(255) NULL,
                nominee_bank_details TEXT NULL,
                accrued_wallet DECIMAL(14,2) NOT NULL DEFAULT 0,
                accrued_pending_wd DECIMAL(14,2) NOT NULL DEFAULT 0,
                settled_gross DECIMAL(14,2) NOT NULL DEFAULT 0,
                tds_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                net_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                payout_ref VARCHAR(120) NULL,
                admin_note TEXT NULL,
                reported_by INT NULL,
                verified_by INT NULL,
                settled_by INT NULL,
                verified_at DATETIME NULL,
                settled_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_nominee_member (member_id),
                KEY idx_nominee_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS member_nominee_documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                settlement_id INT NOT NULL,
                doc_type VARCHAR(40) NOT NULL,
                file_path VARCHAR(255) NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                admin_note VARCHAR(255) NULL,
                uploaded_by INT NULL,
                reviewed_by INT NULL,
                reviewed_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_nom_doc (settlement_id, doc_type),
                KEY idx_nom_doc_case (settlement_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }

    $done = true;
}

function nominee_profile_fields(array $row): array
{
    return [
        'nominee_name' => trim((string) ($row['nominee_name'] ?? '')),
        'nominee_relation' => trim((string) ($row['nominee_relation'] ?? '')),
    ];
}

/**
 * @return array{ok:bool,errors:list<string>,data:array<string,string>}
 */
function nominee_validate_input(array $post, bool $requireName = false): array
{
    $data = [
        'nominee_name' => trim((string) ($post['nominee_name'] ?? '')),
        'nominee_relation' => trim((string) ($post['nominee_relation'] ?? '')),
    ];
    $errors = [];
    $filled = $data['nominee_name'] !== '' || $data['nominee_relation'] !== '';
    if ($requireName || $filled) {
        if ($data['nominee_name'] === '' || mb_strlen($data['nominee_name']) < 2) {
            $errors[] = 'Nominee full name is required.';
        }
        if ($data['nominee_relation'] === '' || !in_array($data['nominee_relation'], nominee_relations(), true)) {
            $errors[] = 'Select nominee relationship.';
        }
    }
    return ['ok' => $errors === [], 'errors' => $errors, 'data' => $data];
}

function nominee_save_member(PDO $pdo, int $memberId, array $data): void
{
    nominee_ensure_schema($pdo);
    $pdo->prepare('
        UPDATE members SET
            nominee_name = ?, nominee_relation = ?,
            nominee_phone = NULL, nominee_email = NULL, nominee_address = NULL
        WHERE id = ?
    ')->execute([
        $data['nominee_name'] !== '' ? $data['nominee_name'] : null,
        $data['nominee_relation'] !== '' ? $data['nominee_relation'] : null,
        $memberId,
    ]);
}

function nominee_case_for_member(PDO $pdo, int $memberId): ?array
{
    nominee_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM member_nominee_settlements WHERE member_id = ? LIMIT 1');
    $st->execute([$memberId]);
    $row = $st->fetch();
    return $row ?: null;
}

function nominee_case_by_id(PDO $pdo, int $id): ?array
{
    nominee_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT s.*, m.member_id AS mid, m.full_name, m.email, m.phone, m.kyc_status, m.wallet_balance, m.status AS member_status
        FROM member_nominee_settlements s
        INNER JOIN members m ON m.id = s.member_id
        WHERE s.id = ?
        LIMIT 1
    ');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function nominee_accrued(PDO $pdo, int $memberId): array
{
    require_once __DIR__ . '/withdrawal.php';
    wd_ensure_columns($pdo);
    $wallet = 0.0;
    try {
        $st = $pdo->prepare('SELECT wallet_balance FROM members WHERE id = ? LIMIT 1');
        $st->execute([$memberId]);
        $wallet = (float) $st->fetchColumn();
    } catch (Throwable $e) {
        $wallet = 0.0;
    }
    $pending = 0.0;
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE member_id = ? AND status = 'pending'");
        $st->execute([$memberId]);
        $pending = (float) $st->fetchColumn();
    } catch (Throwable $e) {
        $pending = 0.0;
    }
    $approvedNet = 0.0;
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(net_amount, amount)),0) FROM withdrawals WHERE member_id = ? AND status = 'approved'");
        $st->execute([$memberId]);
        $approvedNet = (float) $st->fetchColumn();
    } catch (Throwable $e) {
        $approvedNet = 0.0;
    }
    return [
        'wallet' => round($wallet, 2),
        'pending_wd' => round($pending, 2),
        'approved_wd_net' => round($approvedNet, 2),
    ];
}

/**
 * @return array{ok:bool,id:?int,message:string}
 */
function nominee_open_case(PDO $pdo, int $memberId, ?int $adminId, ?string $deathDate, string $note = ''): array
{
    nominee_ensure_schema($pdo);
    $mem = $pdo->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
    $mem->execute([$memberId]);
    $m = $mem->fetch();
    if (!$m) {
        return ['ok' => false, 'id' => null, 'message' => 'Member not found.'];
    }
    if (($m['status'] ?? '') === 'deceased') {
        $existing = nominee_case_for_member($pdo, $memberId);
        if ($existing) {
            return ['ok' => true, 'id' => (int) $existing['id'], 'message' => 'Settlement case already open.'];
        }
    }
    $existing = nominee_case_for_member($pdo, $memberId);
    if ($existing && !in_array($existing['status'], ['rejected'], true)) {
        return ['ok' => false, 'id' => (int) $existing['id'], 'message' => 'A settlement case already exists for this member.'];
    }

    $acc = nominee_accrued($pdo, $memberId);
    $death = ($deathDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $deathDate)) ? $deathDate : null;

    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE members SET status = 'deceased' WHERE id = ?")->execute([$memberId]);
        if ($existing && ($existing['status'] ?? '') === 'rejected') {
            $pdo->prepare('
                UPDATE member_nominee_settlements SET
                    status = ?, death_date = ?, nominee_name = ?, nominee_relation = ?,
                    nominee_phone = NULL, nominee_email = NULL, nominee_address = NULL,
                    accrued_wallet = ?, accrued_pending_wd = ?,
                    admin_note = ?, reported_by = ?, verified_by = NULL, settled_by = NULL,
                    verified_at = NULL, settled_at = NULL
                WHERE id = ?
            ')->execute([
                'docs_pending',
                $death,
                $m['nominee_name'] ?? null,
                $m['nominee_relation'] ?? null,
                $acc['wallet'],
                $acc['pending_wd'],
                $note !== '' ? $note : null,
                $adminId,
                (int) $existing['id'],
            ]);
            $id = (int) $existing['id'];
        } else {
            $pdo->prepare('
                INSERT INTO member_nominee_settlements (
                    member_id, status, death_date, nominee_name, nominee_relation,
                    nominee_phone, nominee_email, nominee_address,
                    accrued_wallet, accrued_pending_wd, admin_note, reported_by
                ) VALUES (?,?,?,?,?,NULL,NULL,NULL,?,?,?,?)
            ')->execute([
                $memberId,
                'docs_pending',
                $death,
                $m['nominee_name'] ?? null,
                $m['nominee_relation'] ?? null,
                $acc['wallet'],
                $acc['pending_wd'],
                $note !== '' ? $note : null,
                $adminId,
            ]);
            $id = (int) $pdo->lastInsertId();
        }
        $pdo->commit();
        return ['ok' => true, 'id' => $id, 'message' => 'Member marked deceased. Login closed. Complete nominee KYC and legal documents, then settle accrued benefits.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'id' => null, 'message' => 'Could not open settlement case.'];
    }
}

function nominee_docs(PDO $pdo, int $settlementId): array
{
    nominee_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM member_nominee_documents WHERE settlement_id = ?');
    $st->execute([$settlementId]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[(string) $row['doc_type']] = $row;
    }
    return $out;
}

function nominee_required_docs_approved(PDO $pdo, int $settlementId): bool
{
    $docs = nominee_docs($pdo, $settlementId);
    foreach (array_keys(nominee_doc_types()) as $type) {
        if (($docs[$type]['status'] ?? '') !== 'approved') {
            return false;
        }
    }
    return true;
}

function nominee_store_file(array $file, int $settlementId, string $docType): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Please upload a document.', 'path' => null];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed.', 'path' => null];
    }
    if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Document must be under 4MB.', 'path' => null];
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
        return ['ok' => false, 'error' => 'Only JPG, PNG, WebP or PDF allowed.', 'path' => null];
    }
    $dir = BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'nominee';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Could not create upload folder.', 'path' => null];
    }
    $name = $docType . '_s' . $settlementId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $allowed[$mime];
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Could not save document.', 'path' => null];
    }
    return ['ok' => true, 'error' => null, 'path' => 'uploads/nominee/' . $name];
}

function nominee_upsert_doc(PDO $pdo, int $settlementId, string $docType, string $path, ?int $adminId): void
{
    $pdo->prepare('
        INSERT INTO member_nominee_documents (settlement_id, doc_type, file_path, status, uploaded_by)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), status = \'pending\', admin_note = NULL,
            uploaded_by = VALUES(uploaded_by), reviewed_by = NULL, reviewed_at = NULL
    ')->execute([$settlementId, $docType, $path, 'pending', $adminId]);
}

function nominee_review_doc(PDO $pdo, int $docId, string $action, ?int $adminId, string $note = ''): array
{
    $status = $action === 'approve' ? 'approved' : 'rejected';
    if (!in_array($status, ['approved', 'rejected'], true)) {
        return ['ok' => false, 'message' => 'Invalid document action.'];
    }
    $st = $pdo->prepare('SELECT * FROM member_nominee_documents WHERE id = ? LIMIT 1');
    $st->execute([$docId]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => 'Document not found.'];
    }
    $pdo->prepare('UPDATE member_nominee_documents SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
        ->execute([$status, $note !== '' ? $note : null, $adminId, $docId]);
    return ['ok' => true, 'message' => 'Document ' . $status . '.'];
}

function nominee_mark_verified(PDO $pdo, int $settlementId, ?int $adminId): array
{
    $case = nominee_case_by_id($pdo, $settlementId);
    if (!$case || in_array($case['status'], ['settled', 'rejected'], true)) {
        return ['ok' => false, 'message' => 'Case cannot be verified.'];
    }
    if (!nominee_required_docs_approved($pdo, $settlementId)) {
        return ['ok' => false, 'message' => 'Approve death certificate, legal heir paper, nominee ID and nominee bank proof first.'];
    }
    $name = trim((string) ($case['nominee_name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'message' => 'Nominee name is required before verification.'];
    }
    $pdo->prepare("UPDATE member_nominee_settlements SET status = 'verified', verified_by = ?, verified_at = NOW() WHERE id = ?")
        ->execute([$adminId, $settlementId]);
    return ['ok' => true, 'message' => 'KYC and legal documents verified. Accrued benefits can now be settled to the nominee.'];
}

/**
 * Debit income wallet and record nominee payout (TDS + admin charges).
 */
function nominee_settle(PDO $pdo, int $settlementId, ?int $adminId, string $bankDetails, string $payoutRef, string $note = ''): array
{
    require_once __DIR__ . '/withdrawal.php';
    $case = nominee_case_by_id($pdo, $settlementId);
    if (!$case) {
        return ['ok' => false, 'message' => 'Case not found.'];
    }
    if (($case['status'] ?? '') !== 'verified') {
        return ['ok' => false, 'message' => 'Verify documents before settling accrued benefits.'];
    }
    $memberId = (int) $case['member_id'];
    $acc = nominee_accrued($pdo, $memberId);
    $gross = $acc['wallet'];
    $break = wd_calc_breakdown($pdo, $gross);

    try {
        $pdo->beginTransaction();
        if ($gross > 0.00001) {
            $deb = wallet_debit(
                $pdo,
                $memberId,
                'income',
                $gross,
                'nominee_settlement',
                $settlementId,
                'Nominee settlement of accrued income',
                $adminId
            );
            if (!$deb['ok']) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => $deb['error'] ?: 'Could not debit income wallet.'];
            }
        }
        try {
            $pdo->prepare("UPDATE withdrawals SET status = 'rejected', admin_note = ?, processed_at = NOW() WHERE member_id = ? AND status = 'pending'")
                ->execute(['Cancelled: nominee settlement', $memberId]);
        } catch (Throwable $e) {
            // ignore
        }
        $pdo->prepare('
            UPDATE member_nominee_settlements SET
                status = ?, nominee_bank_details = ?, accrued_wallet = ?, accrued_pending_wd = ?,
                settled_gross = ?, tds_amount = ?, fee_amount = ?, net_amount = ?,
                payout_ref = ?, admin_note = ?, settled_by = ?, settled_at = NOW()
            WHERE id = ? AND status = \'verified\'
        ')->execute([
            'settled',
            $bankDetails !== '' ? $bankDetails : null,
            $acc['wallet'],
            $acc['pending_wd'],
            $break['gross'],
            $break['tds_amount'],
            round($break['fee_amount'] + $break['other_deduction'], 2),
            $break['net_amount'],
            $payoutRef !== '' ? $payoutRef : null,
            $note !== '' ? $note : ($case['admin_note'] ?? null),
            $adminId,
            $settlementId,
        ]);
        $pdo->commit();
        return [
            'ok' => true,
            'message' => 'Settled to nominee. Gross ' . number_format($break['gross'], 2)
                . ' · Net ' . number_format($break['net_amount'], 2)
                . ' after TDS and admin charges.',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Settlement failed.'];
    }
}

function nominee_reject_case(PDO $pdo, int $settlementId, ?int $adminId, string $note): array
{
    $case = nominee_case_by_id($pdo, $settlementId);
    if (!$case || ($case['status'] ?? '') === 'settled') {
        return ['ok' => false, 'message' => 'Case cannot be rejected.'];
    }
    $pdo->prepare("UPDATE member_nominee_settlements SET status = 'rejected', admin_note = ?, settled_by = ? WHERE id = ?")
        ->execute([$note !== '' ? $note : 'Rejected', $adminId, $settlementId]);
    return ['ok' => true, 'message' => 'Case rejected. Member remains deceased until you reopen from this desk.'];
}

function nominee_pending_count(PDO $pdo): int
{
    nominee_ensure_schema($pdo);
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM member_nominee_settlements WHERE status IN ('open','docs_pending','verified')")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function nominee_has_name(array $member): bool
{
    return trim((string) ($member['nominee_name'] ?? '')) !== '';
}
