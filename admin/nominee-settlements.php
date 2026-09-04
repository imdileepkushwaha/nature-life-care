<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/nominee.php';
require_once __DIR__ . '/../includes/kyc.php';
require_admin();

$pageTitle = 'Nominee Settlement';
nominee_ensure_schema($pdo);

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$viewId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'open') {
        $mid = (int) ($_POST['member_pk'] ?? 0);
        $death = trim((string) ($_POST['death_date'] ?? ''));
        $note = trim((string) ($_POST['admin_note'] ?? ''));
        $res = nominee_open_case($pdo, $mid, $adminId, $death !== '' ? $death : null, $note);
        flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: nominee-settlements.php' . (!empty($res['id']) ? '?id=' . (int) $res['id'] : ''));
        exit;
    }

    if ($action === 'save_nominee') {
        $sid = (int) ($_POST['settlement_id'] ?? 0);
        $case = nominee_case_by_id($pdo, $sid);
        if (!$case || in_array($case['status'], ['settled', 'rejected'], true)) {
            flash('error', 'Nominee details are locked on this case.');
        } else {
            $check = nominee_validate_input($_POST, true);
            if (!$check['ok']) {
                flash('error', implode(' ', $check['errors']));
            } else {
                nominee_save_member($pdo, (int) $case['member_id'], $check['data']);
                $pdo->prepare('
                    UPDATE member_nominee_settlements SET
                        nominee_name = ?, nominee_relation = ?,
                        nominee_phone = NULL, nominee_email = NULL, nominee_address = NULL
                    WHERE id = ?
                ')->execute([
                    $check['data']['nominee_name'],
                    $check['data']['nominee_relation'],
                    $sid,
                ]);
                flash('success', 'Nominee details saved.');
            }
        }
        header('Location: nominee-settlements.php?id=' . $sid);
        exit;
    }

    if ($action === 'upload_doc') {
        $sid = (int) ($_POST['settlement_id'] ?? 0);
        $type = (string) ($_POST['doc_type'] ?? '');
        $case = nominee_case_by_id($pdo, $sid);
        if (!$case || in_array($case['status'], ['settled', 'rejected'], true)) {
            flash('error', 'Documents are locked.');
        } elseif (!isset(nominee_doc_types()[$type])) {
            flash('error', 'Invalid document type.');
        } else {
            $up = nominee_store_file($_FILES['document'] ?? [], $sid, $type);
            if (!$up['ok']) {
                flash('error', $up['error']);
            } else {
                nominee_upsert_doc($pdo, $sid, $type, $up['path'], $adminId);
                flash('success', nominee_doc_types()[$type] . ' uploaded for review.');
            }
        }
        header('Location: nominee-settlements.php?id=' . $sid);
        exit;
    }

    if ($action === 'review_doc') {
        $sid = (int) ($_POST['settlement_id'] ?? 0);
        $docId = (int) ($_POST['doc_id'] ?? 0);
        $rev = (string) ($_POST['review'] ?? '');
        $note = trim((string) ($_POST['doc_note'] ?? ''));
        $res = nominee_review_doc($pdo, $docId, $rev, $adminId, $note);
        flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: nominee-settlements.php?id=' . $sid);
        exit;
    }

    if ($action === 'verify') {
        $sid = (int) ($_POST['settlement_id'] ?? 0);
        $res = nominee_mark_verified($pdo, $sid, $adminId);
        if ($res['ok']) {
            log_activity('nominee_verify', 'Verified nominee case #' . $sid);
        }
        flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: nominee-settlements.php?id=' . $sid);
        exit;
    }

    if ($action === 'settle') {
        $sid = (int) ($_POST['settlement_id'] ?? 0);
        $bank = trim((string) ($_POST['nominee_bank_details'] ?? ''));
        $ref = trim((string) ($_POST['payout_ref'] ?? ''));
        $note = trim((string) ($_POST['admin_note'] ?? ''));
        if ($bank === '' || strlen($bank) < 8) {
            flash('error', 'Enter nominee bank / UPI details for the remittance.');
        } else {
            $res = nominee_settle($pdo, $sid, $adminId, $bank, $ref, $note);
            if ($res['ok']) {
                log_activity('nominee_settle', 'Settled nominee case #' . $sid . ($ref !== '' ? " ref=$ref" : ''));
            }
            flash($res['ok'] ? 'success' : 'error', $res['message']);
        }
        header('Location: nominee-settlements.php?id=' . $sid);
        exit;
    }

    if ($action === 'reject') {
        $sid = (int) ($_POST['settlement_id'] ?? 0);
        $note = trim((string) ($_POST['admin_note'] ?? ''));
        $res = nominee_reject_case($pdo, $sid, $adminId, $note);
        flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: nominee-settlements.php?id=' . $sid);
        exit;
    }

    header('Location: nominee-settlements.php');
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$searchHits = [];
if ($search !== '' && $viewId < 1) {
    $like = '%' . $search . '%';
    $st = $pdo->prepare("
        SELECT id, member_id, full_name, phone, email, status, nominee_name, wallet_balance
        FROM members
        WHERE member_id LIKE ? OR username LIKE ? OR full_name LIKE ? OR email LIKE ? OR phone LIKE ?
        ORDER BY id DESC
        LIMIT 12
    ");
    $st->execute([$like, $like, $like, $like, $like]);
    $searchHits = $st->fetchAll();
}

$where = ['1=1'];
$params = [];
if (in_array($statusFilter, ['docs_pending', 'verified', 'settled', 'rejected'], true)) {
    $where[] = 's.status = ?';
    $params[] = $statusFilter;
} else {
    $where[] = "s.status IN ('open','docs_pending','verified','settled','rejected')";
}
$list = [];
try {
    $sql = '
        SELECT s.*, m.member_id AS mid, m.full_name
        FROM member_nominee_settlements s
        INNER JOIN members m ON m.id = s.member_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY s.id DESC
        LIMIT 40
    ';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $list = $st->fetchAll();
} catch (Throwable $e) {
    $list = [];
}

$case = $viewId > 0 ? nominee_case_by_id($pdo, $viewId) : null;
$docs = $case ? nominee_docs($pdo, (int) $case['id']) : [];
$accrued = $case ? nominee_accrued($pdo, (int) $case['member_id']) : null;
$memberKyc = $case ? strtolower((string) ($case['kyc_status'] ?? '')) : '';
$docsOk = $case ? nominee_required_docs_approved($pdo, (int) $case['id']) : false;

$icoUser = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
$icoDoc = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
$icoInr = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/></svg>';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="cls-page">
    <header class="rpt-hero">
        <div class="rpt-hero-glow" aria-hidden="true"></div>
        <div class="rpt-hero-main">
            <span class="rpt-hero-ico"><?= $icoUser ?></span>
            <div>
                <p class="rpt-kicker">Legal � KYC</p>
                <h1>Nominee Settlement</h1>
                <p class="rpt-sub">Death of an eligible member ? nominee KYC and legal papers ? settle accrued Income Wallet benefits.</p>
            </div>
        </div>
    </header>

    <?php if ($case): ?>
        <div class="ops-cycle-banner <?= ($case['status'] === 'settled') ? 'is-ok' : 'is-wait' ?>">
            <div>
                <strong><?= e($case['full_name']) ?> � <?= e($case['mid']) ?></strong>
                <p>
                    Case #<?= (int) $case['id'] ?> � <?= e(ucfirst(str_replace('_', ' ', (string) $case['status']))) ?>
                    <?= !empty($case['death_date']) ? ' � Date of death ' . e(date('d M Y', strtotime((string) $case['death_date']))) : '' ?>
                    � Member KYC <?= e($memberKyc !== '' ? $memberKyc : 'n/a') ?>
                </p>
            </div>
            <a class="btn btn-outline btn-sm" href="member-view.php?id=<?= (int) $case['member_id'] ?>">Member profile</a>
        </div>

        <div class="rpt-stats">
            <article class="rpt-stat">
                <span class="rpt-stat-ico is-gold"><?= $icoInr ?></span>
                <div>
                    <span class="rpt-stat-label">Income wallet now</span>
                    <strong><?= currency((float) $accrued['wallet']) ?></strong>
                    <small>Accrued benefit to settle</small>
                </div>
            </article>
            <article class="rpt-stat">
                <span class="rpt-stat-ico is-coral"><?= $icoInr ?></span>
                <div>
                    <span class="rpt-stat-label">Pending requests</span>
                    <strong><?= currency((float) $accrued['pending_wd']) ?></strong>
                    <small>Cancelled on settle</small>
                </div>
            </article>
            <article class="rpt-stat">
                <span class="rpt-stat-ico is-blue"><?= $icoInr ?></span>
                <div>
                    <span class="rpt-stat-label">Approved unpaid</span>
                    <strong><?= currency((float) $accrued['approved_wd_net']) ?></strong>
                    <small>Already deducted � pay via withdrawals</small>
                </div>
            </article>
            <article class="rpt-stat">
                <span class="rpt-stat-ico is-green"><?= $icoDoc ?></span>
                <div>
                    <span class="rpt-stat-label">Legal pack</span>
                    <strong><?= $docsOk ? 'Ready' : 'Incomplete' ?></strong>
                    <small>4 documents required</small>
                </div>
            </article>
        </div>

        <div class="cls-grid">
            <section class="rpt-panel">
                <div class="rpt-panel-head is-blue">
                    <div class="rpt-panel-main">
                        <span class="rpt-panel-ico"><?= $icoUser ?></span>
                        <div>
                            <span class="rpt-kicker">Nominee</span>
                            <h2>Designated nominee</h2>
                        </div>
                    </div>
                </div>
                <div class="rpt-panel-body">
                    <?php if (in_array($case['status'], ['settled', 'rejected'], true)): ?>
                        <p class="cls-help">
                            <strong><?= e((string) ($case['nominee_name'] ?? '-')) ?></strong>
                            · <?= e((string) ($case['nominee_relation'] ?? '-')) ?>
                        </p>
                    <?php else: ?>
                    <form method="post" class="form-grid" style="grid-template-columns:1fr 1fr;gap:0.85rem">
                        <input type="hidden" name="action" value="save_nominee">
                        <input type="hidden" name="settlement_id" value="<?= (int) $case['id'] ?>">
                        <div class="form-group">
                            <label>Name *</label>
                            <input type="text" name="nominee_name" required value="<?= e((string) ($case['nominee_name'] ?? '')) ?>">
                        </div>
                        <div class="form-group">
                            <label>Relation *</label>
                            <select name="nominee_relation" required>
                                <option value="">Select</option>
                                <?php foreach (nominee_relations() as $rel): ?>
                                    <option value="<?= e($rel) ?>" <?= ($case['nominee_relation'] ?? '') === $rel ? 'selected' : '' ?>><?= e($rel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary">Save nominee</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </section>

            <section class="rpt-panel">
                <div class="rpt-panel-head is-gold">
                    <div class="rpt-panel-main">
                        <span class="rpt-panel-ico"><?= $icoDoc ?></span>
                        <div>
                            <span class="rpt-kicker">Documents</span>
                            <h2>KYC &amp; legal pack</h2>
                        </div>
                    </div>
                </div>
                <div class="rpt-panel-body">
                    <?php foreach (nominee_doc_types() as $type => $label):
                        $d = $docs[$type] ?? null;
                        $st = strtolower((string) ($d['status'] ?? 'missing'));
                        $url = !empty($d['file_path']) ? kyc_doc_admin_url((string) $d['file_path']) : null;
                        ?>
                        <div class="ops-check <?= $st === 'approved' ? 'is-ok' : 'is-wait' ?>" style="margin-bottom:0.65rem">
                            <div style="flex:1">
                                <strong><?= e($label) ?></strong>
                                <p><?= e(ucfirst($st)) ?><?= !empty($d['admin_note']) ? ' � ' . e((string) $d['admin_note']) : '' ?></p>
                                <?php if ($url): ?>
                                    <p><a href="<?= e($url) ?>" target="_blank" rel="noopener">View file</a></p>
                                <?php endif; ?>
                                <?php if (!in_array($case['status'], ['settled', 'rejected'], true)): ?>
                                <form method="post" enctype="multipart/form-data" style="margin-top:0.4rem;display:flex;flex-wrap:wrap;gap:0.4rem;align-items:center">
                                    <input type="hidden" name="action" value="upload_doc">
                                    <input type="hidden" name="settlement_id" value="<?= (int) $case['id'] ?>">
                                    <input type="hidden" name="doc_type" value="<?= e($type) ?>">
                                    <input type="file" name="document" accept=".jpg,.jpeg,.png,.webp,.pdf" required style="font-size:0.8rem">
                                    <button type="submit" class="btn btn-outline btn-sm">Upload</button>
                                </form>
                                <?php if ($d && $st === 'pending'): ?>
                                <form method="post" style="margin-top:0.35rem;display:flex;flex-wrap:wrap;gap:0.35rem">
                                    <input type="hidden" name="action" value="review_doc">
                                    <input type="hidden" name="settlement_id" value="<?= (int) $case['id'] ?>">
                                    <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
                                    <input type="text" name="doc_note" placeholder="Note" style="padding:0.3rem;font-size:0.8rem;border:1px solid var(--border);border-radius:6px">
                                    <button type="submit" name="review" value="approve" class="btn btn-primary btn-sm">Approve</button>
                                    <button type="submit" name="review" value="reject" class="btn btn-outline btn-sm">Reject</button>
                                </form>
                                <?php endif; endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <?php if (!in_array($case['status'], ['settled', 'rejected'], true)): ?>
        <div class="cls-grid">
            <section class="rpt-panel">
                <div class="rpt-panel-head is-green">
                    <div class="rpt-panel-main">
                        <span class="rpt-panel-ico"><?= $icoDoc ?></span>
                        <div>
                            <span class="rpt-kicker">Step 2</span>
                            <h2>Verify legal pack</h2>
                        </div>
                    </div>
                </div>
                <div class="rpt-panel-body cls-actions">
                    <p class="cls-help">All four documents must be approved. Member KYC on file: <strong><?= e($memberKyc !== '' ? $memberKyc : 'not submitted') ?></strong>.</p>
                    <form method="post">
                        <input type="hidden" name="action" value="verify">
                        <input type="hidden" name="settlement_id" value="<?= (int) $case['id'] ?>">
                        <button type="submit" class="btn btn-primary" <?= ($docsOk && ($case['status'] !== 'verified')) ? '' : 'disabled' ?>>
                            <?= $case['status'] === 'verified' ? 'Already verified' : 'Mark verified' ?>
                        </button>
                    </form>
                </div>
            </section>
            <section class="rpt-panel">
                <div class="rpt-panel-head is-coral">
                    <div class="rpt-panel-main">
                        <span class="rpt-panel-ico"><?= $icoInr ?></span>
                        <div>
                            <span class="rpt-kicker">Step 3</span>
                            <h2>Settle accrued benefits</h2>
                        </div>
                    </div>
                </div>
                <div class="rpt-panel-body">
                    <?php
                    $preview = wd_calc_breakdown($pdo, (float) $accrued['wallet']);
                    ?>
                    <p class="cls-help">
                        Gross <?= currency($preview['gross']) ?>
                        � TDS <?= currency($preview['tds_amount']) ?>
                        � Admin charges <?= currency((float) $preview['fee_amount'] + (float) $preview['other_deduction']) ?>
                        � <strong>Net <?= currency($preview['net_amount']) ?></strong>
                    </p>
                    <form method="post" class="cls-confirm-form" onsubmit="return confirm('Remit net amount to the nominee and close this ID?');">
                        <input type="hidden" name="action" value="settle">
                        <input type="hidden" name="settlement_id" value="<?= (int) $case['id'] ?>">
                        <label class="cls-confirm" style="min-width:220px">
                            <span>Nominee bank / UPI</span>
                            <textarea name="nominee_bank_details" rows="3" required placeholder="Account, IFSC, holder" style="width:100%;border:1px solid var(--border);border-radius:8px;padding:0.45rem"><?= e((string) ($case['nominee_bank_details'] ?? '')) ?></textarea>
                        </label>
                        <label class="cls-confirm">
                            <span>UTR / ref</span>
                            <input type="text" name="payout_ref" maxlength="120" placeholder="Bank UTR">
                        </label>
                        <button type="submit" class="btn btn-primary" <?= $case['status'] === 'verified' ? '' : 'disabled' ?>>Settle to nominee</button>
                    </form>
                    <form method="post" style="margin-top:0.85rem" onsubmit="return confirm('Reject this settlement case?');">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="settlement_id" value="<?= (int) $case['id'] ?>">
                        <input type="text" name="admin_note" placeholder="Reject reason" style="padding:0.4rem;border:1px solid var(--border);border-radius:8px">
                        <button type="submit" class="btn btn-outline btn-sm">Reject case</button>
                    </form>
                </div>
            </section>
        </div>
        <?php else: ?>
            <section class="rpt-panel">
                <div class="rpt-panel-body">
                    <p class="cls-help">
                        <?= $case['status'] === 'settled'
                            ? 'Settled net ' . strip_tags(currency((float) $case['net_amount'])) . ' � UTR ' . e((string) ($case['payout_ref'] ?? '�'))
                            : 'Case rejected. ' . e((string) ($case['admin_note'] ?? '')) ?>
                    </p>
                </div>
            </section>
        <?php endif; ?>

        <p><a href="nominee-settlements.php">? All cases</a></p>
    <?php else: ?>

        <section class="rpt-panel">
            <div class="rpt-panel-head is-blue">
                <div class="rpt-panel-main">
                    <span class="rpt-panel-ico"><?= $icoUser ?></span>
                    <div>
                        <span class="rpt-kicker">New case</span>
                        <h2>Report a death</h2>
                    </div>
                </div>
            </div>
            <div class="rpt-panel-body">
                <form method="get" class="filters">
                    <div class="form-group">
                        <label>Find member</label>
                        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Member ID, name, phone">
                    </div>
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
                <?php if ($searchHits): ?>
                <div class="rpt-table-wrap" style="margin-top:1rem">
                    <table class="rpt-table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Nominee on file</th>
                                <th>Wallet</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($searchHits as $h):
                            $openAlready = nominee_case_for_member($pdo, (int) $h['id']);
                            ?>
                            <tr>
                                <td>
                                    <strong><?= e($h['full_name']) ?></strong>
                                    <small class="cls-muted"><?= e($h['member_id']) ?> � <?= e((string) $h['status']) ?></small>
                                </td>
                                <td><?= e((string) ($h['nominee_name'] ?: '�')) ?></td>
                                <td><?= currency((float) $h['wallet_balance']) ?></td>
                                <td>
                                    <?php if ($openAlready && ($openAlready['status'] ?? '') !== 'rejected'): ?>
                                        <a href="nominee-settlements.php?id=<?= (int) $openAlready['id'] ?>">Open case</a>
                                    <?php else: ?>
                                    <form method="post" onsubmit="return confirm('Mark this member deceased and close login?');">
                                        <input type="hidden" name="action" value="open">
                                        <input type="hidden" name="member_pk" value="<?= (int) $h['id'] ?>">
                                        <input type="date" name="death_date" required style="margin-bottom:0.35rem">
                                        <button type="submit" class="btn btn-primary btn-sm">Start settlement</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php elseif ($search !== ''): ?>
                    <p class="cls-help">No members matched.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="rpt-panel">
            <div class="rpt-panel-head is-gold">
                <div class="rpt-panel-main">
                    <span class="rpt-panel-ico"><?= $icoDoc ?></span>
                    <div>
                        <span class="rpt-kicker">Cases</span>
                        <h2>Settlement desk</h2>
                    </div>
                </div>
            </div>
            <div class="rpt-panel-body rpt-table-wrap">
                <form method="get" class="filters" style="margin-bottom:0.75rem">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" onchange="this.form.submit()">
                            <option value="">All</option>
                            <?php foreach (['docs_pending' => 'Docs pending', 'verified' => 'Verified', 'settled' => 'Settled', 'rejected' => 'Rejected'] as $sk => $sl): ?>
                                <option value="<?= $sk ?>" <?= $statusFilter === $sk ? 'selected' : '' ?>><?= e($sl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
                <table class="rpt-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Member</th>
                            <th>Nominee</th>
                            <th>Status</th>
                            <th>Wallet snapshot</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$list): ?>
                        <tr><td colspan="6"><div class="rpt-empty"><strong>No cases yet</strong><p>Search a member to start a death settlement.</p></div></td></tr>
                    <?php else: foreach ($list as $r): ?>
                        <tr>
                            <td><?= (int) $r['id'] ?></td>
                            <td>
                                <strong><?= e($r['full_name']) ?></strong>
                                <small class="cls-muted"><?= e($r['mid']) ?></small>
                            </td>
                            <td><?= e((string) ($r['nominee_name'] ?? '�')) ?></td>
                            <td><?= status_badge((string) $r['status']) ?></td>
                            <td><?= currency((float) $r['accrued_wallet']) ?></td>
                            <td><a href="nominee-settlements.php?id=<?= (int) $r['id'] ?>">Manage</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
