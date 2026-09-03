document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('upSidebar');
    const menuBtn = document.getElementById('upMenuBtn');
    const overlay = document.getElementById('upOverlay');

    const closeMenu = () => {
        if (sidebar) sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('show');
    };

    if (menuBtn && sidebar) {
        menuBtn.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            if (overlay) overlay.classList.toggle('show');
        });
    }
    if (overlay) overlay.addEventListener('click', closeMenu);

    const navGroups = Array.from(document.querySelectorAll('[data-up-nav-group]'));

    const getSub = (group) => group.querySelector('.up-nav-sub');

    const lockSubHeight = (sub, px) => {
        if (!sub) return;
        sub.style.height = typeof px === 'number' ? `${px}px` : px;
    };

    const openNavGroup = (group) => {
        const btn = group.querySelector('[data-up-nav-toggle]');
        const sub = getSub(group);
        if (!sub) return;

        group.classList.add('is-open');
        if (btn) btn.setAttribute('aria-expanded', 'true');

        // Start from 0, then animate to full content height
        lockSubHeight(sub, 0);
        // Force reflow so the browser registers the starting height
        void sub.offsetHeight;
        lockSubHeight(sub, sub.scrollHeight);

        const onEnd = (e) => {
            if (e.propertyName !== 'height') return;
            sub.removeEventListener('transitionend', onEnd);
            if (group.classList.contains('is-open')) {
                lockSubHeight(sub, 'auto');
            }
        };
        sub.addEventListener('transitionend', onEnd);
    };

    const closeNavGroup = (group) => {
        const btn = group.querySelector('[data-up-nav-toggle]');
        const sub = getSub(group);
        if (!sub) return;

        // Fix current height so transition from auto works
        lockSubHeight(sub, sub.scrollHeight);
        void sub.offsetHeight;
        group.classList.remove('is-open');
        if (btn) btn.setAttribute('aria-expanded', 'false');
        lockSubHeight(sub, 0);
    };

    // Init: sync height with current open state (no flash)
    navGroups.forEach((group) => {
        const sub = getSub(group);
        if (!sub) return;
        if (group.classList.contains('is-open')) {
            lockSubHeight(sub, 'auto');
            sub.style.opacity = '1';
        } else {
            lockSubHeight(sub, 0);
        }
    });

    navGroups.forEach((group) => {
        const btn = group.querySelector('[data-up-nav-toggle]');
        if (!btn) return;
        btn.addEventListener('click', () => {
            const willOpen = !group.classList.contains('is-open');
            navGroups.forEach((other) => {
                if (other !== group && other.classList.contains('is-open')) {
                    closeNavGroup(other);
                }
            });
            if (willOpen) openNavGroup(group);
            else closeNavGroup(group);
        });
    });

    const fsBtn = document.getElementById('upFullscreenBtn');
    if (fsBtn) {
        fsBtn.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen?.();
            } else {
                document.exitFullscreen?.();
            }
        });
    }

    document.querySelectorAll('[data-password-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const wrap = btn.closest('.up-password-wrap, .ureg-input-ico');
            const input = wrap && wrap.querySelector('input');
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.classList.toggle('is-visible', show);
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    document.querySelectorAll('[data-copy], [data-copy-text]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            let text = '';
            let input = null;
            if (btn.hasAttribute('data-copy-text')) {
                text = btn.getAttribute('data-copy-text') || '';
            } else {
                const sel = btn.getAttribute('data-copy');
                input = sel ? document.querySelector(sel) : null;
                text = input && 'value' in input ? String(input.value) : '';
            }
            if (!text) return;

            const label = btn.querySelector('span');
            const original = label ? label.textContent : 'Copy';
            const isIconCopy = btn.classList.contains('actx-copy-btn') || btn.classList.contains('urs-copy');

            const markCopied = () => {
                btn.classList.add('is-copied');
                if (label) label.textContent = 'Copied!';
                if (isIconCopy) {
                    btn.setAttribute('aria-label', 'Copied');
                    btn.setAttribute('title', 'Copied');
                }
                setTimeout(() => {
                    btn.classList.remove('is-copied');
                    if (label) label.textContent = original;
                    if (isIconCopy) {
                        const restore = btn.getAttribute('data-label') || 'Copy';
                        btn.setAttribute('aria-label', restore);
                        btn.setAttribute('title', restore);
                    }
                }, 1600);
            };

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                } else if (input) {
                    input.focus();
                    input.select();
                    document.execCommand('copy');
                } else {
                    const ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                }
                markCopied();
            } catch (err) {
                if (input) {
                    input.focus();
                    input.select();
                }
            }
        });
    });

    const userDropdowns = document.querySelectorAll('[data-up-dropdown]');
    const closeUserDropdowns = (except = null) => {
        userDropdowns.forEach((dd) => {
            if (dd === except) return;
            dd.classList.remove('open');
            const t = dd.querySelector('[data-up-dropdown-toggle]');
            if (t) t.setAttribute('aria-expanded', 'false');
        });
    };

    userDropdowns.forEach((dd) => {
        const btn = dd.querySelector('[data-up-dropdown-toggle]');
        if (!btn) return;
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const willOpen = !dd.classList.contains('open');
            closeUserDropdowns();
            if (willOpen) {
                dd.classList.add('open');
                btn.setAttribute('aria-expanded', 'true');
            }
        });
    });

    document.addEventListener('click', () => closeUserDropdowns());
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeUserDropdowns();
    });

    // Profile photo upload preview + drag/drop
    const photoInput = document.getElementById('phPhotoInput');
    const dropzone = document.getElementById('phDropzone');
    const uploadBtn = document.getElementById('phUploadBtn');
    const fileNameEl = document.getElementById('phFileName');
    const previewImg = document.getElementById('phPreviewImg');
    const previewInitials = document.getElementById('phPreviewInitials');
    const avatarPreview = document.getElementById('phAvatarPreview');
    const statusChip = document.getElementById('phStatusChip');

    const applyPhotoPreview = (file) => {
        if (!file || !file.type.startsWith('image/')) return;
        if (fileNameEl) fileNameEl.textContent = file.name;
        if (uploadBtn) uploadBtn.disabled = false;
        if (dropzone) dropzone.classList.add('has-file');
        if (statusChip) {
            statusChip.textContent = 'Ready to upload';
            statusChip.classList.remove('is-muted');
            statusChip.classList.add('is-ok');
        }

        const reader = new FileReader();
        reader.onload = () => {
            if (!previewImg) return;
            previewImg.src = String(reader.result || '');
            previewImg.hidden = false;
            if (previewInitials) previewInitials.hidden = true;
            if (avatarPreview) avatarPreview.classList.add('has-photo');
        };
        reader.readAsDataURL(file);
    };

    if (photoInput) {
        photoInput.addEventListener('change', () => {
            const file = photoInput.files && photoInput.files[0];
            applyPhotoPreview(file);
        });
    }

    if (dropzone && photoInput) {
        ['dragenter', 'dragover'].forEach((evt) => {
            dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('is-drag');
            });
        });
        ['dragleave', 'drop'].forEach((evt) => {
            dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('is-drag');
            });
        });
        dropzone.addEventListener('drop', (e) => {
            const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (!file) return;
            const dt = new DataTransfer();
            dt.items.add(file);
            photoInput.files = dt.files;
            applyPhotoPreview(file);
        });
    }

    // KYC document upload dropzone + preview
    const kycInput = document.getElementById('kycDocInput');
    const kycZone = document.getElementById('kycDropzone');
    const kycFileName = document.getElementById('kycFileName');
    const kycPreview = document.getElementById('kycDropPreview');
    const kycEmpty = document.getElementById('kycDropEmpty');
    const kycImg = document.getElementById('kycPreviewImg');
    const kycPdf = document.getElementById('kycPreviewPdf');

    const applyKycPreview = (file) => {
        if (!file) return;
        if (kycFileName) {
            kycFileName.hidden = false;
            kycFileName.textContent = file.name;
        }
        if (kycZone) kycZone.classList.add('has-file');
        if (kycPreview) kycPreview.hidden = false;
        if (kycEmpty) kycEmpty.hidden = true;

        const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
        if (isPdf) {
            if (kycImg) {
                kycImg.hidden = true;
                kycImg.removeAttribute('src');
            }
            if (kycPdf) {
                kycPdf.classList.remove('is-hidden');
                const label = document.getElementById('kycPdfLabel');
                if (label) label.textContent = file.name;
            }
            return;
        }

        if (!file.type.startsWith('image/')) return;
        const reader = new FileReader();
        reader.onload = () => {
            if (kycPdf) kycPdf.classList.add('is-hidden');
            if (kycImg) {
                kycImg.hidden = false;
                kycImg.src = String(reader.result || '');
            }
        };
        reader.readAsDataURL(file);
    };

    if (kycInput) {
        kycInput.addEventListener('change', () => {
            const file = kycInput.files && kycInput.files[0];
            applyKycPreview(file);
        });
    }

    if (kycZone && kycInput) {
        ['dragenter', 'dragover'].forEach((evt) => {
            kycZone.addEventListener(evt, (e) => {
                e.preventDefault();
                e.stopPropagation();
                kycZone.classList.add('is-drag');
            });
        });
        ['dragleave', 'drop'].forEach((evt) => {
            kycZone.addEventListener(evt, (e) => {
                e.preventDefault();
                e.stopPropagation();
                kycZone.classList.remove('is-drag');
            });
        });
        kycZone.addEventListener('drop', (e) => {
            const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (!file) return;
            const dt = new DataTransfer();
            dt.items.add(file);
            kycInput.files = dt.files;
            applyKycPreview(file);
        });
    }

    // Aadhaar dual upload + geo cascade + lightbox
    const bindSideUpload = (inputId, dropId, emptyId, previewId, imgId, pdfId, thumbId, thumbEmptyId, boxSel) => {
        const input = document.getElementById(inputId);
        const drop = document.getElementById(dropId);
        const empty = document.getElementById(emptyId);
        const preview = document.getElementById(previewId);
        const img = document.getElementById(imgId);
        const pdf = document.getElementById(pdfId);
        const thumb = document.getElementById(thumbId);
        const thumbEmpty = document.getElementById(thumbEmptyId);
        const box = document.querySelector(boxSel);
        if (!input || !drop) return;

        const apply = (file) => {
            if (!file) return;
            drop.classList.add('has-file');
            if (preview) preview.hidden = false;
            if (empty) empty.hidden = true;

            const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
            if (isPdf) {
                if (img) { img.hidden = true; img.removeAttribute('src'); }
                if (pdf) pdf.classList.remove('is-hidden');
                if (thumb) { thumb.hidden = true; }
                if (thumbEmpty) {
                    thumbEmpty.hidden = false;
                    thumbEmpty.querySelector('span').textContent = 'PDF file';
                }
                if (box) box.removeAttribute('data-src');
                return;
            }
            if (!file.type.startsWith('image/')) return;
            const reader = new FileReader();
            reader.onload = () => {
                const url = String(reader.result || '');
                if (pdf) pdf.classList.add('is-hidden');
                if (img) { img.hidden = false; img.src = url; }
                if (thumbEmpty) thumbEmpty.hidden = true;
                if (thumb) { thumb.hidden = false; thumb.src = url; }
                if (box) box.setAttribute('data-src', url);
            };
            reader.readAsDataURL(file);
        };

        input.addEventListener('change', () => apply(input.files && input.files[0]));
        ['dragenter', 'dragover'].forEach((evt) => {
            drop.addEventListener(evt, (e) => { e.preventDefault(); e.stopPropagation(); drop.classList.add('is-drag'); });
        });
        ['dragleave', 'drop'].forEach((evt) => {
            drop.addEventListener(evt, (e) => { e.preventDefault(); e.stopPropagation(); drop.classList.remove('is-drag'); });
        });
        drop.addEventListener('drop', (e) => {
            const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (!file) return;
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            apply(file);
        });
    };

    bindSideUpload('aadharFrontInput', 'aadharFrontDrop', 'aadharFrontEmpty', 'aadharFrontPreview', 'aadharFrontImg', 'aadharFrontPdf', 'aadharFrontThumb', 'aadharFrontThumbEmpty', '.ad-preview-box[data-side="front"]');
    bindSideUpload('aadharBackInput', 'aadharBackDrop', 'aadharBackEmpty', 'aadharBackPreview', 'aadharBackImg', 'aadharBackPdf', 'aadharBackThumb', 'aadharBackThumbEmpty', '.ad-preview-box[data-side="back"]');

    const countrySel = document.getElementById('country_id');
    const stateSel = document.getElementById('state_id');
    const citySel = document.getElementById('city_id');
    const countryName = document.getElementById('country_name');
    const stateName = document.getElementById('state_name');
    const cityName = document.getElementById('city_name');

    const fillSelect = (sel, rows, placeholder) => {
        if (!sel) return;
        const cur = sel.value;
        sel.innerHTML = '';
        const opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = placeholder;
        sel.appendChild(opt0);
        rows.forEach((r) => {
            const o = document.createElement('option');
            o.value = String(r.id);
            o.textContent = r.name;
            sel.appendChild(o);
        });
        if (cur && [...sel.options].some((o) => o.value === cur)) sel.value = cur;
    };

    const syncHiddenName = (sel, hidden) => {
        if (!sel || !hidden) return;
        const opt = sel.options[sel.selectedIndex];
        hidden.value = opt && opt.value ? opt.textContent : '';
    };

    if (countrySel && stateSel) {
        countrySel.addEventListener('change', async () => {
            syncHiddenName(countrySel, countryName);
            fillSelect(stateSel, [], 'Select state');
            fillSelect(citySel, [], 'Select city');
            syncHiddenName(stateSel, stateName);
            syncHiddenName(citySel, cityName);
            const cid = countrySel.value;
            if (!cid) return;
            try {
                const res = await fetch(`ajax-geo.php?type=states&country_id=${encodeURIComponent(cid)}`);
                const rows = await res.json();
                fillSelect(stateSel, Array.isArray(rows) ? rows : [], 'Select state');
            } catch (err) { /* ignore */ }
        });
        stateSel.addEventListener('change', async () => {
            syncHiddenName(stateSel, stateName);
            fillSelect(citySel, [], 'Select city');
            syncHiddenName(citySel, cityName);
            const sid = stateSel.value;
            if (!sid) return;
            try {
                const res = await fetch(`ajax-geo.php?type=cities&state_id=${encodeURIComponent(sid)}`);
                const rows = await res.json();
                fillSelect(citySel, Array.isArray(rows) ? rows : [], 'Select city');
            } catch (err) { /* ignore */ }
        });
        if (citySel) {
            citySel.addEventListener('change', () => syncHiddenName(citySel, cityName));
        }
        syncHiddenName(countrySel, countryName);
        syncHiddenName(stateSel, stateName);
        syncHiddenName(citySel, cityName);

        const aadharForm = document.getElementById('aadharKycForm');
        if (aadharForm) {
            aadharForm.addEventListener('submit', () => {
                syncHiddenName(countrySel, countryName);
                syncHiddenName(stateSel, stateName);
                syncHiddenName(citySel, cityName);
            });
        }

        // If country already selected (e.g. India default) but state list empty, load via AJAX
        if (countrySel.value && stateSel.options.length <= 1) {
            countrySel.dispatchEvent(new Event('change'));
        } else if (stateSel.value && citySel && citySel.options.length <= 1) {
            stateSel.dispatchEvent(new Event('change'));
        }
    }

    const lightbox = document.getElementById('aadharLightbox');
    const lightboxImg = document.getElementById('aadharLightboxImg');
    const lightboxClose = document.getElementById('aadharLightboxClose');
    document.querySelectorAll('[data-enlarge]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const src = btn.getAttribute('data-src');
            if (!src || !lightbox || !lightboxImg) return;
            lightboxImg.src = src;
            lightbox.hidden = false;
        });
    });
    if (lightboxClose && lightbox) {
        lightboxClose.addEventListener('click', () => { lightbox.hidden = true; lightboxImg.src = ''; });
        lightbox.addEventListener('click', (e) => {
            if (e.target === lightbox) {
                lightbox.hidden = true;
                lightboxImg.src = '';
            }
        });
    }

    const searchWrap = document.querySelector('[data-up-search]');
    const searchInput = document.getElementById('upSearchInput');
    const searchDrop = document.getElementById('upSearchDrop');
    if (searchWrap && searchInput && searchDrop) {
        const links = Array.from(searchDrop.querySelectorAll('a'));
        const showDrop = () => { searchDrop.hidden = false; };
        const hideDrop = () => { searchDrop.hidden = true; };
        const filterSearch = () => {
            const q = (searchInput.value || '').trim().toLowerCase();
            let visible = 0;
            links.forEach((a) => {
                const hay = ((a.getAttribute('data-search') || '') + ' ' + (a.textContent || '')).toLowerCase();
                const on = !q || hay.includes(q);
                a.hidden = !on;
                if (on) visible += 1;
            });
            searchDrop.hidden = visible === 0;
        };
        searchInput.addEventListener('focus', () => {
            filterSearch();
            showDrop();
        });
        searchInput.addEventListener('input', filterSearch);
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                hideDrop();
                searchInput.blur();
            }
        });
        document.addEventListener('click', (e) => {
            if (!searchWrap.contains(e.target)) hideDrop();
        });
    }
});
