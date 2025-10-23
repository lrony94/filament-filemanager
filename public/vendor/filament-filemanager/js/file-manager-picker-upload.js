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
            let html = '<div style="display:flex;flex-wrap:wrap;gap:10px;">';
            arr.forEach((v, idx) => {
                const u = /^https?:\/\//i.test(v) || v.startsWith('/') ? v : ('/filament-filemanager/file-preview/' + btoa(unescape(encodeURIComponent(String(v)))).replace(/=/g, ''));
                const name = String(v).split('/').pop();
                html += `<div style="width:120px">` +
                    `<img src="${u}" style="width:120px;height:90px;object-fit:cover;border-radius:8px;display:block;">` +
                    `<div style="font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${name}</div>` +
                    `</div>`;
            });
            html += '</div>';
            el.innerHTML = html;
            return;
        }
        if (!value) { el.innerHTML = ''; return; }
        const u = /^https?:\/\//i.test(value) || value.startsWith('/') ? value : ('/filament-filemanager/file-preview/' + btoa(unescape(encodeURIComponent(String(value)))).replace(/=/g, ''));
        const name = String(value).split('/').pop();
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
            const url = openUrl + (openUrl.indexOf('?') === -1 ? '?' : '&') + (isMultiple ? 'multiple=1' : '');
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
