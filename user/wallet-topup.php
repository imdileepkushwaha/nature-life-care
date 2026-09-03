<?php
$pageTitle = 'Topup Wallet';
require_once __DIR__ . '/../includes/wallet_topup.php';
require_once __DIR__ . '/../includes/utility.php';
require_once __DIR__ . '/includes/auth.php';
require_user();
feature_guard_user_page('wallet-topup');

$user = current_user($pdo);
if (!$user || ($user['status'] ?? '') === 'blocked') {
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_code']);
    header('Location: login.php');
    exit;
}

wallet_topup_ensure_requests_table($pdo);
$uid = (int) $user['id'];
$errors = [];
$openModal = false;

$form = [
    'amount' => $_POST['amount'] ?? '',
    'payment_mode' => $_POST['payment_mode'] ?? 'UPI',
    'utr_reference' => $_POST['utr_reference'] ?? '',
    'note' => $_POST['note'] ?? '',
];
$modes = wallet_topup_payment_modes();
if (!isset($modes[$form['payment_mode']])) {
    $form['payment_mode'] = 'UPI';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_money') {
    $openModal = true;
    $amount = (float) ($_POST['amount'] ?? 0);
    $mode = (string) ($_POST['payment_mode'] ?? 'UPI');
    $utr = trim((string) ($_POST['utr_reference'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));
    $isCash = ($mode === 'Cash');
    $proofPath = null;

    if (!$isCash) {
        $up = wallet_topup_store_proof($_FILES['payment_proof'] ?? [], $uid);
        if (!$up['ok']) {
            $errors[] = $up['error'] ?? 'Proof upload failed.';
        } else {
            $proofPath = $up['path'];
        }
    }

    if (!$errors) {
        $res = wallet_topup_submit_request($pdo, $uid, $amount, $mode, $utr, $proofPath, $note !== '' ? $note : null);
        if ($res['ok']) {
            flash('success', 'Topup request submitted. After admin approval, transfer Topup → Shopping Wallet, then buy products.');
            header('Location: wallet-topup.php' . (in_array(($_POST['next'] ?? $_GET['next'] ?? ''), ['shop', 'checkout'], true) ? '?next=' . rawurlencode((string) ($_POST['next'] ?? $_GET['next'])) : ''));
            exit;
        }
        if ($proofPath) {
            $orphan = BASE_PATH . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $proofPath);
            if (is_file($orphan)) {
                @unlink($orphan);
            }
        }
        $errors[] = $res['error'] ?? 'Could not submit request.';
    }

    $form['amount'] = $_POST['amount'] ?? '';
    $form['payment_mode'] = $mode;
    $form['utr_reference'] = $utr;
    $form['note'] = $note;
}

$balances = wallet_get_balances($pdo, $uid);
$balance = (float) ($balances['topup'] ?? 0);
$pendingSum = wallet_topup_member_pending_sum($pdo, $uid);
$requests = wallet_topup_member_requests($pdo, $uid, 40);
$ledgerRows = wallet_ledger_rows($pdo, $uid, 'topup', 12);
$types = wallet_types();
$pendingCount = 0;
foreach ($requests as $rq) {
    if (($rq['status'] ?? '') === 'pending') {
        $pendingCount++;
    }
}

