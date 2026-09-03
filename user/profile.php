<?php
$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/closing.php';
require_once __DIR__ . '/includes/header.php';

$showBinaryUi = plan_uses_binary();
$showWithdrawUi = feature_module_allowed('withdrawals');
$teamTreeHref = $showBinaryUi ? 'my-treeview.php' : (plan_uses_matrix() ? 'matrix-tree.php' : 'level-tree.php');
$teamTreeLabel = $showBinaryUi ? 'Binary tree' : (plan_uses_matrix() ? 'Matrix tree' : 'Level tree');

$uid = (int) $user['id'];
$memberCode = (string) ($user['member_id'] ?? '');
$status = member_effective_status($user);
$isActive = member_is_active($user);
$kyc = strtolower((string) ($user['kyc_status'] ?? 'not_submitted'));

$leftBv = (float) ($user['left_bv'] ?? 0);
$rightBv = (float) ($user['right_bv'] ?? 0);
$pairBv = closing_pair_bv();
$flush = max(0, (int) setting('binary_flush_pairs', '0'));
$openMatch = $showBinaryUi ? closing_compute_match($leftBv, $rightBv, $pairBv, $flush) : ['pairs' => 0];
$openPairs = (float) ($openMatch['pairs'] ?? 0);

$sponsor = null;
if (!empty($user['sponsor_id'])) {
    $ss = $pdo->prepare('SELECT member_id, full_name, email, username FROM members WHERE id = ? LIMIT 1');
    $ss->execute([(int) $user['sponsor_id']]);
    $sponsor = $ss->fetch() ?: null;
}

$directCount = 0;
try {
    $ds = $pdo->prepare('SELECT COUNT(*) FROM members WHERE sponsor_id = ?');
    $ds->execute([$uid]);
    $directCount = (int) $ds->fetchColumn();
} catch (Throwable $e) {
    $directCount = 0;
}

$teamCount = $showBinaryUi
    ? ((int) $user['left_count'] + (int) $user['right_count'])
    : $directCount;
$downlineCount = max($teamCount, $directCount);

