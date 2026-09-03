<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/kyc.php';
$pageTitle = 'Approve KYC';

ensure_kyc_documents_table($pdo);
ensure_kyc_upi_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $docId = (int) ($_POST['doc_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note = trim((string) ($_POST['kyc_note'] ?? ''));
    $source = $_POST['source'] ?? 'doc';

    if ($docId > 0 && in_array($action, ['approve', 'reject'], true)) {
        $status = $action === 'approve' ? 'approved' : 'rejected';

        if ($source === 'upi') {
            $row = kyc_upi_get($pdo, $docId);
            if ($row) {
                $pdo->prepare('UPDATE member_kyc_upi SET status = ?, admin_note = ?, reviewed_at = NOW() WHERE id = ?')
                    ->execute([$status, $note !== '' ? $note : null, $docId]);
                kyc_upi_sync_parent($pdo, (int) $row['member_id']);
                log_activity('kyc_' . $action, "KYC $status (UPI) upi #{$docId} member #{$row['member_id']}");
                flash('success', 'UPI Details ' . $status . ' successfully.');
            } else {
                flash('error', 'UPI entry not found.');
            }
        } else {
            $stmt = $pdo->prepare('SELECT * FROM member_kyc_documents WHERE id = ? LIMIT 1');
            $stmt->execute([$docId]);
            $doc = $stmt->fetch();
            if ($doc && ($doc['doc_type'] ?? '') !== 'upi') {
                $pdo->prepare('UPDATE member_kyc_documents SET status = ?, admin_note = ?, reviewed_at = NOW() WHERE id = ?')
                    ->execute([$status, $note !== '' ? $note : null, $docId]);
                kyc_sync_member_status($pdo, (int) $doc['member_id']);
                $typeLabel = kyc_doc_types()[$doc['doc_type']]['label'] ?? $doc['doc_type'];
                log_activity('kyc_' . $action, "KYC $status ($typeLabel) doc #$docId member #{$doc['member_id']}");
                flash('success', $typeLabel . ' ' . $status . ' successfully.');
            } else {
                flash('error', 'KYC document not found.');
            }
        }
    } else {
        flash('error', 'Invalid KYC action.');
    }
    $backStatus = $_POST['back_status'] ?? 'pending';
    $backType = $_POST['back_type'] ?? 'all';
    $redirect = 'approve-kyc.php?status=' . urlencode((string) $backStatus) . '&type=' . urlencode((string) $backType);
    header('Location: ' . $redirect);
    exit;
}

$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'pending';
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'not_submitted'], true)) {
    $statusFilter = 'pending';
}
$typeFilter = $_GET['type'] ?? 'all';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$types = kyc_doc_types();

/**
 * Build UNION query parts for KYC docs + UPI rows.
 * @return array{0: string[], 1: array}
 */
