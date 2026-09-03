<?php
$pageTitle = 'Withdrawal Fund';
require_once __DIR__ . '/../includes/withdrawal.php';
require_once __DIR__ . '/../includes/kyc.php';

require_once __DIR__ . '/includes/auth.php';
require_user();
feature_guard_user_page('withdrawal-fund');

$user = current_user($pdo);
if (!$user || ($user['status'] ?? '') === 'blocked') {
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_code']);
    header('Location: login.php');
    exit;
}

$uid = (int) $user['id'];
$errors = [];
$minAmt = wd_min_amount();
$maxAmt = wd_max_amount();
$wallet = (float) $user['wallet_balance']; // Income Wallet
$pendingSum = wd_pending_sum($pdo, $uid);
$available = wd_available_balance($pdo, $user);
$withdrawMax = $available;
if ($maxAmt > 0) {
    $withdrawMax = min($available, $maxAmt);
}
$prefills = wd_kyc_bank_prefills($pdo, $uid);
$tdsPct = wd_tds_percent();
$feePct = wd_fee_percent();
$sampleBreak = wd_calc_breakdown($pdo, max($minAmt, 1000));
$kycGate = wd_kyc_gate_check($pdo, $uid);
$kycBlocked = !$kycGate['ok'];

$allowedMethods = ['Bank Transfer', 'UPI', 'Other'];
$form = [
    'amount' => $_POST['amount'] ?? '',
    'payment_method' => $_POST['payment_method'] ?? ($prefills['method'] ?? 'Bank Transfer'),
    'account_details' => $_POST['account_details'] ?? ($prefills['details'] ?? ''),
];
if (!in_array($form['payment_method'], $allowedMethods, true)) {
    $form['payment_method'] = 'Bank Transfer';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wd_assert_enabled()) {
        $errors[] = 'Withdrawals are disabled for this client.';
    } elseif ($kycBlocked) {
        $errors[] = $kycGate['message'];
    } else {
        $amount = (float) ($_POST['amount'] ?? 0);
        $method = trim($_POST['payment_method'] ?? '');
        $details = trim($_POST['account_details'] ?? '');

        // Refresh balances under lock-ish re-read
        $user = current_user($pdo, true) ?? $user;
        $available = wd_available_balance($pdo, $user);
        $wallet = (float) $user['wallet_balance'];

        if ($amount <= 0) {
            $errors[] = 'Enter a valid withdrawal amount.';
        } elseif ($amount < $minAmt) {
            $errors[] = 'Minimum withdrawal is ' . strip_tags(currency($minAmt)) . '.';
        } elseif ($maxAmt > 0 && $amount > $maxAmt + 0.00001) {
            $errors[] = 'Maximum withdrawal per request is ' . strip_tags(currency($maxAmt)) . '.';
        } elseif ($amount > $available + 0.00001) {
            $errors[] = 'Amount exceeds available balance (wallet minus pending requests).';
        }
        if ($method === '' || !in_array($method, $allowedMethods, true)) {
            $errors[] = 'Select a valid payment method.';
        }
        if ($details === '' || strlen($details) < 8) {
            $errors[] = 'Enter complete account / payout details.';
        }

        if (!$errors) {
            // Re-check KYC gate at submit time
            $kycGate = wd_kyc_gate_check($pdo, $uid);
            if (!$kycGate['ok']) {
                $errors[] = $kycGate['message'];
            }
        }

        if (!$errors) {
            wd_ensure_columns($pdo);
            $break = wd_calc_breakdown($pdo, $amount);
            $pdo->prepare('INSERT INTO withdrawals (member_id, amount, tds_amount, fee_amount, other_deduction, net_amount, payment_method, account_details, status) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $uid,
                    $break['gross'],
                    $break['tds_amount'],
                    $break['fee_amount'],
                    $break['other_deduction'],
                    $break['net_amount'],
                    $method,
                    $details,
                    'pending',
                ]);
            flash('success', 'Withdrawal request submitted. Net payout after deductions: ' . strip_tags(currency($break['net_amount'])) . '. Waiting for admin approval.');
            header('Location: withdrawal-report.php');
            exit;
        }

        $form = [
            'amount' => (string) ($_POST['amount'] ?? ''),
            'payment_method' => $method,
            'account_details' => $details,
        ];
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="up-page-head">
    <div>
        <h1>Withdrawal Fund</h1>
        <p>Request a payout from your Income Wallet.</p>
    </div>
    <a href="withdrawal-report.php" class="up-btn up-btn-outline">View Report</a>
