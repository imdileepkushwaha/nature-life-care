<?php
/**
 * Super Admin: diagnose why a sponsor did/didn't receive DSI after a direct earned binary.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/plan_incentives.php';
require_once __DIR__ . '/../includes/income_tables.php';
require_superadmin();
$pageTitle = 'DSI Check';

$code = strtoupper(trim((string) ($_GET['direct'] ?? 'BS000002')));
$sponsorCode = strtoupper(trim((string) ($_GET['sponsor'] ?? '')));

income_tables_ensure($pdo);
plan_incentives_ensure($pdo);

$load = $pdo->prepare("
    SELECT id, member_id, full_name, username, status, package_id, sponsor_id,
           left_bv, right_bv
    FROM members
    WHERE member_id = ? OR username = ?
    LIMIT 1
");
$load->execute([$code, $code]);
$direct = $load->fetch() ?: null;

$sponsor = null;
if ($sponsorCode !== '') {
    $load->execute([$sponsorCode, $sponsorCode]);
    $sponsor = $load->fetch() ?: null;
} elseif ($direct && !empty($direct['sponsor_id'])) {
    $st = $pdo->prepare('SELECT id, member_id, full_name, username, status, package_id, sponsor_id FROM members WHERE id = ? LIMIT 1');
    $st->execute([(int) $direct['sponsor_id']]);
    $sponsor = $st->fetch() ?: null;
} else {
    // Default: first member (often root)
    $sponsor = $pdo->query('SELECT id, member_id, full_name, username, status, package_id, sponsor_id FROM members ORDER BY id ASC LIMIT 1')->fetch() ?: null;
}

$featDsi = feature_enabled('feature_dsi_income');
$featBin = feature_enabled('feature_binary_income');
$l1Pct = (float) setting('dsi_level_1_percent', '50');
$l2Pct = (float) setting('dsi_level_2_percent', '20');
$l3Pct = (float) setting('dsi_level_3_percent', '15');
$l4Pct = (float) setting('dsi_level_4_percent', '10');
$l5Pct = (float) setting('dsi_level_5_percent', '5');
$dsiBinTotal = $l1Pct + $l2Pct + $l3Pct + $l4Pct + $l5Pct;

$directBinary = [];
$sponsorBinaryDirects = [];
$dsiToSponsor = [];
$qualify = false;
$verdict = [];

if ($direct) {
    $st = $pdo->prepare("
        SELECT id, amount, status, description, created_at
        FROM commissions
        WHERE member_id = ? AND type = 'binary'
        ORDER BY id DESC
        LIMIT 20
    ");
    $st->execute([(int) $direct['id']]);
    $directBinary = $st->fetchAll();
}

if ($sponsor) {
    $qualify = plan_dsi_has_binary_direct($pdo, (int) $sponsor['id']);
    $st = $pdo->prepare("
        SELECT d.member_id, d.full_name, c.amount, c.status, c.created_at, c.description
        FROM commissions c
        INNER JOIN members d ON d.id = c.member_id AND d.sponsor_id = ?
        WHERE c.type = 'binary' AND c.status = 'paid' AND c.amount > 0
        ORDER BY c.id DESC
        LIMIT 20
    ");
    $st->execute([(int) $sponsor['id']]);
    $sponsorBinaryDirects = $st->fetchAll();

    try {
        $st = $pdo->prepare("
            SELECT id, from_member_id, amount, status, description, created_at
            FROM income_dsi
            WHERE member_id = ?
            ORDER BY id DESC
            LIMIT 30
        ");
        $st->execute([(int) $sponsor['id']]);
        $dsiToSponsor = $st->fetchAll();
    } catch (Throwable $e) {
        $dsiToSponsor = [];
    }
}

// Build verdict
if (!$featDsi) {
    $verdict[] = 'feature_dsi_income is OFF — DSI will not pay.';
}
if (!$featBin) {
    $verdict[] = 'feature_binary_income is OFF — no binary / no DSI.';
}
if ($dsiBinTotal <= 0) {
    $verdict[] = 'All DSI level % are 0 — nothing pays on binary.';
}
if (!$direct) {
    $verdict[] = "Direct member {$code} not found.";
} elseif (!$directBinary) {
    $verdict[] = $code . ' has no binary commission — DSI only pays when this ID gets binary net.';
} else {
    $paidBin = array_filter($directBinary, static fn ($r) => ($r['status'] ?? '') === 'paid' && (float) $r['amount'] > 0);
    if (!$paidBin) {
        $verdict[] = $code . ' has binary rows but none paid.';
    } else {
        $last = reset($paidBin);
        $binAmt = round((float) ($last['amount'] ?? 0), 2);
        $verdict[] = $code . ' paid binary net ₹' . number_format($binAmt, 2, '.', '') . ' → L1 DSI would be ₹' . number_format(round($binAmt * $l1Pct / 100, 2), 2, '.', '') . ' (if sponsor active).';
    }
}
if ($sponsor && $direct) {
    if ((int) ($direct['sponsor_id'] ?? 0) !== (int) $sponsor['id']) {
        $verdict[] = $code . ' sponsor is NOT ' . $sponsor['member_id'] . ' (sponsor_id=' . (int) ($direct['sponsor_id'] ?? 0) . '). Placement tree ≠ sponsor line for DSI.';
    } else {
        $verdict[] = $code . ' is a direct of ' . $sponsor['member_id'] . ' (sponsor OK — L1).';
    }
}
if ($sponsor) {
    $spActive = ($sponsor['status'] ?? '') === 'active' && !empty($sponsor['package_id']);
    if ($spActive) {
        $verdict[] = $sponsor['member_id'] . ' is active + packaged — eligible for DSI when a downline earns binary.';
    } else {
        $verdict[] = $sponsor['member_id'] . ' is NOT active/packaged — skips DSI (no roll-up).';
    }
    $pending = array_filter($dsiToSponsor, static fn ($r) => ($r['status'] ?? '') === 'pending');
    $paid = array_filter($dsiToSponsor, static fn ($r) => ($r['status'] ?? '') === 'paid');
    if (!$dsiToSponsor) {
        $verdict[] = 'No income_dsi rows for ' . $sponsor['member_id'] . ' yet.';
    } elseif ($paid) {
        $verdict[] = 'Some DSI already paid to ' . $sponsor['member_id'] . '.';
    }
    if ($pending) {
        $verdict[] = 'Legacy pending DSI rows still exist — settle on next closing if they qualify.';
    }
}

require __DIR__ . '/includes/header.php';
$envLabel = app_is_local() ? 'LOCAL' : 'LIVE';
?>
<section class="sa-hero">
    <div>
        <span class="sa-hero-kicker">Diagnose · <?= e($envLabel) ?></span>
        <h1>DSI Check</h1>
        <p>DSI = L1–L5 % of the direct’s <strong>binary net</strong> on closing, up <strong>sponsor_id</strong>. Only active + packaged uplines. Example: ₹720 → L1 50% = ₹360.</p>
    </div>
</section>

<form method="get" class="sa-panel" style="margin-bottom:1rem">
    <div class="sa-panel-body" style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end">
        <div class="form-group" style="margin:0">
            <label>Direct (who earned binary)</label>
            <input type="text" name="direct" value="<?= e($code) ?>" placeholder="Member ID">
        </div>
        <div class="form-group" style="margin:0">
            <label>Sponsor (optional — blank = their sponsor / root)</label>
            <input type="text" name="sponsor" value="<?= e($sponsorCode) ?>" placeholder="BS000001">
        </div>
        <button type="submit" class="btn btn-primary">Check</button>
    </div>
</form>

<div class="sa-panel">
    <div class="sa-panel-head"><div><h2>Verdict</h2><p>Flags &amp; relationships</p></div></div>
    <div class="sa-panel-body">
        <p class="sa-note" style="margin-bottom:0.75rem">
            DSI <?= $featDsi ? 'ON' : 'OFF' ?> · Binary <?= $featBin ? 'ON' : 'OFF' ?> · Binary L1–L5 <?= e(rtrim(rtrim(number_format($dsiBinTotal, 2, '.', ''), '0'), '.')) ?>%
        </p>
        <ul style="margin:0;padding-left:1.2rem">
            <?php foreach ($verdict as $v): ?>
                <li><?= e($v) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<div class="sa-stat-grid">
    <article class="sa-stat">
        <span class="sa-stat-label">Direct</span>
        <strong><?= $direct ? e($direct['member_id']) : '—' ?></strong>
        <small><?= $direct ? e($direct['full_name'] . ' · ' . $direct['status'] . ' · pkg ' . ($direct['package_id'] ?: 'none')) : 'not found' ?></small>
    </article>
    <article class="sa-stat">
        <span class="sa-stat-label">Sponsor checked</span>
        <strong><?= $sponsor ? e($sponsor['member_id']) : '—' ?></strong>
        <small><?= $sponsor ? e($sponsor['full_name'] . ' · ' . (($sponsor['status'] ?? '') === 'active' && !empty($sponsor['package_id']) ? 'eligible' : 'skip')) : 'not found' ?></small>
    </article>
    <article class="sa-stat">
        <span class="sa-stat-label">Direct binary rows</span>
        <strong><?= count($directBinary) ?></strong>
        <small>Paid directs for sponsor: <?= count($sponsorBinaryDirects) ?></small>
    </article>
    <article class="sa-stat">
        <span class="sa-stat-label">DSI rows to sponsor</span>
        <strong><?= count($dsiToSponsor) ?></strong>
        <small>pending/paid/cancelled in list below</small>
    </article>
</div>

<div class="sa-panel">
    <div class="sa-panel-head"><div><h2><?= e($code) ?> — binary commissions</h2></div></div>
    <div class="sa-panel-body rpt-table-wrap">
        <table class="rpt-table">
            <thead><tr><th>#</th><th>Amount</th><th>Status</th><th>When</th><th>Description</th></tr></thead>
            <tbody>
            <?php if (!$directBinary): ?>
                <tr><td colspan="5">None</td></tr>
            <?php else: foreach ($directBinary as $r): ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><?= currency((float) $r['amount']) ?></td>
                    <td><?= e((string) $r['status']) ?></td>
                    <td><?= e((string) $r['created_at']) ?></td>
                    <td><?= e((string) $r['description']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="sa-panel">
    <div class="sa-panel-head"><div><h2>Sponsor’s directs with paid binary</h2><p>When these earn binary, DSI pays up sponsor line</p></div></div>
    <div class="sa-panel-body rpt-table-wrap">
        <table class="rpt-table">
            <thead><tr><th>Direct</th><th>Amount</th><th>When</th><th>Description</th></tr></thead>
            <tbody>
            <?php if (!$sponsorBinaryDirects): ?>
                <tr><td colspan="4">None — sponsor will not receive DSI settle</td></tr>
            <?php else: foreach ($sponsorBinaryDirects as $r): ?>
                <tr>
                    <td><?= e($r['member_id'] . ' — ' . $r['full_name']) ?></td>
                    <td><?= currency((float) $r['amount']) ?></td>
                    <td><?= e((string) $r['created_at']) ?></td>
                    <td><?= e((string) $r['description']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="sa-panel">
    <div class="sa-panel-head"><div><h2>DSI rows for sponsor</h2></div></div>
    <div class="sa-panel-body rpt-table-wrap">
        <table class="rpt-table">
            <thead><tr><th>#</th><th>From mid</th><th>Amount</th><th>Status</th><th>When</th><th>Description</th></tr></thead>
            <tbody>
            <?php if (!$dsiToSponsor): ?>
                <tr><td colspan="6">None</td></tr>
            <?php else: foreach ($dsiToSponsor as $r): ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><?= (int) $r['from_member_id'] ?></td>
                    <td><?= currency((float) $r['amount']) ?></td>
                    <td><?= e((string) $r['status']) ?></td>
                    <td><?= e((string) $r['created_at']) ?></td>
                    <td><?= e((string) $r['description']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