function kyc_admin_union_parts(PDO $pdo, string $statusFilter, string $typeFilter, string $q): array
{
    $docWhere = ["d.doc_type != 'upi'"];
    $docParams = [];
    $upiWhere = ['1=1'];
    $upiParams = [];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $docWhere[] = '(m.member_id LIKE ? OR m.username LIKE ? OR m.full_name LIKE ? OR m.email LIKE ? OR m.phone LIKE ?
            OR d.pan_number LIKE ? OR d.account_number LIKE ? OR d.aadhar_number LIKE ? OR d.ifsc_code LIKE ?)';
        $docParams = array_merge($docParams, [$like, $like, $like, $like, $like, $like, $like, $like, $like]);

        $upiWhere[] = '(m.member_id LIKE ? OR m.username LIKE ? OR m.full_name LIKE ? OR m.email LIKE ? OR m.phone LIKE ?
            OR u.upi_id LIKE ? OR u.upi_name LIKE ?)';
        $upiParams = array_merge($upiParams, [$like, $like, $like, $like, $like, $like, $like]);
    }

    if (in_array($statusFilter, ['pending', 'approved', 'rejected', 'not_submitted'], true)) {
        $docWhere[] = 'd.status = ?';
        $docParams[] = $statusFilter;
        if ($statusFilter === 'not_submitted') {
            $upiWhere[] = '0=1';
        } else {
            $upiWhere[] = 'u.status = ?';
            $upiParams[] = $statusFilter;
        }
    }

    $includeDocs = in_array($typeFilter, ['all', 'pan', 'bank', 'aadhar'], true);
    $includeUpi = in_array($typeFilter, ['all', 'upi'], true);

    if (in_array($typeFilter, ['pan', 'bank', 'aadhar'], true)) {
        $docWhere[] = 'd.doc_type = ?';
        $docParams[] = $typeFilter;
    }

    $docWhereSql = implode(' AND ', $docWhere);
    $upiWhereSql = implode(' AND ', $upiWhere);

    $unionParts = [];
    $unionParams = [];

    if ($includeDocs) {
        $unionParts[] = "
            SELECT d.id, d.member_id, d.doc_type, d.status, d.submitted_at, d.reviewed_at, d.admin_note,
                   d.document_file, d.document_back,
                   d.pan_number, d.pan_name, d.account_holder, d.account_number, d.ifsc_code,
                   d.bank_name, d.branch_name, d.aadhar_number, d.address_line,
                   d.country, d.state, d.city, d.area, d.pincode,
                   d.upi_id, d.upi_name, 'doc' AS source,
                   m.member_id AS member_code, m.username, m.full_name, m.email, m.phone
            FROM member_kyc_documents d
            JOIN members m ON m.id = d.member_id
            WHERE $docWhereSql
        ";
        $unionParams = array_merge($unionParams, $docParams);
    }

    if ($includeUpi) {
        $unionParts[] = "
            SELECT u.id, u.member_id, 'upi' AS doc_type, u.status, u.submitted_at, u.reviewed_at, u.admin_note,
                   u.document_file, NULL AS document_back,
                   NULL AS pan_number, NULL AS pan_name, NULL AS account_holder, NULL AS account_number, NULL AS ifsc_code,
                   NULL AS bank_name, NULL AS branch_name, NULL AS aadhar_number, NULL AS address_line,
                   NULL AS country, NULL AS state, NULL AS city, NULL AS area, NULL AS pincode,
                   u.upi_id, u.upi_name, 'upi' AS source,
                   m.member_id AS member_code, m.username, m.full_name, m.email, m.phone
            FROM member_kyc_upi u
            JOIN members m ON m.id = u.member_id
            WHERE $upiWhereSql
        ";
        $unionParams = array_merge($unionParams, $upiParams);
    }

    return [$unionParts, $unionParams];
}

function kyc_admin_fetch_rows(PDO $pdo, string $statusFilter, string $typeFilter, string $q, int $limit = 0, int $offset = 0): array
{
    [$unionParts, $unionParams] = kyc_admin_union_parts($pdo, $statusFilter, $typeFilter, $q);
    if (!$unionParts) {
        return [];
    }
    $unionSql = implode(' UNION ALL ', $unionParts);
    $sql = "
        SELECT * FROM ($unionSql) AS kyc_union
        ORDER BY
            CASE status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 WHEN 'approved' THEN 2 ELSE 3 END,
            submitted_at DESC,
            id DESC
    ";
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($unionParams);
    return $stmt->fetchAll() ?: [];
}

function kyc_admin_count_rows(PDO $pdo, string $statusFilter, string $typeFilter, string $q): int
{
    [$unionParts, $unionParams] = kyc_admin_union_parts($pdo, $statusFilter, $typeFilter, $q);
    if (!$unionParts) {
        return 0;
    }
    $unionSql = implode(' UNION ALL ', $unionParts);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ($unionSql) AS kyc_union");
    $countStmt->execute($unionParams);
    return (int) $countStmt->fetchColumn();
}

$statPending = kyc_admin_count_rows($pdo, 'pending', $typeFilter, $q);
$statApproved = kyc_admin_count_rows($pdo, 'approved', $typeFilter, $q);
$statRejected = kyc_admin_count_rows($pdo, 'rejected', $typeFilter, $q);
$statNotSubmitted = kyc_admin_count_rows($pdo, 'not_submitted', $typeFilter, $q);

$total = kyc_admin_count_rows($pdo, $statusFilter, $typeFilter, $q);
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}
$rows = kyc_admin_fetch_rows($pdo, $statusFilter, $typeFilter, $q, $perPage, $offset);