</div>

<div class="wd-stats">
    <article class="wd-stat g-green">
        <span class="wd-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></span>
        <div>
            <span class="wd-stat-label">Income Wallet</span>
            <strong><?= currency($wallet) ?></strong>
        </div>
    </article>
    <article class="wd-stat g-orange">
        <span class="wd-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span>
        <div>
            <span class="wd-stat-label">Pending Requests</span>
            <strong><?= currency($pendingSum) ?></strong>
        </div>
    </article>
    <article class="wd-stat g-blue">
        <span class="wd-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/></svg></span>
        <div>
            <span class="wd-stat-label">Available to Withdraw</span>
            <strong><?= currency($available) ?></strong>
        </div>
    </article>
    <article class="wd-stat g-purple">
        <span class="wd-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/></svg></span>
        <div>
            <span class="wd-stat-label">Minimum</span>
            <strong><?= currency($minAmt) ?></strong>
        </div>
    </article>
</div>

<div class="wd-layout">
    <section class="wd-card">
        <div class="wd-banner is-report">
            <div class="wd-banner-main">
                <span class="wd-banner-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><circle cx="16" cy="15" r="1.5" fill="currentColor" stroke="none"/></svg>
                </span>
                <div>
                    <span class="wd-kicker">Payout request</span>
                    <h2>Withdrawal Fund</h2>
                    <!-- <p>Admin will review and approve. Wallet is deducted only after approval.</p> -->
                </div>
            </div>
        </div>
        <div class="wd-body">
            <?php foreach ($errors as $err): ?>
                <div class="up-alert up-alert-err"><?= e($err) ?></div>
            <?php endforeach; ?>

            <?php if ($kycBlocked): ?>
                <div class="up-alert up-alert-err">
                    <?= e($kycGate['message']) ?>
                    <div style="margin-top:0.5rem">
                        <a href="kyc-pan.php" class="up-btn up-btn-outline" style="display:inline-flex">Go to KYC</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($available < $minAmt): ?>
                <div class="up-alert up-alert-info">Available balance is below the minimum withdrawal of <?= currency($minAmt) ?>.</div>
            <?php endif; ?>

            <form method="post" class="wd-form" autocomplete="off" id="wdForm"<?= $kycBlocked ? ' onsubmit="return false;"' : '' ?>>
                <div class="wd-form-strip">
                    <div class="wd-form-chip">
                        <span class="wd-form-chip-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                        </span>
                        <div>
                            <small>Available</small>
                            <strong><?= currency($available) ?></strong>
                        </div>
                    </div>
                    <div class="wd-form-chip is-min">
                        <span class="wd-form-chip-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/></svg>
                        </span>
                        <div>
                            <small>Minimum</small>
                            <strong><?= currency($minAmt) ?></strong>
                        </div>
                    </div>
                    <?php if ($maxAmt > 0): ?>
                    <div class="wd-form-chip is-max">
                        <span class="wd-form-chip-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M12 8v8M8 12h8"/></svg>
                        </span>
                        <div>
                            <small>Maximum</small>
                            <strong><?= currency($maxAmt) ?></strong>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="wd-form-grid">
                    <div class="wd-field">
                        <label for="amount" class="wd-label-row">
                            <span>Amount</span>
                            <small class="wd-hint">Max <?= currency($withdrawMax) ?></small>
                        </label>
                        <div class="wd-amount">
                            <span class="wd-amount-prefix" aria-hidden="true">&#8377;</span>
                            <input type="number" step="0.01" min="<?= e((string) $minAmt) ?>" max="<?= e((string) $withdrawMax) ?>"
                                   id="amount" name="amount" value="<?= e($form['amount']) ?>"
                                   placeholder="0.00" required <?= $available < $minAmt ? 'disabled' : '' ?>>
                            <button type="button" class="wd-amount-max" id="wdMaxBtn" <?= $available < $minAmt ? 'disabled' : '' ?>>MAX</button>
                        </div>
                    </div>

                    <div class="wd-field">
                        <span class="wd-field-label">Payment Method</span>
                        <div class="wd-methods" role="radiogroup" aria-label="Payment method">
                            <?php
                            $methods = [
                                'Bank Transfer' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
                                'UPI' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>',
                                'Other' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>',
                            ];
                            foreach ($methods as $opt => $ico):
                                $checked = $form['payment_method'] === $opt;
                            ?>
                                <label class="wd-method<?= $checked ? ' is-on' : '' ?>">
                                    <input type="radio" name="payment_method" value="<?= e($opt) ?>"
                                           <?= $checked ? 'checked' : '' ?>
                                           <?= $available < $minAmt ? 'disabled' : '' ?> required>
                                    <span class="wd-method-ico" aria-hidden="true"><?= $ico ?></span>
                                    <span class="wd-method-text"><?= e($opt) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="wd-field is-full">
                        <label for="account_details" class="wd-label-row">
                            <span>Account / Payout Details</span>
                            <?php if ($prefills): ?>
                                <small class="wd-hint is-ok">Prefilled from approved KYC</small>
                            <?php endif; ?>
                        </label>
                        <div class="wd-textarea-wrap">
                            <textarea id="account_details" name="account_details" rows="4"
                                      placeholder="Account holder, number, IFSC / UPI ID / wallet address…"
                                      required <?= $available < $minAmt ? 'disabled' : '' ?>><?= e($form['account_details']) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="wd-form-foot">
                    <div class="wd-break" id="wdBreak">
                        <div class="wd-break-row"><span>Gross request</span><strong id="wdGross">—</strong></div>
                        <div class="wd-break-row"><span>TDS (<?= e(rtrim(rtrim(number_format($tdsPct, 2, '.', ''), '0'), '.')) ?>%)</span><strong id="wdTds">—</strong></div>
                        <div class="wd-break-row"><span>Admin charges (<?= e(rtrim(rtrim(number_format($feePct, 2, '.', ''), '0'), '.')) ?>%)</span><strong id="wdFee">—</strong></div>
                        <div class="wd-break-row"><span>Other deductions</span><strong id="wdOther">—</strong></div>
                        <div class="wd-break-row is-net"><span>You receive (net)</span><strong id="wdNet">—</strong></div>
                        <p class="wd-form-note">Income Wallet deducts the <strong>gross</strong> amount on approval. Bank/UPI receives <strong>net</strong> after TDS &amp; fees.</p>
                    </div>
                    <div class="wd-form-actions">
                        <a href="withdrawal-report.php" class="up-btn up-btn-outline">Cancel</a>
                        <button type="submit" class="up-btn up-btn-primary wd-submit" <?= ($available < $minAmt || $kycBlocked) ? 'disabled' : '' ?>>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                            Submit Request
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <aside class="wd-side">
        <div class="wd-guide">
            <div class="wd-guide-head is-flow">
                <span class="wd-guide-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                </span>
                <div>
                    <span class="wd-guide-kicker">Process</span>
                    <h3>How it works</h3>
                </div>
            </div>
            <ol class="wd-timeline">
                <li>
                    <span class="wd-tl-num" aria-hidden="true">1</span>
                    <div>
                        <strong>Submit request</strong>
                        <p>Enter amount and payout details</p>
                    </div>
                </li>
                <li>
                    <span class="wd-tl-num" aria-hidden="true">2</span>
                    <div>
                        <strong>Pending review</strong>
                        <p>Request waits for admin approval</p>
                    </div>
                </li>
                <li>
                    <span class="wd-tl-num" aria-hidden="true">3</span>
                    <div>
                        <strong>Admin approves</strong>
                        <p>Income Wallet is deducted on approval</p>
                    </div>
                </li>
                <li>
                    <span class="wd-tl-num" aria-hidden="true">4</span>
                    <div>
                        <strong>Marked paid</strong>
                        <p>Funds reach your payout method</p>
                    </div>
                </li>
            </ol>
        </div>

        <div class="wd-guide">
            <div class="wd-guide-head is-tips">
                <span class="wd-guide-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 18h6M10 22h4M12 2a7 7 0 017 7c0 2.38-1.19 4.47-3 5.74V17a1 1 0 01-1 1h-6a1 1 0 01-1-1v-2.26C6.19 13.47 5 11.38 5 9a7 7 0 017-7z"/></svg>
                </span>
                <div>
                    <span class="wd-guide-kicker">Helpful</span>
                    <h3>Tips</h3>
                </div>
            </div>
            <ul class="wd-tip-list">
                <li>
                    <span class="wd-tip-ico t-orange" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                    </span>
                    <div>
                        <strong>Pending locks balance</strong>
                        <p>Open requests reduce available withdraw amount</p>
                    </div>
                </li>
                <li>
                    <span class="wd-tip-ico t-blue" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                    </span>
                    <div>
                        <strong>Accurate payout details</strong>
                        <p>Bank / UPI must match your KYC records</p>
                    </div>
                </li>
                <li>
                    <span class="wd-tip-ico t-green" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </span>
                    <div>
                        <strong>Track every request</strong>
                        <p>Follow status anytime in Withdrawal Report</p>
                    </div>
                </li>
            </ul>
            <a href="kyc-bank.php" class="wd-guide-cta">
                <span>
                    <strong>Update bank KYC</strong>
                    <small>Keep payout details approved</small>
                </span>
                <span class="wd-guide-cta-arrow" aria-hidden="true">→</span>
            </a>
        </div>
    </aside>
