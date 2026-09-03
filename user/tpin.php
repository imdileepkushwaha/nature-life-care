<?php
require_once __DIR__ . '/../includes/tpin.php';
require_once __DIR__ . '/includes/auth.php';
require_user();
feature_guard_user_page('tpin');

$user = current_user($pdo);
if (!$user || ($user['status'] ?? '') === 'blocked') {
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_code']);
    header('Location: login.php');
    exit;
}

$pageTitle = 'T-Pin';
tpin_ensure_tables($pdo);
$errors = [];
$uid = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pinCode = (string) ($_POST['pin_code'] ?? '');
    $toLogin = (string) ($_POST['to_member'] ?? '');
    $res = tpin_transfer($pdo, $user, $pinCode, $toLogin);
    if ($res['ok']) {
        flash('success', 'T-Pin transferred successfully.');
        header('Location: tpin.php');
        exit;
    }
    $errors[] = $res['error'] ?? 'Transfer failed.';
}

$unused = tpin_member_unused($pdo, $uid);
$used = tpin_member_used($pdo, $uid, 40);
$transfers = tpin_member_transfers($pdo, $uid, 40);
$unusedCount = count($unused);
$usedCount = count($used);
$transferCount = count($transfers);
$canActivate = empty($user['package_id']) || activation_can_upgrade($pdo, $user);

require_once __DIR__ . '/includes/header.php';
$flash = get_flash();
?>

<div class="up-page-head">
    <div>
        <h1>T-Pin</h1>
        <p>Package topup pins in your wallet — transfer to teammates or activate instantly.</p>
    </div>
    <div class="tpin-head-actions">
        <?php if ($canActivate): ?>
            <a href="activate.php" class="up-btn up-btn-primary">Use on Activate</a>
        <?php endif; ?>
        <a href="index.php" class="up-btn up-btn-outline">Dashboard</a>
    </div>
</div>

<?php if ($flash): ?>
    <div class="up-alert up-alert-<?= $flash['type'] === 'error' ? 'err' : 'ok' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="up-alert up-alert-err"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="tpin-stats">
    <article class="tpin-stat g-green">
        <span class="tpin-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 9h4M7 13h10M15 9h2"/></svg>
        </span>
        <div>
            <span class="tpin-stat-label">Unused</span>
            <strong><?= $unusedCount ?></strong>
            <small>Ready to use / transfer</small>
        </div>
    </article>
    <article class="tpin-stat g-blue">
        <span class="tpin-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </span>
        <div>
            <span class="tpin-stat-label">Used</span>
            <strong><?= $usedCount ?></strong>
            <small>Redeemed by you</small>
        </div>
    </article>
    <article class="tpin-stat g-orange">
        <span class="tpin-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 014-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
        </span>
        <div>
            <span class="tpin-stat-label">Transfers</span>
            <strong><?= $transferCount ?></strong>
            <small>Sent &amp; received</small>
        </div>
    </article>
</div>