$statusTabs = [
    'pending' => ['label' => 'Pending', 'count' => $statPending],
    'approved' => ['label' => 'Approved', 'count' => $statApproved],
    'rejected' => ['label' => 'Rejected', 'count' => $statRejected],
    'not_submitted' => ['label' => 'Not Submitted', 'count' => $statNotSubmitted],
];

require_once __DIR__ . '/../includes/header.php';

/** Render one KYC rows table. */
$renderKycTable = static function (array $rows, array $types, string $backStatus, string $typeFilter) {
    ?>
    <div class="table-wrap">
        <table class="data members-table">
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Document</th>
                    <th>Details</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <strong>No KYC documents</strong>
                            <span>No records in this list.</span>
                        </div>
                    </td>
                </tr>
            <?php else: foreach ($rows as $r):
                $typeKey = $r['doc_type'];
                $typeLabel = $types[$typeKey]['label'] ?? $typeKey;
                $source = $r['source'] ?? 'doc';
                $fileUrl = kyc_doc_admin_url($r['document_file'] ?? null);
                $fileBackUrl = kyc_doc_admin_url($r['document_back'] ?? null);
                ?>
                <tr>
                    <td>
                        <div class="member-cell">
                            <strong><a href="member-view.php?id=<?= (int) $r['member_id'] ?>"><?= e($r['full_name']) ?></a></strong>
                            <span><?= e($r['member_code']) ?> · <?= e($r['username']) ?></span>
                            <span><?= e($r['phone'] ?: $r['email']) ?></span>
                        </div>
                    </td>
                    <td>
                        <strong><?= e($typeLabel) ?></strong>
                        <?php if ($fileUrl): ?>
                            <div><a href="<?= e($fileUrl) ?>" target="_blank" rel="noopener"><?= $typeKey === 'aadhar' ? 'Front' : 'View file' ?></a></div>
                        <?php else: ?>
                            <div class="ink-muted">No file</div>
                        <?php endif; ?>
                        <?php if ($typeKey === 'aadhar' && $fileBackUrl): ?>
                            <div><a href="<?= e($fileBackUrl) ?>" target="_blank" rel="noopener">Back</a></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="member-cell">
                            <?php if ($typeKey === 'pan'): ?>
                                <strong><?= e($r['pan_number'] ?: '—') ?></strong>
                                <span><?= e($r['pan_name'] ?: '—') ?></span>
                            <?php elseif ($typeKey === 'bank'): ?>
                                <strong><?= e($r['bank_name'] ?: '—') ?></strong>
                                <span><?= e($r['account_holder'] ?: '—') ?></span>
                                <span><?= e($r['account_number'] ?: '—') ?> · <?= e($r['ifsc_code'] ?: '') ?></span>
                                <?php if (!empty($r['branch_name'])): ?>
                                    <span><?= e($r['branch_name']) ?></span>
                                <?php endif; ?>
                            <?php elseif ($typeKey === 'upi'): ?>
                                <strong><?= e($r['upi_name'] ?: 'UPI') ?></strong>
                                <span><?= e($r['upi_id'] ?: '—') ?></span>
                            <?php else: ?>
                                <strong><?= e($r['aadhar_number'] ?: '—') ?></strong>
                                <span><?= e($r['address_line'] ?: '—') ?></span>
                                <?php if (!empty($r['city']) || !empty($r['state'])): ?>
                                    <span><?= e(trim(($r['area'] ? $r['area'] . ', ' : '') . ($r['city'] ?? '') . ', ' . ($r['state'] ?? '') . ' ' . ($r['pincode'] ?? ''), ' ,')) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($r['country'])): ?>
                                    <span><?= e($r['country']) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?= $r['submitted_at'] ? e(date('d M Y, h:i A', strtotime($r['submitted_at']))) : '—' ?>
                    </td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                        <form method="post" class="kyc-actions">
                            <input type="hidden" name="doc_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="source" value="<?= e($source) ?>">
                            <input type="hidden" name="back_status" value="<?= e($backStatus) ?>">
                            <input type="hidden" name="back_type" value="<?= e($typeFilter) ?>">
                            <input type="text" name="kyc_note" placeholder="Note (optional)" class="kyc-note-input">
                            <div class="action-icons">
                                <?= action_approve_btn('Approve this KYC document?') ?>
                                <?= action_reject_btn('Reject this document?') ?>
                            </div>
                        </form>
                        <?php else: ?>
                            <span class="ink-muted"><?= $r['reviewed_at'] ? e(date('d M Y', strtotime($r['reviewed_at']))) : '—' ?></span>
                            <?php if (!empty($r['admin_note'])): ?>
                                <div class="field-hint"><?= e($r['admin_note']) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
};
?>

