@php
    $jsId = str_replace(['.', '[', ']', '-'], '_', $getId());
    $multiple = method_exists($field, 'isMultiple') ? $field->isMultiple() : false;
    $isDisabled = method_exists($field, 'isInteractionDisabled') ? $field->isInteractionDisabled() : (method_exists($field, 'isDisabled') ? $field->isDisabled() : false);
    $pickerOpts = json_encode([
        'jsId' => $jsId,
        'statePath' => $getStatePath(),
        'isMultiple' => $multiple,
        'isDisabled' => $isDisabled,
        'openUrl' => route('filament-filemanager.file-manager'),
        'previewSelector' => 'file-preview-' . $getId(),
        'inputId' => $getId(),
        // package config-driven options
        'previewBase' => config('filament-filemanager.preview_base'),
        'enableDownload' => config('filament-filemanager.enable_download', true),
        'downloadBase' => config('filament-filemanager.download_base'),
        // previewData can be provided by the field via ->previewData([...]) or ->previewData(fn($record){})
        'previewData' => method_exists($field, 'getPreviewData') ? $field->getPreviewData() : null,
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
@endphp

@script
<script>
// Inline minimal initializer to avoid needing external asset publishing
window.fileManagerPickerUploadComponent = function (opts) {
    const {
        jsId,
        statePath,
        isMultiple,
        isDisabled,
        openUrl,
        previewSelector,
        inputId,
        previewBase,
        enableDownload,
        downloadBase,
        previewData,
    } = opts;

    // 共享 IntersectionObserver，懒加载图片
    let __io = null;
    function getIO() {
        if (__io !== null) return __io;
        if ('IntersectionObserver' in window) {
            __io = new IntersectionObserver((entries, obs) => {
                entries.forEach((e) => {
                    if (e.isIntersecting) {
                        const img = e.target;
                        try {
                            if (img && img.dataset && img.dataset.src && !img._loaded) {
                                img.src = img.dataset.src;
                                img._loaded = true;
                                delete img.dataset.src;
                            }
                        } catch (err) {}
                        obs.unobserve(img);
                    }
                });
            }, { root: null, rootMargin: '200px', threshold: 0 });
        }
        return __io;
    }

    function observeOrSetSrc(img, url) {
        try {
            img.loading = 'lazy';
            img.decoding = 'async';
        } catch (e) {}
        const io = getIO();
        if (io) {
            img.dataset.src = url;
            io.observe(img);
        } else {
            img.src = url;
        }
    }

    function parseValue(v) {
        if (!v) return isMultiple ? [] : '';
        if (isMultiple) {
            try {
                const arr = JSON.parse(v);
                if (Array.isArray(arr)) {
                    return arr.map(item => {
                        if (typeof item === 'string') return { path: item, alt: '' };
                        if (item && typeof item === 'object') return { path: (item.path || item.url || ''), alt: (item.alt || '') };
                        return { path: String(item || ''), alt: '' };
                    });
                }
                return [];
            } catch (e) {
                return [];
            }
        }
        return v;
    }

    function normalizePath(p) {
        if (!p) return '';
        try {
            let s = String(p);
            // remove protocol and host
            s = s.replace(/^https?:\/\/[^\/]+/i, '');
            // unify leading slashes
            s = s.replace(/^\/+/, '/');
            // drop common storage/public prefixes for matching DB-origin path
            s = s.replace(/^\/(storage|public)\//i, '');
            return s;
        } catch (e) { return String(p || ''); }
    }

    // API methods (closure-scoped) — will be invoked via event delegation
    const api = {
        removeIndex(idx) {
            if (isDisabled) return;
            try {
                const el = document.getElementById(inputId);
                if (!el) return;

                let arr = [];
                try {
                    arr = JSON.parse(el.value || '[]') || [];
                } catch (e) {
                    arr = [];
                }

                if (Array.isArray(arr)) {
                    arr.splice(idx, 1);
                    el.value = JSON.stringify(arr);
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                }

                try {
                    if (typeof previewData === 'object' && previewData) {
                        if (Array.isArray(previewData.thumb)) {
                            try { previewData.thumb.splice(idx, 1); } catch (e) {}
                        }
                        if (Array.isArray(previewData.origin)) {
                            try { previewData.origin.splice(idx, 1); } catch (e) {}
                        }
                        if (Array.isArray(previewData.alt)) {
                            try { previewData.alt.splice(idx, 1); } catch (e) {}
                        }
                    }
                } catch (e) {}

                renderPreview(el.value);
            } catch (e) {
                console.error(e);
            }
        },

        clearAll() {
            if (isDisabled) return;
            try {
                const el = document.getElementById(inputId);
                if (!el) return;

                el.value = isMultiple ? '[]' : '';
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));

                try {
                    if (typeof previewData === 'object' && previewData) {
                        if (Array.isArray(previewData.thumb)) {
                            try { previewData.thumb.splice(0, previewData.thumb.length); } catch (e) {}
                        }
                        if (Array.isArray(previewData.origin)) {
                            try { previewData.origin.splice(0, previewData.origin.length); } catch (e) {}
                        }
                        if (Array.isArray(previewData.alt)) {
                            try { previewData.alt.splice(0, previewData.alt.length); } catch (e) {}
                        }
                    }
                } catch (e) {}

                renderPreview(el.value);
            } catch (e) {
                console.error(e);
            }
        },
    };

    function renderPreview(value) {
        const el = document.getElementById(previewSelector);
        if (!el) return;

        // Clear existing content
        el.innerHTML = '';

        if (isMultiple) {
            const arr = parseValue(value) || [];
            if (!arr.length) { el.innerHTML = ''; return; }

            const wrapper = document.createElement('div');
            wrapper.style.display = 'flex';
            wrapper.style.flexWrap = 'wrap';
            wrapper.style.gap = '10px';

            // 在首次渲染时尝试把 previewData 中已有的 alt 同步回隐藏输入
            let hiddenEl = document.getElementById(inputId);
            let hiddenArr = [];
            let hiddenChanged = false;
            const allowBackfillAlt = !el._fm_altHydrated; // 仅首轮允许回填
            try { hiddenArr = JSON.parse(hiddenEl?.value || '[]') || []; } catch (e) { hiddenArr = []; }

            arr.forEach((v, idx) => {
                const pth = (v && typeof v === 'object') ? (v.path || '') : String(v || '');
                let altVal = (v && typeof v === 'object') ? (v.alt || '') : '';
                const name = String(pth).split('/').pop();

                // Find corresponding thumb/origin in previewData
                let thumbs = null;
                let origins = null;
                try {
                    if (previewData) {
                        if (Array.isArray(previewData)) {
                            const item = previewData[idx] || previewData.find(p => p && ((p.value === pth) || (p.value === String(pth))));
                            if (item) { thumbs = item.thumb || null; origins = item.origin || null; }
                        } else if (previewData.thumb && previewData.origin && Array.isArray(previewData.thumb) && Array.isArray(previewData.origin)) {
                            let idx2 = -1;
                            try {
                                const originsArr = Array.isArray(previewData.origin) ? previewData.origin : [];
                                const nPth = normalizePath(pth);
                                idx2 = originsArr.findIndex(o => String(o) === pth);
                                if (idx2 < 0) { idx2 = originsArr.findIndex(o => normalizePath(o) === nPth); }
                                if (idx2 < 0) {
                                    const base = nPth.split('/').pop();
                                    idx2 = originsArr.findIndex(o => String(o).split('/').pop() === base);
                                }
                                if (idx2 < 0) {
                                    const thumbsArr = Array.isArray(previewData.thumb) ? previewData.thumb : [];
                                    idx2 = thumbsArr.findIndex(t => String(t) === pth || normalizePath(t) === nPth || String(t).split('/').pop() === nPth.split('/').pop());
                                }
                                if (idx2 < 0 && Array.isArray(previewData.origin) && idx < previewData.origin.length) {
                                    idx2 = idx;
                                }
                            } catch (e) { idx2 = -1; }

                            const tArr = Array.isArray(previewData.thumb) ? previewData.thumb : [];
                            const oArr = Array.isArray(previewData.origin) ? previewData.origin : [];
                            thumbs = (idx2 >= 0 && idx2 < tArr.length) ? tArr[idx2] : (tArr[idx] || null);
                            origins = (idx2 >= 0 && idx2 < oArr.length) ? oArr[idx2] : (oArr[idx] || null);
                            const aArr = Array.isArray(previewData.alt) ? previewData.alt : [];
                            if (!altVal && aArr.length) {
                                altVal = (idx2 >= 0 && idx2 < aArr.length) ? (aArr[idx2] || '') : (aArr[idx] || '');
                            }
                        } else {
                            let entry = previewData[pth];
                            if (!entry) {
                                const nPth = normalizePath(pth);
                                const keys = Object.keys(previewData || {});
                                entry = keys.map(k => [k, previewData[k]]).find(([k]) => normalizePath(k) === nPth)?.[1];
                                if (!entry) {
                                    const base = nPth.split('/').pop();
                                    entry = keys.map(k => [k, previewData[k]]).find(([k]) => (normalizePath(k).split('/').pop() === base))?.[1];
                                }
                            }
                            if (entry) {
                                thumbs = entry.thumb || null;
                                origins = entry.origin || null;
                                if (Array.isArray(entry.alt)) { altVal = altVal || (entry.alt[0] || ''); }
                                else if (typeof entry.alt === 'string') { altVal = altVal || (entry.alt || ''); }
                            }
                        }
                    }
                } catch (e) {
                    console.error(e);
                }

                try {
                    if (hiddenEl) {
                        if (typeof hiddenArr[idx] === 'string') {
                            const upgraded = { path: hiddenArr[idx], alt: (altVal || '') };
                            hiddenArr[idx] = upgraded; hiddenChanged = true;
                        } else if (hiddenArr[idx] && typeof hiddenArr[idx] === 'object') {
                            const curPath = hiddenArr[idx].path || hiddenArr[idx].url || '';
                            const hasAltKey = Object.prototype.hasOwnProperty.call(hiddenArr[idx], 'alt');
                            if (!hasAltKey && altVal && (curPath === pth)) { hiddenArr[idx].alt = altVal; hiddenChanged = true; }
                        }
                    }
                } catch (e) {}

                let previewUrl = null;
                if (thumbs) previewUrl = Array.isArray(thumbs) ? thumbs[0] : thumbs;
                if (!previewUrl) {
                    const encoded = btoa(unescape(encodeURIComponent(String(pth)))).replace(/=/g, '');
                    previewUrl = /^https?:\/\//i.test(pth) || pth.startsWith('/')
                        ? pth
                        : (previewBase ? (previewBase.replace(/\/$/, '') + '/' + encoded) : ('/filament-filemanager/file-preview/' + encoded));
                }

                let downloadUrl = null;
                if (origins) downloadUrl = Array.isArray(origins) ? origins[0] : origins;
                if (downloadUrl) {
                    if (!/^https?:\/\//i.test(downloadUrl) && !downloadUrl.startsWith('/')) {
                        downloadUrl = downloadBase ? (downloadBase.replace(/\/$/, '') + '/' + encodeURIComponent(String(downloadUrl))) : ('/filament-filemanager/file-manager/download/' + encodeURIComponent(String(downloadUrl)));
                    }
                } else {
                    downloadUrl = downloadBase ? (downloadBase.replace(/\/$/, '') + '/' + encodeURIComponent(String(pth))) : ('/filament-filemanager/file-manager/download/' + encodeURIComponent(String(pth)));
                }

                const itemDiv = document.createElement('div');
                itemDiv.style.width = '132px';
                itemDiv.style.position = 'relative';
                itemDiv.style.border = '1px solid #e5e7eb';
                itemDiv.style.borderRadius = '10px';
                itemDiv.style.padding = '6px';
                itemDiv.style.background = '#fff';
                itemDiv.style.boxShadow = '0 1px 2px rgba(0,0,0,0.04)';

                const a = document.createElement('a');
                a.href = previewUrl;
                a.target = '_blank';
                a.style.display = 'block';

                const img = document.createElement('img');
                img.style.width = '120px';
                img.style.height = '90px';
                img.style.objectFit = 'cover';
                img.style.borderRadius = '8px';
                img.style.display = 'block';
                observeOrSetSrc(img, previewUrl);
                a.appendChild(img);
                itemDiv.appendChild(a);

                if (enableDownload) {
                    const dl = document.createElement('a');
                    dl.href = downloadUrl;
                    dl.target = '_blank';
                    dl.style.position = 'absolute';
                    dl.style.left = '6px';
                    dl.style.top = '6px';
                    dl.style.background = 'rgba(0,0,0,0.6)';
                    dl.style.color = '#fff';
                    dl.style.borderRadius = '6px';
                    dl.style.padding = '4px 6px';
                    dl.style.fontSize = '12px';
                    dl.style.textDecoration = 'none';
                    dl.textContent = '⬇';
                    itemDiv.appendChild(dl);
                }

                if (!isDisabled) {
                    const delBtn = document.createElement('button');
                    delBtn.type = 'button';
                    delBtn.textContent = '✕';
                    delBtn.className = 'fm-delete';
                    delBtn.setAttribute('data-idx', idx);
                    delBtn.style.position = 'absolute';
                    delBtn.style.top = '6px';
                    delBtn.style.right = '6px';
                    delBtn.style.background = 'rgba(0,0,0,0.6)';
                    delBtn.style.color = '#fff';
                    delBtn.style.border = '0';
                    delBtn.style.borderRadius = '6px';
                    delBtn.style.padding = '4px 6px';
                    delBtn.style.cursor = 'pointer';
                    itemDiv.appendChild(delBtn);
                }

                const altInput = document.createElement('input');
                altInput.type = 'text';
                altInput.placeholder = 'Alt text';
                altInput.value = altVal;
                altInput.style.marginTop = '6px';
                altInput.style.width = '100%';
                altInput.style.boxSizing = 'border-box';
                altInput.style.fontSize = '13px';
                altInput.style.padding = '6px 8px';
                altInput.style.border = '1px solid #e5e7eb';
                altInput.style.borderRadius = '8px';
                altInput.style.background = isDisabled ? '#f3f4f6' : '#fff';
                altInput.readOnly = isDisabled;
                altInput.disabled = isDisabled;
                if (!isDisabled) {
                    altInput.addEventListener('input', function() {
                        try { itemDiv.dataset.pendingAlt = altInput.value; } catch(e) {}
                    });
                    altInput.addEventListener('blur', function() {
                        try {
                            const el = document.getElementById(inputId);
                            if (!el) return;
                            let cur = [];
                            try { cur = JSON.parse(el.value || '[]') || []; } catch(e){ cur = []; }
                            if (!Array.isArray(cur)) cur = [];
                            const pending = itemDiv.dataset && itemDiv.dataset.pendingAlt !== undefined ? itemDiv.dataset.pendingAlt : altInput.value;
                            cur = cur.map((it, i) => {
                                if (typeof it === 'string') return { path: it, alt: (i===idx ? pending : '') };
                                return { path: (it.path || it.url || ''), alt: (i===idx ? pending : (it.alt || '')) };
                            });
                            el.value = JSON.stringify(cur);
                            el.dispatchEvent(new Event('input', { bubbles: true }));
                            el.dispatchEvent(new Event('change', { bubbles: true }));
                        } catch(e) {}
                    });
                }
                itemDiv.appendChild(altInput);

                const nameDiv = document.createElement('div');
                nameDiv.style.fontSize = '12px';
                nameDiv.style.overflow = 'hidden';
                nameDiv.style.textOverflow = 'ellipsis';
                nameDiv.style.whiteSpace = 'nowrap';
                nameDiv.textContent = name;
                itemDiv.appendChild(nameDiv);

                wrapper.appendChild(itemDiv);
            });

            try {
                if (hiddenChanged && hiddenEl) {
                    hiddenEl.value = JSON.stringify(hiddenArr);
                }
                el._fm_altHydrated = true;
            } catch (e) {}

            function moveArray(a, from, to) {
                const copy = a.slice();
                const it = copy.splice(from, 1)[0];
                copy.splice(to, 0, it);
                return copy;
            }

            if (!isDisabled) {
                Array.from(wrapper.children).forEach(function(ch, i){
                    ch.setAttribute('draggable', 'true');
                    ch.setAttribute('data-idx', i);
                    ch.addEventListener('dragstart', function(ev){
                        try { ev.dataTransfer.setData('text/plain', String(i)); } catch(e){}
                        ch.style.opacity = '0.5';
                        try {
                            const img = ch.querySelector('img');
                            if (img) {
                                const d = img.cloneNode(true);
                                d.style.width = '160px'; d.style.height = '120px'; d.style.objectFit = 'cover'; d.style.borderRadius = '8px';
                                d.style.position = 'absolute'; d.style.left = '-9999px';
                                document.body.appendChild(d);
                                try { ev.dataTransfer.setDragImage(d, 80, 60); } catch (e) {}
                                setTimeout(()=>{ try{ document.body.removeChild(d); }catch(e){} }, 0);
                            }
                        } catch (e) {}
                    });
                    ch.addEventListener('dragend', function(){ ch.style.opacity = ''; removeHighlights(); });
                });
            }

            function removeHighlights(){ Array.from(wrapper.querySelectorAll('[data-idx]')).forEach(function(c){ c.style.outline=''; c.style.boxShadow=''; }); }

            if (!isDisabled) {
                wrapper.addEventListener('dragover', function(ev){
                    ev.preventDefault();
                    try {
                        const targetEl = ev.target.closest && ev.target.closest('[data-idx]');
                        removeHighlights();
                        if (targetEl) {
                            targetEl.style.outline = '3px dashed rgba(37,99,235,0.9)';
                            targetEl.style.boxShadow = '0 6px 18px rgba(37,99,235,0.12)';
                        }
                    } catch (e) {}
                });
                wrapper.addEventListener('drop', function(ev){
                    ev.preventDefault();
                    try {
                        const from = parseInt(ev.dataTransfer.getData('text/plain'), 10);
                        if (Number.isNaN(from)) return;
                        const targetEl = ev.target.closest && ev.target.closest('[data-idx]');
                        let to = null;
                        if (targetEl) to = parseInt(targetEl.getAttribute('data-idx'), 10);
                        else to = wrapper.children.length - 1;
                        if (Number.isNaN(to) || from === to) return;

                        const inp = document.getElementById(inputId);
                        if (!inp) return;
                        let arrCur = [];
                        try { arrCur = JSON.parse(inp.value || '[]') || []; } catch(e){ arrCur = []; }
                        const newArr = moveArray(arrCur, from, to);
                        inp.value = JSON.stringify(newArr);
                        inp.dispatchEvent(new Event('input', { bubbles: true }));
                        inp.dispatchEvent(new Event('change', { bubbles: true }));

                        try {
                            if (previewData) {
                                if (Array.isArray(previewData)) {
                                    previewData = moveArray(previewData, from, to);
                                } else if (Array.isArray(previewData.thumb) && Array.isArray(previewData.origin)) {
                                    previewData.thumb = moveArray(previewData.thumb, from, to);
                                    previewData.origin = moveArray(previewData.origin, from, to);
                                    if (Array.isArray(previewData.alt)) {
                                        previewData.alt = moveArray(previewData.alt, from, to);
                                    }
                                }
                            }
                        } catch (e) { console.error(e); }

                        renderPreview(inp.value);
                    } catch (e) { console.error(e); }
                });
            }

            el.appendChild(wrapper);
            return;
        }

        // single
        if (!value) { el.innerHTML = ''; return; }

        let singleThumb = null;
        let singleOrigin = null;
        let singleAlt = '';
        if (previewData) {
            try {
                if (previewData.thumb && previewData.origin) {
                    singleThumb = Array.isArray(previewData.thumb) ? previewData.thumb[0] : previewData.thumb;
                    singleOrigin = Array.isArray(previewData.origin) ? previewData.origin[0] : previewData.origin;
                    if (previewData.alt) {
                        singleAlt = Array.isArray(previewData.alt) ? (previewData.alt[0] || '') : (previewData.alt || '');
                    }
                } else if (previewData.value === value) {
                    singleThumb = previewData.thumb || null;
                    singleOrigin = previewData.origin || null;
                    if (typeof previewData.alt === 'string') singleAlt = previewData.alt || '';
                }
            } catch (e) { console.error(e); }
        }

        try {
            const altHidden = document.getElementById(inputId + '_alt');
            if (altHidden && typeof altHidden.value === 'string' && altHidden.value) {
                singleAlt = altHidden.value;
            }
        } catch (e) {}

        const encoded = btoa(unescape(encodeURIComponent(String(value)))).replace(/=/g, '');
        const u = singleThumb
            ? (/^https?:\/\//i.test(singleThumb) || singleThumb.startsWith('/') ? singleThumb : (previewBase ? (previewBase.replace(/\/$/, '') + '/' + singleThumb) : ('/filament-filemanager/file-preview/' + encoded)))
            : (/^https?:\/\//i.test(value) || value.startsWith('/') ? value : (previewBase ? (previewBase.replace(/\/$/, '') + '/' + encoded) : ('/filament-filemanager/file-preview/' + encoded)));
        const name = String(value).split('/').pop();
        const downloadLink = (singleOrigin
            ? (/^https?:\/\//i.test(singleOrigin) || singleOrigin.startsWith('/') ? singleOrigin : (downloadBase ? (downloadBase.replace(/\/$/, '') + '/' + encodeURIComponent(String(singleOrigin))) : ('/filament-filemanager/file-manager/download/' + encodeURIComponent(String(singleOrigin)))))
            : (downloadBase ? (downloadBase.replace(/\/$/, '') + '/' + encodeURIComponent(String(value))) : ('/filament-filemanager/file-manager/download/' + encodeURIComponent(String(value)))));

        const box = document.createElement('div');
        box.style.maxWidth = '100%';
        box.style.background = '#fff';
        box.style.padding = '10px';
        box.style.borderRadius = '10px';
        box.style.border = '1px solid #e5e7eb';
        box.style.color = '#111827';
        box.style.position = 'relative';
        box.style.display = 'flex';
        box.style.flexDirection = 'column';
        box.style.alignItems = 'center';
        box.style.justifyContent = 'center';
        box.style.boxShadow = '0 1px 2px rgba(0,0,0,0.04)';

        const title = document.createElement('div');
        title.style.fontWeight = '600';
        title.style.marginBottom = '8px';
        title.style.textAlign = 'center';
        title.textContent = name;
        box.appendChild(title);

        const a2 = document.createElement('a');
        a2.href = u; a2.target = '_blank';
        const img2 = document.createElement('img');
        img2.style.maxWidth = '100%'; img2.style.maxHeight = '280px'; img2.style.borderRadius = '8px';
        img2.style.objectFit = 'contain';
        observeOrSetSrc(img2, u);
        a2.appendChild(img2);
        box.appendChild(a2);

        if (enableDownload) {
            const dl2 = document.createElement('a');
            dl2.href = downloadLink; dl2.target = '_blank';
            dl2.style.marginTop = '8px'; dl2.style.background = '#f3f4f6'; dl2.style.color = '#111827'; dl2.style.padding = '6px 10px'; dl2.style.borderRadius = '6px'; dl2.style.textDecoration = 'none';
            dl2.textContent = 'Download original';
            box.appendChild(dl2);
        }

        if (!isDisabled) {
            const clearBtn = document.createElement('button');
            clearBtn.type = 'button';
            clearBtn.textContent = 'Clear';
            clearBtn.className = 'fm-clear';
            clearBtn.style.position = 'absolute';
            clearBtn.style.top = '8px';
            clearBtn.style.right = '8px';
            clearBtn.style.background = 'rgba(0,0,0,0.6)';
            clearBtn.style.color = '#fff';
            clearBtn.style.border = '0';
            clearBtn.style.borderRadius = '6px';
            clearBtn.style.padding = '6px 8px';
            clearBtn.style.cursor = 'pointer';
            box.appendChild(clearBtn);

            clearBtn.addEventListener('click', function(){
                try {
                    const altHidden = document.getElementById(inputId + '_alt');
                    if (altHidden) altHidden.value = '';
                } catch (e) {}
            });
        }

        const altWrap = document.createElement('div');
        altWrap.style.marginTop = '10px';
        altWrap.style.width = '100%';
        const altInput = document.createElement('input');
        altInput.type = 'text';
        altInput.placeholder = 'Alt text';
        altInput.value = singleAlt || '';
        altInput.style.width = '100%';
        altInput.style.boxSizing = 'border-box';
        altInput.style.fontSize = '14px';
        altInput.style.padding = '8px 10px';
        altInput.style.border = '1px solid #e5e7eb';
        altInput.style.borderRadius = '8px';
        altInput.style.background = isDisabled ? '#f3f4f6' : '#fff';
        altInput.readOnly = isDisabled;
        altInput.disabled = isDisabled;
        if (!isDisabled) {
            altInput.addEventListener('input', function(){
                try {
                    const altHidden = document.getElementById(inputId + '_alt');
                    if (altHidden) { altHidden.value = altInput.value; }
                } catch (e) {}
            });
            altInput.addEventListener('blur', function(){
                try {
                    const altHidden = document.getElementById(inputId + '_alt');
                    if (altHidden) {
                        altHidden.value = altInput.value;
                        altHidden.dispatchEvent(new Event('input', { bubbles: true }));
                        altHidden.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                } catch (e) {}
            });
        }
        altWrap.appendChild(altInput);
        box.appendChild(altWrap);

        el.appendChild(box);
    }

    return {
        openPicker() {
            if (isDisabled) return;
            const instanceKey = jsId + '_' + (new Date().getTime()) + '_' + Math.floor(Math.random() * 100000);
            window.__fileManagerSelectCallbacks = window.__fileManagerSelectCallbacks || {};
            window.__fileManagerSelectCallbacks[instanceKey] = (payload) => {
                try {
                    if (isMultiple) {
                        let arr = [];
                        try { arr = JSON.parse(document.getElementById(inputId).value || '[]') || []; } catch (e) { arr = []; }
                        const payloads = Array.isArray(payload) ? payload : [payload];
                        payloads.forEach(p => {
                            const v = (p.path || p.url || p);
                            const alt = (p.alt || '');
                            const item = (typeof v === 'string') ? { path: v, alt: alt } : { path: String(v || ''), alt: alt };
                            const exists = Array.isArray(arr) && arr.some(it => (typeof it === 'string' ? it === v : (it && (it.path === v))));
                            if (v && !exists) arr.push(item);
                        });
                        document.getElementById(inputId).value = JSON.stringify(arr);
                    } else {
                        const v = (payload.path || payload.url || payload);
                        document.getElementById(inputId).value = v || '';
                        try {
                            const altHidden = document.getElementById(inputId + '_alt');
                            if (altHidden) altHidden.value = (payload.alt || '');
                        } catch (e) {}
                    }
                    document.getElementById(inputId).dispatchEvent(new Event('input', { bubbles: true }));
                    document.getElementById(inputId).dispatchEvent(new Event('change', { bubbles: true }));
                    renderPreview(document.getElementById(inputId).value);
                } catch (e) {
                    console.error(e);
                } finally {
                    try { delete window.__fileManagerSelectCallbacks[instanceKey]; } catch (e) {}
                }
            };
            let url = openUrl + (openUrl.indexOf('?') === -1 ? '?' : '&') + (isMultiple ? 'multiple=1' : '');
            try {
                const last = localStorage.getItem('ffm:lastPath');
                if (last) {
                    url += '&path=' + encodeURIComponent(last);
                }
            } catch (e) {}
            const urlWithCb = url + (url.indexOf('?') === -1 ? '?': '&') + 'cb=' + encodeURIComponent(instanceKey);
            window.open(urlWithCb, 'FileManager', 'width=900,height=600');
        },

        clear() {
            if (isDisabled) return;
            const el = document.getElementById(inputId);
            if (!el) return;
            el.value = isMultiple ? '[]' : '';
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            if (!isMultiple) {
                try {
                    const altHidden = document.getElementById(inputId + '_alt');
                    if (altHidden) altHidden.value = '';
                } catch (e) {}
            }
            renderPreview(el.value);
        },

        init() {
            const el = document.getElementById(inputId);
            if (!el) return;
            renderPreview(el.value);

            const observer = new MutationObserver(() => renderPreview(el.value));
            try { observer.observe(el, { attributes: true, childList: true, subtree: true }); } catch (e) {}

            try {
                const previewEl = document.getElementById(previewSelector);
                if (previewEl && !previewEl._fm_delegated) {
                    previewEl.addEventListener('click', function (ev) {
                        const btn = ev.target.closest && ev.target.closest('.fm-delete');
                        if (btn) {
                            ev.preventDefault();
                            const idx = parseInt(btn.getAttribute('data-idx'), 10);
                            if (!Number.isNaN(idx)) {
                                try { api.removeIndex(idx); } catch (e) { console.error(e); }
                            }
                            return;
                        }
                        const clearBtn = ev.target.closest && ev.target.closest('.fm-clear');
                        if (clearBtn) {
                            ev.preventDefault();
                            try { api.clearAll(); } catch (e) { console.error(e); }
                            return;
                        }
                    });
                    previewEl._fm_delegated = true;
                }
            } catch (e) { }

            try {
                const formEl = el.closest && el.closest('form');
                if (formEl && !formEl._fm_altSync) {
                    formEl.addEventListener('submit', function(){
                        try {
                            const previewBox = document.getElementById(previewSelector);
                            if (!previewBox) return;
                            if (isMultiple) {
                                const items = Array.from(previewBox.querySelectorAll('div[draggable="true"]'));
                                let cur = [];
                                try { cur = JSON.parse(el.value || '[]') || []; } catch (e) { cur = []; }
                                if (!Array.isArray(cur)) cur = [];
                                const rebuilt = items.map((node, i) => {
                                    const altInput = node.querySelector('input[type="text"]');
                                    const pending = (node.dataset && node.dataset.pendingAlt !== undefined) ? node.dataset.pendingAlt : (altInput ? altInput.value : '');
                                    const it = cur[i];
                                    if (typeof it === 'string') return { path: it, alt: pending || '' };
                                    return { path: (it?.path || it?.url || ''), alt: (pending || it?.alt || '') };
                                });
                                el.value = JSON.stringify(rebuilt);
                            } else {
                                const altHidden = document.getElementById(inputId + '_alt');
                                const singleAltInput = previewBox.querySelector('input[type="text"]');
                                if (altHidden && singleAltInput) {
                                    altHidden.value = singleAltInput.value || '';
                                }
                            }
                        } catch (e) {}
                    });
                    formEl._fm_altSync = true;
                }
            } catch (e) {}
        },
    };
};
</script>
@endscript

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data='(function(){ const opts = {!! $pickerOpts !!}; return fileManagerPickerUploadComponent(opts); })()'
        x-init="init()"
        wire:ignore
    >
        <div id="file-preview-{{ $getId() }}" style="margin-bottom:1rem;width:100%"></div>

        <div style="display:flex;gap:8px;align-items:center;">
            @if ($isDisabled)
                <button type="button" class="fi-btn" x-on:click="openPicker()" id="browse-btn-{{ $getId() }}" disabled style="opacity:.45;cursor:not-allowed;pointer-events:none;">Browse</button>
                <button type="button" class="fi-btn" x-on:click="clear()" disabled style="opacity:.45;cursor:not-allowed;pointer-events:none;">Clear</button>
            @else
                <button type="button" class="fi-btn" x-on:click="openPicker()" id="browse-btn-{{ $getId() }}">Browse</button>
                <button type="button" class="fi-btn" x-on:click="clear()">Clear</button>
            @endif
        </div>

        <input
            type="hidden"
            id="{{ $getId() }}"
            name="{{ $getName() }}"
            {{ $applyStateBindingModifiers('wire:model') }}="{{ $getStatePath() }}"
            value="{{ is_array($getState()) ? json_encode($getState(), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : ($getState() ?? '') }}"
        />
        @if (! $multiple)
            <input
                type="hidden"
                id="{{ $getId() }}_alt"
                name="{{ method_exists($field, 'getAltName') ? $field->getAltName() : ($getName() . '_alt') }}"
                {{ $applyStateBindingModifiers('wire:model') }}="{{ method_exists($field, 'getAltStatePath') ? $field->getAltStatePath() : ($getStatePath() . '_alt') }}"
                value=""
            />
        @endif
    </div>
</x-dynamic-component>
