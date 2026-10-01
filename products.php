<?php
$pageTitle = 'Our Products';
require_once __DIR__ . '/includes/public_header.php';
?>
  <main>
    <section class="page-banner">
      <div class="container page-banner-inner">
        <p class="eyebrow green" style="color: white;">Nature Life Care</p>
        <h1>Our Products</h1>
        <p>Holistic nutrition, Ayurveda-inspired wellness, and natural daily support.</p>
      </div>
    </section>

    <section class="section products-catalog-page">
      <div class="container">
        <div class="image-carousel catalog-carousel" data-image-carousel aria-label="Nature Life Care product images">
          <button class="image-carousel-arrow previous" type="button" aria-label="Previous image">
            <i class="fa-solid fa-chevron-left"></i>
          </button>
          <div class="image-carousel-track" id="catalogSlider" data-images="Images/image1.png,Images/img1.png,Images/img2.png" aria-live="polite"></div>
          <button class="image-carousel-arrow next" type="button" aria-label="Next image">
            <i class="fa-solid fa-chevron-right"></i>
          </button>
        </div>
      </div>
    </section>
  </main>
<?php require_once __DIR__ . '/includes/public_footer.php'; ?>

