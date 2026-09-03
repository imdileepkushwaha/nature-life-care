<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ops_cycle.php';
require_admin();
feature_guard_admin_page('weekly-reconciliation');

$pageTitle = 'Weekly Reconciliation';
ops_ensure_tables($pdo);

$weekParam = trim((string) ($_GET['week'] ?? ''));
$when = preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekParam) ? ops_parse_date($weekParam) : ops_now();
$week = ops_week_for($when);
$prevWeek = ops_week_for($week['start']->modify('-1 day'));
$nextWeek = ops_week_for($week['end']->modify('+1 day'));

$adminId = (int) ($_SESSION['admin_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $postWeek = trim((string) ($_POST['week_end'] ?? $week['end_date']));
    $w = ops_week_for(ops_parse_date($postWeek));
    if ($action === 'snapshot') {
        $res = ops_save_reconciliation($pdo, $w, $adminId, false, 'Admin snapshot');
        flash($res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($action === 'reconcile') {
        $res = ops_save_reconciliation($pdo, $w, $adminId, true, trim((string) ($_POST['notes'] ?? 'Weekly recon signed off')));
        if ($res['ok']) {
            log_activity('weekly_reconciliation', 'Reconciled week ending ' . $w['end_date']);
        }
        flash($res['ok'] ? 'success' : 'error', $res['message']);
    }
    header('Location: weekly-reconciliation.php?week=' . urlencode($w['end_date']));
    exit;
}

$snap = ops_week_snapshot($pdo, $week);
$t = $snap['totals'];
$history = ops_recent_reconciliations($pdo, 16);
$cronLogs = ops_recent_cron_logs($pdo, 8);

$icoClose = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/></svg>';
$icoInr = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/></svg>';
$icoCal = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';
$icoCheck = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 6L9 17l-5-5"/></svg>';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="cls-page">
    <header class="rpt-hero">
        <div class="rpt-hero-glow" aria-hidden="true"></div>
        <div class="rpt-hero-main">
            <span class="rpt-hero-ico"><?= $icoCal ?></span>
            <div>
                <p class="rpt-kicker">Operations &amp; Payment</p>
                <h1>Weekly Reconciliation</h1>
                <p class="rpt-sub">Daily ledger vs week totals · Saturday closing · TDS / admin charges · Mon–Tue bank payout.</p>
            </div>
        </div>
        <div class="rpt-hero-actions">
            <a class="btn btn-outline btn-sm" href="weekly-reconciliation.php?week=<?= e($prevWeek['end_date']) ?>">← Prev</a>
            <a class="btn btn-outline btn-sm" href="weekly-reconciliation.php">This week</a>
            <a class="btn btn-outline btn-sm" href="weekly-reconciliation.php?week=<?= e($nextWeek['end_date']) ?>">Next →</a>
        </div>
    </header>

    <div class="ops-cycle-banner is-ok">
        <div>
            <strong><?= e($week['label']) ?></strong>
            <p>Sunday–Saturday IST · Closing <?= e(ops_weekday_name(ops_closing_weekday())) ?> · Bank <?= e(ops_payout_days_label()) ?></p>
        </div>
        <span class="ops-cycle-pill"><?= ($snap['recon']['status'] ?? '') === 'reconciled' ? 'Reconciled' : 'Open week' ?></span>
    </div>

    <div class="rpt-stats">
        <article class="rpt-stat">
            <span class="rpt-stat-ico is-green"><?= $icoInr ?></span>
            <div>
                <span class="rpt-stat-label">Ledger credits</span>
                <strong><?= currency((float) $t['ledger_credits']) ?></strong>
                <small>Daily wallet recording</small>
            </div>
        </article>
        <article class="rpt-stat">
            <span class="rpt-stat-ico is-coral"><?= $icoInr ?></span>
            <div>
                <span class="rpt-stat-label">Ledger debits</span>
                <strong><?= currency((float) $t['ledger_debits']) ?></strong>
                <small>Withdrawals / transfers</small>
            </div>
        </article>
        <article class="rpt-stat">
            <span class="rpt-stat-ico is-gold"><?= $icoClose ?></span>
            <div>
                <span class="rpt-stat-label">Closing net</span>
                <strong><?= currency((float) $t['closing_binary_net']) ?></strong>
                <small>Matching <?= currency((float) $t['closing_matching']) ?></small>
            </div>
        </article>
        <article class="rpt-stat">
            <span class="rpt-stat-ico is-blue"><?= $icoInr ?></span>
            <div>
                <span class="rpt-stat-label">Bank paid net</span>
                <strong><?= currency((float) $t['withdrawals_paid_net']) ?></strong>
                <small>TDS <?= currency((float) $t['tds_amount']) ?> · fees <?= currency((float) $t['admin_charges']) ?></small>
            </div>
        </article>
    </div>

    <div class="ops-check-grid">
        <?php foreach ($snap['checks'] as $c): ?>
            <article class="ops-check <?= !empty($c['ok']) ? 'is-ok' : 'is-wait' ?>">
                <span class="ops-check-ico"><?= !empty($c['ok']) ? $icoCheck : $icoCal ?></span>
                <div>
                    <strong><?= e((string) $c['label']) ?></strong>
                    <p><?= e((string) $c['detail']) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <section class="rpt-panel">
        <div class="panel-header">
            <h2>Daily recording · Sun–Sat ledger</h2>
        </div>
        <div class="rpt-panel-body rpt-table-wrap">
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Credits</th>
                        <th>Debits</th>
                        <th>Commissions</th>
                        <th>Joins</th>
                        <th>WD requested</th>
                        <th>Bank paid</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($snap['days'] as $d): ?>
                    <tr class="<?= !empty($d['is_today']) ? 'ops-row-today' : '' ?>">
                        <td>
                            <strong><?= e((string) $d['label']) ?></strong>
                            <?php if (!empty($d['is_closing_day'])): ?><small class="cls-muted"> · closing</small><?php endif; ?>
                            <?php if (!empty($d['is_payout_day'])): ?><small class="cls-muted"> · payout</small><?php endif; ?>
                        </td>
                        <td><?= currency((float) $d['credits']) ?></td>
                        <td><?= currency((float) $d['debits']) ?></td>
                        <td><?= currency((float) $d['commissions']) ?></td>
                        <td><?= (int) $d['joins'] ?></td>
                        <td><?= currency((float) $d['wd_requested']) ?></td>
                        <td><?= currency((float) $d['wd_paid_net']) ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr>
                        <td><strong>Week total</strong></td>
                        <td><strong><?= currency((float) $t['ledger_credits']) ?></strong></td>
                        <td><strong><?= currency((float) $t['ledger_debits']) ?></strong></td>
                        <td><strong><?= currency((float) $t['commissions_paid']) ?></strong></td>
                        <td><strong><?= (int) $t['joins_count'] ?></strong></td>
                        <td><strong><?= currency((float) $t['withdrawals_requested']) ?></strong></td>
                        <td><strong><?= currency((float) $t['withdrawals_paid_net']) ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <div class="cls-grid">
        <section class="rpt-panel">
            <div class="panel-header">
                <h2>Reconcile this week</h2>
            </div>
            <div class="rpt-panel-body cls-actions">
                <p class="cls-help">
                    Snapshot stores daily totals, Saturday closing, TDS and admin charges.
                    Sign off after you have checked the ledger against closing and payout CSV.
                </p>
                <div class="cls-btn-row">
                    <form method="post">
                        <input type="hidden" name="action" value="snapshot">
                        <input type="hidden" name="week_end" value="<?= e($week['end_date']) ?>">
                        <button type="submit" class="btn btn-outline">Save snapshot</button>
                    </form>
                    <form method="post" class="cls-confirm-form" onsubmit="return confirm('Mark this week reconciled?');">
                        <input type="hidden" name="action" value="reconcile">
                        <input type="hidden" name="week_end" value="<?= e($week['end_date']) ?>">
                        <label class="cls-confirm">
                            <span>Note (optional)</span>
                            <input type="text" name="notes" maxlength="255" placeholder="Weekly recon OK" value="<?= e((string) ($snap['recon']['notes'] ?? '')) ?>">
                        </label>
                        <button type="submit" class="btn btn-primary" <?= ($snap['recon']['status'] ?? '') === 'reconciled' ? 'disabled' : '' ?>>
                            <?= ($snap['recon']['status'] ?? '') === 'reconciled' ? 'Already reconciled' : 'Mark reconciled' ?>
                        </button>
                    </form>
                </div>
                <p class="cls-help">
                    <a href="binary-closing.php">Binary closing</a>
                    · <a href="withdrawals.php?status=approved">Approved payouts</a>
                    · <a href="tds-report.php">TDS report</a>
                    · <a href="settings.php?tab=operations">Cron / cycle settings</a>
                </p>
            </div>
        </section>

        <section class="rpt-panel">
            <div class="panel-header">
                <h2>Saved weeks</h2>
            </div>
            <div class="rpt-panel-body rpt-table-wrap">
                <table class="rpt-table">
                    <thead>
                        <tr>
                            <th>Week ending</th>
                            <th>Closing</th>
                            <th>Paid net</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$history): ?>
                        <tr><td colspan="4"><div class="rpt-empty"><strong>No snapshots yet</strong></div></td></tr>
                    <?php else: foreach ($history as $h): ?>
                        <tr>
                            <td><a href="weekly-reconciliation.php?week=<?= e((string) $h['week_end']) ?>"><?= e(date('d M Y', strtotime((string) $h['week_end']))) ?></a></td>
                            <td><?= currency((float) $h['closing_binary_net']) ?></td>
                            <td><?= currency((float) $h['withdrawals_paid_net']) ?></td>
                            <td><?= e((string) $h['status']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <?php if ($cronLogs): ?>
    <section class="rpt-panel">
        <div class="panel-header">
            <h2>Cron log</h2>
        </div>
        <div class="rpt-panel-body rpt-table-wrap">
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Job</th>
                        <th>Result</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cronLogs as $cl): ?>
                    <tr>
                        <td><?= e(date('d M Y H:i', strtotime((string) $cl['created_at']))) ?></td>
                        <td><?= e((string) $cl['job']) ?></td>
                        <td><?= ((int) $cl['ok'] === 1) ? 'OK' : 'ERR' ?></td>
                        <td><?= e((string) ($cl['message'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
