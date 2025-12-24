window.fileManagerPickerUploadComponent = function (opts) {
    const { jsId, statePath, isMultiple, openUrl, previewSelector, inputId } = opts;

    function parseValue(v) {
        if (!v) return isMultiple ? [] : '';
        if (isMultiple) {
            try { return JSON.parse(v); } catch (e) { return []; }
        }
        return v;
    }

    function renderPreview(value) {
        const el = document.getElementById(previewSelector);
        if (!el) return;
        if (isMultiple) {
            const arr = parseValue(value) || [];
            if (!arr.length) { el.innerHTML = ''; return; }

            // helper to move array items
            function move(arr, from, to) {
                const a = arr.slice();
                const item = a.splice(from, 1)[0];
                a.splice(to, 0, item);
                return a;
            }

            const wrapper = document.createElement('div');
            wrapper.style.display = 'flex';
            wrapper.style.flexWrap = 'wrap';
            wrapper.style.gap = '10px';

            arr.forEach((v, idx) => {
                const raw = v == null ? '' : v;
                const s = String(raw);
                const u = /^https?:\/\//i.test(s) || s.startsWith('/') ? s : ('/filament-filemanager/file-preview/' + btoa(unescape(encodeURIComponent(s))).replace(/=/g, ''));
                const name = s.split('/').pop();

                const itemDiv = document.createElement('div');
                itemDiv.style.width = '120px';
                itemDiv.style.position = 'relative';
                itemDiv.setAttribute('draggable', 'true');
                itemDiv.setAttribute('data-idx', idx);

                const img = document.createElement('img');
                img.src = u;
                img.style.width = '120px';
                img.style.height = '90px';
                img.style.objectFit = 'cover';
                img.style.borderRadius = '8px';
                img.style.display = 'block';
                itemDiv.appendChild(img);

                const nameDiv = document.createElement('div');
                nameDiv.style.fontSize = '12px';
                nameDiv.style.overflow = 'hidden';
                nameDiv.style.textOverflow = 'ellipsis';
                nameDiv.style.whiteSpace = 'nowrap';
                nameDiv.textContent = name;
                itemDiv.appendChild(nameDiv);

                // drag event handlers
                itemDiv.addEventListener('dragstart', function (ev) {
                    try { ev.dataTransfer.setData('text/plain', String(idx)); } catch (e) {}
                    itemDiv.style.opacity = '0.5';
                    // try to set a nicer drag image (clone the image)
                    try {
                        const dragImg = img.cloneNode(true);
                        dragImg.style.width = '160px';
                        dragImg.style.height = '120px';
                        dragImg.style.objectFit = 'cover';
                        dragImg.style.borderRadius = '8px';
                        // offscreen container
                        dragImg.style.position = 'absolute';
                        dragImg.style.left = '-9999px';
                        document.body.appendChild(dragImg);
                        ev.dataTransfer.setDragImage(dragImg, 80, 60);
                        // cleanup after a tick
                        setTimeout(() => { try { document.body.removeChild(dragImg); } catch (e) {} }, 0);
                    } catch (e) {}
                });
                itemDiv.addEventListener('dragend', function () { itemDiv.style.opacity = ''; removeHighlights(); });

                wrapper.appendChild(itemDiv);
            });

            // allow dropping to reorder and provide visual feedback
            function removeHighlights() {
                Array.from(wrapper.querySelectorAll('[data-idx]')).forEach(function(c){ c.style.outline = ''; c.style.boxShadow = ''; });
            }

            wrapper.addEventListener('dragover', function (ev) {
                ev.preventDefault();
                // highlight potential target
                try {
                    const targetEl = ev.target.closest && ev.target.closest('[data-idx]');
                    removeHighlights();
                    if (targetEl) {
                        targetEl.style.outline = '3px dashed rgba(37,99,235,0.9)';
                        targetEl.style.boxShadow = '0 6px 18px rgba(37,99,235,0.12)';
                    }
                } catch (e) {}
            });
            wrapper.addEventListener('dragleave', function (ev) { try { /* keep until next over or drop */ } catch (e) {} });
            wrapper.addEventListener('drop', function (ev) {
                ev.preventDefault();
                try {
                    const from = parseInt(ev.dataTransfer.getData('text/plain'), 10);
                    if (Number.isNaN(from)) return;
                    // determine target index from drop location
                    const targetEl = ev.target.closest && ev.target.closest('[data-idx]');
                    let to = null;
                    if (targetEl) {
                        to = parseInt(targetEl.getAttribute('data-idx'), 10);
                    } else {
                        to = arr.length - 1;
                    }
                    if (to === null || Number.isNaN(to)) return;
                    if (from === to) return;
                    const newArr = move(arr, from, to);
                    const inp = document.getElementById(inputId);
                    if (!inp) return;
                    inp.value = JSON.stringify(newArr);
                    inp.dispatchEvent(new Event('input', { bubbles: true }));
                    inp.dispatchEvent(new Event('change', { bubbles: true }));
                    // re-render with new order
                    removeHighlights();
                    renderPreview(inp.value);
                } catch (e) {
                    console.error(e);
                }
            });

            el.innerHTML = '';
            el.appendChild(wrapper);
            return;
        }
        if (!value) { el.innerHTML = ''; return; }
        const rawVal = value == null ? '' : value;
        const sVal = String(rawVal);
        const u = /^https?:\/\//i.test(sVal) || sVal.startsWith('/') ? sVal : ('/filament-filemanager/file-preview/' + btoa(unescape(encodeURIComponent(sVal))).replace(/=/g, ''));
        const name = sVal.split('/').pop();
        el.innerHTML = `<div style="max-width:100%;background:#222;padding:8px;border-radius:8px;color:#fff"><div style="font-weight:600">${name}</div><img src="${u}" style="max-width:100%;max-height:240px;margin-top:8px;border-radius:6px"/></div>`;
    }

    return {
        openPicker() {
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
                            if (v && !arr.includes(v)) arr.push(v);
                        });
                        document.getElementById(inputId).value = JSON.stringify(arr);
                    } else {
                        const v = (payload.path || payload.url || payload);
                        document.getElementById(inputId).value = v || '';
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
            // append last visited path from localStorage if available (non-destructive)
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
            const el = document.getElementById(inputId);
            if (!el) return;
            el.value = isMultiple ? '[]' : '';
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            renderPreview(el.value);
        },
        init() {
            const el = document.getElementById(inputId);
            if (!el) return;
            renderPreview(el.value);
            // hookup livewire updates if necessary
            const observer = new MutationObserver(() => renderPreview(el.value));
            try { observer.observe(el, { attributes: true, childList: true, subtree: true }) } catch (e) {}
        }
    };
};
