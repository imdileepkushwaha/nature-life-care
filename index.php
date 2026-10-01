<?php
require_once __DIR__ . '/config/database.php';

$rawCompany = setting('company_name', 'Nature Life Care');
$company = ($rawCompany === '' || strcasecmp($rawCompany, 'Binary MLM') === 0)
    ? 'Nature Life Care'
    : $rawCompany;
$logoUrl = company_logo_url();
$favUrl = company_favicon_url();
$tagline = setting('company_tagline', 'Nourishing Life Naturally');

$phone = setting('contact_phone', '');
$whatsapp = preg_replace('/\D+/', '', (string) setting('contact_whatsapp', ''));
$email = setting('contact_email', setting('support_email', ''));
$siteUrl = 'naturelifecare.com';
$rupee = currency_symbol_html();

$products = products_public_list($pdo);

require_once __DIR__ . '/includes/plan_incentives.php';
require_once __DIR__ . '/includes/website_sliders.php';
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

$heroSlides = website_sliders_active($pdo);
if (!$heroSlides) {
    $heroSlides = [
        [
            'heading' => "Nourishing Life Naturally",
            'lead' => 'Introducing NATURE RASAYAN™ – 19-in-1 Berries & Herbs',
            'image_url' => 'assets/img/lp-hero-1.png',
            'alt' => 'Organic Ayurvedic wellness products on a wooden table',
        ],
        [
            'heading' => "Discover • Nourish • Grow",
            'lead' => 'Natural wellness, nutrition education, and growth opportunities centered on healthy living.',
            'image_url' => 'assets/img/lp-hero-2.png',
            'alt' => 'Wellness mart with organic kits and natural products',
        ],
        [
            'heading' => "Healthy Living, Natural Growth",
            'lead' => $tagline,
            'image_url' => 'assets/img/lp-hero-3.png',
            'alt' => 'Fresh organic harvest from farm fields at golden hour',
        ],
    ];
}
?>
<?php require_once __DIR__ . '/includes/public_header.php'; ?>

    <main id="top">

        <section class="hero">
            <div class="hero-overlay"></div>
            <div class="container hero-grid">
                <div class="hero-copy reveal">
                    <p class="eyebrow">NATURE LIFE CARE™</p>
                    <h1>Nourishing Life Naturally</h1>
                    <p class="lead">Introducing <strong>NATURE RASAYAN™</strong>
                        <span class="product-highlight">
                            – 19-in-1 Berries & Herbs
                        </span>
                    </p>
                    <div class="tagline">Discover • Nourish • Grow</div>
                    <div class="hero-actions">
                        <a href="#products" class="btn btn-primary">Explore Product</a>
                        <a href="user/register.php" class="btn btn-secondary">Join Our Business</a>
                        <a href="user/login.php" class="btn btn-ghost">Login</a>
                    </div>
                </div>
            </div>
        </section>

        <section>
            <div class="container">

                <div class="hero-after-grid">
                    <div class="hero-after-card reveal">
                        <i class="fa-solid fa-seedling"></i>
                        <h3>Quality</h3>

                    </div>

                    <div class="hero-after-card reveal">
                        <i class="fa-solid fa-bullseye"></i>
                        <h3>Wellness</h3>

                    </div>
                    <div class="hero-after-card reveal">
                        <i class="fa-solid fa-bullseye"></i>
                        <h3>Transparency</h3>
                    </div>

                    <div class="hero-after-card reveal">
                        <i class="fa-solid fa-heart-pulse"></i>
                        <h3>Growth</h3>
                    </div>
                </div>
            </div>
        </section>

        <section id="about" class="section about-section">
            <div class="container">
                <div class="section-heading reveal">
                    <p class="eyebrow green">About Nature Life Care</p>

                </div>

                <div class="about-layout">
                    <div class="about-intro reveal">
                        <p class="eyebrow green">Why people choose us</p>
                        <h3>One Stop Health &amp; Business Solutions</h3>
                        <p>
                            NATURE LIFE CARE™ is a wellness-focused brand dedicated to making quality wellness products,
                            nutrition education, healthy lifestyle guidance, and business opportunities more accessible
                            to people.
                        </p>
                        <p>
                            Our approach brings together Ayurveda, Nutrition, Diet Management and Exercise to encourage
                            people to make informed choices for a healthier and more balanced lifestyle.
                        </p>
                    </div>

                    <article class="about-card reveal about-feature">
                        <i class="fa-solid fa-seedling"></i>
                        <h3>Our Approach</h3>
                        <p>
                            We combine traditional knowledge with modern nutrition and lifestyle practices while
                            maintaining transparency about products, ingredients, usage, and certifications.
                        </p>
                    </article>

                    <div class="about-subcards">
                        <article class="about-card reveal">
                            <i class="fa-solid fa-bullseye"></i>
                            <h3>Vision</h3>
                            <p>
                                To build a trusted wellness community where people can discover better lifestyle
                                choices, quality wellness products and opportunities for personal and professional
                                growth.
                            </p>
                        </article>

                        <article class="about-card reveal">
                            <i class="fa-solid fa-bullseye"></i>
                            <h3>Mission</h3>
                            <p>
                                To deliver quality wellness products, practical nutrition education, healthy lifestyle
                                guidance, and business opportunities through a clear and supportive network.
                            </p>
                        </article>

                        <article class="about-card reveal">
                            <i class="fa-solid fa-heart-pulse"></i>
                            <h3>Our Philosophy</h3>
                            <p>
                                We believe wellness is not about one product or one habit. It is a combination of
                                balanced nutrition, physical activity, healthy routines and informed lifestyle choices.
                            </p>
                        </article>
                        <article class="about-card reveal approach-card">
                            <i class="fa-solid fa-seedling"></i>
                            <h3>Our Approach</h3>
                            <p>
                                We combine traditional knowledge with modern nutrition and lifestyle practices while
                                maintaining transparency about products, ingredients, usage, and certifications.
                            </p>
                        </article>
                    </div>
                </div>
            </div>
        </section>

        <section id="products" class="section product-section">
            <div class="container">
                <div class="section-heading reveal">
                    <p class="eyebrow green">Our Products</p>
                    <h2>Natural products designed for daily vitality</h2>
                </div>

                <div class="image-carousel reveal" data-image-carousel aria-label="Nature Life Care product images">
                    <button class="image-carousel-arrow previous" type="button" aria-label="Previous image">
                        <i class="fa-solid fa-chevron-left"></i>
                    </button>
                    <div class="image-carousel-track" id="productSlider"
                        data-images="Images/image1.png,Images/img1.png,Images/img2.png" aria-live="polite"></div>
                    <button class="image-carousel-arrow next" type="button" aria-label="Next image">
                        <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </section>

        <section id="featured-product" class="section featured-product-section" aria-label="Featured Product">
            <div class="container">
                <div class="section-heading reveal">
                    <p class="eyebrow green">Nature Life Care</p>
                    <h2>Featured Product</h2>
                </div>

                <!-- Part 1: product card with eye-button compliance points -->
                <article class="featured-product-card reveal">
                    <div class="featured-product-image">
                        <img src="Images/image1.png" alt="NATURE RASAYAN™ product pack" />
                    </div>
                    <div class="featured-product-content">
                        <h3>NATURE RASAYAN™</h3>
                        <p class="featured-tagline">19-in-1 Berries &amp; Herbs</p>
                        <p class="featured-price">₹1,999</p>

                        <div class="quality-summary">
                            <h4><i class="fa-solid fa-shield-heart"></i> Quality &amp; Compliance</h4>
                            <ul class="quality-points">
                                <li>
                                    <span class="point-label">WHO-GMP</span>
                                    <button class="eye-btn" type="button" aria-expanded="false"
                                        aria-label="View WHO-GMP details"><i class="fa-solid fa-eye"></i></button>
                                    <div class="desc-pane" role="region" aria-label="WHO-GMP details">
                                        <button class="close-pane" type="button" aria-label="Close"><i
                                                class="fa-solid fa-xmark"></i></button>

                                        <p>Manufacturing is carried out in facilities following applicable Good
                                            Manufacturing Practices (GMP) requirements. WHO-GMP principles emphasize
                                            controlled manufacturing processes, hygiene, quality control, documentation,
                                            and consistency of products.</p>
                                    </div>
                                </li>
                                <li>
                                    <span class="point-label">ISO 22000:2005</span>
                                    <button class="eye-btn" type="button" aria-expanded="false"
                                        aria-label="View ISO 22000 details"><i class="fa-solid fa-eye"></i></button>
                                    <div class="desc-pane" role="region" aria-label="ISO 22000 details">
                                        <button class="close-pane" type="button" aria-label="Close"><i
                                                class="fa-solid fa-xmark"></i></button>

                                        <p>Where applicable, manufacturing facilities follow ISO 22000:2005 requirements
                                            relating to food-safety management systems. These systems are designed to
                                            support systematic identification, control, monitoring, and management of
                                            food-safety hazards throughout relevant processes.</p>
                                    </div>
                                </li>
                                <li>
                                    <span class="point-label">US FDA Registered/Compliant</span>
                                    <button class="eye-btn" type="button" aria-expanded="false"
                                        aria-label="View US FDA details"><i class="fa-solid fa-eye"></i></button>
                                    <div class="desc-pane" role="region" aria-label="US FDA details">
                                        <button class="close-pane" type="button" aria-label="Close"><i
                                                class="fa-solid fa-xmark"></i></button>

                                        <p>Where applicable, the manufacturing facility/product may have relevant US FDA
                                            registration or regulatory compliance. The exact status depends on the
                                            product category, facility, intended market, and applicable US regulatory
                                            requirements.</p>
                                    </div>
                                </li>
                                <li>
                                    <span class="point-label">AYUSH Licensed/Approved</span>
                                    <button class="eye-btn" type="button" aria-expanded="false"
                                        aria-label="View AYUSH details"><i class="fa-solid fa-eye"></i></button>
                                    <div class="desc-pane" role="region" aria-label="AYUSH details">
                                        <button class="close-pane" type="button" aria-label="Close"><i
                                                class="fa-solid fa-xmark"></i></button>

                                        <p>For applicable Ayurvedic products, manufacturing is undertaken through
                                            facilities holding the required AYUSH licence/approval under the applicable
                                            Indian regulatory framework.</p>
                                    </div>
                                </li>
                                <li>
                                    <span class="point-label">FSSAI Licensed</span>
                                    <button class="eye-btn" type="button" aria-expanded="false"
                                        aria-label="View FSSAI details"><i class="fa-solid fa-eye"></i></button>
                                    <div class="desc-pane" role="region" aria-label="FSSAI details">
                                        <button class="close-pane" type="button" aria-label="Close"><i
                                                class="fa-solid fa-xmark"></i></button>

                                        <p>For products falling under the applicable food, nutraceutical, or
                                            health-supplement regulations, the relevant FSSAI licence/registration and
                                            regulatory requirements are followed.</p>
                                    </div>
                                </li>
                                <li>
                                    <span class="point-label">Organic Certified</span>
                                    <button class="eye-btn" type="button" aria-expanded="false"
                                        aria-label="View Organic Certification details"><i
                                            class="fa-solid fa-eye"></i></button>
                                    <div class="desc-pane" role="region" aria-label="Organic certification details">
                                        <button class="close-pane" type="button" aria-label="Close"><i
                                                class="fa-solid fa-xmark"></i></button>

                                        <p>For products marketed as organic, the applicable organic certification is
                                            maintained by the relevant certified manufacturer/supplier, wherever
                                            required. Organic claims are made only where supported by the appropriate
                                            certification and documentation.</p>
                                    </div>
                                </li>
                            </ul>
                            <p class="compliance-caveat">Applicable certifications and licences vary by product and
                                manufacturing facility.</p>
                        </div>
                    </div>
                </article>

                <!-- Part 2: split layout -->
                <div class="featured-split">
                    <!-- LEFT -->
                    <div class="split-col split-left reveal">
                        <div class="quality-commitment">
                            <p class="eyebrow green">Our Quality Commitment</p>
                            <h3>Care in every step</h3>
                            <ul>
                                <li>Carefully selected ingredients</li>
                                <li>Controlled, hygienic manufacturing</li>
                                <li>Appropriate quality testing</li>
                                <li>Batch-wise quality controls, where applicable</li>
                                <li>Proper documentation and traceability</li>
                                <li>Compliance with applicable Indian regulations</li>
                            </ul>
                        </div>

                        <aside class="transparency-note">
                            <i class="fa-solid fa-circle-info"></i>
                            <div>
                                <h3>Transparency Matters</h3>
                                <p>Certifications and licences differ by product, facility, and country of sale. We use
                                    only claims supported by valid documentation.</p>
                                <strong>NATURE LIFE CARE™ <span>Nourishing Life Naturally</span></strong>
                            </div>
                        </aside>
                    </div>

                    <!-- RIGHT -->
                    <div class="split-col split-right reveal">
                        <p class="eyebrow green">Why Nature Rasayan</p>
                        <h3>Product Benefits</h3>
                        <ul class="benefit-list">
                            <li><strong>Rich in Antioxidants</strong> – Helps fight free radicals</li>
                            <li><strong>Immune Support</strong> – Strengthens natural defence</li>
                            <li><strong>Natural Energy</strong> – Supports stamina &amp; vitality</li>
                            <li><strong>Heart Wellness</strong> – Supports healthy circulation</li>
                            <li><strong>Digestive Balance</strong> – Supports gut health</li>
                            <li><strong>Overall Well-Being</strong> – For a healthier, active life</li>
                        </ul>
                        <p class="compliance-caveat">Not intended to diagnose, treat, cure, or prevent any disease and not a substitute for medical
                            advice or a balanced diet. Consult a healthcare professional if you are pregnant, nursing,
                            taking medication, or have a medical condition.</p>
                    </div>
                </div>
            </div>
        </section>



        <section id="wellness" class="section wellness-section">
            <div class="container">
                <div class="section-heading reveal">
                    <p class="eyebrow green">Wellness Knowledge</p>
                    <h2>Learning for everyday health</h2>
                </div>

                <div class="wellness-grid">
                    <article class="wellness-card reveal">
                        <i class="fa-solid fa-apple-whole"></i>
                        <h3>Nutrition</h3>
                        <p>Balanced eating habits, wholesome foods, and nutrient-rich routines for long-term wellness.
                        </p>
                    </article>
                    <article class="wellness-card reveal">
                        <i class="fa-solid fa-leaf"></i>
                        <h3>Ayurveda</h3>
                        <p>Traditional principles that support natural balance, digestion, and vitality.</p>
                    </article>
                    <article class="wellness-card reveal">
                        <i class="fa-solid fa-utensils"></i>
                        <h3>Diet Management</h3>
                        <p>Smart food choices and lifestyle patterns that complement health goals.</p>
                    </article>
                    <article class="wellness-card reveal">
                        <i class="fa-solid fa-dumbbell"></i>
                        <h3>Exercise</h3>
                        <p>Movement routines designed to improve energy, immunity, and stress resilience.</p>
                    </article>
                    <article class="wellness-card reveal">
                        <i class="fa-solid fa-heart"></i>
                        <h3>Healthy Lifestyle</h3>
                        <p>Simple daily habits that nurture mind, body, and sustainable wellness.</p>
                    </article>
                    <article class="wellness-card reveal">
                        <i class="fa-solid fa-book-open-reader"></i>
                        <h3>Articles & Guides</h3>
                        <p>Educational resources covering prevention, nutrition, and daily health rituals.</p>
                    </article>
                </div>
            </div>
        </section>

        <section id="business" class="section business-section">
            <div class="container">
                <div class="section-heading reveal">
                    <p class="eyebrow green">Business Opportunity</p>
                    <h2>Build your wellness business with confidence</h2>
                </div>

                <div class="business-layout">
                    <div class="business-copy reveal">
                        <p class="eyebrow green">Your Next Step</p>

                        <p>
                            Nature Life Care gives individuals a simple path to build a wellness-focused business with
                            natural products, education, and support.
                        </p>
                        <ul>
                            <li>Become a distributor or business partner</li>
                            <li>Understand the onboarding and registration process</li>
                            <li>Access product purchase and retail opportunities</li>
                            <li>Learn the compensation and growth pathway clearly</li>
                            <li>Build trusted customer relationships through health education</li>
                        </ul>
                    </div>

                    <div class="business-card reveal">
                        <div class="mini-stat">
                            <span class="mini-step">01</span>
                            <strong>Register</strong>
                            <small>Begin your onboarding</small>
                        </div>
                        <div class="mini-stat">
                            <span class="mini-step">02</span>
                            <strong>Purchase</strong>
                            <small>Choose your products</small>
                        </div>
                        <div class="mini-stat">
                            <span class="mini-step">03</span>
                            <strong>Grow</strong>
                            <small>Build your network</small>
                        </div>
                        <div class="mini-stat">
                            <span class="mini-step">04</span>
                            <strong>Earn</strong>
                            <small>Coach and scale</small>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="section faq-section">
            <div class="container">
                <div class="section-heading reveal">
                    <p class="eyebrow green">FAQs</p>
                    <h2>Quick answers about NATURE RASAYAN™</h2>
                </div>

                <div class="faq-list reveal">
                    <details open>
                        <summary>What is NATURE RASAYAN™?</summary>
                        <p>It is a holistic wellness supplement featuring 19 ingredient blends centered on berries and
                            herbs for natural daily support.</p>
                    </details>
                    <details>
                        <summary>Does it contain artificial ingredients?</summary>
                        <p>No. The product positioning highlights natural ingredients, no added sugar, and no artificial
                            colours.</p>
                    </details>
                    <details>
                        <summary>How should it be used?</summary>
                        <p>Use as directed on the product label as part of a balanced routine and healthy lifestyle.</p>
                    </details>
                    <details>
                        <summary>Is it suitable for daily consumption?</summary>
                        <p>It is designed for daily dietary support, though individual needs may vary. Please consult a
                            professional for medical guidance if needed.</p>
                    </details>
                </div>
            </div>
        </section>

        <section class="section certificates-section">
            <div class="container">
                <div class="section-heading reveal">
                    <p class="eyebrow green">Certificates & Compliance</p>
                    <h2>Verified documentation and registrations</h2>
                </div>

                <div class="certificate-grid reveal">
                    <div class="certificate-card" data-pdf="PDFs/AYUSH LICENCE (2).pdf" data-title="AYUSH Licence">
                        <i class="fa-solid fa-certificate"></i>
                        <h3>AYUSH Licence</h3>
                        <div class="card-actions">
                            <button class="link-btn view-pdf">View PDF</button>
                            <a href="PDFs/AYUSH LICENCE (2).pdf" target="_blank" rel="noopener noreferrer"
                                download>Download</a>
                        </div>
                    </div>

                    <div class="certificate-card" data-pdf="PDFs/ISO- OMS (2) (1) (1) (3) (2)_page-0001.pdf"
                        data-title="ISO / OMS Certificate">
                        <i class="fa-solid fa-shield-halved"></i>
                        <h3>ISO / OMS Certificate</h3>
                        <div class="card-actions">
                            <button class="link-btn view-pdf">View PDF</button>
                            <a href="PDFs/ISO- OMS (2) (1) (1) (3) (2)_page-0001.pdf" target="_blank"
                                rel="noopener noreferrer" download>Download</a>
                        </div>
                    </div>

                    <div class="certificate-card" data-pdf="PDFs/organic certificate 02 (1) (1) (2)_page-0001 (1).pdf"
                        data-title="Organic Certificate">
                        <i class="fa-solid fa-leaf"></i>
                        <h3>Organic Certificate</h3>
                        <div class="card-actions">
                            <button class="link-btn view-pdf">View PDF</button>
                            <a href="PDFs/organic certificate 02 (1) (1) (2)_page-0001 (1).pdf" target="_blank"
                                rel="noopener noreferrer" download>Download</a>
                        </div>
                    </div>

                    <div class="certificate-card" data-pdf="PDFs/SHAKUMBHRI HERBALS PVT. LTD WHO-GMP FINAL (1).pdf"
                        data-title="WHO-GMP Certificate">
                        <i class="fa-solid fa-medal"></i>
                        <h3>WHO-GMP Certificate</h3>
                        <div class="card-actions">
                            <button class="link-btn view-pdf">View PDF</button>
                            <a href="PDFs/SHAKUMBHRI HERBALS PVT. LTD WHO-GMP FINAL (1).pdf" target="_blank"
                                rel="noopener noreferrer" download>Download</a>
                        </div>
                    </div>

                    <div class="certificate-card" data-pdf="PDFs/updateFacilityRegistration-2026.pdf"
                        data-title="Facility Registration">
                        <i class="fa-solid fa-file-lines"></i>
                        <h3>Facility Registration</h3>
                        <div class="card-actions">
                            <button class="link-btn view-pdf">View PDF</button>
                            <a href="PDFs/updateFacilityRegistration-2026.pdf" target="_blank" rel="noopener noreferrer"
                                download>Download</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="testimonials" class="section testimonial-section">
            <div class="container">
                <div class="section-heading reveal">
                    <p class="eyebrow green">Customer Experiences</p>
                    <h2>Real stories from healthy living journeys</h2>
                </div>

                <div class="testimonial-grid">
                    <blockquote class="testimonial reveal">
                        “I started using Nature Rasayan as part of my daily routine and noticed better energy and
                        consistency in my wellness habits.”
                        <footer>— Rina S., Asansol</footer>
                    </blockquote>
                    <blockquote class="testimonial reveal">
                        “The natural ingredients and the business opportunity made it easy for me to believe in the
                        brand and its purpose.”
                        <footer>— Amit K., Durgapur</footer>
                    </blockquote>
                    <blockquote class="testimonial reveal">
                        “Nature Life Care brings together health, education, and entrepreneurship in one model that
                        feels meaningful and practical.”
                        <footer>— Priya M., Kolkata</footer>
                    </blockquote>
                </div>
            </div>
        </section>

        <!-- <section id="contact" class="section contact-section">
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
                        <li><i class="fa-solid fa-location-dot"></i> Shiv Nagar Lane-2, Gurunanak Pally, Asansol, West
                            Bengal – 713301</li>
                        <li><i class="fa-solid fa-phone"></i> <a href="tel:8900407342">8900407342</a></li>
                        <li><i class="fa-solid fa-envelope"></i> <a
                                href="mailto:naturelifecaretm@gmail.com">naturelifecaretm@gmail.com</a></li>
                        <li><i class="fa-solid fa-globe"></i> <a href="https://naturelifecare.com" target="_blank"
                                rel="noopener noreferrer">naturelifecare.com</a></li>
                        <li><i class="fa-solid fa-file-invoice"></i> GSTIN: 19AZIPB1251D1ZV</li>
                    </ul>
                </div>

                <div class="contact-form reveal">
                    <form>
                        <div class="form-row">
                            <label>
                                Name
                                <input type="text" name="name" placeholder="Your name" />
                            </label>
                        </div>
                        <div class="form-row">
                            <label>
                                Phone
                                <input type="tel" name="phone" placeholder="Your phone number" />
                            </label>
                        </div>
                        <div class="form-row">
                            <label>
                                Message
                                <textarea name="message" rows="4" placeholder="Tell us about your interest"></textarea>
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary">Send Message</button>
                    </form>
                </div>
            </div>
        </section> -->
    </main>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>

