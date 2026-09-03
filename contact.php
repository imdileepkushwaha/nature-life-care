<?php
require_once __DIR__ . '/config/database.php';

$company = setting('company_name', 'Binary MLM');
$logoUrl = company_logo_url();
$favUrl = company_favicon_url();
$formEnabled = setting('contact_form_enabled', '1') === '1';

$contact = [
    'person' => setting('contact_person', 'Support Team'),
    'phone' => setting('contact_phone'),
    'whatsapp' => preg_replace('/\D+/', '', (string) setting('contact_whatsapp')),
    'email' => setting('contact_email', setting('support_email', 'support@binarymlm.com')),
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us | <?= e($company) ?></title>
    <meta name="description" content="Contact <?= e($company) ?> — phone, WhatsApp, email, or send a message.">
    <?php if ($favUrl): ?><link rel="icon" href="<?= e($favUrl) ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/landing.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/landing.css') ?>">
    <link rel="stylesheet" href="assets/css/contact.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/contact.css') ?>">
</head>
<body class="lp lp-contact">
    <div class="lp-grain" aria-hidden="true"></div>

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
                    <a href="index.php#about">About</a>
                    <a href="index.php#how">How it works</a>
                    <a href="index.php#income">Income</a>
                    <a href="index.php#stories">Stories</a>
                    <a href="contact.php" aria-current="page">Contact</a>
                    <a href="user/login.php" class="lp-nav-ghost">Member login</a>
                    <a href="user/register.php" class="lp-nav-cta">Join now</a>
                </nav>
                <button type="button" class="lp-nav-toggle" id="lpNavToggle" aria-label="Open menu" aria-expanded="false">
                    <span></span><span></span>
                </button>
            </div>
            <div class="lp-drawer" id="lpDrawer" hidden>
                <a href="index.php#about">About</a>
                <a href="index.php#how">How it works</a>
                <a href="index.php#income">Income</a>
                <a href="index.php#stories">Stories</a>
                <a href="contact.php">Contact</a>
                <a href="user/login.php">Member login</a>
                <a href="user/register.php" class="lp-nav-cta">Join now</a>
            </div>
        </div>
    </header>

    <main>
        <section class="lp-contact-hero">
            <div class="lp-contact-hero-stage" aria-hidden="true">
                <div class="lp-contact-hero-wash"></div>
            </div>
            <div class="lp-shell lp-contact-hero-inner">
                <p class="lp-kicker lp-contact-kicker">Contact</p>
                <h1>Talk to <?= e($company) ?></h1>
                <p class="lp-contact-lead">Reach <?= e($contact['person'] ?: 'our team') ?> — questions on registration, activation, or support.</p>
            </div>
        </section>

        <section class="lp-section lp-contact-body">
            <div class="lp-shell">
                <div class="lp-contact-layout">
                    <aside class="lp-contact-reach">
                        <p class="lp-kicker lp-kicker-dark">Get in touch</p>
                        <h2>Direct lines</h2>
                        <p class="lp-contact-reach-sub">Prefer a quick reply? Use phone, WhatsApp, or email below.</p>

                        <div class="lp-contact-rows">
                            <?php if ($contact['person']): ?>
                            <div class="lp-contact-row">
                                <span class="lp-contact-row-label">Contact person</span>
                                <span class="lp-contact-row-value"><?= e($contact['person']) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($contact['phone']): ?>
                            <a class="lp-contact-row" href="tel:<?= e(preg_replace('/\s+/', '', $contact['phone'])) ?>">
                                <span class="lp-contact-row-label">Phone</span>
                                <span class="lp-contact-row-value"><?= e($contact['phone']) ?></span>
                            </a>
                            <?php endif; ?>
                            <?php if ($contact['whatsapp']): ?>
                            <a class="lp-contact-row" href="https://wa.me/<?= e($contact['whatsapp']) ?>" target="_blank" rel="noopener">
                                <span class="lp-contact-row-label">WhatsApp</span>
                                <span class="lp-contact-row-value">Chat with support</span>
                            </a>
                            <?php endif; ?>
                            <?php if ($contact['email']): ?>
                            <a class="lp-contact-row" href="mailto:<?= e($contact['email']) ?>">
                                <span class="lp-contact-row-label">Email</span>
                                <span class="lp-contact-row-value"><?= e($contact['email']) ?></span>
                            </a>
                            <?php endif; ?>
                            <?php if ($contact['alt_phone']): ?>
                            <div class="lp-contact-row">
                                <span class="lp-contact-row-label">Alternate</span>
                                <span class="lp-contact-row-value"><?= e($contact['alt_phone']) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($fullAddress): ?>
                            <div class="lp-contact-row">
                                <span class="lp-contact-row-label">Address</span>
                                <span class="lp-contact-row-value"><?= e($fullAddress) ?></span>
                                <?php if ($contact['map_url']): ?>
                                <a class="lp-contact-map" href="<?= e($contact['map_url']) ?>" target="_blank" rel="noopener">Open in Maps →</a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($contact['hours']): ?>
                            <div class="lp-contact-row">
                                <span class="lp-contact-row-label">Hours</span>
                                <span class="lp-contact-row-value"><?= e($contact['hours']) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

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

                        <?php if (!$formEnabled): ?>
                            <div class="lp-alert lp-alert-info">Contact form is currently disabled. Please use phone or email.</div>
                        <?php elseif ($success): ?>
                            <div class="lp-alert lp-alert-ok">Thank you! Your message has been sent. Our team will contact you soon.</div>
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
                                        <input type="text" name="subject" value="<?= e($_POST['subject'] ?? '') ?>">
                                    </label>
                                </div>
                                <label class="lp-field">
                                    <span>Message *</span>
                                    <textarea name="message" rows="5" required><?= e($_POST['message'] ?? '') ?></textarea>
                                </label>
                                <button type="submit" class="lp-btn lp-btn-primary">Send message</button>
                            </form>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        </section>
    </main>

    <footer class="lp-foot">
        <div class="lp-foot-glow" aria-hidden="true"></div>
        <div class="lp-shell">
            <div class="lp-foot-intro">
                <div class="lp-foot-brand-block">
                    <?php if ($logoUrl): ?>
                    <img src="<?= e($logoUrl) ?>" alt="<?= e($company) ?>" class="lp-foot-logo">
                    <?php endif; ?>
                    <strong class="lp-foot-brand"><?= e($company) ?></strong>
                    <p>Network marketing for members and leaders who want clarity, structure, and steady growth.</p>
                </div>
                <a href="user/register.php" class="lp-foot-join">
                    <span>Join the network</span>
                    <span class="lp-foot-join-arrow" aria-hidden="true">→</span>
                </a>
            </div>

            <div class="lp-foot-grid">
                <nav class="lp-foot-col" aria-label="Explore">
                    <h3>Explore</h3>
                    <a href="index.php#about">About</a>
                    <a href="index.php#how">How it works</a>
                    <a href="index.php#income">Income plans</a>
                    <a href="index.php#stories">Member stories</a>
                </nav>
                <nav class="lp-foot-col" aria-label="Members">
                    <h3>Members</h3>
                    <a href="user/register.php">Register</a>
                    <a href="user/login.php">Member login</a>
                    <a href="contact.php">Contact</a>
                    <a href="admin/login.php">Admin login</a>
                </nav>
                <div class="lp-foot-col lp-foot-reach">
                    <h3>Reach us</h3>
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
                    <?php if ($phone === '' && $whatsapp === '' && $email === ''): ?>
                    <a href="contact.php">
                        <span class="lp-foot-reach-label">Support</span>
                        <span class="lp-foot-reach-value">Open contact form</span>
                    </a>
                    <?php endif; ?>
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
            btn.addEventListener('click', function () {
                var open = drawer.hasAttribute('hidden');
                if (open) drawer.removeAttribute('hidden');
                else drawer.setAttribute('hidden', '');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            drawer.querySelectorAll('a').forEach(function (a) {
                a.addEventListener('click', function () {
                    drawer.setAttribute('hidden', '');
                    btn.setAttribute('aria-expanded', 'false');
                });
            });
        }
    })();
    </script>
</body>
</html>