<div class="panel members-panel">
    <div class="panel-header members-toolbar">
        <div>
            <h2>Approve KYC</h2>
            <p class="members-sub">Review PAN, Bank, Aadhaar and UPI documents submitted by members</p>
        </div>
    </div>

    <div class="kyc-tabs" role="tablist" aria-label="KYC status">
        <?php foreach ($statusTabs as $key => $tab):
            $href = 'approve-kyc.php?status=' . urlencode($key) . '&type=' . urlencode($typeFilter) . '&q=' . urlencode($q);
            ?>
            <a href="<?= e($href) ?>"
               class="kyc-tab<?= $statusFilter === $key ? ' is-active' : '' ?>"
               role="tab"
               aria-selected="<?= $statusFilter === $key ? 'true' : 'false' ?>">
                <span class="kyc-tab-label"><?= e($tab['label']) ?></span>
                <span class="kyc-tab-count"><?= (int) $tab['count'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="panel-body members-filters">
        <form class="members-filter-form" method="get">
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            <div class="form-group">
                <label>Document</label>
                <select name="type">
                    <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>All types</option>
                    <?php foreach ($types as $key => $info): ?>
                        <option value="<?= e($key) ?>" <?= $typeFilter === $key ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Search</label>
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="Member ID, name, PAN, Aadhaar, UPI…">
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="approve-kyc.php?status=<?= e($statusFilter) ?>" class="btn btn-outline">Reset</a>
        </form>
    </div>

    <?php $renderKycTable($rows, $types, $statusFilter, $typeFilter); ?>

    <?php if ($totalPages > 1): ?>
    <div class="pagination members-pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a class="<?= $i === $page ? 'active' : '' ?>" href="?page=<?= $i ?>&status=<?= e($statusFilter) ?>&type=<?= e($typeFilter) ?>&q=<?= urlencode($q) ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.kyc-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    padding: 0.75rem 1.15rem 0;
    border-bottom: 1px solid rgba(131, 146, 171, 0.18);
    background: #f8f9fa;
}
.kyc-tab {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.7rem 1rem;
    border-radius: 0.65rem 0.65rem 0 0;
    text-decoration: none;
    color: #67748e;
    font-weight: 600;
    font-size: 0.9rem;
    border: 1px solid transparent;
    border-bottom: 0;
    margin-bottom: -1px;
    transition: color .15s ease, background .15s ease, border-color .15s ease;
}
.kyc-tab:hover {
    color: #344767;
    background: rgba(255,255,255,0.7);
}
.kyc-tab.is-active {
    color: #344767;
    background: #fff;
    border-color: rgba(131, 146, 171, 0.18);
    box-shadow: 0 -1px 0 #fff;
}
.kyc-tab-count {
    min-width: 1.5rem;
    height: 1.35rem;
    padding: 0 0.45rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 700;
    background: rgba(131, 146, 171, 0.16);
    color: #67748e;
}
.kyc-tab.is-active .kyc-tab-count {
    background: rgba(94, 114, 228, 0.15);
    color: #5e72e4;
}
.kyc-tab[href*="status=pending"].is-active .kyc-tab-count {
    background: rgba(245, 158, 11, 0.18);
    color: #d97706;
}
.kyc-tab[href*="status=approved"].is-active .kyc-tab-count {
    background: rgba(23, 173, 55, 0.15);
    color: #17ad37;
}
.kyc-tab[href*="status=rejected"].is-active .kyc-tab-count {
    background: rgba(234, 6, 6, 0.12);
    color: #ea0606;
}
@media (max-width: 640px) {
    .kyc-tabs { gap: 0.2rem; padding: 0.55rem 0.65rem 0; }
    .kyc-tab { padding: 0.6rem 0.7rem; font-size: 0.82rem; }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
