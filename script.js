const navToggle = document.querySelector('.nav-toggle');
const mainNav = document.querySelector('.main-nav');

if (navToggle && mainNav) {
  navToggle.addEventListener('click', () => {
    const isOpen = mainNav.classList.toggle('open');
    navToggle.setAttribute('aria-expanded', String(isOpen));
  });

  mainNav.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => mainNav.classList.remove('open'));
  });
}

const faqItems = document.querySelectorAll('.faq-list details');

faqItems.forEach((item) => {
  item.addEventListener('toggle', () => {
    if (!item.open) return;
    faqItems.forEach((otherItem) => {
      if (otherItem !== item) otherItem.removeAttribute('open');
    });
  });
});

document.querySelectorAll('[data-image-carousel]').forEach((carousel) => {
  const track = carousel.querySelector('.image-carousel-track');
  if (!track) return;

  const images = (track.dataset.images || '')
    .split(',')
    .map((path) => path.trim())
    .filter(Boolean);
  if (!images.length) return;

  const cloneCount = Math.min(3, images.length);
  const loopImages = [...images.slice(-cloneCount), ...images, ...images.slice(0, cloneCount)];
  track.innerHTML = loopImages.map((path, index) => {
    const isClone = index < cloneCount || index >= cloneCount + images.length;
    const originalIndex = (index - cloneCount + images.length) % images.length;
    return `<img class="carousel-image" src="${path}" alt="${isClone ? '' : `Nature Life Care product image ${originalIndex + 1}`}" ${isClone ? 'aria-hidden="true"' : ''} ${index === cloneCount ? 'fetchpriority="high"' : 'loading="lazy"'} />`;
  }).join('');

  const slides = Array.from(track.querySelectorAll('.carousel-image'));
  const previousButton = carousel.querySelector('.image-carousel-arrow.previous');
  const nextButton = carousel.querySelector('.image-carousel-arrow.next');
  let activeIndex = cloneCount;
  let timer;

  const moveTo = (index, animate = true) => {
    activeIndex = index;
    track.classList.toggle('no-transition', !animate);
    const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
    const step = (slides[0]?.getBoundingClientRect().width || 0) + gap;
    track.style.transform = `translateX(${-activeIndex * step}px)`;
    if (!animate) requestAnimationFrame(() => track.classList.remove('no-transition'));
  };

  track.addEventListener('transitionend', (event) => {
    if (event.propertyName !== 'transform') return;
    if (activeIndex < cloneCount) moveTo(activeIndex + images.length, false);
    else if (activeIndex >= cloneCount + images.length) moveTo(activeIndex - images.length, false);
  });

  const stopAutoplay = () => window.clearInterval(timer);
  const startAutoplay = () => {
    stopAutoplay();
    if (images.length > 1) timer = window.setInterval(() => moveTo(activeIndex + 1), 3500);
  };

  previousButton?.addEventListener('click', () => {
    moveTo(activeIndex - 1);
    startAutoplay();
  });
  nextButton?.addEventListener('click', () => {
    moveTo(activeIndex + 1);
    startAutoplay();
  });

  window.addEventListener('resize', () => moveTo(activeIndex, false));
  carousel.addEventListener('mouseenter', stopAutoplay);
  carousel.addEventListener('mouseleave', startAutoplay);
  carousel.addEventListener('focusin', stopAutoplay);
  carousel.addEventListener('focusout', (event) => {
    if (!carousel.contains(event.relatedTarget)) startAutoplay();
  });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopAutoplay();
    else startAutoplay();
  });

  moveTo(activeIndex, false);
  startAutoplay();
});

if ('IntersectionObserver' in window) {
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12 });

  document.querySelectorAll('.reveal').forEach((element) => observer.observe(element));
} else {
  document.querySelectorAll('.reveal').forEach((element) => element.classList.add('visible'));
}

const modal = document.getElementById('pdfModal');
const pdfFrame = document.getElementById('pdfFrame');
const pdfTitle = document.getElementById('pdfTitle');
const closeModal = document.querySelector('.close-modal');
const viewButtons = document.querySelectorAll('.view-pdf');

if (modal && pdfFrame && pdfTitle && closeModal) {
  const openModal = (pdfPath, title) => {
    pdfFrame.src = pdfPath;
    pdfTitle.textContent = title;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
  };

  const closePdfModal = () => {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    pdfFrame.src = '';
  };

  viewButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const card = button.closest('.certificate-card');
      openModal(card?.dataset.pdf || '', card?.dataset.title || 'Certificate');
    });
  });

  closeModal.addEventListener('click', closePdfModal);
  modal.addEventListener('click', (event) => {
    if (event.target === modal) closePdfModal();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('open')) closePdfModal();
  });
}

document.querySelector('.contact-form form')?.addEventListener('submit', (event) => {
  event.preventDefault();
  const button = event.currentTarget.querySelector('button[type="submit"]');
  if (!button) return;

  const original = button.textContent;
  button.textContent = 'Message Sent';
  button.disabled = true;
  setTimeout(() => {
    button.textContent = original;
    button.disabled = false;
    event.currentTarget.reset();
  }, 2200);
});