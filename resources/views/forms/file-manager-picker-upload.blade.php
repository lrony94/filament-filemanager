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

    // Bounded-concurrency thumbnail loader. Each /file-thumb request may generate
    // the thumbnail on demand, holding a PHP-FPM worker for the whole request. With
    // only a handful of workers, letting the whole grid load at once saturates them
    // and starves the form's Save (Livewire POST) — the user then "can't save until
    // images load". Capping in-flight requests always leaves workers free for Save.
    const THUMB_MAX_CONCURRENT = 3;
    let __thumbActive = 0;
    const __thumbQueue = [];
    function pumpThumbs() {
        while (__thumbActive < THUMB_MAX_CONCURRENT && __thumbQueue.length) {
            const img = __thumbQueue.shift();
            if (!img || img._loaded || !img.dataset || !img.dataset.src) continue;
            const url = img.dataset.src;
            __thumbActive++;
            const release = function () {
                img.removeEventListener('load', release);
                img.removeEventListener('error', release);
                __thumbActive--;
                pumpThumbs();
            };
            img.addEventListener('load', release);
            img.addEventListener('error', release);
            img._loaded = true;
            delete img.dataset.src;
            img.src = url;
        }
    }
    function queueThumb(img) {
        __thumbQueue.push(img);
        pumpThumbs();
    }

    // 共享 IntersectionObserver，懒加载图片（经并发队列限流）
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
                                queueThumb(img);
                            }
                        } catch (err) {}
                        obs.unobserve(img);
                    }
                });
            }, { root: null, rootMargin: '200px', threshold: 0 });
        }
        return __io;
    }

    const FM_PLACEHOLDER = 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="90"><rect width="100%" height="100%" fill="#f3f4f6"/><text x="50%" y="50%" font-size="10" fill="#9ca3af" text-anchor="middle" dominant-baseline="middle">no preview</text></svg>');

    // Inject the loading-skeleton shimmer keyframes once.
    (function ensureSkelStyle(){
        try {
            if (typeof document !== 'undefined' && !document.getElementById('fm-skel-style')) {
                const st = document.createElement('style');
                st.id = 'fm-skel-style';
                st.textContent = '@keyframes fmShimmer{0%{background-position:100% 0}100%{background-position:0 0}}';
                document.head.appendChild(st);
            }
        } catch (e) {}
    })();

    // Lazy-load an image (IntersectionObserver), fading it in on load and removing
    // the loading skeleton. On failure show a lightweight placeholder — we never
    // fall back to the full-size source (that is what used to freeze the page).
    function observeOrSetSrc(img, url, skel) {
        try {
            img.loading = 'lazy';
            img.decoding = 'async';
        } catch (e) {}
        const done = () => { try { if (skel) skel.remove(); } catch (e) {} };
        img.addEventListener('load', function () { img.style.opacity = '1'; done(); });
        img.addEventListener('error', function onErr() {
            img.removeEventListener('error', onErr);
            done();
            if (img.src !== FM_PLACEHOLDER) { img.src = FM_PLACEHOLDER; img.style.opacity = '1'; }
        });
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

    function decodeDisplayName(value) {
        const name = String(value || '').split('/').pop() || '';
        try {
            return decodeURIComponent(name);
        } catch (e) {
            return name;
        }
    }

    function isSameStoredValue(left, right) {
        const leftValue = normalizePath(left);
        const rightValue = normalizePath(right);

        if (!leftValue || !rightValue) {
            return false;
        }

        if (leftValue === rightValue) {
            return true;
        }

        return decodeDisplayName(leftValue) === decodeDisplayName(rightValue);
    }

    function isExternalUrl(value) {
        const url = String(value || '').trim();
        return /^https?:\/\//i.test(url) || url.startsWith('//');
    }

    function isFileManagerRoute(value) {
        const url = String(value || '').trim();

        return url.startsWith('/filament-filemanager/')
            || /^https?:\/\/[^\/]+\/filament-filemanager\//i.test(url);
    }

    function normalizeLocalRoutePath(value) {
        if (!value) return '';

        try {
            let path = String(value).trim();
            if (!path) return '';

            if (/^https?:\/\//i.test(path)) {
                const parsed = new URL(path, window.location.origin);
                return parsed.pathname || '';
            }

            if (path.startsWith('//')) {
                const parsed = new URL(window.location.protocol + path);
                return parsed.pathname || '';
            }

            return path;
        } catch (e) {
            return String(value || '').trim();
        }
    }

    function buildPreviewLink(value) {
        if (!value) return null;

        const raw = String(value).trim();
        if (!raw) return null;

        // Respect already-resolved web paths from backend previewData thumb values.
        if (raw.startsWith('/') || isExternalUrl(raw) || isFileManagerRoute(raw)) {
            return raw;
        }

        const localPath = normalizeLocalRoutePath(raw);
        const encoded = btoa(unescape(encodeURIComponent(String(localPath)))).replace(/=/g, '');

        return previewBase
            ? (previewBase.replace(/\/$/, '') + '/' + encoded)
            : ('/filament-filemanager/file-preview/' + encoded);
    }

    function buildDownloadLink(value) {
        if (!value) return null;

        const raw = String(value).trim();
        if (!raw) return null;

        if (isExternalUrl(raw) || isFileManagerRoute(raw)) {
            return raw;
        }

        const localPath = normalizeLocalRoutePath(raw);

        return downloadBase
            ? (downloadBase.replace(/\/$/, '') + '/' + encodeURIComponent(String(localPath)))
            : ('/filament-filemanager/file-manager/download/' + encodeURIComponent(String(localPath)));
    }

    // Build a URL to the small, disk-cached thumbnail endpoint. The grid always
    // loads these (~10KB) instead of the full-size source, regardless of how large
    // the original is or whether a display variant has been generated yet.
    function buildThumbLink(value) {
        if (!value) return null;
        const raw = String(value).trim();
        if (!raw) return null;
        // External URLs can't be re-thumbnailed server-side; use as-is.
        if (isExternalUrl(raw)) return raw;
        let p = normalizeLocalRoutePath(raw);        // strip protocol/host
        p = p.replace(/^\/+/, '');                   // drop leading slash
        p = p.replace(/^(storage|public)\//i, '');   // drop public-disk web prefix
        const encoded = btoa(unescape(encodeURIComponent(String(p)))).replace(/=/g, '');
        return '/filament-filemanager/file-thumb/' + encoded;
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

    // Signature = ordered list of stored paths. Used so the MutationObserver only
    // does a full rebuild when items are actually added/removed — never on reorder.
    let __fmSig = null;
    function signatureOf(value) {
        try {
            const arr = parseValue(value) || [];
            if (!Array.isArray(arr)) return String(value || '');
            return JSON.stringify(arr.map(v => (v && typeof v === 'object') ? (v.path || v.url || '') : String(v || '')));
        } catch (e) { return String(value || ''); }
    }

    function renderPreview(value) {
        const el = document.getElementById(previewSelector);
        if (!el) return;

        __fmSig = signatureOf(value);

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
                const name = decodeDisplayName(pth);

                // Find corresponding thumb/origin in previewData
                let thumbs = null;
                let origins = null;
                try {
                    if (previewData) {
                        if (Array.isArray(previewData)) {
                            // Match by PATH only — never by positional index, or a
                            // re-selected item would inherit the previous item's image.
                            const item = previewData.find(p => p && ((p.value === pth) || (p.value === String(pth))));
                            if (item) { thumbs = item.thumb || null; origins = item.origin || null; }
                        } else if (previewData.thumb && previewData.origin && Array.isArray(previewData.thumb) && Array.isArray(previewData.origin)) {
                            let idx2 = -1;
                            try {
                                const originsArr = Array.isArray(previewData.origin) ? previewData.origin : [];
                                const nPth = normalizePath(pth);
                                idx2 = originsArr.findIndex(o => String(o) === pth);
                                if (idx2 < 0) { idx2 = originsArr.findIndex(o => normalizePath(o) === nPth); }
                                if (idx2 < 0) {
                                    const thumbsArr = Array.isArray(previewData.thumb) ? previewData.thumb : [];
                                    idx2 = thumbsArr.findIndex(t => String(t) === pth || normalizePath(t) === nPth);
                                }
                                // Match by FULL path only (exact or normalized). Basename
                                // matching was removed so two files with the same name in
                                // different folders never share a preview/thumbnail.
                                // NOTE: intentionally NO positional-index fallback here.
                                // previewData is built from the value at initial page load;
                                // after a clear + re-select the value changes but previewData
                                // is stale, so a positional match would show the previous
                                // image under the new name. If the path doesn't match, we let
                                // the item fall back to its own path's thumbnail below.
                            } catch (e) { idx2 = -1; }

                            const tArr = Array.isArray(previewData.thumb) ? previewData.thumb : [];
                            const oArr = Array.isArray(previewData.origin) ? previewData.origin : [];
                            thumbs = (idx2 >= 0 && idx2 < tArr.length) ? tArr[idx2] : null;
                            origins = (idx2 >= 0 && idx2 < oArr.length) ? oArr[idx2] : null;
                            const aArr = Array.isArray(previewData.alt) ? previewData.alt : [];
                            if (!altVal && aArr.length && idx2 >= 0 && idx2 < aArr.length) {
                                altVal = aArr[idx2] || '';
                            }
                        } else {
                            let entry = previewData[pth];
                            if (!entry) {
                                const nPth = normalizePath(pth);
                                const keys = Object.keys(previewData || {});
                                // Full-path match only — no basename fallback (see above).
                                entry = keys.map(k => [k, previewData[k]]).find(([k]) => normalizePath(k) === nPth)?.[1];
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
                    previewUrl = buildPreviewLink(pth);
                }

                let downloadUrl = null;
                if (origins) downloadUrl = Array.isArray(origins) ? origins[0] : origins;
                if (downloadUrl) {
                    downloadUrl = buildDownloadLink(downloadUrl) || downloadUrl;
                } else {
                    downloadUrl = buildDownloadLink(pth);
                }

                const itemDiv = document.createElement('div');
                itemDiv.style.width = '132px';
                itemDiv.style.position = 'relative';
                itemDiv.style.border = '1px solid #e5e7eb';
                itemDiv.style.borderRadius = '10px';
                itemDiv.style.padding = '6px';
                itemDiv.style.background = '#fff';
                itemDiv.style.boxShadow = '0 1px 2px rgba(0,0,0,0.04)';

                const originForImg = origins ? (Array.isArray(origins) ? origins[0] : origins) : pth;

                const a = document.createElement('a');
                a.href = buildPreviewLink(originForImg) || previewUrl;   // click opens the full image
                a.target = '_blank';
                a.style.display = 'block';
                a.style.position = 'relative';
                // Never let the link/image become the native drag source — otherwise a
                // not-yet-loaded card drags the <a> href (text/plain becomes the URL, so
                // drop's parseInt() is NaN and reorder is skipped). Only the card (itemDiv)
                // should be draggable, so drag works uniformly regardless of load state.
                a.draggable = false;

                // loading skeleton, shown until the (cached) thumbnail resolves
                const skel = document.createElement('div');
                skel.className = 'fm-skel';
                skel.style.cssText = 'position:absolute;inset:0;width:120px;height:90px;border-radius:8px;background:linear-gradient(90deg,#eeeeee 25%,#f6f6f6 37%,#eeeeee 63%);background-size:400% 100%;animation:fmShimmer 1.2s ease-in-out infinite;';
                a.appendChild(skel);

                const img = document.createElement('img');
                img.style.width = '120px';
                img.style.height = '90px';
                img.style.objectFit = 'cover';
                img.style.borderRadius = '8px';
                img.style.display = 'block';
                img.style.opacity = '0';
                img.style.transition = 'opacity .2s ease';
                img.draggable = false;
                // Always load a small, disk-cached thumbnail of the source — never the
                // full-size image. This is what keeps many/large photos from freezing.
                observeOrSetSrc(img, buildThumbLink(originForImg), skel);
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
                            // Use the card's LIVE position (it may have been reordered in place),
                            // not the stale render-time index, so alt is written to the right item.
                            const liveIdx = itemDiv.parentElement ? Array.prototype.indexOf.call(itemDiv.parentElement.children, itemDiv) : idx;
                            cur = cur.map((it, i) => {
                                if (typeof it === 'string') return { path: it, alt: (i===liveIdx ? pending : '') };
                                return { path: (it.path || it.url || ''), alt: (i===liveIdx ? pending : (it.alt || '')) };
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
                        try { ev.dataTransfer.setData('text/plain', String(ch.getAttribute('data-idx'))); } catch(e){}
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

            // Only iterate the item cards (direct children), and only the ones
            // currently highlighted, so this stays cheap during a drag.
            function removeHighlights(){ Array.from(wrapper.children).forEach(function(c){ if (c.style.outline) { c.style.outline=''; c.style.boxShadow=''; } }); }

            // Renumber the item cards (and their delete buttons) after an in-place move.
            function renumber(){
                Array.from(wrapper.children).forEach(function(n, i){
                    n.setAttribute('data-idx', i);
                    const del = n.querySelector('.fm-delete');
                    if (del) del.setAttribute('data-idx', i);
                });
            }

            if (!isDisabled) {
                let lastOver = null;
                wrapper.addEventListener('dragover', function(ev){
                    ev.preventDefault();
                    try {
                        const targetEl = ev.target.closest && ev.target.closest('[draggable="true"]');
                        // Throttle: only touch the DOM when the hovered card actually changes.
                        if (targetEl === lastOver) return;
                        removeHighlights();
                        lastOver = targetEl;
                        if (targetEl) {
                            targetEl.style.outline = '3px dashed rgba(37,99,235,0.9)';
                            targetEl.style.boxShadow = '0 6px 18px rgba(37,99,235,0.12)';
                        }
                    } catch (e) {}
                });
                wrapper.addEventListener('drop', function(ev){
                    ev.preventDefault();
                    lastOver = null;
                    try {
                        const from = parseInt(ev.dataTransfer.getData('text/plain'), 10);
                        if (Number.isNaN(from)) return;
                        const targetEl = ev.target.closest && ev.target.closest('[draggable="true"]');
                        let to = targetEl ? parseInt(targetEl.getAttribute('data-idx'), 10) : (wrapper.children.length - 1);
                        if (Number.isNaN(to) || from === to) { removeHighlights(); return; }

                        // 1) Update the hidden input (source of truth) and previewData order.
                        const inp = document.getElementById(inputId);
                        if (!inp) return;
                        let arrCur = [];
                        try { arrCur = JSON.parse(inp.value || '[]') || []; } catch(e){ arrCur = []; }
                        inp.value = JSON.stringify(moveArray(arrCur, from, to));
                        inp.dispatchEvent(new Event('input', { bubbles: true }));
                        inp.dispatchEvent(new Event('change', { bubbles: true }));
                        try {
                            if (previewData) {
                                if (Array.isArray(previewData)) {
                                    previewData = moveArray(previewData, from, to);
                                } else if (Array.isArray(previewData.thumb) && Array.isArray(previewData.origin)) {
                                    previewData.thumb = moveArray(previewData.thumb, from, to);
                                    previewData.origin = moveArray(previewData.origin, from, to);
                                    if (Array.isArray(previewData.alt)) previewData.alt = moveArray(previewData.alt, from, to);
                                }
                            }
                        } catch (e) { console.error(e); }

                        // 2) Move the DOM node in place — NO teardown/rebuild, NO image re-fetch.
                        //    This is what keeps reordering instant and flicker-free.
                        const nodes = Array.from(wrapper.children);
                        const moving = nodes[from];
                        const ref = nodes[to];
                        if (moving && ref) {
                            if (from < to) ref.after(moving); else ref.before(moving);
                        }
                        renumber();
                        removeHighlights();
                        // Keep the signature guard in sync so the observer doesn't rebuild.
                        try { __fmSig = signatureOf(inp.value); } catch (e) {}
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
                const previewValue = previewData.value || previewData.path || previewData.origin || null;
                const previewMatchesValue = previewValue ? isSameStoredValue(previewValue, value) : false;

                if (previewData.thumb && previewData.origin && previewMatchesValue) {
                    singleThumb = Array.isArray(previewData.thumb) ? previewData.thumb[0] : previewData.thumb;
                    singleOrigin = Array.isArray(previewData.origin) ? previewData.origin[0] : previewData.origin;
                    if (previewData.alt) {
                        singleAlt = Array.isArray(previewData.alt) ? (previewData.alt[0] || '') : (previewData.alt || '');
                    }
                } else if (previewData.value && isSameStoredValue(previewData.value, value)) {
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

        const u = singleThumb ? (buildPreviewLink(singleThumb) || buildPreviewLink(value)) : buildPreviewLink(value);
        const name = decodeDisplayName(value);
        const downloadLink = singleOrigin ? (buildDownloadLink(singleOrigin) || buildDownloadLink(value)) : buildDownloadLink(value);

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
        // Inline preview loads a small cached thumbnail (not the full original); the
        // wrapping link (a2.href = u) still opens the full image. A single image can
        // safely fall back to the full source if the thumbnail can't be produced
        // (unlike the multi grid, where that fallback is what caused the freeze).
        img2.loading = 'lazy'; img2.decoding = 'async';
        img2.style.opacity = '0'; img2.style.transition = 'opacity .2s ease';
        img2.addEventListener('load', function(){ img2.style.opacity = '1'; });
        let previewImgSrc = u;
        const thumbLink = buildThumbLink(singleOrigin || value);
        if (thumbLink && thumbLink.indexOf('/file-thumb/') !== -1) {
            previewImgSrc = thumbLink + '?w=480';
            img2.addEventListener('error', function onThumbErr(){
                img2.removeEventListener('error', onThumbErr);
                if (u && img2.getAttribute('src') !== u) { img2.src = u; }
                else { img2.style.opacity = '1'; }
            });
        }
        img2.src = previewImgSrc;
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

            __fmSig = signatureOf(el.value);
            let __obTimer = null;
            const observer = new MutationObserver(() => {
                if (__obTimer) clearTimeout(__obTimer);
                __obTimer = setTimeout(() => {
                    const sig = signatureOf(el.value);
                    if (sig === __fmSig) return;   // reorder / no-op → skip full rebuild
                    renderPreview(el.value);
                }, 50);
            });
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
