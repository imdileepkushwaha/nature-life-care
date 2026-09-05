<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ops_maintenance.php';
require_superadmin();
$pageTitle = 'Closing Reset';

$snap = ops_maintenance_snapshot($pdo);
$runs = ops_maintenance_list_runs($pdo, 40);
$lastLines = $_SESSION['sa_maint_lines'] ?? null;
unset($_SESSION['sa_maint_lines']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirm = trim((string) ($_POST['confirm'] ?? ''));
    if ($confirm !== 'RESET CLOSING') {
        flash('error', 'Type RESET CLOSING to confirm.');
        header('Location: closing-reset.php');
        exit;
    }

    $scope = strtolower(trim((string) ($_POST['scope'] ?? 'last')));
    if (!in_array($scope, ['all', 'last', 'from'], true)) {
        $scope = 'last';
    }

    $opts = [
        'scope' => $scope,
        'closing_run_id' => (int) ($_POST['closing_run_id'] ?? 0),
        'reverse_binary' => isset($_POST['reverse_binary']),
        'reverse_matching' => isset($_POST['reverse_matching']),
        'reverse_dsi' => isset($_POST['reverse_dsi']),
        'clear_closing_history' => isset($_POST['clear_closing_history']),
        'sync_package_pv' => isset($_POST['sync_package_pv']),
        'purge_cancelled' => isset($_POST['purge_cancelled']),
    ];

    $result = ops_maintenance_run($pdo, $opts);
    $_SESSION['sa_maint_lines'] = $result['lines'] ?? [];
    log_superadmin_activity(
        'closing_reset',
        ($result['ok'] ? 'OK: ' : 'FAIL: ') . ($result['message'] ?? '') . ' | scope=' . $scope . ' | ' . implode('; ', $result['lines'] ?? [])
    );
    flash($result['ok'] ? 'success' : 'error', $result['message'] ?? 'Done.');
    header('Location: closing-reset.php');
    exit;
}

require __DIR__ . '/includes/header.php';
$envLabel = app_is_local() ? 'LOCAL database' : 'LIVE database';
$defaultRunId = (int) ($snap['last_run_id'] ?? 0);
?>
<section class="sa-hero">
    <div>
        <span class="sa-hero-kicker">Server maintenance</span>
        <h1>Closing Reset</h1>
        <p>Reverse closing payouts on <strong><?= e($envLabel) ?></strong>. Prefer <strong>Last closing</strong> — “All” wipes every run.</p>
    </div>
</section>

<div class="sa-stat-grid">
    <article class="sa-stat">
        <span class="sa-stat-label">Binary closing (paid)</span>
        <strong><?= (int) $snap['binary_paid'] ?></strong>
        <small><?= currency((float) $snap['binary_sum']) ?></small>
    </article>
    <article class="sa-stat">
        <span class="sa-stat-label">Paid DSI</span>
        <strong><?= (int) $snap['dsi_paid'] ?></strong>
        <small><?= currency((float) $snap['dsi_sum']) ?> · pending <?= (int) $snap['dsi_pending'] ?></small>
    </article>
    <article class="sa-stat">
        <span class="sa-stat-label">Closing runs</span>
        <strong><?= (int) $snap['closing_runs'] ?></strong>
        <small><?php if (!empty($snap['last_run_id'])): ?>Last #<?= (int) $snap['last_run_id'] ?> · <?= e((string) $snap['last_run_at']) ?><?php else: ?>None<?php endif; ?></small>
    </article>
    <article class="sa-stat">
        <span class="sa-stat-label">Starter PV / old lots</span>
        <strong><?= $snap['starter_bv'] !== null ? number_format((float) $snap['starter_bv'], 0) : '—' ?></strong>
        <small>Lots still 1599: <?= (int) $snap['lots_1599'] ?></small>
    </article>
</div>

