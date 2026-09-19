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
<?php
$wpScript = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
if (preg_match('#/user/(index\.php)?$#i', $wpScript)) {
    require_once dirname(__DIR__, 2) . '/includes/public_popup.php';
}
?>
</body>
</html>
