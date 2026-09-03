<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/closing.php';
require_admin();
feature_guard_admin_page('binary-closing');

$pageTitle = 'Binary Closing';
closing_ensure_tables($pdo);

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'preview') {
        $preview = closing_run_binary($pdo, $adminId, false);
        if (!$preview['ok']) {
            flash('error', $preview['message']);
        }
    } elseif ($action === 'run') {
        $confirm = trim((string) ($_POST['confirm'] ?? ''));
        if (strtoupper($confirm) !== 'CLOSE') {
            flash('error', 'Type CLOSE to confirm the closing run.');
        } else {
            $result = closing_run_binary($pdo, $adminId, true);
            if ($result['ok']) {
                flash('success', $result['message'] . ' Binary net: ' . currency((float) $result['binary_net_total']) . ' · Matching: ' . currency((float) $result['matching_total']));
            } else {
                flash('error', $result['message']);
            }
            header('Location: binary-closing.php');
            exit;
        }
    } elseif ($action === 'rebuild_bv') {
        $confirm = trim((string) ($_POST['confirm'] ?? ''));
        if (strtoupper($confirm) !== 'REBUILD') {
            flash('error', 'Type REBUILD to confirm BV recalculation.');
        } else {
            $result = closing_rebuild_bv($pdo);
            flash($result['ok'] ? 'success' : 'error', $result['message']);
            header('Location: binary-closing.php');
            exit;
        }
    }
}

$summary = closing_open_pair_summary($pdo);
$binaryEnabled = setting('binary_income_enabled', '1') === '1';
$matchingPct = (float) setting('matching_commission_percent', '0');
$adminCharge = (float) setting('daily_closing_admin_charge', '0');

