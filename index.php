<?php
require_once __DIR__ . '/config/database.php';

$rawCompany = setting('company_name', 'Bharat Seva');
$company = ($rawCompany === '' || strcasecmp($rawCompany, 'Binary MLM') === 0)
    ? 'Bharat Seva'
    : $rawCompany;
$logoUrl = company_logo_url();
$favUrl = company_favicon_url();
$tagline = setting('company_tagline', 'स्वास्थ्य भी · रोजगार भी · सम्मान भी');

$phone = setting('contact_phone', '');
$whatsapp = preg_replace('/\D+/', '', (string) setting('contact_whatsapp', ''));
$email = setting('contact_email', setting('support_email', ''));
$siteUrl = 'www.bharatsevamart.com';
$rupee = currency_symbol_html();

$products = [
    [
        'name' => 'Tulsi Guard Drops',
        'category' => 'Immunity',
        'price' => '499',
        'note' => 'Daily herbal tulsi drops for immunity and daily wellness.',
        'image' => 'assets/img/lp-product-1.png',
        'alt' => 'Tulsi herbal immunity drops bottle',
    ],
    [
        'name' => 'Haldi Gold Capsules',
        'category' => 'Wellness',
        'price' => '799',
        'note' => 'Organic turmeric capsules for joint comfort and inner health.',
        'image' => 'assets/img/lp-product-2.png',
        'alt' => 'Turmeric gold Ayurvedic capsules',
    ],
    [
        'name' => 'Amla Hair Oil',
        'category' => 'Hair care',
        'price' => '349',
        'note' => 'Amla and herb oil to nourish scalp and strengthen hair.',
        'image' => 'assets/img/lp-product-3.png',
        'alt' => 'Herbal amla hair oil bottle',
    ],
    [
        'name' => 'Forest Honey',
        'category' => 'Nutrition',
        'price' => '449',
        'note' => 'Raw organic honey for energy, immunity and natural taste.',
        'image' => 'assets/img/lp-product-4.png',
        'alt' => 'Organic forest honey jar',
    ],
];

require_once __DIR__ . '/includes/plan_incentives.php';
$landingRanks = [];
$landingRewards = [];
try {
    $landingRanks = plan_ranks_list($pdo);
    $landingRewards = plan_rewards_list($pdo);
} catch (Throwable $e) {
    $landingRanks = [];
    $landingRewards = [];
}
if (!$landingRanks) {
    $landingRanks = plan_rank_defaults();
}
if (!$landingRewards) {
    $landingRewards = plan_reward_defaults();
}
?>
<!DOCTYPE html>
<html lang="hi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($company) ?> — Wellness · Organic · Natural</title>
    <meta name="description" content="<?= e($company) ?> — भरोसेमंद wellness और organic products के साथ product-led व्यवसाय। स्वास्थ्य भी, रोजगार भी, सम्मान भी।">
    <?php if ($favUrl): ?><link rel="icon" href="<?= e($favUrl) ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Source+Serif+4:opsz,wght@8..60,500;8..60,600;8..60,700&family=Manrope:wght@400;500;600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/landing.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/landing.css') ?>">