$packageAmount = 0.0;
if (!empty($user['package_id'])) {
    try {
        $ps = $pdo->prepare('SELECT amount FROM packages WHERE id = ? LIMIT 1');
        $ps->execute([(int) $user['package_id']]);
        $packageAmount = (float) ($ps->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $packageAmount = 0.0;
    }
}
$wallet = (float) $user['wallet_balance'];
$earnings = (float) $user['total_earnings'];
$xpTarget = $packageAmount > 0 ? $packageAmount : 10000;
$xpPct = min(100, $xpTarget > 0 ? round(($wallet / $xpTarget) * 100) : 0);

$refBase = rtrim(APP_URL, '/') . '/user/register.php';
$referralSponsor = $refBase . '?ref=' . rawurlencode($memberCode);
$referralLeft = $referralSponsor . '&pos=left';
$referralRight = $referralSponsor . '&pos=right';
$waLeftUrl = 'https://wa.me/?text=' . rawurlencode('Join with my Left referral link (' . $memberCode . '): ' . $referralLeft);
$waRightUrl = 'https://wa.me/?text=' . rawurlencode('Join with my Right referral link (' . $memberCode . '): ' . $referralRight);
$waSponsorUrl = 'https://wa.me/?text=' . rawurlencode('Join with my referral link (' . $memberCode . '): ' . $referralSponsor);
$company = setting('company_name', 'Binary MLM');

$weekly = array_fill(0, 7, 0.0);
$labels = [];
for ($i = 6; $i >= 0; $i--) {
    $labels[] = date('D', strtotime("-{$i} days"));
}
try {
    $ws = $pdo->prepare("
        SELECT DATE(created_at) AS d, SUM(amount) AS total
        FROM commissions
        WHERE member_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(created_at)
    ");
    $ws->execute([$uid]);
    $byDay = [];
    foreach ($ws->fetchAll() as $row) {
        $byDay[$row['d']] = (float) $row['total'];
    }
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $weekly[6 - $i] = $byDay[$d] ?? 0.0;
    }
} catch (Throwable $e) {
    // keep zeros
}

$weekMax = max(1, max($weekly));
$weekSum = array_sum($weekly);
$weekPrev = max(1, $weekSum * 0.82);
$weekPct = round((($weekSum - $weekPrev) / $weekPrev) * 100);
if ($weekSum <= 0) {
    $weekPct = 0;
}

$sponsorInitials = $sponsor ? user_initials($sponsor['full_name']) : '—';
$kycOk = in_array($kyc, ['approved'], true);

$pathVals = [];
foreach ($weekly as $v) {
    $pathVals[] = round(($v / $weekMax) * 100);
}

$transactions = [];
try {
    $cs = $pdo->prepare('
        SELECT id, type, amount, description, status, created_at AS txn_at, \'commission\' AS source
        FROM commissions
        WHERE member_id = ?
        ORDER BY created_at DESC
        LIMIT 8
    ');
    $cs->execute([$uid]);
    foreach ($cs->fetchAll() as $row) {
        $transactions[] = $row;
    }
} catch (Throwable $e) {
    // ignore
}
try {
    $ws2 = $pdo->prepare('
        SELECT id, \'withdrawal\' AS type, amount, payment_method AS description, status, requested_at AS txn_at, \'withdrawal\' AS source
        FROM withdrawals
        WHERE member_id = ?
        ORDER BY requested_at DESC
        LIMIT 5
    ');
    if ($showWithdrawUi) {
        $ws2->execute([$uid]);
        foreach ($ws2->fetchAll() as $row) {
            $transactions[] = $row;
        }
    }
} catch (Throwable $e) {
    // ignore
}

usort($transactions, static function ($a, $b) {
    return strtotime((string) ($b['txn_at'] ?? '')) <=> strtotime((string) ($a['txn_at'] ?? ''));
});
$transactions = array_slice($transactions, 0, 8);
?>
<div class="pp">
    <div class="pp-layout">
        <!-- Left column -->
        <aside class="pp-left">
            <div class="pp-card pp-profile-card">
                <div class="pp-profile-banner">
                    <div class="pp-profile-banner-orb a" aria-hidden="true"></div>
                    <div class="pp-profile-banner-orb b" aria-hidden="true"></div>
                    <span class="pp-profile-kicker">Member identity</span>
                    <strong class="pp-profile-id-tag">@<?= e($memberCode) ?></strong>
                </div>
                <div class="pp-profile-body">
                    <div class="pp-avatar-wrap">
                        <?php
                        $ppPhoto = user_photo_url($user['photo'] ?? null);
                        if ($ppPhoto): ?>
                            <div class="pp-avatar has-photo"><img src="<?= e($ppPhoto) ?>" alt="<?= e($user['full_name']) ?>"></div>
                        <?php else: ?>
                            <div class="pp-avatar"><?= e($initials) ?></div>
                        <?php endif; ?>
                        <span class="pp-online<?= $isActive ? '' : ' is-off' ?>" title="<?= e(ucfirst($status)) ?>"></span>
                    </div>

                    <h2 class="pp-name">
                        <?= e($user['full_name']) ?>
                        <?php if ($isActive): ?>
                        <svg class="pp-verified" viewBox="0 0 24 24" aria-label="Verified"><circle cx="12" cy="12" r="10" fill="#2152ff"/><path d="M8.5 12.5l2.2 2.2 4.8-5" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <?php endif; ?>
                    </h2>
                    <p class="pp-role"><?= e($user['username']) ?> · <?= e($user['package_name'] ?? 'No package') ?></p>

                    <div class="pp-profile-badges">
                        <span class="pp-status-pill<?= $isActive ? ' is-active' : '' ?>">
                            <span class="dot"></span> <?= e(ucfirst($status)) ?>
                        </span>
                        <span class="pp-status-pill is-kyc<?= $kycOk ? ' ok' : '' ?>">
                            KYC <?= $kycOk ? 'Verified' : e(ucfirst(str_replace('_', ' ', $kyc))) ?>
                        </span>
                    </div>

                    <div class="pp-profile-stats">
                        <div>
                            <small>Direct</small>
                            <strong><?= $directCount ?></strong>
                        </div>
                        <div>
                            <small>Team</small>
                            <strong><?= $teamCount ?></strong>
                        </div>
                        <div>
                            <small>Wallet</small>
                            <strong><?= currency($wallet) ?></strong>
                        </div>
                    </div>

                    <div class="pp-level">
                        <div class="pp-level-top">
                            <span>Level Progress</span>
                            <strong><?= (int) $xpPct ?>%</strong>
                        </div>
                        <div class="pp-progress" role="progressbar" aria-valuenow="<?= (int) $xpPct ?>" aria-valuemin="0" aria-valuemax="100">
                            <span style="width:<?= (int) $xpPct ?>%"></span>
                        </div>
                        <div class="pp-level-foot">
                            <small><?= currency($wallet) ?> earned XP</small>
                            <small>Goal <?= currency($xpTarget) ?></small>
                        </div>
                    </div>

                    <div class="pp-actions">
                        <a href="upload-photo.php" class="pp-btn pp-btn-teal">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                            Photo
                        </a>
                        <a href="edit-profile.php" class="pp-btn pp-btn-outline">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                            Edit
                        </a>
                    </div>
                </div>
            </div>

            <div class="pp-card pp-ref-section">
                <div class="pp-panel-banner is-coral">
                    <div class="pp-panel-banner-main">
                        <span class="pp-panel-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
                        </span>
                        <div>
                            <span class="pp-panel-kicker">Grow team</span>
                            <h3>Referral Links</h3>
                            <p><?= $showBinaryUi
                                ? 'Share Left / Right links under ID <strong>' . e($memberCode) . '</strong>.'
                                : 'Share your sponsor link under ID <strong>' . e($memberCode) . '</strong>.' ?></p>
                        </div>
                    </div>
                </div>

                <div class="pp-ref-body">
                    <div class="pp-ref-id-chip">
                        <small>Your ID</small>
                        <strong><?= e($memberCode) ?></strong>
                    </div>

                    <?php if ($showBinaryUi): ?>
                    <div class="pp-ref-block pp-ref-left">
                        <div class="pp-ref-block-top">
                            <div class="pp-ref-leg">
                                <span class="pp-ref-leg-ico left" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                </span>
                                <strong>Left Leg</strong>
                            </div>
                            <span class="pp-ref-count">L <?= (int) $user['left_count'] ?></span>
                        </div>
                        <p class="pp-ref-hint">Place new members on your left binary side.</p>
                        <div class="pp-ref-line">
                            <input type="text" readonly value="<?= e($referralLeft) ?>" id="ppRefLeft" aria-label="Left referral link">
                            <button type="button" class="pp-copy" data-copy="#ppRefLeft" title="Copy">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                            </button>
                            <a href="<?= e($waLeftUrl) ?>" class="pp-wa" target="_blank" rel="noopener noreferrer" title="Share on WhatsApp">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            </a>
                        </div>
                    </div>

                    <div class="pp-ref-block pp-ref-right">
                        <div class="pp-ref-block-top">
                            <div class="pp-ref-leg">
                                <span class="pp-ref-leg-ico right" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                </span>
                                <strong>Right Leg</strong>
                            </div>
                            <span class="pp-ref-count">R <?= (int) $user['right_count'] ?></span>
                        </div>
                        <p class="pp-ref-hint">Place new members on your right binary side.</p>
                        <div class="pp-ref-line">
                            <input type="text" readonly value="<?= e($referralRight) ?>" id="ppRefRight" aria-label="Right referral link">
                            <button type="button" class="pp-copy" data-copy="#ppRefRight" title="Copy">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                            </button>
                            <a href="<?= e($waRightUrl) ?>" class="pp-wa" target="_blank" rel="noopener noreferrer" title="Share on WhatsApp">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            </a>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="pp-ref-block">
                        <div class="pp-ref-block-top">
                            <div class="pp-ref-leg">
                                <strong>Sponsor Link</strong>
                            </div>
                            <span class="pp-ref-count"><?= (int) $directCount ?> directs</span>
                        </div>
                        <p class="pp-ref-hint">Share this link so new members join under your sponsorship.</p>
                        <div class="pp-ref-line">
                            <input type="text" readonly value="<?= e($referralSponsor) ?>" id="ppRefSponsor" aria-label="Sponsor referral link">
                            <button type="button" class="pp-copy" data-copy="#ppRefSponsor" title="Copy">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                            </button>
                            <a href="<?= e($waSponsorUrl) ?>" class="pp-wa" target="_blank" rel="noopener noreferrer" title="Share on WhatsApp">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pp-card pp-contact">
                <div class="pp-contact-banner">
                    <div>
                        <span class="pp-contact-kicker">Account details</span>
                        <h3>Contact Information</h3>
                        <p>Reach & membership details for your profile.</p>
                    </div>
                    <a href="edit-profile.php" class="pp-contact-edit" title="Edit profile">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                    </a>
                </div>
                <ul class="pp-contact-list">
                    <li>
                        <span class="pp-ci purple" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v16H4z"/><path d="M22 6l-10 7L2 6"/></svg>
                        </span>
                        <div class="pp-contact-body">
                            <small>Email</small>
                            <strong><a href="mailto:<?= e($user['email']) ?>"><?= e($user['email']) ?></a></strong>
                        </div>
                        <span class="pp-contact-tag">Primary</span>
                    </li>
                    <li>
                        <span class="pp-ci teal" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                        </span>
                        <div class="pp-contact-body">
                            <small>Phone</small>
                            <?php if (!empty($user['phone'])): ?>
                            <strong><a href="tel:<?= e(preg_replace('/\s+/', '', $user['phone'])) ?>"><?= e($user['phone']) ?></a></strong>
                            <?php else: ?>
                            <strong class="is-muted">Not set</strong>
                            <?php endif; ?>
                        </div>
                        <span class="pp-contact-tag teal">Mobile</span>
                    </li>
                    <li>
                        <span class="pp-ci indigo" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                        </span>
                        <div class="pp-contact-body">
                            <small>Timezone</small>
                            <strong>Asia/Kolkata (IST)</strong>
                        </div>
                        <span class="pp-contact-tag indigo">UTC+5:30</span>
                    </li>
                    <li>
                        <span class="pp-ci green" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
                        </span>
                        <div class="pp-contact-body">
                            <small>Package</small>
                            <strong><?= e($user['package_name'] ?? 'No package') ?></strong>
                        </div>
                        <span class="pp-contact-tag green"><?= $packageAmount > 0 ? currency($packageAmount) : 'N/A' ?></span>
                    </li>
                </ul>
            </div>

        </aside>

        <!-- Right column -->
        <div class="pp-right">
            <div class="pp-welcome">
                <div>
                    <span class="pp-welcome-kicker">Welcome back</span>
                    <h1>Hi, <?= e(explode(' ', trim($user['full_name']))[0] ?? $user['full_name']) ?>!</h1>
                </div>
                <div class="pp-mini-stats">
                    <div class="pp-mini">
                        <span class="pp-mini-ico blue">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                        </span>
                        <div>
                            <small>My Team</small>
                            <strong><?= $teamCount ?></strong>
                        </div>
                    </div>
                    <div class="pp-mini">
                        <span class="pp-mini-ico green">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11l-3-3 3-3"/></svg>
                        </span>
                        <div>
                            <small>My Direct</small>
                            <strong><?= $directCount ?></strong>
                        </div>
                    </div>
                    <div class="pp-mini">
                        <span class="pp-mini-ico red">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v4M12 11L5 17M12 11l7 6"/></svg>
                        </span>
                        <div>
                            <small>My Downline</small>
                            <strong><?= $downlineCount ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pp-stat-row">
                <div class="pp-stat-card">
                    <div class="pp-stat-top">
                        <span class="pp-stat-ico purple">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                        </span>
                        <span class="pp-chip up">↑ Direct</span>
                    </div>
                    <small>Referrals</small>
                    <strong><?= $directCount ?></strong>
                </div>
                <div class="pp-stat-card">
                    <div class="pp-stat-top">
                        <span class="pp-stat-ico green">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/></svg>
                        </span>
                        <span class="pp-chip up">↑ Income</span>
                    </div>
                    <small>Total Earnings</small>
                    <strong><?= currency($earnings) ?></strong>
                </div>
                <div class="pp-stat-card">
                    <div class="pp-stat-top">
                        <span class="pp-stat-ico orange">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                        </span>
                        <span class="pp-chip up">↑ Balance</span>
                    </div>
                    <small>Wallet Bal.</small>
                    <strong><?= currency($wallet) ?></strong>
                </div>
            </div>

            <div class="pp-mid-row">
                <div class="pp-card pp-sponsor">
                    <div class="pp-panel-banner is-gold">
                        <div class="pp-panel-banner-main">
                            <span class="pp-panel-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v4M12 11L5 17M12 11l7 6"/></svg>
                            </span>
                            <div>
                                <span class="pp-panel-kicker">Upline</span>
                                <h3>Sponsor Network Info</h3>
                                <p>Your place in the <?= e($company) ?> network.</p>
                            </div>
                        </div>
                    </div>
                    <div class="pp-sponsor-body">
                        <div class="pp-sponsor-chips">
                            <span class="pp-s-chip"><small>Package</small><strong><?= e($user['package_name'] ?? 'None') ?></strong></span>
                            <?php if ($showBinaryUi): ?>
                            <span class="pp-s-chip"><small>Position</small><strong><?= e($user['position'] ? ucfirst($user['position']) : '—') ?></strong></span>
                            <span class="pp-s-chip"><small>L / R</small><strong><?= (int) $user['left_count'] ?> / <?= (int) $user['right_count'] ?></strong></span>
                            <span class="pp-s-chip"><small>Left BV</small><strong><?= number_format($leftBv, 0) ?></strong></span>
                            <span class="pp-s-chip"><small>Right BV</small><strong><?= number_format($rightBv, 0) ?></strong></span>
                            <span class="pp-s-chip"><small>Open Pairs</small><strong><?= number_format($openPairs, $openPairs == floor($openPairs) ? 0 : 2) ?></strong></span>
                            <?php else: ?>
                            <span class="pp-s-chip"><small>Direct</small><strong><?= (int) $directCount ?></strong></span>
                            <span class="pp-s-chip"><small>Team</small><strong><?= (int) $teamCount ?></strong></span>
                            <?php endif; ?>
                        </div>
                        <p>
                            <?= $sponsor
                                ? 'Your upline sponsor guides placements and growth under their team.'
                                : 'You are a root-level member with no assigned sponsor.' ?>
                        </p>
                        <div class="pp-sponsor-foot">
                            <div class="pp-sponsor-avatar"><?= e($sponsorInitials) ?></div>
                            <div class="pp-sponsor-meta">
                                <strong><?= $sponsor ? e($sponsor['full_name']) : 'Root Member' ?></strong>
                                <small><?= $sponsor ? e(($sponsor['member_id'] ?? '') . ($sponsor['email'] ? ' · ' . $sponsor['email'] : '')) : 'No sponsor assigned' ?></small>
                            </div>
                            <?php if ($sponsor): ?>
                            <span class="pp-sponsor-badge">Sponsor</span>
                            <?php else: ?>
                            <span class="pp-sponsor-badge is-root">Root</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="pp-card pp-chart-card">
                    <div class="pp-panel-banner is-teal">
                        <div class="pp-panel-banner-main">
                            <span class="pp-panel-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3v18h18"/><path d="M7 14l4-4 4 3 5-6"/></svg>
                            </span>
                            <div>
                                <span class="pp-panel-kicker">7-day trend</span>
                                <h3>Weekly Productivity</h3>
                                <p>Commission activity across the last week.</p>
                            </div>
                        </div>
                        <span class="pp-chip up"><?= $weekPct >= 0 ? '+' : '' ?><?= (int) $weekPct ?>%</span>
                    </div>
                    <div class="pp-chart-body">
                        <div class="pp-chart-meta">
                            <div>
                                <small>This week</small>
                                <strong><?= currency($weekSum) ?></strong>
                            </div>
                            <div>
                                <small>Peak day</small>
                                <strong><?= currency($weekMax > 1 || $weekSum > 0 ? max($weekly) : 0) ?></strong>
                            </div>
                        </div>
                        <div class="pp-chart" aria-hidden="true">
                            <svg viewBox="0 0 280 120" preserveAspectRatio="none">
                                <?php
                                $pts = [];
                                $n = count($pathVals);
                                for ($i = 0; $i < $n; $i++) {
                                    $x = $n > 1 ? ($i / ($n - 1)) * 280 : 0;
                                    $y = 110 - ($pathVals[$i] / 100) * 90;
                                    $pts[] = round($x, 1) . ',' . round($y, 1);
                                }
                                $line = implode(' ', $pts);
                                $area = '0,120 ' . $line . ' 280,120';
                                ?>
                                <defs>
                                    <linearGradient id="ppGrad" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="#2152ff"/>
                                        <stop offset="100%" stop-color="#21d4fd" stop-opacity="0"/>
                                    </linearGradient>
                                </defs>
                                <polygon points="<?= e($area) ?>" fill="url(#ppGrad)" opacity="0.4"/>
                                <polyline points="<?= e($line) ?>" fill="none" stroke="#2152ff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <div class="pp-chart-labels">
                                <?php foreach ($labels as $lb): ?>
                                <span><?= e(substr($lb, 0, 2)) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pp-bottom-row">
                <div class="pp-card pp-quick-card">
                    <div class="pp-panel-banner is-orange">
                        <div class="pp-panel-banner-main">
                            <span class="pp-panel-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                            </span>
                            <div>
                                <span class="pp-panel-kicker">Shortcuts</span>
                                <h3>Quick Links</h3>
                                <p>Snapshot chips for status, team and wallet.</p>
                            </div>
                        </div>
                    </div>
                    <div class="pp-quick">
                        <div class="pp-q orange">
                            <span class="pp-q-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </span>
                            <strong><?= $isActive ? 'Active' : e(ucfirst($status)) ?></strong>
                            <small>Member</small>
                        </div>
                        <div class="pp-q purple">
                            <span class="pp-q-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            </span>
                            <strong><?= $kycOk ? 'Verified' : e(ucfirst(str_replace('_', ' ', $kyc))) ?></strong>
                            <small>KYC</small>
                        </div>
                        <div class="pp-q green">
                            <span class="pp-q-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                            </span>
                            <strong><?= $directCount ?> Direct</strong>
                            <small>Referrals</small>
                        </div>
                        <div class="pp-q red">
                            <span class="pp-q-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v4M12 11L5 17M12 11l7 6"/></svg>
                            </span>
                            <strong><?= $teamCount ?> Team</strong>
                            <small><?= e($showBinaryUi ? 'Binary' : 'Network') ?></small>
                        </div>
                        <div class="pp-q blue">
                            <span class="pp-q-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                            </span>
                            <strong><?= currency($wallet) ?></strong>
                            <small>Wallet</small>
                        </div>
                        <a href="<?= e($teamTreeHref) ?>" class="pp-q grey">
                            <span class="pp-q-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v4M12 11L5 17M12 11l7 6"/></svg>
                            </span>
                            <strong>Team</strong>
                            <small><?= e($teamTreeLabel) ?></small>
                        </a>
                        <a href="index.php" class="pp-q grey">
                            <span class="pp-q-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                            </span>
                            <strong>Dashboard</strong>
                            <small>Home</small>
                        </a>
                    </div>
                </div>

                <div class="pp-card pp-activity-card">
                    <div class="pp-panel-banner is-green">
                        <div class="pp-panel-banner-main">
                            <span class="pp-panel-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                            </span>
                            <div>
                                <span class="pp-panel-kicker">Timeline</span>
                                <h3>Recent Activity</h3>
                                <p>Key milestones on your membership journey.</p>
                            </div>
                        </div>
                    </div>
                    <ul class="pp-timeline">
                        <li>
                            <span class="pp-dot-wrap purple"><span class="pp-dot"></span></span>
                            <div class="pp-timeline-card">
                                <strong>Registered on <?= e($company) ?></strong>
                                <small>Joined: <?= $user['join_date'] ? date('d M Y', strtotime($user['join_date'])) : '—' ?></small>
                            </div>
                        </li>
                        <li>
                            <span class="pp-dot-wrap green"><span class="pp-dot"></span></span>
                            <div class="pp-timeline-card">
                                <strong>Direct Referral Code Issued</strong>
                                <small>Code: <?= e($memberCode) ?></small>
                            </div>
                        </li>
                        <li>
                            <span class="pp-dot-wrap red"><span class="pp-dot"></span></span>
                            <div class="pp-timeline-card">
                                <strong>Default Placement Set</strong>
                                <small>Tree Parent: <?= $sponsor ? e($sponsor['member_id']) : 'Root' ?><?= $showBinaryUi ? ' · Side: ' . e($user['position'] ? ucfirst($user['position']) : '—') : '' ?></small>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="pp-card pp-txn-card">
                <div class="pp-txn-banner">
                    <div class="pp-panel-banner-main">
                        <div class="pp-txn-banner-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                        </div>
                        <div class="pp-txn-banner-text">
                            <span class="pp-txn-kicker">Wallet ledger</span>
                            <h3>Recent Transactions</h3>
                            <!-- <p>Latest commissions and withdrawal activity on your account.</p> -->
                        </div>
                    </div>
                </div>

                <?php
                $txnIn = 0.0;
                $txnOut = 0.0;
                foreach ($transactions as $t) {
                    if (($t['source'] ?? '') === 'withdrawal') {
                        $txnOut += (float) ($t['amount'] ?? 0);
                    } else {
                        $txnIn += (float) ($t['amount'] ?? 0);
                    }
                }
                ?>

                <?php if ($transactions): ?>
                <div class="pp-txn-summary">
                    <div class="pp-txn-sum in">
                        <small>Credits</small>
                        <strong>+<?= currency($txnIn) ?></strong>
                    </div>
                    <div class="pp-txn-sum out">
                        <small>Debits</small>
                        <strong>−<?= currency($txnOut) ?></strong>
                    </div>
                    <div class="pp-txn-sum all">
                        <small>Listed</small>
                        <strong><?= count($transactions) ?></strong>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$transactions): ?>
                    <div class="pp-txn-empty">
                        <span class="pp-txn-empty-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M12 14v2"/></svg>
                        </span>
                        <strong>No transactions yet</strong>
                        <p>Earnings and withdrawals will show up here once activity starts.</p>
                    </div>
                <?php else: ?>
                <ul class="pp-txn-list">
                    <?php foreach ($transactions as $txn):
                        $isOut = ($txn['source'] ?? '') === 'withdrawal';
                        $typeRaw = strtolower((string) ($txn['type'] ?? 'other'));
                        $typeLabel = ucfirst(str_replace('_', ' ', $typeRaw));
                        $desc = trim((string) ($txn['description'] ?? ''));
                        $title = $isOut ? 'Withdrawal Request' : ($typeLabel . ' Income');
                        $st = strtolower((string) ($txn['status'] ?? ''));
                        $amt = (float) ($txn['amount'] ?? 0);
                        $badgeClass = $isOut ? 'withdraw' : preg_replace('/[^a-z]/', '', $typeRaw);
                    ?>
                    <li class="<?= $isOut ? 'is-out' : 'is-in' ?>">
                        <span class="pp-txn-ico <?= $isOut ? 'out' : 'in' ?>" aria-hidden="true">
                            <?php if ($isOut): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
                            <?php else: ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                            <?php endif; ?>
                        </span>
                        <div class="pp-txn-meta">
                            <div class="pp-txn-title-row">
                                <strong><?= e($title) ?></strong>
                                <span class="pp-txn-type <?= e($badgeClass) ?>"><?= e($isOut ? 'Payout' : $typeLabel) ?></span>
                            </div>
                            <small>
                                <?= !empty($txn['txn_at']) ? date('d M Y · h:i A', strtotime($txn['txn_at'])) : '—' ?>
                                <?= $desc !== '' ? ' · ' . e(function_exists('mb_strimwidth') ? mb_strimwidth($desc, 0, 42, '…') : (strlen($desc) > 42 ? substr($desc, 0, 39) . '…' : $desc)) : '' ?>
                            </small>
                        </div>
                        <div class="pp-txn-right">
                            <strong class="<?= $isOut ? 'neg' : 'pos' ?>"><?= $isOut ? '−' : '+' ?><?= currency($amt) ?></strong>
                            <span class="pp-txn-status <?= e($st) ?>"><?= e(ucfirst($st ?: '—')) ?></span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