$payBanks = [];
try {
    bank_accounts_ensure_columns($pdo);
    $payBanks = $pdo->query("
        SELECT a.account_name, a.account_number, a.ifsc_code, a.branch_name, a.account_type,
               a.upi_id, a.qr_code, b.name AS bank_name
        FROM bank_accounts a
        JOIN banks b ON b.id = a.bank_id
        WHERE a.status = 'active'
        ORDER BY a.id ASC
        LIMIT 3
    ")->fetchAll();
} catch (Throwable $e) {
    $payBanks = [];
}

$modeHints = [
    'Online' => 'Bank transfer',
    'UPI' => 'Scan & pay',
    'Cash' => 'Office deposit',
];
$modeIcons = [
    'Online' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
    'UPI' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg>',
    'Cash' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01M18 12h.01"/></svg>',
];
$quickIcons = [
    'income' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>',
    'topup' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
    'shopping' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M6 6L5 3H2"/></svg>',
];
$quickTone = ['income' => 'green', 'topup' => 'blue', 'shopping' => 'purple'];
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
if (!in_array($next, ['shop', 'checkout'], true)) {
    $next = '';
}
$featWalletActivate = feature_module_allowed('wallet_activate');
$featProducts = feature_module_allowed('products');

require_once __DIR__ . '/includes/header.php';
$flash = get_flash();
?>
<div class="up-page-head">
    <div>
        <h1>Topup Wallet</h1>
        <p>Step 1 for shopping: request topup → admin approves → transfer to Shopping Wallet → buy.</p>
    </div>
    <div class="up-head-actions">
        <button type="button" class="up-btn up-btn-primary" data-wal-open-add>Add Money</button>
        <a href="wallet-transfer.php?from=topup&to=shopping<?= $next !== '' ? '&next=' . rawurlencode($next) : '' ?>" class="up-btn up-btn-outline">Transfer</a>
        <?php if ($featProducts): ?>
        <a href="purchase-product.php" class="up-btn up-btn-outline">Buy Products</a>
        <?php endif; ?>
        <?php if ($featWalletActivate): ?>
        <a href="wallet-topup-activate.php" class="up-btn up-btn-outline">Activate Member</a>
        <?php endif; ?>
        <a href="wallet.php" class="up-btn up-btn-outline">All Wallets</a>
    </div>
</div>

<?php if ($flash): ?>
    <div class="up-alert up-alert-<?= $flash['type'] === 'error' ? 'err' : 'ok' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="up-alert up-alert-err"><?= e($err) ?></div>
<?php endforeach; ?>
<?php if ($next === 'shop' || $next === 'checkout'): ?>
<div class="up-alert up-alert-ok">
    Shopping flow: request topup here → after approval,
    <a href="wallet-transfer.php?from=topup&to=shopping&next=<?= e($next) ?>">transfer to Shopping Wallet</a>
    → <?= $next === 'checkout' ? '<a href="purchase-checkout.php?step=payment">complete payment</a>' : '<a href="purchase-product.php">buy products</a>' ?>.
</div>
<?php endif; ?>

<div class="wal-stats">
    <article class="wal-stat g-blue">
        <span class="wal-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
        </span>
        <div class="wal-stat-copy">
            <span class="wal-stat-label">Topup Balance</span>
            <strong><?= currency($balance) ?></strong>
            <small>Available to use</small>
        </div>
    </article>
    <article class="wal-stat g-orange">
        <span class="wal-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        </span>
        <div class="wal-stat-copy">
            <span class="wal-stat-label">Pending requests</span>
            <strong><?= currency($pendingSum) ?></strong>
            <small><?= (int) $pendingCount ?> awaiting approval</small>
        </div>
    </article>
    <article class="wal-stat g-purple">
        <span class="wal-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 014-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
        </span>
        <div class="wal-stat-copy">
            <span class="wal-stat-label">Quick use</span>
            <strong style="font-size:1.05rem">Activate · Shop</strong>
            <small>Activate downline or transfer</small>
        </div>
    </article>
</div>

<div class="wal-quick">
    <?php foreach ($types as $key => $t): ?>
        <a href="<?= e($t['page']) ?>" class="wal-quick-card tone-<?= e($quickTone[$key] ?? 'blue') ?><?= $key === 'topup' ? ' is-on' : '' ?>">
            <span class="wal-quick-ico" aria-hidden="true"><?= $quickIcons[$key] ?? $quickIcons['topup'] ?></span>
            <span class="wal-quick-meta">
                <span class="wal-quick-label"><?= e($t['label']) ?></span>
                <strong><?= currency($balances[$key] ?? 0) ?></strong>
            </span>
            <span class="wal-quick-go" aria-hidden="true">→</span>
        </a>
    <?php endforeach; ?>
</div>

<section class="wal-panel" style="margin-bottom:1rem">
    <div class="wal-banner is-blue">
        <div class="wal-banner-main">
            <span class="wal-banner-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
            </span>
            <div>
                <span class="wal-kicker">Fund wallet</span>
                <h2>Add money requests</h2>
                <!-- <p>Submit Online / UPI / Cash proof — credited after admin approval</p> -->
            </div>
        </div>
        <div class="wal-banner-actions">
            <button type="button" class="up-btn" data-wal-open-add>Add Money</button>
        </div>
    </div>
    <div class="wal-table-wrap">
        <table class="wal-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Mode</th>
                    <th>UTR</th>
                    <th>Proof</th>
                    <th>Status</th>
                    <th>Admin note</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$requests): ?>
                <tr><td colspan="8" class="wal-empty">No topup requests yet. Click <strong>Add Money</strong> to submit one.</td></tr>
            <?php else: foreach ($requests as $r):
                $st = (string) ($r['status'] ?? 'pending');
                $proofUrl = wallet_topup_proof_url($r['payment_proof'] ?? null);
                ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><?= e(date('d M Y, h:i A', strtotime((string) $r['created_at']))) ?></td>
                    <td><strong><?= currency((float) $r['amount']) ?></strong></td>
                    <td><?= e($r['payment_mode'] ?? '—') ?></td>
                    <td><?= e($r['utr_reference'] ?? '—') ?></td>
                    <td>
                        <?php if ($proofUrl): ?>
                            <a href="<?= e($proofUrl) ?>" target="_blank" rel="noopener">View</a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><span class="wal-badge is-<?= e($st) ?>"><?= e(ucfirst($st)) ?></span></td>
                    <td><?= e($r['admin_note'] ?: '—') ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="wal-panel">
    <div class="wal-banner is-navy">
        <div class="wal-banner-main">
            <span class="wal-banner-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/></svg>
            </span>
            <div>
                <span class="wal-kicker">Ledger</span>
                <h2>Topup ledger</h2>
                <!-- <p>Credits from approval &amp; debits for activation / transfer</p> -->
            </div>
        </div>
    </div>
    <div class="wal-table-wrap">
        <table class="wal-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Particular</th>
                    <th>Note</th>
                    <th>Credit</th>
                    <th>Debit</th>
                    <th>Balance</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$ledgerRows): ?>
                <tr><td colspan="6" class="wal-empty">No ledger entries yet.</td></tr>
            <?php else: foreach ($ledgerRows as $row):
                $isCredit = ($row['direction'] ?? '') === 'credit';
                ?>
                <tr>
                    <td><?= e(date('d M Y, h:i A', strtotime((string) $row['created_at']))) ?></td>
                    <td><?= e(wallet_ref_label((string) $row['ref_type'])) ?></td>
                    <td><?= e($row['note'] ?: '—') ?></td>
                    <td class="is-in"><?= $isCredit ? currency((float) $row['amount']) : '—' ?></td>
                    <td class="is-out"><?= !$isCredit ? currency((float) $row['amount']) : '—' ?></td>
                    <td><?= currency((float) $row['balance_after']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>

<dialog class="wal-dialog" id="walAddMoneyDialog"<?= $openModal || $errors ? ' open' : '' ?>>
    <form method="post" enctype="multipart/form-data" class="wal-dialog-card">
        <input type="hidden" name="action" value="add_money">
        <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>

        <div class="wal-dialog-hero">
            <div class="wal-dialog-hero-row">
                <div class="wal-dialog-hero-copy">
                    <div class="wal-dialog-hero-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                    </div>
                    <div>
                        <h2>Add Money</h2>
                        <p>Choose payment mode, pay company, then submit UTR &amp; proof.</p>
                    </div>
                </div>
                <button type="button" class="wal-dialog-x" data-wal-close-add aria-label="Close">&times;</button>
            </div>
        </div>

        <div class="wal-dialog-body">
            <div class="up-form-grid">
                <div class="up-field full">
                    <label>Payment mode</label>
                    <div class="wal-mode-grid" data-wal-mode-grid>
                        <?php foreach ($modes as $k => $label): ?>
                            <label class="wal-mode-chip<?= $form['payment_mode'] === $k ? ' is-on' : '' ?>">
                                <input type="radio" name="payment_mode" value="<?= e($k) ?>" <?= $form['payment_mode'] === $k ? 'checked' : '' ?> data-wal-mode>
                                <span class="wal-mode-ico" aria-hidden="true"><?= $modeIcons[$k] ?? '' ?></span>
                                <span class="wal-mode-text">
                                    <strong><?= e($label) ?></strong>
                                    <small><?= e($modeHints[$k] ?? '') ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="wal-pay-split" data-wal-pay-split>
                <div class="wal-pay-side">
                    <?php if ($payBanks): ?>
                    <div class="wal-pay-online" data-wal-pay-online>
                        <div class="wal-pay-section-title">Bank account details</div>
                        <div class="wal-pay-banks">
                            <?php foreach ($payBanks as $ba): ?>
                                <div class="wal-pay-bank is-online">
                                    <div class="wal-pay-bank-meta">
                                        <strong><?= e($ba['bank_name'] ?? 'Bank') ?></strong>
                                        <span><?= e($ba['account_name'] ?? '') ?></span>
                                        <span>A/C: <?= e($ba['account_number'] ?? '') ?></span>
                                        <span>IFSC: <?= e($ba['ifsc_code'] ?? '') ?></span>
                                        <?php if (!empty($ba['branch_name'])): ?>
                                            <span>Branch: <?= e($ba['branch_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="wal-pay-upi" data-wal-pay-upi hidden>
                        <div class="wal-pay-section-title">UPI payment</div>
                        <div class="wal-upi-grid">
                            <?php
                            $hasUpiBlock = false;
                            foreach ($payBanks as $ba):
                                $qrUrl = function_exists('bank_qr_url') ? bank_qr_url($ba['qr_code'] ?? null) : null;
                                $upiId = trim((string) ($ba['upi_id'] ?? ''));
                                if (!$qrUrl && $upiId === '') {
                                    continue;
                                }
                                $hasUpiBlock = true;
                                ?>
                                <div class="wal-upi-card">
                                    <?php if ($qrUrl): ?>
                                        <div class="wal-upi-qr-wrap">
                                            <img src="<?= e($qrUrl) ?>" alt="UPI QR" class="wal-pay-qr">
                                            <span>Scan to pay</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($upiId !== ''): ?>
                                        <div class="wal-upi-id">
                                            <small>UPI ID</small>
                                            <strong><?= e($upiId) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$hasUpiBlock): ?>
                                <div class="wal-upi-empty">No UPI ID / QR configured. Ask admin to add UPI details.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="wal-pay-cash" data-wal-pay-cash hidden>
                        <div class="wal-cash-note">
                            <strong>Cash deposit</strong>
                            <p>Pay at company office / collector, then submit this request. UTR &amp; proof are optional for cash.</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="wal-form-side">
                    <div class="wal-pay-section-title">Payment details</div>
                    <div class="up-form-grid wal-form-grid">
                        <div class="up-field full">
                            <label for="amount">Amount</label>
                            <input type="number" name="amount" id="amount" min="1" step="0.01" required value="<?= e((string) $form['amount']) ?>" placeholder="Enter amount">
                        </div>
                        <div class="up-field full" data-wal-utr-wrap>
                            <label for="utr_reference">UTR / Transaction No.</label>
                            <input type="text" name="utr_reference" id="utr_reference" maxlength="100" value="<?= e($form['utr_reference']) ?>" placeholder="Enter UTR number">
                        </div>
                        <div class="up-field full" data-wal-proof-wrap>
                            <label>Payment proof</label>
                            <label class="wal-upbox">
                                <input type="file" name="payment_proof" id="payment_proof" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                <span class="wal-upbox-ico" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                </span>
                                <span class="wal-upbox-text">
                                    <strong>Upload screenshot / slip</strong>
                                    <span>JPG, PNG or WebP · max 3MB</span>
                                    <span data-wal-file-name class="wal-upbox-file"></span>
                                </span>
                            </label>
                        </div>
                        <div class="up-field full">
                            <label for="note">Note (optional)</label>
                            <input type="text" name="note" id="note" maxlength="200" value="<?= e($form['note']) ?>" placeholder="Optional remark">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="wal-dialog-actions">
            <button type="button" class="up-btn up-btn-outline" data-wal-close-add>Cancel</button>
            <button type="submit" class="up-btn up-btn-primary">Submit Request</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    var dlg = document.getElementById('walAddMoneyDialog');
    if (!dlg) return;
    var modeInputs = dlg.querySelectorAll('[data-wal-mode]');
    var chips = dlg.querySelectorAll('.wal-mode-chip');
    var utrWrap = dlg.querySelector('[data-wal-utr-wrap]');
    var proofWrap = dlg.querySelector('[data-wal-proof-wrap]');
    var onlineBox = dlg.querySelector('[data-wal-pay-online]');
    var upiBox = dlg.querySelector('[data-wal-pay-upi]');
    var cashBox = dlg.querySelector('[data-wal-pay-cash]');
    var utrInput = document.getElementById('utr_reference');
    var proofInput = document.getElementById('payment_proof');
    var fileName = dlg.querySelector('[data-wal-file-name]');

    function currentMode() {
        var checked = dlg.querySelector('[data-wal-mode]:checked');
        return checked ? checked.value : 'UPI';
    }

    function syncMode() {
        var mode = currentMode();
        var cash = mode === 'Cash';
        var upi = mode === 'UPI';
        var online = mode === 'Online';
        var split = dlg.querySelector('[data-wal-pay-split]');

        chips.forEach(function (chip) {
            var inp = chip.querySelector('input');
            chip.classList.toggle('is-on', !!(inp && inp.checked));
        });

        if (onlineBox) onlineBox.hidden = !online;
        if (upiBox) upiBox.hidden = !upi;
        if (cashBox) cashBox.hidden = !cash;
        if (split) {
            split.classList.toggle('is-upi', upi);
            split.classList.toggle('is-online', online);
            split.classList.toggle('is-cash', cash);
        }

        if (utrWrap) utrWrap.style.display = cash ? 'none' : '';
        if (proofWrap) proofWrap.style.display = cash ? 'none' : '';
        if (utrInput) utrInput.required = !cash;
        if (proofInput) proofInput.required = !cash;
    }

    function openDlg() {
        if (typeof dlg.showModal === 'function') {
            if (!dlg.open) dlg.showModal();
        } else {
            dlg.setAttribute('open', '');
        }
        syncMode();
    }

    function closeDlg() {
        if (typeof dlg.close === 'function') dlg.close();
        else dlg.removeAttribute('open');
    }

    document.querySelectorAll('[data-wal-open-add]').forEach(function (btn) {
        btn.addEventListener('click', openDlg);
    });
    document.querySelectorAll('[data-wal-close-add]').forEach(function (btn) {
        btn.addEventListener('click', closeDlg);
    });
    dlg.addEventListener('click', function (e) {
        if (e.target === dlg) closeDlg();
    });
    modeInputs.forEach(function (el) {
        el.addEventListener('change', syncMode);
    });
    if (proofInput && fileName) {
        var upbox = proofInput.closest('.wal-upbox');
        proofInput.addEventListener('change', function () {
            var name = proofInput.files && proofInput.files[0] ? proofInput.files[0].name : '';
            fileName.textContent = name;
            if (upbox) upbox.classList.toggle('has-file', !!name);
        });
    }
    syncMode();
    <?php if ($openModal || $errors): ?>
    openDlg();
    <?php endif; ?>
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
