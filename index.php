<?php
require_once __DIR__ . '/config/database.php';

$company = setting('company_name', 'Binary MLM');
$logoUrl = company_logo_url();
$favUrl = company_favicon_url();
$tagline = setting('company_tagline', 'Build your network. Grow your income.');

$phone = setting('contact_phone', '');
$whatsapp = preg_replace('/\D+/', '', (string) setting('contact_whatsapp', ''));
$email = setting('contact_email', setting('support_email', ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($company) ?> — Opportunity · Network · Growth</title>
    <meta name="description" content="<?= e($company) ?> — join our network marketing opportunity. Register, grow your team, and earn through binary, level, and referral income.">
    <?php if ($favUrl): ?><link rel="icon" href="<?= e($favUrl) ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/landing.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/landing.css') ?>">
</head>
<body class="lp">
    <div class="lp-grain" aria-hidden="true"></div>

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
                    <a href="#about">About</a>
                    <a href="#how">How it works</a>
                    <a href="#income">Income</a>
                    <a href="#stories">Stories</a>
                    <a href="contact.php">Contact</a>
                    <a href="user/login.php" class="lp-nav-ghost">Member login</a>
                    <a href="user/register.php" class="lp-nav-cta">Join now</a>
                </nav>
                <button type="button" class="lp-nav-toggle" id="lpNavToggle" aria-label="Open menu" aria-expanded="false">
                    <span></span><span></span>
                </button>
            </div>
            <div class="lp-drawer" id="lpDrawer" hidden>
                <a href="#about">About</a>
                <a href="#how">How it works</a>
                <a href="#income">Income</a>
                <a href="#stories">Stories</a>
                <a href="contact.php">Contact</a>
                <a href="user/login.php">Member login</a>
                <a href="user/register.php" class="lp-nav-cta">Join now</a>
            </div>
        </div>
    </header>

    <main>
        <section class="lp-hero">
            <div class="lp-hero-stage" aria-hidden="true">
                <div class="lp-hero-wash"></div>
                <div class="lp-hero-orb lp-hero-orb-a"></div>
                <div class="lp-hero-orb lp-hero-orb-b"></div>
                <div class="lp-hero-rays"></div>
                <svg class="lp-hero-tree" viewBox="0 0 640 720" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path class="lp-tree-line" d="M320 48V160M320 160L170 280M320 160L470 280M170 280L90 420M170 280L250 430M470 280L390 430M470 280L550 420M250 430L320 560M390 430L320 560" stroke="currentColor" stroke-width="1.5"/>
                    <g class="lp-tree-nodes" fill="currentColor">
                        <circle cx="320" cy="48" r="14"/>
                        <circle cx="320" cy="160" r="11"/>
                        <circle cx="170" cy="280" r="10"/>
                        <circle cx="470" cy="280" r="10"/>
                        <circle cx="90" cy="420" r="8"/>
                        <circle cx="250" cy="430" r="9"/>
                        <circle cx="390" cy="430" r="9"/>
                        <circle cx="550" cy="420" r="8"/>
                        <circle cx="320" cy="560" r="12"/>
                    </g>
                    <g class="lp-tree-pulse" fill="none" stroke="currentColor" stroke-width="1.2">
                        <circle cx="320" cy="48" r="28"/>
                        <circle cx="320" cy="560" r="24"/>
                    </g>
                </svg>
            </div>

            <div class="lp-shell lp-hero-shell">
                <div class="lp-hero-copy">
                    <p class="lp-hero-brand"><?= e($company) ?></p>
                    <h1>Your network.<br>Your growth engine.</h1>
                    <p class="lp-hero-lead"><?= e($tagline) ?></p>
                    <div class="lp-hero-cta">
                        <a href="user/register.php" class="lp-btn lp-btn-primary">Start free registration</a>
                        <a href="user/login.php" class="lp-btn lp-btn-line">I already have an ID</a>
                    </div>
                </div>
            </div>
        </section>

        <section class="lp-section lp-about" id="about">
            <div class="lp-shell">
                <div class="lp-about-panel">
                    <aside class="lp-about-aside" aria-hidden="false">
                        <p class="lp-kicker">About us</p>
                        <p class="lp-about-mark"><?= e(strtoupper(substr($company, 0, 1))) ?></p>
                        <p class="lp-about-aside-name"><?= e($company) ?></p>
                        <p class="lp-about-aside-tag">Network · Opportunity · Growth</p>
                    </aside>
                    <div class="lp-about-main">
                        <h2>Built for partners who want clarity, not confusion</h2>
                        <p class="lp-about-lead">
                            <?= e($company) ?> brings members, leaders, and products onto one platform —
                            so your team, income, and activations stay transparent from day one.
                        </p>
                        <div class="lp-about-copy">
                            <p>
                                We run modern member tools with proven MLM structures — binary, level, referral,
                                and product paths — so every partner understands how value moves through the network.
                            </p>
                            <p>
                                Register cleanly, activate with confidence, grow a disciplined downline,
                                and withdraw earnings without friction.
                            </p>
                        </div>
                        <ul class="lp-about-rail">
                            <li>
                                <span class="lp-about-rail-n">01</span>
                                <strong>Member-first tools</strong>
                                <span>Trees, wallets, KYC, and reports in one login.</span>
                            </li>
                            <li>
                                <span class="lp-about-rail-n">02</span>
                                <strong>Plan clarity</strong>
                                <span>Income rules set by leadership, visible to you.</span>
                            </li>
                            <li>
                                <span class="lp-about-rail-n">03</span>
                                <strong>Real support</strong>
                                <span>Reach us via contact, phone, or WhatsApp.</span>
                            </li>
                        </ul>
                        <a href="contact.php" class="lp-btn lp-btn-primary lp-about-cta">Talk to our team</a>
                    </div>
                </div>
            </div>
        </section>

        <section class="lp-section lp-how" id="how">
            <div class="lp-shell">
                <header class="lp-how-head">
                    <div>
                        <p class="lp-kicker lp-kicker-dark">How it works</p>
                        <h2>Three steps from join to payout</h2>
                    </div>
                    <p class="lp-how-sub">A clear member path — register, activate your place in the network, then earn and withdraw with confidence.</p>
                </header>

                <ol class="lp-journey">
                    <li class="lp-journey-step">
                        <div class="lp-journey-index">
                            <span>01</span>
                            <i class="lp-journey-dot" aria-hidden="true"></i>
                        </div>
                        <div class="lp-journey-body">
                            <h3>Register your ID</h3>
                            <p>Create your member account with a sponsor code and complete your profile in minutes.</p>
                            <a href="user/register.php" class="lp-journey-link">Open registration →</a>
                        </div>
                    </li>
                    <li class="lp-journey-step">
                        <div class="lp-journey-index">
                            <span>02</span>
                            <i class="lp-journey-dot" aria-hidden="true"></i>
                        </div>
                        <div class="lp-journey-body">
                            <h3>Activate &amp; place</h3>
                            <p>Choose your plan path, activate, and grow left / right or level teams with a clear structure.</p>
                            <span class="lp-journey-meta">Package · T-PIN · Product</span>
                        </div>
                    </li>
                    <li class="lp-journey-step">
                        <div class="lp-journey-index">
                            <span>03</span>
                            <i class="lp-journey-dot" aria-hidden="true"></i>
                        </div>
                        <div class="lp-journey-body">
                            <h3>Earn &amp; withdraw</h3>
                            <p>Track binary, level, and referral income in your wallet — then request payout when ready.</p>
                            <a href="user/login.php" class="lp-journey-link">Go to member login →</a>
                        </div>
                    </li>
                </ol>
            </div>
        </section>

        <section class="lp-section lp-income-sec" id="income">
            <div class="lp-shell">
                <header class="lp-income-head">
                    <div>
                        <p class="lp-kicker">Income</p>
                        <h2>Earnings that grow with your network</h2>
                    </div>
                    <p class="lp-income-sub">Multiple rails — binary, level, referral, and products — enabled as per your company plan.</p>
                </header>

                <ul class="lp-income-grid">
                    <li class="lp-income-tile">
                        <span class="lp-income-tile-n">01</span>
                        <strong>Binary matching</strong>
                        <p>Pair volume from both legs closes into structured binary payouts on every cycle.</p>
                        <span class="lp-income-chip">Left · Right · Pair</span>
                    </li>
                    <li class="lp-income-tile">
                        <span class="lp-income-tile-n">02</span>
                        <strong>Level / unilevel</strong>
                        <p>Earn on downline activations across configured depth — generation by generation.</p>
                        <span class="lp-income-chip">Depth payouts</span>
                    </li>
                    <li class="lp-income-tile">
                        <span class="lp-income-tile-n">03</span>
                        <strong>Direct referral</strong>
                        <p>Instant sponsor bonus when someone you personally introduce activates.</p>
                        <span class="lp-income-chip">Sponsor bonus</span>
                    </li>
                    <li class="lp-income-tile">
                        <span class="lp-income-tile-n">04</span>
                        <strong>Product shop</strong>
                        <p>Move retail and activation value through your catalogue when product mode is on.</p>
                        <span class="lp-income-chip">Retail · Activation</span>
                    </li>
                </ul>

                <div class="lp-income-foot">
                    <p>See your live income summary anytime after login.</p>
                    <a href="user/register.php" class="lp-btn lp-btn-primary">Start earning with us</a>
                </div>
            </div>
        </section>

        <section class="lp-section lp-stories" id="stories">
            <div class="lp-shell">
                <header class="lp-stories-head">
                    <div>
                        <p class="lp-kicker lp-kicker-dark">Testimonials</p>
                        <h2>What our partners say</h2>
                    </div>
                    <div class="lp-stories-head-right">
                        <p class="lp-stories-sub">Real voices from members building with <?= e($company) ?> — clarity, support, and consistent growth.</p>
                        <div class="lp-stories-nav" aria-label="Scroll testimonials">
                            <button type="button" class="lp-stories-btn" id="lpStoriesPrev" aria-label="Previous">‹</button>
                            <button type="button" class="lp-stories-btn" id="lpStoriesNext" aria-label="Next">›</button>
                        </div>
                    </div>
                </header>

                <div class="lp-stories-track-wrap">
                    <div class="lp-stories-track" id="lpStoriesTrack" tabindex="0">
                        <blockquote class="lp-story">
                            <p class="lp-story-quote">“The dashboard made binary and level income easy to track. Within weeks I understood exactly how my team volume turned into payouts.”</p>
                            <footer class="lp-story-meta">
                                <span class="lp-story-avatar" aria-hidden="true">RK</span>
                                <div>
                                    <strong>Ravi Kumar</strong>
                                    <span>Team leader · Active since 2024</span>
                                </div>
                            </footer>
                        </blockquote>
                        <blockquote class="lp-story">
                            <p class="lp-story-quote">“Registration to activation was smooth. Support answered WhatsApp the same day when I had a KYC question.”</p>
                            <footer class="lp-story-meta">
                                <span class="lp-story-avatar" aria-hidden="true">PS</span>
                                <div>
                                    <strong>Priya Sharma</strong>
                                    <span>Direct partner</span>
                                </div>
                            </footer>
                        </blockquote>
                        <blockquote class="lp-story">
                            <p class="lp-story-quote">“I like that plan rules are clear. No confusion on referral vs binary — everything shows in my income wallet.”</p>
                            <footer class="lp-story-meta">
                                <span class="lp-story-avatar" aria-hidden="true">AM</span>
                                <div>
                                    <strong>Amit Mehta</strong>
                                    <span>Member · Product path</span>
                                </div>
                            </footer>
                        </blockquote>
                        <blockquote class="lp-story">
                            <p class="lp-story-quote">“Tree view helped me place new joiners correctly. Closing and matching finally feel transparent.”</p>
                            <footer class="lp-story-meta">
                                <span class="lp-story-avatar" aria-hidden="true">NS</span>
                                <div>
                                    <strong>Neha Singh</strong>
                                    <span>Binary builder</span>
                                </div>
                            </footer>
                        </blockquote>
                        <blockquote class="lp-story">
                            <p class="lp-story-quote">“Product activation path is simple for my team. Orders and wallet credits stay in sync.”</p>
                            <footer class="lp-story-meta">
                                <span class="lp-story-avatar" aria-hidden="true">VK</span>
                                <div>
                                    <strong>Vikram Kapoor</strong>
                                    <span>Shop partner</span>
                                </div>
                            </footer>
                        </blockquote>
                        <blockquote class="lp-story">
                            <p class="lp-story-quote">“Withdrawals with KYC gate felt secure. Once approved, payout tracking was easy for my group.”</p>
                            <footer class="lp-story-meta">
                                <span class="lp-story-avatar" aria-hidden="true">SJ</span>
                                <div>
                                    <strong>Sana Joshi</strong>
                                    <span>Region lead</span>
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
                    <div class="lp-cta-copy">
                        <p class="lp-kicker">Get started</p>
                        <h2>Ready to build with <?= e($company) ?>?</h2>
                        <p>Register in minutes. Your dashboard, team tree, and wallet are waiting on the other side.</p>
                        <div class="lp-cta-actions">
                            <a href="user/register.php" class="lp-btn lp-btn-primary lp-btn-lg">Create my member ID</a>
                            <a href="user/login.php" class="lp-btn lp-btn-line lp-btn-lg">Member login</a>
                        </div>
                    </div>
                    <ul class="lp-cta-perks" aria-label="What you get">
                        <li>
                            <strong>Instant ID</strong>
                            <span>Register with a sponsor and start your journey today.</span>
                        </li>
                        <li>
                            <strong>Live income</strong>
                            <span>Binary, level, and referral — tracked in one wallet.</span>
                        </li>
                        <li>
                            <strong>Team tools</strong>
                            <span>Trees, KYC, shop, and withdrawals in your panel.</span>
                        </li>
                    </ul>
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
                    <a href="#about">About</a>
                    <a href="#how">How it works</a>
                    <a href="#income">Income plans</a>
                    <a href="#stories">Member stories</a>
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
            header.classList.toggle('is-scrolled', window.scrollY > 24);
        }
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        if (btn && drawer) {
            btn.addEventListener('click', function () {
                var open = drawer.hasAttribute('hidden');
                if (open) drawer.removeAttribute('hidden');
                else drawer.setAttribute('hidden', '');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (header && open) header.classList.add('is-scrolled');
            });
            drawer.querySelectorAll('a').forEach(function (a) {
                a.addEventListener('click', function () {
                    drawer.setAttribute('hidden', '');
                    btn.setAttribute('aria-expanded', 'false');
                });
            });
        }

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
