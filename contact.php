<?php
require_once __DIR__ . '/config/database.php';

$rawCompany = setting('company_name', 'Bharat Seva');
$company = ($rawCompany === '' || strcasecmp($rawCompany, 'Binary MLM') === 0)
    ? 'Bharat Seva'
    : $rawCompany;
$logoUrl = company_logo_url();
$favUrl = company_favicon_url();
$tagline = setting('company_tagline', 'स्वास्थ्य भी · रोजगार भी · सम्मान भी');
$formEnabled = setting('contact_form_enabled', '1') === '1';
$siteUrl = 'www.bharatsevamart.com';

$contact = [
    'person' => setting('contact_person', 'Support Team'),
    'phone' => setting('contact_phone'),
    'whatsapp' => preg_replace('/\D+/', '', (string) setting('contact_whatsapp')),
    'email' => setting('contact_email', setting('support_email', '')),
    'alt_phone' => setting('contact_alt_phone'),
    'address' => setting('contact_address'),
    'city' => setting('contact_city'),
    'state' => setting('contact_state'),
    'country' => setting('contact_country', 'India'),
    'pincode' => setting('contact_pincode'),
    'hours' => setting('contact_hours'),
    'map_url' => setting('contact_map_url'),
    'facebook' => setting('contact_facebook'),
    'instagram' => setting('contact_instagram'),
    'twitter' => setting('contact_twitter'),
    'youtube' => setting('contact_youtube'),
    'telegram' => setting('contact_telegram'),
];