</head>
<body class="lp">
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

    <header class="lp-header" id="lpHeader">
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
                    <a href="index.php" aria-current="page">Home</a>
                    <a href="#about">About</a>
                    <a href="#how">How</a>
                    <a href="#products">Products</a>
                    <a href="#stories">Stories</a>
                    <a href="contact.php">Contact</a>
                    <a href="user/login.php" class="lp-nav-ghost">Sign In</a>
                    <a href="user/register.php" class="lp-nav-cta">Join Us</a>
                </nav>
                <button type="button" class="lp-nav-toggle" id="lpNavToggle" aria-label="Open menu" aria-expanded="false" aria-controls="lpDrawer">
                    <span></span><span></span><span></span>
                </button>
            </div>
            <div class="lp-drawer" id="lpDrawer" hidden>
                <nav class="lp-drawer-nav" aria-label="Mobile">
                    <a href="index.php" aria-current="page">Home</a>
                    <a href="#about">About</a>
                    <a href="#how">How it works</a>
                    <a href="#products">Products</a>
                    <a href="#vision">Vision</a>
                    <a href="#stories">Stories</a>
                    <a href="contact.php">Contact</a>
                </nav>
                <div class="lp-drawer-actions">
                    <a href="user/login.php" class="lp-nav-ghost">Sign In</a>
                    <a href="user/register.php" class="lp-nav-cta">Join Us</a>
                </div>
            </div>
        </div>
    </header>

    <main>
        <section class="lp-hero" id="lpHero">
            <div class="lp-hero-stage" aria-hidden="true">
                <div class="lp-hero-wash"></div>
                <div class="lp-hero-sun"></div>
                <div class="lp-hero-leaf lp-hero-leaf-a"></div>
                <div class="lp-hero-leaf lp-hero-leaf-b"></div>
            </div>

            <div class="lp-slides" id="lpSlides">
                <article class="lp-slide is-on">
                    <div class="lp-shell lp-hero-shell">
                        <div class="lp-hero-copy">
                            <p class="lp-hero-brand"><?= e($company) ?></p>
                            <h1>Nurturing Health<br>Growing Prosperity</h1>
                            <p class="lp-hero-lead">स्वास्थ्य भी · रोजगार भी · सम्मान भी</p>
                            <a href="user/register.php" class="lp-btn lp-btn-primary lp-btn-lg">Join Us</a>
                        </div>
                        <figure class="lp-hero-visual">
                            <img src="assets/img/lp-hero-1.png" alt="Organic Ayurvedic wellness products on a wooden table" width="960" height="720">
                        </figure>
                    </div>
                </article>
                <article class="lp-slide">
                    <div class="lp-shell lp-hero-shell">
                        <div class="lp-hero-copy">
                            <p class="lp-hero-brand"><?= e($company) ?></p>
                            <h1>Rooted in Nature<br>Rising with Purpose</h1>
                            <p class="lp-hero-lead">Wellness · Organic · Natural products, सही जानकारी के साथ।</p>
                            <a href="user/register.php" class="lp-btn lp-btn-primary lp-btn-lg">Join Us</a>
                        </div>
                        <figure class="lp-hero-visual">
                            <img src="assets/img/lp-hero-2.png" alt="Wellness mart with organic kits and natural products" width="960" height="720">
                        </figure>
                    </div>
                </article>
                <article class="lp-slide">
                    <div class="lp-shell lp-hero-shell">
                        <div class="lp-hero-copy">
                            <p class="lp-hero-brand"><?= e($company) ?></p>
                            <h1>Harvesting Health<br>Building Futures</h1>
                            <p class="lp-hero-lead"><?= e($tagline) ?></p>
                            <a href="user/register.php" class="lp-btn lp-btn-primary lp-btn-lg">Join Us</a>
                        </div>
                        <figure class="lp-hero-visual">
                            <img src="assets/img/lp-hero-3.png" alt="Fresh organic harvest from farm fields at golden hour" width="960" height="720">
                        </figure>
                    </div>
                </article>
            </div>

            <div class="lp-slide-nav" aria-label="Hero slides">
                <button type="button" class="is-on" data-slide="0" aria-label="Slide 1"></button>
                <button type="button" data-slide="1" aria-label="Slide 2"></button>
                <button type="button" data-slide="2" aria-label="Slide 3"></button>
            </div>
        </section>

        <section class="lp-section lp-welcome" id="about">
            <div class="lp-shell lp-welcome-grid">
                <figure class="lp-welcome-visual">
                    <img src="assets/img/lp-about.png" alt="Ayurvedic wellness studio with organic herbs and natural products" width="900" height="1200">
                    <figcaption class="lp-welcome-badge">सबका साथ · सबका विकास</figcaption>
                </figure>
                <div class="lp-welcome-copy">
                    <p class="lp-kicker lp-kicker-dark">About us</p>
                    <h2>Welcome to <?= e($company) ?></h2>
                    <p class="lp-welcome-mantra">एक product-led सेवा व्यवसाय</p>
                    <p class="lp-about-lead">
                        <?= e($company) ?> हर वर्ग तक भरोसेमंद Wellness और Organic products पहुँचाना चाहता है —
                        सही उत्पाद, सही जानकारी और सही व्यवसाय पद्धति से।
                    </p>
                    <p>
                        यह एक emerging, innovation-driven direct selling अवसर है: Ayurvedic और organic
                        catalogue के साथ ग्राहक को स्पष्ट invoice, MRP और support मिलता है। Joining मुफ्त है;
                        व्यवसाय वास्तविक products और eligible sales volume पर आधारित है।
                    </p>
                    <p>
                        हम केवल membership नहीं बेचते। ग्राहक का भरोसा हमारी सबसे बड़ी पूँजी है —
                        <strong>स्वास्थ्य के साथ समृद्धि की ओर।</strong>
                    </p>
                    <ul class="lp-welcome-values">
                        <li>Quality</li>
                        <li>Transparency</li>
                        <li>Service</li>
                        <li>Training</li>
                        <li>Integrity</li>
                    </ul>
                    <a href="#products" class="lp-btn lp-btn-primary">Discover products</a>
                </div>
            </div>
        </section>

        <section class="lp-section lp-how" id="how">
            <div class="lp-shell">
                <header class="lp-sec-head lp-how-head">
                    <p class="lp-kicker lp-kicker-dark">How it works</p>
                    <h2>चार कदम — register से benefits तक</h2>
                    <p>पहले Product · फिर Customer Service · फिर Business Expansion</p>
                </header>
                <ol class="lp-steps">
                    <li>
                        <span class="lp-step-mark" aria-hidden="true">
                            <span class="lp-step-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M16 11h6"/></svg>
                            </span>
                        </span>
                        <div class="lp-step-body">
                            <span>01</span>
                            <h3>Online Register</h3>
                            <p>Sign up for free</p>
                        </div>
                    </li>
                    <li>
                        <span class="lp-step-mark" aria-hidden="true">
                            <span class="lp-step-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                            </span>
                        </span>
                        <div class="lp-step-body">
                            <span>02</span>
                            <h3>Purchase Product</h3>
                            <p>Activate your account</p>
                        </div>
                    </li>
                    <li>
                        <span class="lp-step-mark" aria-hidden="true">
                            <span class="lp-step-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </span>
                        </span>
                        <div class="lp-step-body">
                            <span>03</span>
                            <h3>Refer Products</h3>
                            <p>Grow your team</p>
                        </div>
                    </li>
                    <li>
                        <span class="lp-step-mark" aria-hidden="true">
                            <span class="lp-step-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 14.5 8.5 20.5 9.5 16 13.8 17.2 20 12 17 6.8 20 8 13.8 3.5 9.5 9.5 8.5Z"/></svg>
                            </span>
                        </span>
                        <div class="lp-step-body">
                            <span>04</span>
                            <h3>Get Benefits</h3>
                            <p>Earn unlimited</p>
                        </div>
                    </li>
                </ol>
            </div>
        </section>

        <section class="lp-section lp-products" id="products">
            <div class="lp-shell">
                <header class="lp-sec-head lp-sec-head-light">
                    <p class="lp-kicker">Our catalogue</p>
                    <h2>Wellness products for everyday health</h2>
                    <p>Dummy showcase — Ayurvedic aur organic range. Final MRP, taxes aur invoice catalogue confirm hone par update होंगे।</p>
                </header>
                <div class="lp-product-grid">
                    <?php foreach ($products as $product): ?>
                    <article class="lp-product">
                        <figure class="lp-product-media">
                            <img src="<?= e($product['image']) ?>" alt="<?= e($product['alt']) ?>" width="600" height="600">
                        </figure>
                        <div class="lp-product-body">
                            <p class="lp-product-cat"><?= e($product['category']) ?></p>
                            <h3><?= e($product['name']) ?></h3>
                            <p><?= e($product['note']) ?></p>
                            <div class="lp-product-row">
                                <p class="lp-product-price"><span><?= $rupee ?></span><?= e($product['price']) ?></p>
                                <a href="user/register.php">View</a>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="lp-section lp-vm" id="vision">
            <div class="lp-shell">
                <header class="lp-sec-head lp-vm-head">
                    <p class="lp-kicker lp-kicker-dark">Purpose</p>
                    <h2>Vision &amp; Mission</h2>
                    <p>स्वास्थ्य भी · रोजगार भी · सम्मान भी</p>
                </header>
                <div class="lp-vm-grid">
                    <article class="lp-vm-card lp-vm-card-vision">
                        <div class="lp-vm-card-top">
                            <span class="lp-vm-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
                            </span>
                            <span class="lp-vm-n">01</span>
                        </div>
                        <p class="lp-kicker">Our Vision</p>
                        <h3>भरोसेमंद Wellness हर वर्ग तक</h3>
                        <p>भारत के हर वर्ग तक भरोसेमंद Wellness और Organic products पहुँचाना — सरल, उत्पाद-केंद्रित अवसर के साथ।</p>
                    </article>
                    <article class="lp-vm-card lp-vm-card-mission">
                        <div class="lp-vm-card-top">
                            <span class="lp-vm-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                            </span>
                            <span class="lp-vm-n">02</span>
                        </div>
                        <p class="lp-kicker lp-kicker-dark">Our Mission</p>
                        <h3>सही उत्पाद, सही पद्धति</h3>
                        <p>सही उत्पाद, सही जानकारी और सही व्यवसाय पद्धति से लोगों को सक्षम बनाना। Training, transparency और quality नींव हैं।</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="lp-section lp-stories" id="stories">
            <div class="lp-shell">
                <header class="lp-stories-head">
                    <div class="lp-stories-head-copy">
                        <p class="lp-kicker lp-kicker-dark">Testimonials</p>
                        <h2>What our partners say</h2>
                        <p class="lp-stories-sub">Product quality, training और transparent payouts — <?= e($company) ?> partners की आवाज़।</p>
                    </div>
                    <div class="lp-stories-nav" aria-label="Scroll testimonials">
                        <button type="button" class="lp-stories-btn" id="lpStoriesPrev" aria-label="Previous">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                        </button>
                        <button type="button" class="lp-stories-btn" id="lpStoriesNext" aria-label="Next">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                    </div>
                </header>
                <div class="lp-stories-track-wrap">
                    <div class="lp-stories-track" id="lpStoriesTrack" tabindex="0">
                        <blockquote class="lp-story">
                            <div class="lp-story-top">
                                <span class="lp-story-mark" aria-hidden="true">“</span>
                                <span class="lp-story-stars" aria-label="5 out of 5">★★★★★</span>
                            </div>
                            <p class="lp-story-quote">Invoice साफ़ है — MRP, quantity, सब दिखता है। Customer को समझाना आसान हो गया।</p>
                            <footer class="lp-story-meta">
                                <span class="lp-story-avatar" aria-hidden="true">RK</span>
                                <div>
                                    <strong>Ravi Kumar</strong>
                                    <span>Wellness partner · Lucknow</span>
                                </div>
                            </footer>
                        </blockquote>
                        <blockquote class="lp-story">
                            <div class="lp-story-top">
                                <span class="lp-story-mark" aria-hidden="true">“</span>
                                <span class="lp-story-stars" aria-label="5 out of 5">★★★★★</span>
                            </div>
                            <p class="lp-story-quote">Registration मुफ्त है, और training से product knowledge मिली। Income claims की जगह real sales पर फ़ोकस है।</p>
                            <footer class="lp-story-meta">
                                <span class="lp-story-avatar" aria-hidden="true">PS</span>
                                <div>
                                    <strong>Priya Sharma</strong>
                                    <span>Direct partner · Jaipur</span>
                                </div>
                            </footer>
                        </blockquote>
                        <blockquote class="lp-story">
                            <div class="lp-story-top">
                                <span class="lp-story-mark" aria-hidden="true">“</span>
                                <span class="lp-story-stars" aria-label="5 out of 5">★★★★★</span>
                            </div>
                            <p class="lp-story-quote">Matching volume समझने लायक है। Return के बाद net eligible volume पर settlement — यही transparency चाहिए थी।</p>
                            <footer class="lp-story-meta">
                                <span class="lp-story-avatar" aria-hidden="true">AM</span>
                                <div>
                                    <strong>Amit Mehta</strong>
                                    <span>Star rank · Indore</span>
                                </div>
                            </footer>
                        </blockquote>
                        <blockquote class="lp-story">
                            <div class="lp-story-top">
                                <span class="lp-story-mark" aria-hidden="true">“</span>
                                <span class="lp-story-stars" aria-label="5 out of 5">★★★★★</span>
                            </div>
                            <p class="lp-story-quote">Weekly closing और bank payout की प्रक्रिया साफ़ बताई गई। TDS statement से trust बना।</p>
                            <footer class="lp-story-meta">
                                <span class="lp-story-avatar" aria-hidden="true">NS</span>
                                <div>
                                    <strong>Neha Singh</strong>
                                    <span>Team builder · Pune</span>
                                </div>
                            </footer>
                        </blockquote>
                    </div>
                </div>
            </div>
        </section>

        <section class="lp-cta-band">
            <div class="lp-shell">
                <div class="lp-cta-panel">
                    <div class="lp-cta-glow" aria-hidden="true"></div>
                    <div class="lp-cta-copy">
                        <p class="lp-kicker">Get started</p>
                        <h2>Ready to build with <?= e($company) ?>?</h2>
                        <p>मुफ्त ID बनाएँ, wellness products देखें, और product-led व्यवसाय शुरू करें — स्वास्थ्य के साथ समृद्धि की ओर।</p>
                        <div class="lp-cta-actions">
                            <a href="user/register.php" class="lp-btn lp-btn-primary lp-btn-lg">Sign Up free</a>
                            <a href="user/login.php" class="lp-btn lp-btn-line lp-btn-lg">Sign In</a>
                        </div>
                    </div>
                    <ul class="lp-cta-perks" aria-label="What you get">
                        <li>
                            <span class="lp-cta-perk-n">01</span>
                            <div>
                                <strong>Free joining</strong>
                                <span>कोई registration fee नहीं — products से शुरुआत।</span>
                            </div>
                        </li>
                        <li>
                            <span class="lp-cta-perk-n">02</span>
                            <div>
                                <strong>Organic catalogue</strong>
                                <span>Tulsi, Haldi, Amla aur honey — everyday wellness range.</span>
                            </div>
                        </li>
                        <li>
                            <span class="lp-cta-perk-n">03</span>
                            <div>
                                <strong>Weekly payout</strong>
                                <span>Saturday closing, Monday–Tuesday verified bank credit.</span>
                            </div>
                        </li>
                    </ul>
                </div>
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
                    <a href="#about">About</a>
                    <a href="#how">How it works</a>
                    <a href="#products">Products</a>
                    <a href="#vision">Vision</a>
                    <a href="#stories">Stories</a>
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
                <a href="contact.php">Need help? Contact support</a>
            </div>
        </div>
    </footer>

    <script>
    (function () {
        var header = document.getElementById('lpHeader');
        var btn = document.getElementById('lpNavToggle');
        var drawer = document.getElementById('lpDrawer');

        function onScroll() {
            if (!header) return;
            header.classList.toggle('is-scrolled', window.scrollY > 12);
        }
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        if (btn && drawer) {
            function setMenu(open) {
                if (open) drawer.removeAttribute('hidden');
                else drawer.setAttribute('hidden', '');
                btn.classList.toggle('is-open', open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
                document.body.classList.toggle('lp-menu-open', open);
                if (header && open) header.classList.add('is-scrolled');
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

        var slides = document.querySelectorAll('.lp-slide');
        var dots = document.querySelectorAll('.lp-slide-nav button');
        var hero = document.getElementById('lpHero');
        var idx = 0;
        var timer;
        function go(n) {
            if (!slides.length) return;
            slides[idx].classList.remove('is-on');
            if (dots[idx]) dots[idx].classList.remove('is-on');
            idx = (n + slides.length) % slides.length;
            slides[idx].classList.add('is-on');
            if (dots[idx]) dots[idx].classList.add('is-on');
        }
        function stop() {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
        }
        function start() {
            stop();
            if (slides.length < 2) return;
            timer = setInterval(function () { go(idx + 1); }, 5600);
        }
        dots.forEach(function (d) {
            d.addEventListener('click', function () {
                go(parseInt(d.getAttribute('data-slide'), 10) || 0);
                start();
            });
        });
        if (hero) {
            hero.addEventListener('mouseenter', stop);
            hero.addEventListener('mouseleave', start);
        }
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) stop();
            else start();
        });
        start();

        var track = document.getElementById('lpStoriesTrack');
        var prev = document.getElementById('lpStoriesPrev');
        var next = document.getElementById('lpStoriesNext');
        if (track && prev && next) {
            function storyStep() {
                var card = track.querySelector('.lp-story');
                if (!card) return 320;
                var styles = window.getComputedStyle(track);
                var gap = parseFloat(styles.columnGap || styles.gap) || 18;
                return card.getBoundingClientRect().width + gap;
            }
            prev.addEventListener('click', function () {
                track.scrollBy({ left: -storyStep(), behavior: 'smooth' });
            });
            next.addEventListener('click', function () {
                track.scrollBy({ left: storyStep(), behavior: 'smooth' });
            });
        }
    })();
    </script>
</body>
</html>