</div>
<script>
(function () {
    var maxBtn = document.getElementById('wdMaxBtn');
    var amount = document.getElementById('amount');
    var tdsPct = <?= json_encode($tdsPct) ?>;
    var feePct = <?= json_encode($feePct) ?>;
    var otherPct = <?= json_encode((float) ($sampleBreak['other_percent'] ?? 0)) ?>;
    var otherFixed = <?= json_encode((float) ($sampleBreak['other_fixed'] ?? 0)) ?>;

    function fmt(n) {
        return '₹' + (Math.max(0, n)).toFixed(2);
    }
    function refreshBreak() {
        var g = parseFloat(amount && amount.value ? amount.value : '0') || 0;
        var tds = Math.round(g * tdsPct * 100) / 10000;
        tds = Math.round(tds * 100) / 100;
        var fee = Math.round(g * feePct * 100) / 10000;
        fee = Math.round(fee * 100) / 100;
        var other = Math.round((g * otherPct / 100 + otherFixed) * 100) / 100;
        var net = Math.round((g - tds - fee - other) * 100) / 100;
        var el = function (id) { return document.getElementById(id); };
        if (el('wdGross')) el('wdGross').textContent = fmt(g);
        if (el('wdTds')) el('wdTds').textContent = fmt(tds);
        if (el('wdFee')) el('wdFee').textContent = fmt(fee);
        if (el('wdOther')) el('wdOther').textContent = fmt(other);
        if (el('wdNet')) el('wdNet').textContent = fmt(Math.max(0, net));
    }

    if (maxBtn && amount && !amount.disabled) {
        maxBtn.addEventListener('click', function () {
            amount.value = amount.getAttribute('max') || '';
            amount.focus();
            refreshBreak();
        });
    }
    if (amount) {
        amount.addEventListener('input', refreshBreak);
        refreshBreak();
    }
    document.querySelectorAll('.wd-method input[type="radio"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            document.querySelectorAll('.wd-method').forEach(function (el) {
                el.classList.toggle('is-on', el.querySelector('input') === radio && radio.checked);
            });
        });
    });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
