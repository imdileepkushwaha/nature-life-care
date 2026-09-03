<?php
require_once __DIR__ . '/../config/database.php';
$pageTitle = 'Client Branding';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys = ['company_name', 'support_email', 'currency', 'currency_symbol', 'member_id_prefix', 'member_id_pad'];
    foreach ($keys as $key) {
        if (!isset($_POST[$key])) {
            continue;
        }
        $val = trim((string) $_POST[$key]);
        if ($key === 'member_id_prefix') {
            $val = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $val) ?? '');
            if ($val === '') {
                $val = 'MLM';
            }
            $val = substr($val, 0, 10);
        }
        if ($key === 'member_id_pad') {
            $val = (string) max(3, min(8, (int) $val));
        }
        feature_save($pdo, $key, $val);
    }

    $logoUp = branding_store_image($_FILES['company_logo'] ?? [], 'logo');
    if (!$logoUp['ok']) {
        flash('error', $logoUp['error'] ?: 'Logo upload failed.');
        header('Location: branding.php');
        exit;
    }
    if (!empty($logoUp['path'])) {
        branding_delete_file(setting('company_logo', ''));
        feature_save($pdo, 'company_logo', $logoUp['path']);
    }
    if (!empty($_POST['remove_logo'])) {
        branding_delete_file(setting('company_logo', ''));
        feature_save($pdo, 'company_logo', '');
    }

    $favUp = branding_store_image($_FILES['company_favicon'] ?? [], 'favicon');
    if (!$favUp['ok']) {
        flash('error', $favUp['error'] ?: 'Favicon upload failed.');
        header('Location: branding.php');
        exit;
    }
    if (!empty($favUp['path'])) {
        branding_delete_file(setting('company_favicon', ''));
        feature_save($pdo, 'company_favicon', $favUp['path']);
    }
    if (!empty($_POST['remove_favicon'])) {
        branding_delete_file(setting('company_favicon', ''));
        feature_save($pdo, 'company_favicon', '');
    }

    clear_setting_cache();
    log_superadmin_activity('branding_save', 'Updated client branding');
    flash('success', 'Client branding updated.');
    header('Location: branding.php');
    exit;
}

require __DIR__ . '/includes/header.php';

$coName = setting('company_name', 'Binary MLM');
$prefix = setting('member_id_prefix', 'MLM');
$pad = (int) setting('member_id_pad', '5');
$sampleId = $prefix . str_pad('1', $pad, '0', STR_PAD_LEFT);
$currency = setting('currency', 'INR');
$symbol = setting('currency_symbol', '₹');
$logoUrl = company_logo_url();
$favUrl = company_favicon_url();
?>
<section class="sa-hero">
    <div>
        <span class="sa-hero-kicker">White-label</span>
        <h1>Client Branding</h1>
        <p>Company name, logo, favicon, currency and member ID format shown across Admin &amp; User panels.</p>
    </div>
</section>