<div class="tpin-layout">
    <section class="tpin-card">
        <div class="tpin-banner is-gold">
            <div class="tpin-banner-main">
                <span class="tpin-banner-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 014-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
                </span>
                <div>
                    <span class="tpin-kicker">Wallet action</span>
                    <h2>Transfer T-Pin</h2>
                    <p>Send one unused pin to another member by Member ID or username.</p>
                </div>
            </div>
        </div>
        <div class="tpin-body">
            <?php if (!$unused): ?>
                <div class="tpin-empty">
                    <strong>No unused pins</strong>
                    <p>Ask admin or your upline to assign / transfer a T-Pin to your wallet.</p>
                </div>
            <?php else: ?>
            <form method="post" class="tpin-form">
                <div class="up-field">
                    <label for="pin_code">Select T-Pin *</label>
                    <select name="pin_code" id="pin_code" required>
                        <option value="">Choose unused pin</option>
                        <?php foreach ($unused as $p): ?>
                            <option value="<?= e(tpin_format_code((string) $p['pin_code'])) ?>">
                                <?= e(tpin_format_code((string) $p['pin_code'])) ?> · <?= e($p['package_name']) ?> (<?= currency((float) $p['package_amount']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="up-field">
                    <label for="to_member">Receiver Member ID / username *</label>
                    <input type="text" name="to_member" id="to_member" required
                           placeholder="e.g. MLM00002"
                           value="<?= e($_POST['to_member'] ?? '') ?>">
                </div>
                <div class="tpin-form-actions">
                    <button type="submit" class="up-btn up-btn-primary">Transfer pin</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </section>

    <aside class="tpin-card tpin-side">
        <div class="tpin-banner is-teal">
            <div class="tpin-banner-main">
                <span class="tpin-banner-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                </span>
                <div>
                    <span class="tpin-kicker">Quick tip</span>
                    <h2>How to use</h2>
                </div>
            </div>
        </div>
        <div class="tpin-body">
            <ol class="tpin-tips">
                <li>Open <a href="activate.php">Activate / Upgrade</a></li>
                <li>Select the matching package</li>
                <li>Choose <strong>T-Pin</strong> payment mode</li>
                <li>Enter or pick the pin — activation is instant</li>
            </ol>
        </div>
    </aside>
</div>

<section class="tpin-card" style="margin-top:1rem">
    <div class="tpin-banner is-navy">
        <div class="tpin-banner-main">
            <span class="tpin-banner-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 9h4M7 13h10"/></svg>
            </span>
            <div>
                <span class="tpin-kicker">Wallet</span>
                <h2>Unused pins</h2>
                <p><?= $unusedCount ?> pin<?= $unusedCount === 1 ? '' : 's' ?> ready in your wallet</p>
            </div>
        </div>
    </div>
    <div class="tpin-body">
        <?php if (!$unused): ?>
            <div class="tpin-empty">
                <strong>Wallet is empty</strong>
                <p>When admin assigns pins or someone transfers to you, they will show here.</p>
            </div>
        <?php else: ?>
            <div class="tpin-pin-grid">
                <?php foreach ($unused as $p):
                    $code = tpin_format_code((string) $p['pin_code']);
                ?>
                <article class="tpin-pin-card">
                    <div class="tpin-pin-top">
                        <span class="tpin-pin-pkg"><?= e($p['package_name']) ?></span>
                        <span class="tpin-pin-amt"><?= currency((float) $p['package_amount']) ?></span>
                    </div>
                    <code class="tpin-pin-code" data-copy="<?= e($code) ?>"><?= e($code) ?></code>
                    <div class="tpin-pin-foot">
                        <small><?= !empty($p['created_at']) ? e(date('d M Y', strtotime((string) $p['created_at']))) : '—' ?></small>
                        <button type="button" class="tpin-copy-btn" data-copy="<?= e($code) ?>">Copy</button>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<div class="tpin-split" style="margin-top:1rem">
    <section class="tpin-card">
        <div class="tpin-banner is-muted">
            <div class="tpin-banner-main">
                <div>
                    <span class="tpin-kicker">History</span>
                    <h2>Used by you</h2>
                </div>
            </div>
        </div>
        <div class="tpin-table-wrap">
            <table class="tpin-table">
                <thead>
                    <tr>
                        <th>T-Pin</th>
                        <th>Package</th>
                        <th>Used at</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$used): ?>
                    <tr><td colspan="3" class="tpin-td-empty">No used pins yet.</td></tr>
                <?php else: foreach ($used as $p): ?>
                    <tr>
                        <td><code class="tpin-code-sm"><?= e(tpin_format_code((string) $p['pin_code'])) ?></code></td>
                        <td><?= e($p['package_name']) ?></td>
                        <td><?= !empty($p['used_at']) ? e(date('d M Y H:i', strtotime((string) $p['used_at']))) : '—' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="tpin-card">
        <div class="tpin-banner is-muted">
            <div class="tpin-banner-main">
                <div>
                    <span class="tpin-kicker">History</span>
                    <h2>Transfers</h2>
                </div>
            </div>
        </div>
        <div class="tpin-table-wrap">
            <table class="tpin-table">
                <thead>
                    <tr>
                        <th>T-Pin</th>
                        <th>From → To</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$transfers): ?>
                    <tr><td colspan="3" class="tpin-td-empty">No transfers yet.</td></tr>
                <?php else: foreach ($transfers as $t):
                    $sent = (int) $t['from_member_id'] === $uid;
                ?>
                    <tr>
                        <td>
                            <code class="tpin-code-sm"><?= e(tpin_format_code((string) $t['pin_code'])) ?></code>
                            <div class="tpin-dir <?= $sent ? 'is-out' : 'is-in' ?>"><?= $sent ? 'Sent' : 'Received' ?></div>
                        </td>
                        <td>
                            <strong><?= e($t['from_code']) ?></strong>
                            <span class="tpin-arrow">→</span>
                            <strong><?= e($t['to_code']) ?></strong>
                            <div class="tpin-sub"><?= e($t['package_name']) ?></div>
                        </td>
                        <td><?= !empty($t['created_at']) ? e(date('d M Y H:i', strtotime((string) $t['created_at']))) : '—' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>
(function () {
    document.querySelectorAll('[data-copy]').forEach((el) => {
        el.addEventListener('click', async () => {
            const text = el.getAttribute('data-copy') || '';
            if (!text) return;
            try {
                await navigator.clipboard.writeText(text);
                if (el.classList.contains('tpin-copy-btn')) {
                    const prev = el.textContent;
                    el.textContent = 'Copied';
                    el.classList.add('is-copied');
                    setTimeout(() => {
                        el.textContent = prev;
                        el.classList.remove('is-copied');
                    }, 1200);
                }
            } catch (e) { /* ignore */ }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
