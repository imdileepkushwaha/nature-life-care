document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');

    if (toggle && sidebar) {
        let overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            document.body.appendChild(overlay);
        }

        const close = () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        };

        toggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
        });

        overlay.addEventListener('click', close);
    }

    document.querySelectorAll('[data-nav-toggle]').forEach((btn) => {
        const group = btn.closest('[data-nav-group]');
        if (!group) return;
        const sub = group.querySelector('.nav-submenu');
        if (!sub) return;

        const setHeight = (px) => {
            sub.style.height = typeof px === 'number' ? `${px}px` : px;
        };

        // Sync initial state without flash
        if (group.classList.contains('open')) {
            setHeight('auto');
            sub.style.opacity = '1';
        } else {
            setHeight(0);
        }

        btn.addEventListener('click', () => {
            const willOpen = !group.classList.contains('open');

            if (willOpen) {
                group.classList.add('open');
                btn.setAttribute('aria-expanded', 'true');
                setHeight(0);
                void sub.offsetHeight;
                setHeight(sub.scrollHeight);
                const onEnd = (e) => {
                    if (e.propertyName !== 'height') return;
                    sub.removeEventListener('transitionend', onEnd);
                    if (group.classList.contains('open')) setHeight('auto');
                };
                sub.addEventListener('transitionend', onEnd);
            } else {
                setHeight(sub.scrollHeight);
                void sub.offsetHeight;
                group.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
                setHeight(0);
            }
        });
    });

    document.querySelectorAll('[data-confirm]').forEach((el) => {
        el.addEventListener('click', (e) => {
            if (!confirm(el.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });

    // Fullscreen toggle
    const fsBtn = document.getElementById('fullscreenBtn');
    if (fsBtn) {
        const expand = fsBtn.querySelector('.ico-expand');
        const compress = fsBtn.querySelector('.ico-compress');

        const syncFsIcons = () => {
            const on = !!document.fullscreenElement;
            if (expand) expand.hidden = on;
            if (compress) compress.hidden = !on;
        };

        fsBtn.addEventListener('click', async () => {
            try {
                if (!document.fullscreenElement) {
                    await document.documentElement.requestFullscreen();
                } else {
                    await document.exitFullscreen();
                }
            } catch (err) {
                console.warn('Fullscreen not available', err);
            }
        });

        document.addEventListener('fullscreenchange', syncFsIcons);
        syncFsIcons();
    }

    // Dropdown menus (notifications + user)
    const dropdowns = document.querySelectorAll('[data-dropdown]');

    const closeAllDropdowns = (except = null) => {
        dropdowns.forEach((dd) => {
            if (dd !== except) {
                dd.classList.remove('open');
                const t = dd.querySelector('[data-dropdown-toggle]');
                if (t) t.setAttribute('aria-expanded', 'false');
            }
        });
    };

    dropdowns.forEach((dd) => {
        const btn = dd.querySelector('[data-dropdown-toggle]');
        if (!btn) return;

        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const willOpen = !dd.classList.contains('open');
            closeAllDropdowns();
            if (willOpen) {
                dd.classList.add('open');
                btn.setAttribute('aria-expanded', 'true');
            }
        });
    });

    document.addEventListener('click', () => closeAllDropdowns());
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeAllDropdowns();
    });

    // Cascading country -> state -> city
    const countrySelect = document.getElementById('country_id');
    const stateSelect = document.getElementById('state_id');
    const citySelect = document.getElementById('city_id');

    async function loadOptions(url, select, placeholder, selectedId) {
        if (!select) return;
        select.innerHTML = `<option value="">${placeholder}</option>`;
        const res = await fetch(url);
        const rows = await res.json();
        rows.forEach((row) => {
            const opt = document.createElement('option');
            opt.value = row.id;
            opt.textContent = row.name;
            if (String(selectedId) === String(row.id)) opt.selected = true;
            select.appendChild(opt);
        });
    }

    if (countrySelect && stateSelect) {
        countrySelect.addEventListener('change', () => {
            const cid = countrySelect.value;
            if (citySelect) citySelect.innerHTML = '<option value="">— Select City —</option>';
            if (!cid) {
                stateSelect.innerHTML = '<option value="">— Select State —</option>';
                return;
            }
            loadOptions(`ajax-geo.php?type=states&country_id=${cid}`, stateSelect, '— Select State —');
        });
    }

    if (stateSelect && citySelect) {
        stateSelect.addEventListener('change', () => {
            const sid = stateSelect.value;
            if (!sid) {
                citySelect.innerHTML = '<option value="">— Select City —</option>';
                return;
            }
            loadOptions(`ajax-geo.php?type=cities&state_id=${sid}`, citySelect, '— Select City —');
        });
    }

    document.querySelectorAll('[data-password-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const wrap = btn.closest('.password-field');
            const input = wrap ? wrap.querySelector('input') : null;
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.classList.toggle('is-visible', show);
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.setAttribute('title', show ? 'Hide password' : 'Show password');
        });
    });

    // Tree view: hover tooltip + add-direct modal
    const tvTooltip = document.getElementById('tvTooltip');
    const tvModal = document.getElementById('tvAddModal');
    if (tvTooltip) {
        let tipTimer = null;
        const hideTip = () => {
            tipTimer = setTimeout(() => {
                tvTooltip.hidden = true;
            }, 120);
        };
        const showTip = (node, data) => {
            clearTimeout(tipTimer);
            tvTooltip.innerHTML = `
                <div class="tt-name">${escHtml(data.name || data.user || '')}</div>
                <div class="tt-id">${escHtml(data.id || '')} · @${escHtml(data.user || '')}</div>
                ${data.alert ? `<div class="tt-alert">${escHtml(data.alert)}</div>` : ''}
                <div class="tt-row"><span>Status</span><span class="badge badge-${escHtml(String(data.status || '').toLowerCase().replace(/[\s-]+/g, '_'))}">${escHtml(data.status || '—')}</span></div>
                <div class="tt-row"><span>Package</span><span>${escHtml(data.package || '—')}</span></div>
                <div class="tt-row"><span>Email</span><span>${escHtml(data.email || '—')}</span></div>
                <div class="tt-row"><span>Phone</span><span>${escHtml(data.phone || '—')}</span></div>
                <div class="tt-row"><span>Left / Right</span><span>${escHtml(String(data.left ?? 0))} / ${escHtml(String(data.right ?? 0))}</span></div>
                <div class="tt-row"><span>Wallet</span><span>${escHtml(data.wallet || '0.00')}</span></div>
                <div class="tt-row"><span>Sponsor</span><span>${escHtml(data.sponsor || '—')}</span></div>
                <div class="tt-row"><span>Joined</span><span>${escHtml(data.joined || '—')}</span></div>
                ${data.profile ? `<a class="tt-link" href="${escHtml(data.profile)}">View profile →</a>` : ''}
                ${data.tree ? `<a class="tt-link" href="${escHtml(data.tree)}">Open 4-level tree →</a>` : ''}
            `;
            tvTooltip.hidden = false;
            const rect = node.getBoundingClientRect();
            const tipW = tvTooltip.offsetWidth;
            const tipH = tvTooltip.offsetHeight;
            let left = rect.left + rect.width / 2 - tipW / 2;
            let top = rect.top - tipH - 10;
            if (top < 8) top = rect.bottom + 10;
            if (left < 8) left = 8;
            if (left + tipW > window.innerWidth - 8) left = window.innerWidth - tipW - 8;
            tvTooltip.style.left = `${left}px`;
            tvTooltip.style.top = `${top}px`;
        };

        document.querySelectorAll('.tv-node.filled[data-tooltip]').forEach((node) => {
            node.addEventListener('mouseenter', () => {
                try {
                    const data = JSON.parse(node.getAttribute('data-tooltip') || '{}');
                    showTip(node, data);
                } catch (e) { /* ignore */ }
            });
            node.addEventListener('mouseleave', hideTip);
            node.addEventListener('focus', () => {
                try {
                    const data = JSON.parse(node.getAttribute('data-tooltip') || '{}');
                    showTip(node, data);
                } catch (e) { /* ignore */ }
            });
            node.addEventListener('blur', hideTip);
        });

        tvTooltip.addEventListener('mouseenter', () => clearTimeout(tipTimer));
        tvTooltip.addEventListener('mouseleave', hideTip);
    }

    if (tvModal) {
        const openModal = (btn) => {
            const parentId = btn.getAttribute('data-parent-id') || '';
            const position = btn.getAttribute('data-position') || '';
            const parentName = btn.getAttribute('data-parent-name') || '';
            const parentCode = btn.getAttribute('data-parent-code') || '';
            const level = btn.getAttribute('data-level') || '';
            document.getElementById('tvParentId').value = parentId;
            document.getElementById('tvPosition').value = position;
            document.getElementById('tvSponsorId').value = parentId;
            document.getElementById('tvSlotChip').textContent =
                `Under ${parentCode || parentName} · ${String(position).toUpperCase()} · showing LVL ${level}`;
            document.getElementById('tvModalSub').textContent =
                `Register under ${parentName} (${String(position).toUpperCase()} side). Member will be placed in the next free slot on this leg.`;
            const form = document.getElementById('tvAddForm');
            if (form) form.reset();
            document.getElementById('tvParentId').value = parentId;
            document.getElementById('tvPosition').value = position;
            document.getElementById('tvSponsorId').value = parentId;
            tvModal.hidden = false;
            document.body.classList.add('tv-modal-open');
        };
        const closeModal = () => {
            tvModal.hidden = true;
            document.body.classList.remove('tv-modal-open');
        };

        document.querySelectorAll('.tv-node.vacant').forEach((btn) => {
            btn.addEventListener('click', () => openModal(btn));
        });
        tvModal.querySelectorAll('[data-tv-close]').forEach((el) => {
            el.addEventListener('click', closeModal);
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !tvModal.hidden) closeModal();
        });
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Category image upload UI
    const imgBox = document.getElementById('catImgUpload');
    const imgInput = document.getElementById('catImgInput');
    if (imgBox && imgInput) {
        const preview = document.getElementById('catImgPreview');
        let thumb = document.getElementById('catImgThumb');
        const nameEl = document.getElementById('catImgName');
        const drop = document.getElementById('catImgDrop') || imgBox.querySelector('.su-upload__pick');

        const showFile = (file) => {
            if (!file || !preview) return;
            imgBox.classList.add('is-filled');
            if (nameEl) nameEl.textContent = file.name;

            const placeholder = document.getElementById('catImgEmpty') || preview.querySelector('.su-upload__ph');
            if (placeholder) {
                placeholder.style.display = 'none';
                placeholder.setAttribute('hidden', '');
                placeholder.remove();
            }

            thumb = document.getElementById('catImgThumb');
            if (!thumb) {
                thumb = document.createElement('img');
                thumb.id = 'catImgThumb';
                thumb.alt = 'Preview';
                preview.appendChild(thumb);
            }
            thumb.src = URL.createObjectURL(file);
            thumb.style.display = 'block';
            thumb.hidden = false;
        };

        imgInput.addEventListener('change', () => {
            const file = imgInput.files && imgInput.files[0];
            if (file) showFile(file);
        });

        if (drop) {
            ['dragenter', 'dragover'].forEach((evt) => {
                drop.addEventListener(evt, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    imgBox.classList.add('is-dragover');
                });
            });
            ['dragleave', 'drop'].forEach((evt) => {
                drop.addEventListener(evt, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    imgBox.classList.remove('is-dragover');
                });
            });
            drop.addEventListener('drop', (e) => {
                const files = e.dataTransfer && e.dataTransfer.files;
                if (!files || !files.length) return;
                const file = files[0];
                if (!file.type.startsWith('image/')) return;
                const dt = new DataTransfer();
                dt.items.add(file);
                imgInput.files = dt.files;
                showFile(file);
            });
        }
    }

    // Color hex picker sync (Soft UI Soft style)
    const hexPicker = document.getElementById('hexColorPicker');
    const hexInput = document.getElementById('hexCodeInput');
    const hexFill = document.getElementById('hexSwatchFill');
    if (hexPicker && hexInput) {
        const normalizeHex = (raw) => {
            let val = String(raw || '').trim();
            if (!val) return '';
            if (val[0] !== '#') val = '#' + val;
            if (/^#[0-9A-Fa-f]{3}$/.test(val)) {
                val = '#' + val[1] + val[1] + val[2] + val[2] + val[3] + val[3];
            }
            return /^#[0-9A-Fa-f]{6}$/.test(val) ? val.toUpperCase() : '';
        };

        const paint = (hex) => {
            if (!hex) return;
            hexPicker.value = hex;
            if (hexFill) hexFill.style.background = hex;
        };

        hexPicker.addEventListener('input', () => {
            const hex = hexPicker.value.toUpperCase();
            hexInput.value = hex;
            paint(hex);
        });

        hexInput.addEventListener('input', () => {
            const hex = normalizeHex(hexInput.value);
            if (hex) {
                hexInput.value = hex;
                paint(hex);
            }
        });

        hexInput.addEventListener('blur', () => {
            const hex = normalizeHex(hexInput.value);
            if (hex) {
                hexInput.value = hex;
                paint(hex);
            }
        });
    }

    // Product Form Wizard
    const pfForm = document.getElementById('pfForm');
    if (pfForm) {
        let step = 1;
        const navItems = Array.from(document.querySelectorAll('[data-pf-step]'));
        const panels = Array.from(document.querySelectorAll('.pf-step'));
        const total = Math.max(navItems.length, panels.length, 1);
        const prevBtn = document.getElementById('pfPrev');
        const nextBtn = document.getElementById('pfNext');
        const submitBtn = document.getElementById('pfSubmit');
        const cancelBtn = document.getElementById('pfCancel');
        const titleInput = document.getElementById('pfTitle');
        const slugPreview = document.getElementById('pfSlugPreview');
        const slugValue = document.getElementById('pfSlugValue');
        const editor = document.getElementById('pfEditor');
        const descValue = document.getElementById('pfDescValue');
        const skuManual = document.getElementById('pfSkuManual');

        const slugify = (text) => String(text || '')
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'auto-generated-slug-path';

        const wordCount = (text) => (String(text || '').trim().match(/\S+/g) || []).length;

        const showStep = (n) => {
            step = Math.max(1, Math.min(total, n));
            panels.forEach((p) => {
                const s = Number(p.getAttribute('data-step'));
                const on = s === step;
                p.hidden = !on;
                p.classList.toggle('is-active', on);
            });
            navItems.forEach((btn) => {
                const s = Number(btn.getAttribute('data-pf-step'));
                btn.classList.toggle('is-active', s === step);
                btn.classList.toggle('is-done', s < step);
            });

            const isFirst = step === 1;
            const isLast = step === total;

            const setVisible = (el, visible) => {
                if (!el) return;
                el.hidden = !visible;
                el.classList.toggle('is-hidden', !visible);
            };

            // Step 1: only Next Progression
            // Mid: Previous + Next Progression (+ Cancel)
            // Last: Previous + Save Product (+ Cancel)
            setVisible(prevBtn, !isFirst);
            setVisible(nextBtn, !isLast);
            setVisible(submitBtn, isLast);
            setVisible(cancelBtn, !isFirst);
        };

        const validateStep1 = () => {
            const words = wordCount(titleInput && titleInput.value);
            if (words < 2) {
                alert('Product title me kam se kam 2 shabd hone chahiye.');
                if (titleInput) titleInput.focus();
                return false;
            }
            return true;
        };

        navItems.forEach((btn) => {
            btn.addEventListener('click', () => {
                const target = Number(btn.getAttribute('data-pf-step'));
                if (target > 1 && !validateStep1()) {
                    showStep(1);
                    return;
                }
                showStep(target);
            });
        });

        if (prevBtn) prevBtn.addEventListener('click', () => showStep(step - 1));
        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                if (step === 1 && !validateStep1()) return;
                showStep(step + 1);
            });
        }

        if (titleInput) {
            titleInput.addEventListener('input', () => {
                const slug = slugify(titleInput.value);
                if (slugPreview) slugPreview.value = slug;
                if (slugValue) slugValue.value = slug === 'auto-generated-slug-path' ? '' : slug;
            });
        }

        document.querySelectorAll('#pfSkuMode .pf-seg-btn').forEach((label) => {
            label.addEventListener('click', () => {
                document.querySelectorAll('#pfSkuMode .pf-seg-btn').forEach((l) => l.classList.remove('is-on'));
                label.classList.add('is-on');
                const input = label.querySelector('input');
                if (input) input.checked = true;
                if (skuManual) skuManual.hidden = !(input && input.value === 'manual');
            });
        });

        document.querySelectorAll('#pfToolbar [data-cmd]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const cmd = btn.getAttribute('data-cmd');
                if (!editor) return;
                editor.focus();
                if (cmd === 'createLink') {
                    const url = prompt('Enter URL');
                    if (url) document.execCommand(cmd, false, url);
                    return;
                }
                document.execCommand(cmd, false, null);
            });
        });

        pfForm.addEventListener('submit', () => {
            if (descValue && editor) descValue.value = editor.innerHTML;
            if (slugValue && titleInput && !slugValue.value) slugValue.value = slugify(titleInput.value);
        });

        const bindDrag = (box, drop, onFiles) => {
            if (!box || !drop) return;
            ['dragenter', 'dragover'].forEach((evt) => {
                drop.addEventListener(evt, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    box.classList.add('is-dragover');
                });
            });
            ['dragleave', 'drop'].forEach((evt) => {
                drop.addEventListener(evt, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    box.classList.remove('is-dragover');
                });
            });
            drop.addEventListener('drop', (e) => {
                const files = e.dataTransfer && e.dataTransfer.files;
                if (!files || !files.length) return;
                onFiles(files);
            });
        };

        const thumb = document.getElementById('pfThumb');
        const thumbBox = document.getElementById('pfThumbBox');
        const thumbPreview = document.getElementById('pfThumbPreview');
        const thumbName = document.getElementById('pfThumbName');
        const thumbDrop = document.getElementById('pfThumbDrop');
        if (thumb && thumbPreview) {
            const showThumb = (file) => {
                if (!file) return;
                thumbBox && thumbBox.classList.add('is-filled');
                const empty = document.getElementById('pfThumbEmpty');
                if (empty) empty.remove();
                let img = document.getElementById('pfThumbImg');
                if (!img) {
                    img = document.createElement('img');
                    img.id = 'pfThumbImg';
                    img.alt = 'Thumbnail';
                    thumbPreview.appendChild(img);
                }
                img.src = URL.createObjectURL(file);
                if (thumbName) thumbName.textContent = file.name;
            };

            thumb.addEventListener('change', () => {
                const file = thumb.files && thumb.files[0];
                if (file) showThumb(file);
            });

            bindDrag(thumbBox, thumbDrop, (files) => {
                const file = Array.from(files).find((f) => f.type.startsWith('image/'));
                if (!file) return;
                const dt = new DataTransfer();
                dt.items.add(file);
                thumb.files = dt.files;
                showThumb(file);
            });
        }

        const gallery = document.getElementById('pfGallery');
        const galleryBox = document.getElementById('pfGalleryBox');
        const galleryPreview = document.getElementById('pfGalleryPreview');
        const galleryDrop = document.getElementById('pfGalleryDrop');
        if (gallery && galleryPreview) {
            const renderGallery = (fileList) => {
                galleryPreview.innerHTML = '';
                Array.from(fileList || []).forEach((file) => {
                    const url = URL.createObjectURL(file);
                    const wrap = document.createElement('div');
                    wrap.className = 'pg-gal-card pg-gal-card--new';
                    wrap.innerHTML = '<span class="pg-gal-card__badge">New</span><img src="' + url + '" alt="">';
                    galleryPreview.appendChild(wrap);
                });
            };

            gallery.addEventListener('change', () => renderGallery(gallery.files));

            bindDrag(galleryBox, galleryDrop, (files) => {
                const images = Array.from(files).filter((f) => f.type.startsWith('image/'));
                if (!images.length) return;
                const dt = new DataTransfer();
                Array.from(gallery.files || []).forEach((f) => dt.items.add(f));
                images.forEach((f) => dt.items.add(f));
                gallery.files = dt.files;
                renderGallery(gallery.files);
            });
        }

        const cat = document.getElementById('category_id');
        const sub = document.getElementById('subcategory_id');
        if (cat && sub) {
            const filterSubs = () => {
                const cid = cat.value;
                Array.prototype.forEach.call(sub.options, (opt) => {
                    if (!opt.value) { opt.hidden = false; return; }
                    const match = !cid || opt.getAttribute('data-category') === cid;
                    opt.hidden = !match;
                    if (!match && opt.selected) opt.selected = false;
                });
            };
            cat.addEventListener('change', filterSubs);
            filterSubs();
        }

        showStep(1);

        // Auto discount from MRP + Selling Price
        const pfMrp = document.getElementById('pfMrp');
        const pfPrice = document.getElementById('pfPrice');
        const pfDiscount = document.getElementById('pfDiscount');
        const recalcDiscount = () => {
            if (!pfDiscount) return;
            const mrp = parseFloat(pfMrp && pfMrp.value) || 0;
            const price = parseFloat(pfPrice && pfPrice.value) || 0;
            if (mrp > 0 && price >= 0 && price <= mrp) {
                pfDiscount.value = (((mrp - price) / mrp) * 100).toFixed(2);
            } else if (mrp > 0 && price > mrp) {
                pfDiscount.value = '0.00';
            } else {
                pfDiscount.value = '0.00';
            }
        };
        if (pfMrp) pfMrp.addEventListener('input', recalcDiscount);
        if (pfPrice) pfPrice.addEventListener('input', recalcDiscount);
        recalcDiscount();
    }

    // Stock purchase line items
    const spiRows = document.getElementById('spiRows');
    const spiAdd = document.getElementById('spiAddRow');
    const spiTpl = document.getElementById('spiRowTemplate');
    const spiTotal = document.getElementById('spiTotal');
    if (spiRows && spiTpl) {
        const money = (n) => (Math.round((n + Number.EPSILON) * 100) / 100).toFixed(2);

        const renumber = () => {
            Array.from(spiRows.querySelectorAll('.spi-row')).forEach((row, i) => {
                const no = row.querySelector('.spi-no');
                if (no) no.textContent = String(i + 1);
            });
        };

        const calcRow = (row) => {
            const qty = parseFloat(row.querySelector('.spi-qty')?.value) || 0;
            const rate = parseFloat(row.querySelector('.spi-rate')?.value) || 0;
            const amtEl = row.querySelector('[data-spi-amount]');
            if (amtEl) amtEl.textContent = money(qty * rate);
        };

        const calcTotal = () => {
            let total = 0;
            spiRows.querySelectorAll('.spi-row').forEach((row) => {
                const qty = parseFloat(row.querySelector('.spi-qty')?.value) || 0;
                const rate = parseFloat(row.querySelector('.spi-rate')?.value) || 0;
                total += qty * rate;
            });
            if (spiTotal) spiTotal.textContent = money(total);
        };

        const bindRow = (row) => {
            row.querySelectorAll('.spi-qty, .spi-rate').forEach((input) => {
                input.addEventListener('input', () => {
                    calcRow(row);
                    calcTotal();
                });
            });
            const removeBtn = row.querySelector('.spi-remove');
            if (removeBtn) {
                removeBtn.addEventListener('click', () => {
                    if (spiRows.querySelectorAll('.spi-row').length <= 1) {
                        row.querySelector('.spi-product').value = '';
                        row.querySelector('.spi-qty').value = '0';
                        row.querySelector('.spi-rate').value = '0';
                        calcRow(row);
                        calcTotal();
                        return;
                    }
                    row.remove();
                    renumber();
                    calcTotal();
                });
            }
        };

        spiRows.querySelectorAll('.spi-row').forEach((row) => {
            bindRow(row);
            calcRow(row);
        });
        calcTotal();

        if (spiAdd) {
            spiAdd.addEventListener('click', () => {
                if (spiRows.querySelectorAll('.spi-row').length >= 20) return;
                const node = spiTpl.content.cloneNode(true);
                const row = node.querySelector('.spi-row');
                spiRows.appendChild(node);
                bindRow(row);
                renumber();
                calcTotal();
            });
        }
    }

    // Auto-submit filter forms: dropdown/date on change, text inputs debounced
    document.querySelectorAll('form.filters, form.members-filter-form, form.tpin-filters').forEach((form) => {
        const method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method === 'post') return;

        let timer = null;
        const submitNow = () => {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        };

        form.querySelectorAll('select, input[type="date"], input[type="month"], input[type="week"]').forEach((el) => {
            el.addEventListener('change', () => {
                clearTimeout(timer);
                submitNow();
            });
        });

        form.querySelectorAll('input[type="text"], input[type="search"], input[type="number"], input:not([type])').forEach((el) => {
            if (el.name === 'admin_note' || el.classList.contains('act-note-input')) return;
            el.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(submitNow, 450);
            });
            el.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(timer);
                    submitNow();
                }
            });
        });
    });

});