$history = [];
try {
    $history = $pdo->query("
        SELECT * FROM closing_runs
        ORDER BY id DESC
        LIMIT 15
    ")->fetchAll();
} catch (Throwable $e) {
    $history = [];
}

$viewId = (int) ($_GET['run'] ?? 0);
$viewItems = [];
$viewRun = null;
if ($viewId > 0) {
    $st = $pdo->prepare('SELECT * FROM closing_runs WHERE id = ? LIMIT 1');
    $st->execute([$viewId]);
    $viewRun = $st->fetch() ?: null;
    if ($viewRun) {
        $it = $pdo->prepare("
            SELECT ci.*, m.member_id, m.full_name
            FROM closing_items ci
            INNER JOIN members m ON m.id = ci.member_id
            WHERE ci.closing_id = ?
            ORDER BY ci.id ASC
        ");
        $it->execute([$viewId]);
        $viewItems = $it->fetchAll();
    }
}

$icoClose = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/></svg>';
$icoInr = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/></svg>';
$icoUsers = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>';
$icoPair = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="12" r="4"/><circle cx="15" cy="12" r="4"/></svg>';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="cls-page">
    <header class="rpt-hero">
        <div class="rpt-hero-glow" aria-hidden="true"></div>
        <div class="rpt-hero-main">
            <span class="rpt-hero-ico"><?= $icoClose ?></span>
            <div>
                <p class="rpt-kicker">Daily settlement</p>
                <h1>Binary Closing</h1>
                <p class="rpt-sub">Match open BV pairs, pay binary + matching, and keep carry-forward clean.</p>
            </div>
        </div>
        <div class="rpt-hero-actions">
            <a class="btn btn-outline btn-sm" href="settings.php?tab=general">Settings</a>
        </div>
    </header>

    <?php if (!$binaryEnabled): ?>
        <div class="cls-alert">Binary income is disabled for this install.</div>
    <?php endif; ?>

    <div class="rpt-stats">
        <article class="rpt-stat">
            <span class="rpt-stat-ico is-blue"><?= $icoUsers ?></span>
            <div>
                <span class="rpt-stat-label">Ready to close</span>
                <strong><?= (int) $summary['eligible_members'] ?></strong>
                <small>members with open pairs</small>
            </div>
        </article>
        <article class="rpt-stat">
            <span class="rpt-stat-ico is-green"><?= $icoPair ?></span>
            <div>
                <span class="rpt-stat-label">Open pairs</span>
                <strong><?= number_format((float) $summary['pairs'], 2) ?></strong>
                <small>pair BV <?= number_format((float) $summary['pair_bv'], 2) ?><?= $summary['flush_pairs'] > 0 ? ' · flush ' . (int) $summary['flush_pairs'] : '' ?></small>
            </div>
        </article>
        <article class="rpt-stat">
            <span class="rpt-stat-ico is-gold"><?= $icoInr ?></span>
            <div>
                <span class="rpt-stat-label">Est. binary gross</span>
                <strong><?= currency((float) $summary['est_binary_gross']) ?></strong>
                <small><?= number_format((float) $summary['binary_percent'], 2) ?>% of matched BV</small>
            </div>
        </article>
        <article class="rpt-stat">
            <span class="rpt-stat-ico is-coral"><?= $icoInr ?></span>
            <div>
                <span class="rpt-stat-label">Matched BV open</span>
                <strong><?= number_format((float) $summary['matched_bv'], 2) ?></strong>
                <small>matching <?= number_format($matchingPct, 2) ?>% · admin <?= number_format($adminCharge, 2) ?>%</small>
            </div>
        </article>
    </div>

    <div class="cls-grid">
        <section class="rpt-panel">
            <div class="rpt-panel-head is-blue">
                <div class="rpt-panel-main">
                    <span class="rpt-panel-ico"><?= $icoClose ?></span>
                    <div>
                        <span class="rpt-kicker">Run</span>
                        <h2>Close pairs</h2>
                    </div>
                </div>
            </div>
            <div class="rpt-panel-body cls-actions">
                <p class="cls-help">
                    Each pair = <strong><?= number_format((float) $summary['pair_bv'], 2) ?> BV</strong> matched on both legs.
                    Leftover BV carries forward. Matching goes to the direct sponsor of each paid member.
                </p>
                <div class="cls-btn-row">
                    <form method="post">
                        <input type="hidden" name="action" value="preview">
                        <button type="submit" class="btn btn-outline" <?= !$binaryEnabled ? 'disabled' : '' ?>>Preview closing</button>
                    </form>
                    <form method="post" class="cls-confirm-form" onsubmit="return confirm('Run binary closing now? This will credit wallets.');">
                        <input type="hidden" name="action" value="run">
                        <label class="cls-confirm">
                            <span>Type <kbd>CLOSE</kbd> to confirm</span>
                            <input type="text" name="confirm" autocomplete="off" placeholder="CLOSE" required <?= !$binaryEnabled ? 'disabled' : '' ?>>
                        </label>
                        <button type="submit" class="btn btn-primary" <?= !$binaryEnabled ? 'disabled' : '' ?>>Run closing</button>
                    </form>
                </div>
            </div>
        </section>

        <section class="rpt-panel">
            <div class="rpt-panel-head is-gold">
                <div class="rpt-panel-main">
                    <span class="rpt-panel-ico"><?= $icoPair ?></span>
                    <div>
                        <span class="rpt-kicker">Maintenance</span>
                        <h2>Rebuild BV</h2>
                    </div>
                </div>
            </div>
            <div class="rpt-panel-body cls-actions">
                <p class="cls-help">
                    Resets all left/right BV to zero, then re-credits package BV for every activated member up the placement tree.
                    Does <strong>not</strong> change commissions or wallets.
                </p>
                <form method="post" class="cls-confirm-form" onsubmit="return confirm('Rebuild all BV from activations?');">
                    <input type="hidden" name="action" value="rebuild_bv">
                    <label class="cls-confirm">
                        <span>Type <kbd>REBUILD</kbd> to confirm</span>
                        <input type="text" name="confirm" autocomplete="off" placeholder="REBUILD" required>
                    </label>
                    <button type="submit" class="btn btn-outline">Rebuild BV now</button>
                </form>
            </div>
        </section>
    </div>

    <?php if (is_array($preview) && !empty($preview['ok'])): ?>
        <section class="rpt-panel">
            <div class="rpt-panel-head is-coral">
                <div class="rpt-panel-main">
                    <span class="rpt-panel-ico"><?= $icoInr ?></span>
                    <div>
                        <span class="rpt-kicker">Dry run</span>
                        <h2>Closing preview</h2>
                    </div>
                </div>
                <span class="rpt-head-meta"><?= (int) $preview['members_paid'] ?> payable</span>
            </div>
            <div class="rpt-panel-body">
                <div class="cls-preview-stats">
                    <div><span>Pairs</span><strong><?= number_format((float) $preview['pairs_total'], 2) ?></strong></div>
                    <div><span>Matched BV</span><strong><?= number_format((float) $preview['matched_bv_total'], 2) ?></strong></div>
                    <div><span>Binary gross</span><strong><?= currency((float) $preview['binary_gross_total']) ?></strong></div>
                    <div><span>Admin charge</span><strong><?= currency((float) $preview['admin_charge_total']) ?></strong></div>
                    <div><span>Binary net</span><strong><?= currency((float) $preview['binary_net_total']) ?></strong></div>
                    <div><span>Matching</span><strong><?= currency((float) $preview['matching_total']) ?></strong></div>
                </div>
                <?php if (empty($preview['items'])): ?>
                    <div class="rpt-empty"><strong>No pairs</strong><p>Activate members on both legs to create open BV.</p></div>
                <?php else: ?>
                    <div class="rpt-table-wrap">
                        <table class="rpt-table">
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>L / R before</th>
                                    <th>Pairs</th>
                                    <th>Matched BV</th>
                                    <th>Binary net</th>
                                    <th>Matching</th>
                                    <th>L / R after</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($preview['items'] as $it): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($it['full_name']) ?></strong>
                                        <small class="cls-muted"><?= e($it['member_code']) ?></small>
                                    </td>
                                    <td><?= number_format((float) $it['left_bv_before'], 2) ?> / <?= number_format((float) $it['right_bv_before'], 2) ?></td>
                                    <td><?= number_format((float) $it['pairs'], 2) ?></td>
                                    <td><?= number_format((float) $it['matched_bv'], 2) ?></td>
                                    <td><strong class="rpt-amt"><?= currency((float) $it['binary_net']) ?></strong></td>
                                    <td><?= currency((float) $it['matching_amount']) ?></td>
                                    <td><?= number_format((float) $it['left_bv_after'], 2) ?> / <?= number_format((float) $it['right_bv_after'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($viewRun): ?>
        <section class="rpt-panel">
            <div class="rpt-panel-head is-green">
                <div class="rpt-panel-main">
                    <span class="rpt-panel-ico"><?= $icoClose ?></span>
                    <div>
                        <span class="rpt-kicker">Closing #<?= (int) $viewRun['id'] ?></span>
                        <h2>Run detail</h2>
                    </div>
                </div>
                <a class="rpt-head-meta" href="binary-closing.php">← Back</a>
            </div>
            <div class="rpt-panel-body">
                <div class="cls-preview-stats">
                    <div><span>When</span><strong><?= e(date('d M Y H:i', strtotime((string) $viewRun['created_at']))) ?></strong></div>
                    <div><span>Paid</span><strong><?= (int) $viewRun['members_paid'] ?></strong></div>
                    <div><span>Binary net</span><strong><?= currency((float) $viewRun['binary_net_total']) ?></strong></div>
                    <div><span>Matching</span><strong><?= currency((float) $viewRun['matching_total']) ?></strong></div>
                </div>
                <div class="rpt-table-wrap">
                    <table class="rpt-table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Pairs</th>
                                <th>Matched BV</th>
                                <th>Binary net</th>
                                <th>Matching</th>
                                <th>Carry L / R</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$viewItems): ?>
                            <tr><td colspan="6"><div class="rpt-empty"><strong>No line items</strong></div></td></tr>
                        <?php else: foreach ($viewItems as $it): ?>
                            <tr>
                                <td>
                                    <a class="rpt-member" href="member-view.php?id=<?= (int) $it['member_id'] ?>">
                                        <strong><?= e($it['full_name']) ?></strong>
                                        <small><?= e($it['member_id']) ?></small>
                                    </a>
                                </td>
                                <td><?= number_format((float) $it['pairs'], 2) ?></td>
                                <td><?= number_format((float) $it['matched_bv'], 2) ?></td>
                                <td><strong class="rpt-amt"><?= currency((float) $it['binary_net']) ?></strong></td>
                                <td><?= currency((float) $it['matching_amount']) ?></td>
                                <td><?= number_format((float) $it['left_bv_after'], 2) ?> / <?= number_format((float) $it['right_bv_after'], 2) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="rpt-panel">
        <div class="rpt-panel-head is-blue">
            <div class="rpt-panel-main">
                <span class="rpt-panel-ico"><?= $icoClose ?></span>
                <div>
                    <span class="rpt-kicker">History</span>
                    <h2>Recent closings</h2>
                </div>
            </div>
        </div>
        <div class="rpt-panel-body rpt-table-wrap">
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Paid</th>
                        <th>Pairs</th>
                        <th>Binary net</th>
                        <th>Matching</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$history): ?>
                    <tr><td colspan="7"><div class="rpt-empty"><strong>No closings yet</strong><p>Run a preview, then confirm CLOSE.</p></div></td></tr>
                <?php else: foreach ($history as $h): ?>
                    <tr>
                        <td><span class="rpt-rank"><?= (int) $h['id'] ?></span></td>
                        <td><?= e(date('d M Y H:i', strtotime((string) $h['created_at']))) ?></td>
                        <td><?= (int) $h['members_paid'] ?></td>
                        <td><?= number_format((float) $h['pairs_total'], 2) ?></td>
                        <td><strong class="rpt-amt"><?= currency((float) $h['binary_net_total']) ?></strong></td>
                        <td><?= currency((float) $h['matching_total']) ?></td>
                        <td><a href="binary-closing.php?run=<?= (int) $h['id'] ?>">View</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
