    <footer class="site-footer">
        <div class="container footer-grid">
            <div class="footer-brand">
                <img src="Images/logo.png" alt="Nature Life Care logo" />
                <p>Nature Life Care delivers natural wellness, nutrition, and business opportunities designed to support
                    healthy living and purposeful growth.</p>
                <ul class="footer-contact-list">
                    <li><i class="fa-solid fa-phone"></i> <a href="tel:8900407342">8900407342</a></li>
                    <li><i class="fa-solid fa-envelope"></i> <a
                            href="mailto:naturelifecaretm@gmail.com">naturelifecaretm@gmail.com</a></li>
                </ul>

                <div class="footer-socials">
                    <a href="https://facebook.com" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><i
                            class="fa-brands fa-facebook-f"></i></a>
                    <a href="https://instagram.com" target="_blank" rel="noopener noreferrer" aria-label="Instagram"><i
                            class="fa-brands fa-instagram"></i></a>
                    <a href="https://wa.me/918900407342" target="_blank" rel="noopener noreferrer"
                        aria-label="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
                </div>
            </div>

            <div class="footer-links">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="index.php#about">About Us</a></li>
                    <li><a href="products.php">Our Products</a></li>
                    <li><a href="index.php#wellness">Wellness Education</a></li>
                    <li><a href="index.php#business">Business Opportunity</a></li>
                    <li><a href="contact.php">Contact Us</a></li>
                </ul>
            </div>

            <div class="footer-links">
                <h3>Important Pages</h3>
                <ul>
                    <li><a href="#">Privacy Policy</a></li>
                    <li><a href="#">Terms & Conditions</a></li>
                    <li><a href="#">Refund/Cancellation Policy</a></li>
                    <li><a href="#">Shipping Policy</a></li>
                    <li><a href="#">Disclaimer</a></li>
                    <li><a href="#">Distributor Terms</a></li>
                    <li><a href="#">Refund/Buyback Policy</a></li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom">
            <div class="container">
                <p>© 2026 Nature Life Care. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <div class="pdf-modal" id="pdfModal" aria-hidden="true">
        <div class="pdf-modal-content">
            <div class="pdf-modal-header">
                <h3 id="pdfTitle">Certificate</h3>
                <button class="close-modal" aria-label="Close modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <iframe id="pdfFrame" title="Certificate preview" src="" frameborder="0"></iframe>
        </div>
    </div>

    <script>
        (function () {
            const summary = document.querySelector('.quality-summary');
            if (!summary) return;
            const items = Array.from(summary.querySelectorAll('.quality-points li'));
            const stacked = window.matchMedia('(max-width: 1024px)');  // was 700px

            function closeAll() {
                items.forEach(li => {
                    li.classList.remove('open');
                    li.querySelector('.eye-btn').setAttribute('aria-expanded', 'false');
                });
                summary.style.minHeight = '';
            }

            function open(li) {
                closeAll();
                li.classList.add('open');
                li.querySelector('.eye-btn').setAttribute('aria-expanded', 'true');

                const pane = li.querySelector('.desc-pane');
                pane.style.top = pane.style.left = pane.style.right = '';
                if (stacked.matches) return;               // pane sits under the point via CSS

                const gap = 10;
                const inLeftColumn = li.offsetLeft < summary.clientWidth / 2;
                pane.style.top = li.offsetTop + 'px';
                if (inLeftColumn) {                        // open to the right of the point
                    pane.style.left = (li.offsetLeft + li.offsetWidth + gap) + 'px';
                    pane.style.right = '16px';
                } else {                                   // open to the left of the point
                    pane.style.left = '16px';
                    pane.style.right = (summary.clientWidth - li.offsetLeft + gap) + 'px';
                }

                const needed = li.offsetTop + pane.offsetHeight + 20;
                if (needed > summary.clientHeight) summary.style.minHeight = needed + 'px';
            }

            items.forEach(li => {
                li.querySelector('.eye-btn').addEventListener('click', () =>
                    li.classList.contains('open') ? closeAll() : open(li));
                li.querySelector('.close-pane').addEventListener('click', closeAll);
            });
            document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAll(); });
            stacked.addEventListener('change', closeAll);   // NEW: reset when crossing 1024px
        })();
    </script>
    <script src="script.js"></script>
    <?php if (file_exists(__DIR__ . '/public_popup.php')) { require_once __DIR__ . '/public_popup.php'; } ?>
</body>

</html>
