<?php
/**
 * Renders active website popup image before </body> on public and user-home pages.
 * Expects $pdo from config/database.php.
 */
if (!isset($pdo) || !($pdo instanceof PDO)) {
    return;
}

require_once __DIR__ . '/website_popups.php';
$wpPopup = website_popup_active($pdo);
if (!$wpPopup) {
    return;
}

$wpImage = website_popup_image_url((string) ($wpPopup['image_path'] ?? ''));
if ($wpImage === '') {
    return;
}
?>
<div class="wp-popup-overlay" id="wpPopupOverlay" role="dialog" aria-modal="true" aria-label="Website popup" hidden>
    <div class="wp-popup wp-popup-image" role="document">
        <button type="button" class="wp-popup-close" id="wpPopupClose" aria-label="Close popup">&times;</button>
        <img class="wp-popup-img" src="<?= e($wpImage) ?>" alt="Popup">
    </div>
</div>
<style>
.wp-popup-overlay {
    position: fixed;
    inset: 0;
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(4px);
}
.wp-popup-overlay[hidden] { display: none !important; }
.wp-popup-image {
    position: relative;
    width: min(720px, 96vw);
    background: transparent;
    border-radius: 16px;
    padding: 0;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.35);
    animation: wpPopupIn 0.28s ease-out;
    overflow: hidden;
}
@keyframes wpPopupIn {
    from { opacity: 0; transform: translateY(12px) scale(0.98); }
    to { opacity: 1; transform: none; }
}
.wp-popup-img {
    display: block;
    width: 100%;
    height: auto;
    max-height: min(88vh, 900px);
    object-fit: cover;
    background: #0f172a;
    border-radius: 16px;
}
.wp-popup-close {
    position: absolute;
    top: 0.45rem;
    right: 0.55rem;
    z-index: 2;
    border: 0;
    width: 2rem;
    height: 2rem;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.72);
    color: #fff;
    font-size: 1.35rem;
    line-height: 1;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.wp-popup-close:hover { background: rgba(15, 23, 42, 0.9); }
</style>
<script>
(function () {
    var overlay = document.getElementById('wpPopupOverlay');
    if (!overlay) return;
    var closeBtn = document.getElementById('wpPopupClose');
    function openPopup() {
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
    }
    function closePopup() {
        overlay.hidden = true;
        document.body.style.overflow = '';
    }
    openPopup();
    if (closeBtn) closeBtn.addEventListener('click', closePopup);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closePopup();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePopup();
    });
})();
</script>