<form method="post" enctype="multipart/form-data" class="sa-brand-layout">
    <div class="sa-panel" style="margin:0">
        <div class="sa-panel-head">
            <div>
                <h2>Brand settings</h2>
                <p>Visible to the client’s members and admins</p>
            </div>
        </div>
        <div class="sa-panel-body">
            <div class="sa-form-grid">
                <div class="form-group span-2">
                    <label>Company name</label>
                    <input type="text" name="company_name" id="saBrandName" value="<?= e($coName) ?>" required>
                </div>
                <div class="form-group span-2">
                    <label>Support email</label>
                    <input type="email" name="support_email" value="<?= e(setting('support_email', '')) ?>">
                </div>
                <div class="form-group">
                    <label>Currency code</label>
                    <input type="text" name="currency" id="saCurrency" value="<?= e($currency) ?>">
                </div>
                <div class="form-group">
                    <label>Currency symbol</label>
                    <input type="text" name="currency_symbol" id="saSymbol" value="<?= e($symbol) ?>">
                </div>
                <div class="form-group">
                    <label>Member ID prefix</label>
                    <input type="text" name="member_id_prefix" id="saPrefix" value="<?= e($prefix) ?>" maxlength="10">
                    <span class="sa-field-hint">Letters/numbers only, max 10</span>
                </div>
                <div class="form-group">
                    <label>Member ID pad</label>
                    <input type="number" min="3" max="8" name="member_id_pad" id="saPad" value="<?= e((string) $pad) ?>">
                    <span class="sa-field-hint">Digits after prefix (3–8)</span>
                </div>
                <div class="form-group span-2">
                    <label>Brand assets</label>
                    <div class="sa-upload-grid">
                        <div class="sa-upload-card" data-sa-upload>
                            <input type="file" name="company_logo" id="saLogoFile" class="sa-upload-input" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                            <div class="sa-upload-preview<?= $logoUrl ? ' has-file' : '' ?>" id="saLogoPreview">
                                <?php if ($logoUrl): ?>
                                <img src="<?= e($logoUrl) ?>" alt="Logo">
                                <?php else: ?>
                                <span class="sa-upload-empty" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="sa-upload-meta">
                                <strong>Company logo</strong>
                                <p>JPG, PNG or WebP · max 2MB<br>Sidebars &amp; login screens</p>
                                <div class="sa-upload-actions">
                                    <label for="saLogoFile" class="sa-upload-btn">Choose file</label>
                                    <?php if ($logoUrl): ?>
                                    <label class="sa-upload-remove">
                                        <input type="checkbox" name="remove_logo" value="1">
                                        <span>Remove</span>
                                    </label>
                                    <?php endif; ?>
                                </div>
                                <span class="sa-upload-name" id="saLogoName"><?= $logoUrl ? 'Current logo set' : 'No file selected' ?></span>
                            </div>
                        </div>

                        <div class="sa-upload-card sa-upload-card-fav" data-sa-upload>
                            <input type="file" name="company_favicon" id="saFavFile" class="sa-upload-input" accept=".ico,.jpg,.jpeg,.png,.webp,image/x-icon,image/png,image/jpeg,image/webp">
                            <div class="sa-upload-preview sa-upload-preview-fav<?= $favUrl ? ' has-file' : '' ?>" id="saFavPreview">
                                <?php if ($favUrl): ?>
                                <img src="<?= e($favUrl) ?>" alt="Favicon">
                                <?php else: ?>
                                <span class="sa-upload-empty" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="sa-upload-meta">
                                <strong>Favicon</strong>
                                <p>ICO, PNG, JPG or WebP<br>Falls back to logo if empty</p>
                                <div class="sa-upload-actions">
                                    <label for="saFavFile" class="sa-upload-btn">Choose file</label>
                                    <?php if ($favUrl): ?>
                                    <label class="sa-upload-remove">
                                        <input type="checkbox" name="remove_favicon" value="1">
                                        <span>Remove</span>
                                    </label>
                                    <?php endif; ?>
                                </div>
                                <span class="sa-upload-name" id="saFavName"><?= $favUrl ? 'Current favicon set' : 'No file selected' ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="sa-form-actions">
                <button type="submit" class="btn btn-primary">Save branding</button>
            </div>
        </div>
    </div>

    <aside class="sa-panel" style="margin:0">
        <div class="sa-panel-head">
            <div>
                <h2>Live preview</h2>
                <p>How it may appear on panels</p>
            </div>
        </div>
        <div class="sa-panel-body">
            <div class="sa-brand-preview">
                <?php if ($logoUrl): ?>
                <img class="sa-brand-preview-logo" src="<?= e($logoUrl) ?>" alt="">
                <?php endif; ?>
                <span>Company</span>
                <strong id="saPreviewName"><?= e($coName) ?></strong>
                <em>Sample ID · <span id="saPreviewId"><?= e($sampleId) ?></span> · <span id="saPreviewCur"><?= e($symbol . ' / ' . $currency) ?></span></em>
            </div>
            <div class="sa-note" style="margin-top:1rem">Changes apply immediately after save on both Client Admin and User panels.</div>
        </div>
    </aside>
</form>
<script>
(function () {
    var name = document.getElementById('saBrandName');
    var prefix = document.getElementById('saPrefix');
    var pad = document.getElementById('saPad');
    var cur = document.getElementById('saCurrency');
    var sym = document.getElementById('saSymbol');
    var pName = document.getElementById('saPreviewName');
    var pId = document.getElementById('saPreviewId');
    var pCur = document.getElementById('saPreviewCur');
    function sync() {
        if (pName && name) pName.textContent = name.value || 'Company';
        var pre = (prefix && prefix.value ? prefix.value : 'MLM').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 10) || 'MLM';
        var n = Math.max(3, Math.min(8, parseInt(pad && pad.value ? pad.value : '5', 10) || 5));
        if (pId) pId.textContent = pre + String(1).padStart(n, '0');
        if (pCur) pCur.textContent = ((sym && sym.value) || '₹') + ' / ' + ((cur && cur.value) || 'INR');
    }
    [name, prefix, pad, cur, sym].forEach(function (el) {
        if (el) el.addEventListener('input', sync);
    });

    function bindUpload(inputId, previewId, nameId) {
        var input = document.getElementById(inputId);
        var preview = document.getElementById(previewId);
        var nameEl = document.getElementById(nameId);
        if (!input || !preview) return;
        var card = input.closest('[data-sa-upload]');

        function setPreview(file) {
            if (!file) return;
            if (!file.type || file.type.indexOf('image/') !== 0) {
                if (nameEl) nameEl.textContent = file.name;
                return;
            }
            var url = URL.createObjectURL(file);
            preview.innerHTML = '<img src="' + url + '" alt="">';
            preview.classList.add('has-file');
            if (nameEl) nameEl.textContent = file.name;
            if (card) card.classList.add('has-selection');
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (file) setPreview(file);
        });

        if (card) {
            ['dragenter', 'dragover'].forEach(function (ev) {
                card.addEventListener(ev, function (e) {
                    e.preventDefault();
                    card.classList.add('is-drag');
                });
            });
            ['dragleave', 'drop'].forEach(function (ev) {
                card.addEventListener(ev, function (e) {
                    e.preventDefault();
                    card.classList.remove('is-drag');
                });
            });
            card.addEventListener('drop', function (e) {
                var files = e.dataTransfer && e.dataTransfer.files;
                if (!files || !files.length) return;
                try {
                    var dt = new DataTransfer();
                    dt.items.add(files[0]);
                    input.files = dt.files;
                } catch (err) { /* ignore */ }
                setPreview(files[0]);
            });
        }
    }
    bindUpload('saLogoFile', 'saLogoPreview', 'saLogoName');
    bindUpload('saFavFile', 'saFavPreview', 'saFavName');
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
