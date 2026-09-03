<?php
$pageTitle = 'Welcome Letter';
$bodyClass = 'up-page-welcome';
require_once __DIR__ . '/includes/header.php';

$status = member_effective_status($user);
$joinDate = !empty($user['join_date']) ? date('d F Y', strtotime((string) $user['join_date'])) : date('d F Y');
$letterDate = date('d F Y');
$package = (string) ($user['package_name'] ?? 'Starter');
$sponsorName = '—';
$sponsorCode = '—';
if (!empty($user['sponsor_id'])) {
    $ss = $pdo->prepare('SELECT member_id, full_name FROM members WHERE id = ? LIMIT 1');
    $ss->execute([(int) $user['sponsor_id']]);
    $sp = $ss->fetch() ?: null;
    if ($sp) {
        $sponsorName = (string) $sp['full_name'];
        $sponsorCode = (string) $sp['member_id'];
    }
}

$company = setting('company_name', 'Binary MLM');
$supportEmail = setting('support_email', setting('contact_email', ''));
$supportPhone = setting('contact_phone', '');
$signatureUrl = company_signature_url();
$addressParts = array_filter([
    setting('contact_address', ''),
    setting('contact_city', ''),
    setting('contact_state', ''),
    setting('contact_pincode', ''),
    setting('contact_country', ''),
]);
$addressLine = $addressParts ? implode(', ', $addressParts) : '';
$firstName = trim(explode(' ', (string) $user['full_name'])[0] ?: (string) $user['full_name']);
?>

<div class="doc-page">
    <div class="doc-toolbar no-print">
        <div>
            <h1 class="doc-title">Welcome Letter</h1>
            <p class="doc-sub">Official A4 welcome letter for <?= e($user['full_name']) ?></p>
        </div>
        <div class="doc-toolbar-actions">
            <button type="button" class="up-btn up-btn-primary" onclick="window.print()">Print / Save PDF</button>
            <a href="id-card.php" class="up-btn up-btn-outline">ID Card</a>
        </div>
    </div>

    <div class="wl-stage">
        <div class="wl-a4">
            <article class="wl-sheet" aria-label="Welcome Letter A4">
                <div class="wl-frame">
                    <div class="wl-frame-inner">
                        <header class="wl-head">
                            <div class="wl-seal" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            </div>
                            <div class="wl-head-text">
                                <p class="wl-eyebrow">Certificate of Welcome</p>
                                <h1><?= e($company) ?></h1>
                                <p class="wl-tagline">Empowering Independent Sellers</p>
                            </div>
                            <div class="wl-ornament" aria-hidden="true"></div>
                        </header>

                        <div class="wl-date-row">
                            <span>Date: <?= e($letterDate) ?></span>
                            <span>Ref: WL/<?= e($user['member_id']) ?></span>
                        </div>

                        <div class="wl-body">
                            <p class="wl-salute">Dear <strong><?= e($user['full_name']) ?></strong>,</p>

                            <p>
                                It gives us great pleasure to welcome you as an official <em>Independent Seller</em> of
                                <strong><?= e($company) ?></strong>. Your association marks the beginning of a rewarding
                                journey of growth, leadership, and shared success.
                            </p>

                            <p>
                                Your membership has been registered successfully. Please keep this letter as a formal
                                acknowledgment of your association with our organisation.
                            </p>

                            <div class="wl-details">
                                <div>
                                    <span>Member Name</span>
                                    <strong><?= e($user['full_name']) ?></strong>
                                </div>
                                <div>
                                    <span>Member ID</span>
                                    <strong><?= e($user['member_id']) ?></strong>
                                </div>
                                <div>
                                    <span>Package</span>
                                    <strong><?= e($package) ?></strong>
                                </div>
                                <div>
                                    <span>Joining Date</span>
                                    <strong><?= e($joinDate) ?></strong>
                                </div>
                                <div>
                                    <span>Sponsor</span>
                                    <strong><?= e($sponsorName) ?> <small>(<?= e($sponsorCode) ?>)</small></strong>
                                </div>
                                <div>
                                    <span>Status</span>
                                    <strong class="wl-status"><?= e(ucfirst($status)) ?></strong>
                                </div>
                            </div>

                            <p>
                                We encourage you to complete your profile, finish KYC verification, activate your preferred
                                package if pending, and begin building your team with integrity and consistency.
                                Our support team is always available to guide you at every step.
                            </p>

                            <p class="wl-closing">
                                Once again, welcome aboard, <?= e($firstName) ?>. We look forward to celebrating your milestones with you.
                            </p>

                            <p class="wl-regards">Warm regards,</p>
                            <div class="wl-sign">
                                <?php if ($signatureUrl): ?>
                                    <img class="wl-sign-img" src="<?= e($signatureUrl) ?>" alt="Authorized signature">
                                <?php else: ?>
                                    <div class="wl-sign-line"></div>
                                <?php endif; ?>
                                <strong>Authorised Signatory</strong>
                                <small><?= e($company) ?></small>
                            </div>
                        </div>

                        <footer class="wl-foot">
                            <?php if ($addressLine !== ''): ?>
                                <span><?= e($addressLine) ?></span>
                            <?php endif; ?>
                            <span>
                                <?php if ($supportEmail !== ''): ?>Email: <?= e($supportEmail) ?><?php endif; ?>
                                <?php if ($supportEmail !== '' && $supportPhone !== ''): ?> · <?php endif; ?>
                                <?php if ($supportPhone !== ''): ?>Phone: <?= e($supportPhone) ?><?php endif; ?>
                            </span>
                            <em>This is a system-generated welcome letter for member <?= e($user['member_id']) ?>.</em>
                        </footer>
                    </div>
                </div>
            </article>
        </div>
    </div>
</div>

<script>
(function () {
    const wrap = document.querySelector('.wl-a4');
    const sheet = document.querySelector('.wl-sheet');
    const stage = document.querySelector('.wl-stage');
    if (!wrap || !sheet || !stage) return;

    const fit = () => {
        sheet.style.transform = '';
        wrap.style.width = '210mm';
        wrap.style.height = '297mm';
        const sheetW = sheet.offsetWidth;
        const avail = stage.clientWidth;
        if (!sheetW || !avail) return;
        const scale = Math.min(1, avail / sheetW);
        sheet.style.transform = 'scale(' + scale + ')';
        wrap.style.width = (sheetW * scale) + 'px';
        wrap.style.height = (sheet.offsetHeight * scale) + 'px';
    };

    fit();
    window.addEventListener('resize', fit);
    window.addEventListener('beforeprint', () => {
        sheet.style.transform = 'none';
        wrap.style.width = '210mm';
        wrap.style.height = '297mm';
    });
    window.addEventListener('afterprint', fit);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
