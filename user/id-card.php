<?php
$pageTitle = 'ID Card';
require_once __DIR__ . '/includes/header.php';

$status = member_effective_status($user);
$photoUrl = user_photo_url($user['photo'] ?? null);
$initials = user_initials($user['full_name'] ?? 'User');
$joinDate = !empty($user['join_date']) ? date('d M Y', strtotime((string) $user['join_date'])) : '—';
$package = (string) ($user['package_name'] ?? 'Not Activated');
$phone = trim((string) ($user['phone'] ?? ''));
$email = trim((string) ($user['email'] ?? ''));
$username = (string) ($user['username'] ?? '');
$company = setting('company_name', 'Binary MLM');
$support = setting('support_email', setting('contact_email', ''));
$supportPhone = setting('contact_phone', '');

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

$validThru = !empty($user['join_date'])
    ? date('m/Y', strtotime((string) $user['join_date'] . ' +3 years'))
    : date('m/Y', strtotime('+3 years'));
?>

<div class="doc-page">
    <div class="doc-toolbar no-print">
        <div>
            <h1 class="doc-title">Membership ID Card</h1>
            <p class="doc-sub">Front &amp; back — print or save as PDF</p>
        </div>
        <div class="doc-toolbar-actions">
            <button type="button" class="up-btn up-btn-primary" onclick="window.print()">Print / Save PDF</button>
            <a href="welcome-letter.php" class="up-btn up-btn-outline">Welcome Letter</a>
        </div>
    </div>

    <div class="idc-stage">
        <div class="idc-pair">
            <!-- FRONT: identity only -->
            <div class="idc-side">
                <p class="idc-side-label no-print">Front</p>
                <article class="idc-card idc-card-front" aria-label="ID Card Front">
                    <div class="idc-glow" aria-hidden="true"></div>
                    <div class="idc-accent-bar" aria-hidden="true"></div>

                    <header class="idc-head">
                        <div class="idc-brand">
                            <span class="idc-brand-mark" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            </span>
                            <div>
                                <strong><?= e($company) ?></strong>
                                <small>Authorized Seller</small>
                            </div>
                        </div>
                        <span class="idc-chip <?= $status === 'active' ? 'is-ok' : 'is-warn' ?>"><?= e(strtoupper($status)) ?></span>
                    </header>

                    <div class="idc-body">
                        <div class="idc-photo">
                            <?php if ($photoUrl): ?>
                                <img src="<?= e($photoUrl) ?>" alt="<?= e($user['full_name']) ?>">
                            <?php else: ?>
                                <span><?= e($initials) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="idc-info">
                            <p class="idc-label">Member Name</p>
                            <h2 class="idc-name"><?= e($user['full_name']) ?></h2>
                            <dl class="idc-meta">
                                <div>
                                    <dt>Member ID</dt>
                                    <dd><?= e($user['member_id']) ?></dd>
                                </div>
                                <div>
                                    <dt>Package</dt>
                                    <dd><?= e($package) ?></dd>
                                </div>
                                <div>
                                    <dt>Joined</dt>
                                    <dd><?= e($joinDate) ?></dd>
                                </div>
                                <div>
                                    <dt>Valid Thru</dt>
                                    <dd><?= e($validThru) ?></dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <footer class="idc-foot">
                        <div class="idc-barcode" aria-hidden="true">
                            <?php for ($i = 0; $i < 32; $i++): ?>
                                <i style="--h:<?= 40 + (($i * 17) % 60) ?>%"></i>
                            <?php endfor; ?>
                        </div>
                        <span class="idc-foot-tag">MEMBER CARD</span>
                    </footer>
                </article>
            </div>

            <!-- BACK: contact + sponsor only (no repeats from front) -->
            <div class="idc-side">
                <p class="idc-side-label no-print">Back</p>
                <article class="idc-card idc-card-back" aria-label="ID Card Back">
                    <header class="idc-back-head">
                        <div class="idc-magstripe" aria-hidden="true"></div>
                        <div class="idc-back-head-text">
                            <strong>Contact &amp; Network</strong>
                            <small><?= e($company) ?></small>
                        </div>
                    </header>

                    <div class="idc-back-body">
                        <div class="idc-back-grid">
                            <div>
                                <span>Username</span>
                                <strong><?= e($username !== '' ? '@' . $username : '—') ?></strong>
                            </div>
                            <div>
                                <span>Phone</span>
                                <strong><?= e($phone !== '' ? $phone : '—') ?></strong>
                            </div>
                            <div class="idc-span-2">
                                <span>Email</span>
                                <strong><?= e($email !== '' ? $email : '—') ?></strong>
                            </div>
                            <div>
                                <span>Sponsor</span>
                                <strong><?= e($sponsorName) ?></strong>
                            </div>
                            <div>
                                <span>Sponsor ID</span>
                                <strong><?= e($sponsorCode) ?></strong>
                            </div>
                        </div>

                        <div class="idc-back-note">
                            Property of <?= e($company) ?>. If found, return to office / contact support. Misuse is prohibited.
                        </div>
                    </div>

                    <footer class="idc-back-foot">
                        <div class="idc-qr" aria-hidden="true">
                            <?php for ($i = 0; $i < 16; $i++): ?><i></i><?php endfor; ?>
                        </div>
                        <div class="idc-back-contact">
                            <strong>Helpdesk</strong>
                            <?php if ($support !== ''): ?>
                                <small><?= e($support) ?></small>
                            <?php elseif ($supportPhone !== ''): ?>
                                <small><?= e($supportPhone) ?></small>
                            <?php else: ?>
                                <small>Contact your upline</small>
                            <?php endif; ?>
                        </div>
                    </footer>
                </article>
            </div>
        </div>

        <p class="doc-hint no-print">Tip: Print both sides (front &amp; back). Use Print → Save as PDF if needed.</p>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
