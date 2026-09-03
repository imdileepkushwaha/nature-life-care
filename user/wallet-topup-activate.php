<?php
$pageTitle = 'Activate with Topup Wallet';
require_once __DIR__ . '/../includes/wallet_topup.php';
require_once __DIR__ . '/../includes/activation.php';
require_once __DIR__ . '/includes/auth.php';
require_user();
feature_guard_user_page('wallet-topup-activate');

$user = current_user($pdo);
if (!$user || ($user['status'] ?? '') === 'blocked') {
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_code']);
    header('Location: login.php');
    exit;
}

wallet_ensure_schema($pdo);
$uid = (int) $user['id'];
$errors = [];
$balances = wallet_get_balances($pdo, $uid);
$topupBal = (float) ($balances['topup'] ?? 0);
$packages = activation_packages($pdo);

// Inactive members sponsored by current user
$downline = [];
try {
    $st = $pdo->prepare("
        SELECT id, member_id, username, full_name, phone, status, package_id, join_date
        FROM members
        WHERE sponsor_id = ? AND (package_id IS NULL OR package_id = 0)
        ORDER BY id DESC
        LIMIT 100
    ");
    $st->execute([$uid]);
    $downline = $st->fetchAll() ?: [];
} catch (Throwable $e) {
    $downline = [];
}

$selfNeeds = empty($user['package_id']);
$selfUpgrade = !$selfNeeds && activation_can_upgrade($pdo, $user);
$selfPackages = $selfNeeds
    ? $packages
    : ($selfUpgrade ? activation_upgrade_packages($pdo, (float) (activation_member_package($pdo, $user)['amount'] ?? 0)) : []);

$targetType = (string) ($_POST['target_type'] ?? ($selfNeeds || $selfUpgrade ? 'self' : 'downline'));
$memberCode = trim((string) ($_POST['member_code'] ?? ''));
$packageId = (int) ($_POST['package_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target = null;
    if ($targetType === 'self') {
        $target = $user;
    } else {
        if ($memberCode === '') {
            $errors[] = 'Enter or select the member ID to activate.';
        } else {
            $lookup = $pdo->prepare('SELECT * FROM members WHERE member_id = ? OR username = ? LIMIT 1');
            $lookup->execute([$memberCode, $memberCode]);
            $target = $lookup->fetch() ?: null;
            if (!$target) {
                $errors[] = 'Member not found.';
            }
        }
    }

    if (!$errors && $target) {
        if ($packageId <= 0) {
            $errors[] = 'Select a package.';
        } else {
            $res = wallet_topup_pay_and_activate($pdo, $user, $target, $packageId);
            if ($res['ok']) {
                $pkgName = (string) (($res['package']['name'] ?? 'package'));
                $who = $targetType === 'self' ? 'your account' : ((string) ($target['member_id'] ?? 'member'));
                flash(
                    'success',
                    ($res['mode'] === 'upgrade')
                        ? "Upgraded {$who} to {$pkgName} using Topup Wallet."
                        : "Activated {$who} ({$pkgName}) using Topup Wallet."
                );
                header('Location: wallet-topup.php');
                exit;
            }
            $errors[] = $res['error'] ?? 'Activation failed.';
        }
    }

    $balances = wallet_get_balances($pdo, $uid);
    $topupBal = (float) ($balances['topup'] ?? 0);
}

require_once __DIR__ . '/includes/header.php';
$flash = get_flash();
?>
<div class="up-page-head">
    <div>
        <h1>Activate with Topup Wallet</h1>
        <p>Use your Topup balance to activate yourself or a sponsored member instantly.</p>
    </div>
    <div class="up-head-actions">
        <a href="wallet-topup.php" class="up-btn up-btn-outline">Topup Wallet</a>
        <a href="wallet-transfer.php" class="up-btn up-btn-outline">Transfer to Shopping</a>
        <a href="register.php?ref=<?= e(urlencode((string) $user['member_id'])) ?>" class="up-btn up-btn-primary" target="_blank" rel="noopener">Add new member</a>
    </div>
</div>

<?php if ($flash): ?>
    <div class="up-alert up-alert-<?= $flash['type'] === 'error' ? 'err' : 'ok' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="up-alert up-alert-err"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="wal-stats">
    <article class="wal-stat g-blue">
        <span class="wal-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
        </span>
        <div class="wal-stat-copy">
            <span class="wal-stat-label">Topup Balance</span>
            <strong><?= currency($topupBal) ?></strong>
            <small>Will be debited on activation</small>
        </div>
    </article>
    <article class="wal-stat g-orange">
        <span class="wal-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        </span>
        <div class="wal-stat-copy">
            <span class="wal-stat-label">Inactive sponsored</span>
            <strong><?= count($downline) ?></strong>
            <small>Ready to activate</small>
        </div>
    </article>
</div>

<section class="wal-panel">
    <div class="wal-banner is-blue">
        <div class="wal-banner-main">
            <span class="wal-banner-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </span>
            <div>
                <span class="wal-kicker">Instant activation</span>
                <h2>Pay from Topup Wallet</h2>
                <p>Debit package amount and activate immediately — no admin wait</p>
            </div>
        </div>
    </div>
    <div class="wal-form-body">
        <form method="post" class="wal-form">
            <div class="up-form-grid">
                <div class="up-field full">
                    <label>Who to activate</label>
                    <div class="wal-radio-row">
                        <?php if ($selfNeeds || $selfUpgrade): ?>
                        <label class="wal-radio">
                            <input type="radio" name="target_type" value="self" <?= $targetType === 'self' ? 'checked' : '' ?> data-wta-target>
                            Myself <?= $selfUpgrade ? '(Upgrade)' : '(Activate)' ?>
                        </label>
                        <?php endif; ?>
                        <label class="wal-radio">
                            <input type="radio" name="target_type" value="downline" <?= $targetType === 'downline' || (!$selfNeeds && !$selfUpgrade) ? 'checked' : '' ?> data-wta-target>
                            Sponsored member
                        </label>
                    </div>
                </div>

                <div class="up-field full" data-wta-downline <?= ($targetType === 'self' && ($selfNeeds || $selfUpgrade)) ? 'hidden' : '' ?>>
                    <label for="member_code">Member ID / Username</label>
                    <input type="text" name="member_code" id="member_code" list="wtaDownlineList" value="<?= e($memberCode) ?>" placeholder="Select or type member ID">
                    <datalist id="wtaDownlineList">
                        <?php foreach ($downline as $d): ?>
                            <option value="<?= e($d['member_id']) ?>"><?= e($d['full_name'] . ' · ' . $d['username']) ?></option>
                        <?php endforeach; ?>
                    </datalist>
                    <?php if (!$downline): ?>
                        <small class="wal-hint">No inactive sponsored members. Register someone with your sponsor ID first.</small>
                    <?php endif; ?>
                </div>

                <div class="up-field">
                    <label for="package_id">Package</label>
                    <select name="package_id" id="package_id" required>
                        <option value="">Select package</option>
                        <?php foreach ($packages as $pkg): ?>
                            <option value="<?= (int) $pkg['id'] ?>" <?= $packageId === (int) $pkg['id'] ? 'selected' : '' ?>
                                data-amt="<?= e(number_format((float) $pkg['amount'], 2, '.', '')) ?>">
                                <?= e($pkg['name']) ?> — <?= strip_tags(currency((float) $pkg['amount'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($selfUpgrade): ?>
                        <small class="wal-hint">For self upgrade, only higher packages are accepted (difference amount is charged).</small>
                    <?php endif; ?>
                </div>
                <div class="up-field">
                    <label>Payable now</label>
                    <input type="text" id="wtaPayable" readonly value="—">
                    <small class="wal-hint">Debited instantly from Topup Wallet (no admin wait).</small>
                </div>
            </div>
            <div class="up-actions">
                <button type="submit" class="up-btn up-btn-primary" <?= $topupBal <= 0 ? 'disabled' : '' ?>>Activate Now</button>
                <?php if ($topupBal <= 0): ?>
                    <a href="wallet-topup.php" class="up-btn up-btn-outline">Add Money first</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</section>

<?php if ($downline): ?>
<section class="wal-panel" style="margin-top:1rem">
    <div class="wal-banner is-navy">
        <div class="wal-banner-main">
            <span class="wal-banner-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
            </span>
            <div>
                <span class="wal-kicker">Team</span>
                <h2>Inactive sponsored members</h2>
                <p>Select a member to fill the form above</p>
            </div>
        </div>
    </div>
    <div class="wal-table-wrap">
        <table class="wal-table">
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Joined</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($downline as $d): ?>
                <tr>
                    <td><strong><?= e($d['member_id']) ?></strong><br><small><?= e($d['username']) ?></small></td>
                    <td><?= e($d['full_name']) ?></td>
                    <td><?= e($d['phone'] ?: '—') ?></td>
                    <td><?= !empty($d['join_date']) ? e(date('d M Y', strtotime((string) $d['join_date']))) : '—' ?></td>
                    <td>
                        <button type="button" class="up-btn up-btn-outline up-btn-sm" data-wta-pick="<?= e($d['member_id']) ?>">Select</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<script>
(function () {
    var pkg = document.getElementById('package_id');
    var payable = document.getElementById('wtaPayable');
    var memberInput = document.getElementById('member_code');
    var downWrap = document.querySelector('[data-wta-downline]');

    function syncPay() {
        if (!pkg || !payable) return;
        var opt = pkg.options[pkg.selectedIndex];
        var amt = opt && opt.getAttribute('data-amt');
        payable.value = amt ? ('₹ ' + amt) : '—';
    }
    function syncTarget() {
        var self = document.querySelector('input[name="target_type"][value="self"]');
        var isSelf = self && self.checked;
        if (downWrap) downWrap.hidden = !!isSelf;
    }
    if (pkg) pkg.addEventListener('change', syncPay);
    document.querySelectorAll('[data-wta-target]').forEach(function (el) {
        el.addEventListener('change', syncTarget);
    });
    document.querySelectorAll('[data-wta-pick]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var code = btn.getAttribute('data-wta-pick') || '';
            var dl = document.querySelector('input[name="target_type"][value="downline"]');
            if (dl) { dl.checked = true; syncTarget(); }
            if (memberInput) memberInput.value = code;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });
    syncPay();
    syncTarget();
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
