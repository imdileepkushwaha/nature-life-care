</main>
        <footer class="up-footer">
            <div class="up-footer-inner">
                <div class="up-footer-brand">
                    <div>
                        <strong><?= e($company) ?></strong>
                        <!-- <small>Member User Panel</small> -->
                    </div>
                </div>

               

                <div class="up-footer-meta">
                    <span class="up-footer-copy">&copy; <?= date('Y') ?> <?= e($company) ?>. All rights reserved.</span>
                    <span class="up-footer-pill">Secure Session</span>
                </div>
            </div>
        </footer>
    </div>
</div>
<div class="up-overlay" id="upOverlay"></div>
<script src="assets/js/user.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/user.js') ?>"></script>
</body>
</html>