$fullAddress = trim(implode(', ', array_filter([
    $contact['address'],
    $contact['city'],
    $contact['state'],
    $contact['pincode'],
    $contact['country'],
])));

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formEnabled) {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));

    if ($name === '') {
        $errors[] = 'Name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email is required.';
    }
    if ($message === '' || strlen($message) < 10) {
        $errors[] = 'Message must be at least 10 characters.';
    }

    if (!$errors) {
        try {
            $pdo->prepare('INSERT INTO contact_inquiries (name, email, phone, subject, message, status, ip_address) VALUES (?,?,?,?,?,?,?)')
                ->execute([
                    $name,
                    $email,
                    $phone !== '' ? $phone : null,
                    $subject !== '' ? $subject : null,
                    $message,
                    'new',
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
            $success = true;
        } catch (Throwable $e) {
            $errors[] = 'Could not send your message. Please try again later.';
        }
    }
}

$social = array_filter([
    'Facebook' => $contact['facebook'],
    'Instagram' => $contact['instagram'],
    'Twitter' => $contact['twitter'],
    'YouTube' => $contact['youtube'],
    'Telegram' => $contact['telegram'],
]);

$phone = (string) ($contact['phone'] ?? '');
$whatsapp = (string) ($contact['whatsapp'] ?? '');
$email = (string) ($contact['email'] ?? '');
?>
<!DOCTYPE html>
<html lang="hi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us | <?= e($company) ?></title>
    <meta name="description" content="<?= e($company) ?> से संपर्क करें — registration, product kits, payout या support के लिए।">
    <?php if ($favUrl): ?><link rel="icon" href="<?= e($favUrl) ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Source+Serif+4:opsz,wght@8..60,500;8..60,600;8..60,700&family=Manrope:wght@400;500;600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/landing.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/landing.css') ?>">
    <link rel="stylesheet" href="assets/css/contact.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/contact.css') ?>">
</head>
<body class="lp lp-contact">
    <div class="lp-grain" aria-hidden="true"></div>

    <div class="lp-topbar">
        <div class="lp-shell lp-topbar-inner">
            <p class="lp-topbar-tag">Wellness · Organic · Natural</p>
            <div class="lp-topbar-links">
                <?php if ($phone !== ''): ?>
                <a href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>"><?= e($phone) ?></a>
                <?php endif; ?>
                <?php if ($email !== ''): ?>
                <a class="lp-topbar-mail" href="mailto:<?= e($email) ?>"><?= e($email) ?></a>
                <?php endif; ?>
                <span class="lp-topbar-web"><?= e($siteUrl) ?></span>
            </div>
        </div>
    </div>

    <header class="lp-header is-scrolled" id="lpHeader">
        <div class="lp-shell lp-header-inner">
            <div class="lp-nav">
                <a class="lp-brand" href="index.php">
                    <?php if ($logoUrl): ?>
                    <img src="<?= e($logoUrl) ?>" alt="<?= e($company) ?>" class="lp-brand-logo">
                    <?php else: ?>
                    <span class="lp-brand-mark" aria-hidden="true"></span>
                    <span class="lp-brand-name"><?= e($company) ?></span>
                    <?php endif; ?>
                </a>
                <nav class="lp-nav-links" aria-label="Primary">
                    <a href="index.php">Home</a>
                    <a href="index.php#about">About</a>
                    <a href="index.php#how">How</a>
                    <a href="index.php#products">Products</a>
                    <a href="index.php#stories">Stories</a>
                    <a href="contact.php" aria-current="page">Contact</a>
                    <a href="user/login.php" class="lp-nav-ghost">Sign In</a>
                    <a href="user/register.php" class="lp-nav-cta">Join Us</a>
                </nav>
                <button type="button" class="lp-nav-toggle" id="lpNavToggle" aria-label="Open menu" aria-expanded="false" aria-controls="lpDrawer">
                    <span></span><span></span><span></span>
                </button>
            </div>
            <div class="lp-drawer" id="lpDrawer" hidden>
                <nav class="lp-drawer-nav" aria-label="Mobile">
                    <a href="index.php">Home</a>
                    <a href="index.php#about">About</a>
                    <a href="index.php#how">How it works</a>
                    <a href="index.php#products">Products</a>
                    <a href="index.php#vision">Vision</a>
                    <a href="index.php#stories">Stories</a>
                    <a href="contact.php" aria-current="page">Contact</a>
                </nav>
                <div class="lp-drawer-actions">
                    <a href="user/login.php" class="lp-nav-ghost">Sign In</a>
                    <a href="user/register.php" class="lp-nav-cta">Join Us</a>
                </div>
            </div>
        </div>
    </header>

    <main>
        <section class="lp-contact-hero">
            <div class="lp-contact-hero-stage" aria-hidden="true">
                <div class="lp-contact-hero-wash"></div>
                <div class="lp-hero-sun"></div>
            </div>
            <div class="lp-shell lp-contact-hero-inner">
                <p class="lp-kicker lp-contact-kicker">Contact</p>
                <h1>Talk to <?= e($company) ?></h1>
                <p class="lp-contact-lead">
                    Registration, products, payout या support — <?= e($contact['person'] ?: 'हमारी टीम') ?> से जुड़ें।
                    <?= e($tagline) ?>
                </p>
                <div class="lp-contact-quick">
                    <?php if ($contact['phone']): ?>
                    <a class="lp-contact-quick-btn" href="tel:<?= e(preg_replace('/\s+/', '', $contact['phone'])) ?>">Call</a>
                    <?php endif; ?>
                    <?php if ($contact['whatsapp']): ?>
                    <a class="lp-contact-quick-btn" href="https://wa.me/<?= e($contact['whatsapp']) ?>" target="_blank" rel="noopener">WhatsApp</a>
                    <?php endif; ?>
                    <?php if ($contact['email']): ?>
                    <a class="lp-contact-quick-btn is-line" href="mailto:<?= e($contact['email']) ?>">Email</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="lp-section lp-contact-body">
            <div class="lp-shell lp-contact-layout">
                <aside class="lp-contact-reach">
                    <p class="lp-kicker lp-kicker-dark">Get in touch</p>
                    <h2>Direct lines</h2>
                    <p class="lp-contact-reach-sub">Phone, WhatsApp या email से जल्दी उत्तर पाएँ। Clear invoice, support और grievance tracking — customer first.</p>

                    <div class="lp-contact-cards">
                        <?php if ($contact['person']): ?>
                        <div class="lp-c-card">
                            <span class="lp-c-card-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                            </span>
                            <span class="lp-c-card-label">Contact person</span>
                            <strong><?= e($contact['person']) ?></strong>
                        </div>
                        <?php endif; ?>
                        <?php if ($contact['phone']): ?>
                        <a class="lp-c-card" href="tel:<?= e(preg_replace('/\s+/', '', $contact['phone'])) ?>">
                            <span class="lp-c-card-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.37 1.9.72 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.35 1.85.59 2.81.72A2 2 0 0 1 22 16.92z"/></svg>
                            </span>
                            <span class="lp-c-card-label">Phone</span>
                            <strong><?= e($contact['phone']) ?></strong>
                        </a>
                        <?php endif; ?>
                        <?php if ($contact['whatsapp']): ?>
                        <a class="lp-c-card" href="https://wa.me/<?= e($contact['whatsapp']) ?>" target="_blank" rel="noopener">
                            <span class="lp-c-card-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8z"/></svg>
                            </span>
                            <span class="lp-c-card-label">WhatsApp</span>
                            <strong>Chat with support</strong>
                        </a>
                        <?php endif; ?>
                        <?php if ($contact['email']): ?>
                        <a class="lp-c-card" href="mailto:<?= e($contact['email']) ?>">
                            <span class="lp-c-card-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                            </span>
                            <span class="lp-c-card-label">Email</span>
                            <strong><?= e($contact['email']) ?></strong>
                        </a>
                        <?php endif; ?>
                        <?php if ($contact['alt_phone']): ?>
                        <div class="lp-c-card">
                            <span class="lp-c-card-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.37 1.9.72 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.35 1.85.59 2.81.72A2 2 0 0 1 22 16.92z"/></svg>
                            </span>
                            <span class="lp-c-card-label">Alternate</span>
                            <strong><?= e($contact['alt_phone']) ?></strong>
                        </div>
                        <?php endif; ?>
                        <?php if ($contact['hours']): ?>
                        <div class="lp-c-card">
                            <span class="lp-c-card-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                            </span>
                            <span class="lp-c-card-label">Hours</span>
                            <strong><?= e($contact['hours']) ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($fullAddress): ?>
                    <div class="lp-c-address">
                        <span class="lp-c-card-label">Address</span>
                        <p><?= e($fullAddress) ?></p>
                        <?php if ($contact['map_url']): ?>
                        <a href="<?= e($contact['map_url']) ?>" target="_blank" rel="noopener">Open in Maps →</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($social): ?>
                    <div class="lp-contact-social">
                        <?php foreach ($social as $label => $url): ?>
                        <a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </aside>

                <section class="lp-contact-form-panel" aria-labelledby="contact-form-title">
                    <p class="lp-kicker lp-kicker-dark">Message</p>
                    <h2 id="contact-form-title">Send a message</h2>
                    <p class="lp-contact-form-lead">Product, registration या payout से जुड़ा सवाल लिखें। हम जल्द उत्तर देंगे।</p>

                    <?php if (!$formEnabled): ?>
                        <div class="lp-alert lp-alert-info">Contact form is currently disabled. Please use phone or email.</div>
                    <?php elseif ($success): ?>
                        <div class="lp-alert lp-alert-ok">धन्यवाद! आपका संदेश भेज दिया गया है। हमारी टीम जल्द संपर्क करेगी।</div>
                        <a href="contact.php" class="lp-btn lp-btn-primary">Send another message</a>
                    <?php else: ?>
                        <?php if ($errors): ?>
                        <div class="lp-alert lp-alert-err"><?= e(implode(' ', $errors)) ?></div>
                        <?php endif; ?>
                        <form method="post" class="lp-contact-form">
                            <div class="lp-contact-form-grid">
                                <label class="lp-field">
                                    <span>Your name *</span>
                                    <input type="text" name="name" value="<?= e($_POST['name'] ?? '') ?>" required>
                                </label>
                                <label class="lp-field">
                                    <span>Email *</span>
                                    <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required>
                                </label>
                                <label class="lp-field">
                                    <span>Phone</span>
                                    <input type="text" name="phone" value="<?= e($_POST['phone'] ?? '') ?>">
                                </label>
                                <label class="lp-field">
                                    <span>Subject</span>
                                    <input type="text" name="subject" value="<?= e($_POST['subject'] ?? '') ?>" placeholder="Product, payout, KYC…">
                                </label>
                            </div>
                            <label class="lp-field">
                                <span>Message *</span>
                                <textarea name="message" rows="5" required placeholder="Write your question…"><?= e($_POST['message'] ?? '') ?></textarea>
                            </label>
                            <button type="submit" class="lp-btn lp-btn-primary">Send message</button>
                        </form>
                    <?php endif; ?>
                </section>
            </div>
        </section>
    </main>

    <footer class="lp-foot">
        <div class="lp-foot-glow" aria-hidden="true"></div>
        <div class="lp-shell">
            <div class="lp-foot-grid">
                <div class="lp-foot-brand-col">
                    <?php if ($logoUrl): ?>
                    <img src="<?= e($logoUrl) ?>" alt="<?= e($company) ?>" class="lp-foot-logo">
                    <?php endif; ?>
                    <strong class="lp-foot-brand"><?= e($company) ?></strong>
                    <p class="lp-foot-mantra"><?= e($tagline) ?></p>
                    <p class="lp-foot-lead">स्वास्थ्य के साथ समृद्धि की ओर — Wellness, Organic और Natural products के साथ एक Direct Selling अवसर।</p>
                    <a href="user/register.php" class="lp-foot-join">
                        <span>Join Us</span>
                        <span class="lp-foot-join-arrow" aria-hidden="true">→</span>
                    </a>
                </div>
                <nav class="lp-foot-col" aria-label="Explore">
                    <h3>Explore</h3>
                    <a href="index.php#about">About</a>
                    <a href="index.php#how">How it works</a>
                    <a href="index.php#products">Products</a>
                    <a href="index.php#vision">Vision</a>
                    <a href="index.php#stories">Stories</a>
                    <a href="contact.php">Contact</a>
                </nav>
                <nav class="lp-foot-col" aria-label="Members">
                    <h3>Members</h3>
                    <a href="user/register.php">Sign Up</a>
                    <a href="user/login.php">Sign In</a>
                    <a href="contact.php">Support</a>
                    <a href="admin/login.php">Admin login</a>
                </nav>
                <div class="lp-foot-col lp-foot-reach">
                    <h3>Contact</h3>
                    <?php if ($phone !== ''): ?>
                    <a href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>">
                        <span class="lp-foot-reach-label">Phone</span>
                        <span class="lp-foot-reach-value"><?= e($phone) ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if ($whatsapp !== ''): ?>
                    <a href="https://wa.me/<?= e($whatsapp) ?>" target="_blank" rel="noopener">
                        <span class="lp-foot-reach-label">WhatsApp</span>
                        <span class="lp-foot-reach-value">Chat with support</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($email !== ''): ?>
                    <a href="mailto:<?= e($email) ?>">
                        <span class="lp-foot-reach-label">Email</span>
                        <span class="lp-foot-reach-value"><?= e($email) ?></span>
                    </a>
                    <?php endif; ?>
                    <a href="https://<?= e($siteUrl) ?>" target="_blank" rel="noopener">
                        <span class="lp-foot-reach-label">Web</span>
                        <span class="lp-foot-reach-value"><?= e($siteUrl) ?></span>
                    </a>
                </div>
            </div>

            <div class="lp-foot-bottom">
                <span>&copy; <?= date('Y') ?> <?= e($company) ?>. All rights reserved.</span>
                <a href="index.php">Back to home</a>
            </div>
        </div>
    </footer>

    <script>
    (function () {
        var header = document.getElementById('lpHeader');
        var btn = document.getElementById('lpNavToggle');
        var drawer = document.getElementById('lpDrawer');

        if (header) header.classList.add('is-scrolled');

        if (btn && drawer) {
            function setMenu(open) {
                if (open) drawer.removeAttribute('hidden');
                else drawer.setAttribute('hidden', '');
                btn.classList.toggle('is-open', open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
                document.body.classList.toggle('lp-menu-open', open);
            }
            btn.addEventListener('click', function () {
                setMenu(drawer.hasAttribute('hidden'));
            });
            drawer.querySelectorAll('a').forEach(function (a) {
                a.addEventListener('click', function () { setMenu(false); });
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') setMenu(false);
            });
        }
    })();
    </script>
    <?php require_once __DIR__ . '/includes/public_popup.php'; ?>
</body>
</html>