<?php if (is_array($lastLines) && $lastLines): ?>
<div class="sa-panel">
    <div class="sa-panel-head">
        <div>
            <h2>Last run report</h2>
            <p>From your previous confirm</p>
        </div>
    </div>
    <div class="sa-panel-body">
        <ul class="sa-note" style="margin:0;padding-left:1.2rem">
            <?php foreach ($lastLines as $line): ?>
                <li><?= e((string) $line) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<form method="post" class="sa-panel" onsubmit="return confirm('This will change wallets and income on <?= e($envLabel) ?>. Continue?');">
    <div class="sa-panel-head">
        <div>
            <h2>Actions</h2>
            <p>Choose scope first, then what to reverse</p>
        </div>
        <span class="sa-chip <?= app_is_local() ? 'off' : 'on' ?>"><?= e(strtoupper(app_is_local() ? 'local' : 'live')) ?></span>
    </div>
    <div class="sa-panel-body">
        <div class="sa-note" style="margin-bottom:1rem">
            <strong>Last</strong> = sirf latest closing (BV bhi wapas).  
            <strong>From selected</strong> = us run se lekar aaj tak ki saari newer closings (purani din pick karoge to beech wali bhi undo).  
            <strong>All</strong> = har closing wipe. Wallet claw balance-limited hai.
        </div>

        <fieldset style="border:0;margin:0 0 1.25rem;padding:0">
            <legend style="font-weight:600;margin-bottom:0.5rem">Scope</legend>
            <label class="sa-mod" style="cursor:pointer;display:block;margin-bottom:0.5rem">
                <span class="sa-mod-name">
                    <input type="radio" name="scope" value="last" checked>
                    Last closing only<?= $defaultRunId ? ' (#' . $defaultRunId . ')' : '' ?>
                </span>
            </label>
            <label class="sa-mod" style="cursor:pointer;display:block;margin-bottom:0.5rem">
                <span class="sa-mod-name">
                    <input type="radio" name="scope" value="from" id="scope-from">
                    From this closing (and all newer)
                </span>
            </label>
            <div class="form-group" style="margin:0.5rem 0 0.75rem 1.5rem;max-width:420px">
                <label for="closing_run_id">Closing run</label>
                <select name="closing_run_id" id="closing_run_id">
                    <?php if (!$runs): ?>
                        <option value="0">No closing runs</option>
                    <?php else: ?>
                        <?php foreach ($runs as $r): ?>
                            <?php
                            $rid = (int) $r['id'];
                            $label = '#' . $rid . ' · ' . (string) ($r['created_at'] ?? '')
                                . ' · paid ' . (int) ($r['members_paid'] ?? 0)
                                . ' · net ₹' . number_format((float) ($r['binary_net_total'] ?? 0), 2, '.', '');
                            ?>
                            <option value="<?= $rid ?>"<?= $rid === $defaultRunId ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <label class="sa-mod" style="cursor:pointer;display:block">
                <span class="sa-mod-name">
                    <input type="radio" name="scope" value="all">
                    All closings (full wipe)
                </span>
            </label>
        </fieldset>

        <div class="sa-mod-grid" style="margin-bottom:1.25rem">
            <label class="sa-mod" style="cursor:pointer">
                <span class="sa-mod-name">
                    <input type="checkbox" name="reverse_binary" value="1" checked>
                    Reverse &amp; delete binary closing income
                </span>
            </label>
            <label class="sa-mod" style="cursor:pointer">
                <span class="sa-mod-name">
                    <input type="checkbox" name="reverse_matching" value="1" checked>
                    Reverse &amp; delete matching from closing
                </span>
            </label>
            <label class="sa-mod" style="cursor:pointer">
                <span class="sa-mod-name">
                    <input type="checkbox" name="reverse_dsi" value="1" checked>
                    Reverse &amp; delete DSI linked to that binary
                </span>
            </label>
            <label class="sa-mod" style="cursor:pointer">
                <span class="sa-mod-name">
                    <input type="checkbox" name="clear_closing_history" value="1" checked>
                    Clear that run history + restore BV / pairs
                </span>
            </label>
            <label class="sa-mod" style="cursor:pointer">
                <span class="sa-mod-name">
                    <input type="checkbox" name="purge_cancelled" value="1">
                    Purge cancelled DSI / income entries
                </span>
            </label>
            <label class="sa-mod" style="cursor:pointer">
                <span class="sa-mod-name">
                    <input type="checkbox" name="sync_package_pv" value="1">
                    Sync package PV to activations (e.g. 1599→1600)
                </span>
            </label>
        </div>
        <div class="form-group">
            <label>Type <kbd>RESET CLOSING</kbd> to confirm</label>
            <input type="text" name="confirm" autocomplete="off" required placeholder="RESET CLOSING" style="max-width:280px">
        </div>
        <div class="sa-form-actions">
            <button type="submit" class="btn btn-primary">Run on this server</button>
            <a href="index.php" class="btn btn-outline">Cancel</a>
        </div>
    </div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
