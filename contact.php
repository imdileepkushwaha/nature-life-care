<?php
require_once __DIR__ . '/config/database.php';

$rawCompany = setting('company_name', 'Nature Life Care');
$company = ($rawCompany === '' || strcasecmp($rawCompany, 'Binary MLM') === 0)
    ? 'Nature Life Care'
    : $rawCompany;
$logoUrl = company_logo_url();
$favUrl = company_favicon_url();
$tagline = setting('company_tagline', 'Nourishing Life Naturally');
$formEnabled = setting('contact_form_enabled', '1') === '1';

$phone = setting('contact_phone', '8900407342');
$email = setting('contact_email', setting('support_email', 'naturelifecaretm@gmail.com'));
$address = setting('contact_address', 'Shiv Nagar Lane-2, Gurunanak Pally, Asansol, West Bengal – 713301');
$siteUrl = 'naturelifecare.com';

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formEnabled) {
    $name = trim((string) ($_POST['name'] ?? ''));
    $phoneInput = trim((string) ($_POST['phone'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));

    if ($name === '') {
        $errors[] = 'Please enter your name.';
    }
    if ($phoneInput === '') {
        $errors[] = 'Please enter your phone number.';
    }
    if ($message === '') {
        $errors[] = 'Please enter your message.';
    }

    if (!$errors) {
        try {
            $pdo->prepare('INSERT INTO contact_inquiries (name, email, phone, subject, message, status, ip_address) VALUES (?,?,?,?,?,?,?)')
                ->execute([
                    $name,
                    null,
                    $phoneInput !== '' ? $phoneInput : null,
                    'Website Inquiry',
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

$pageTitle = 'Contact Us';
require_once __DIR__ . '/includes/public_header.php';
?>

    <main>
        <section class="page-banner">
            <div class="container page-banner-inner">
                <p class="eyebrow green" style="color: white;">Nature Life Care</p>
                <h1>Contact Us</h1>
                <p>Have questions or want to learn more? We'd love to hear from you!</p>
            </div>
        </section>
        <section id="contact" class="section contact-section">
            <div class="container">
                <div class="section-heading reveal">
                    <p class="eyebrow green">Contact Us</p>
                    <h2>Get in Touch</h2>
                </div>
            </div>
            <div class="container contact-grid">
                <div class="contact-info reveal">
                    <h2>NATURE LIFE CARE™</h2>
                    <ul>
                        <li><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($address) ?></li>
                        <li><i class="fa-solid fa-phone"></i> <a href="tel:<?= htmlspecialchars($phone) ?>"><?= htmlspecialchars($phone) ?></a></li>
                        <li><i class="fa-solid fa-envelope"></i> <a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a></li>
                        <li><i class="fa-solid fa-globe"></i> <a href="https://<?= htmlspecialchars($siteUrl) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($siteUrl) ?></a></li>
                        <li><i class="fa-solid fa-file-invoice"></i> GSTIN: 19AZIPB1251D1ZV</li>
                    </ul>
                </div>

                <div class="contact-form reveal">
                    <?php if ($success): ?>
                        <div style="padding: 16px; background: #e8f5e9; border: 1px solid #4caf50; border-radius: 14px; color: #2e7d32; margin-bottom: 20px; font-weight: 500;">
                            Thank you! Your message has been sent successfully. Our team will contact you shortly.
                        </div>
                    <?php elseif ($errors): ?>
                        <div style="padding: 16px; background: #ffebee; border: 1px solid #f44336; border-radius: 14px; color: #c62828; margin-bottom: 20px; font-weight: 500;">
                            <?= htmlspecialchars(implode(' ', $errors)) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="contact.php">
                        <div class="form-row">
                            <label>
                                Name
                                <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" placeholder="Your name" required />
                            </label>
                        </div>
                        <div class="form-row">
                            <label>
                                Phone
                                <input type="tel" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="Your phone number" required />
                            </label>
                        </div>
                        <div class="form-row">
                            <label>
                                Message
                                <textarea name="message" rows="4" placeholder="Tell us about your interest" required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary">Send Message</button>
                    </form>
                </div>
            </div>
        </section>
    </main>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
