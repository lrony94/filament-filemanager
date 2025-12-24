@php
    $jsId = str_replace(['.', '[', ']', '-'], '_', $getId());
    $multiple = method_exists($field, 'isMultiple') ? $field->isMultiple() : false;
    $pickerOpts = json_encode([
        'jsId' => $jsId,
        'statePath' => $getStatePath(),
        'isMultiple' => $multiple,
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
        openUrl,
        previewSelector,
        inputId,
        previewBase,
        enableDownload,
        downloadBase,
        previewData,
    } = opts;

    function parseValue(v) {
        if (!v) return isMultiple ? [] : '';
        if (isMultiple) {
            try {
                return JSON.parse(v);
            } catch (e) {
                return [];
            }
        }
        return v;
    }

    // API methods (closure-scoped) — will be invoked via event delegation
    const api = {
        removeIndex(idx) {
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
                    }
                } catch (e) {}

                renderPreview(el.value);
            } catch (e) {
                console.error(e);
            }
        },

        clearAll() {
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

            arr.forEach((v, idx) => {
                const name = String(v).split('/').pop();

                // Find corresponding thumb/origin in previewData
                let thumbs = null;
                let origins = null;
                try {
                    if (previewData) {
                        if (Array.isArray(previewData)) {
                            const item = previewData[idx] || previewData.find(p => p && (p.value === v || p.value === String(v)));
                            if (item) { thumbs = item.thumb || null; origins = item.origin || null; }
                        } else if (previewData.thumb && previewData.origin && Array.isArray(previewData.thumb) && Array.isArray(previewData.origin)) {
                            thumbs = previewData.thumb[idx] || null;
                            origins = previewData.origin[idx] || null;
                        } else if (previewData[v]) {
                            thumbs = previewData[v].thumb || null;
                            origins = previewData[v].origin || null;
                        }
                    }
                } catch (e) {
                    console.error(e);
                }

                let previewUrl = null;
                if (thumbs) previewUrl = Array.isArray(thumbs) ? thumbs[0] : thumbs;
                if (!previewUrl) {
                    const encoded = btoa(unescape(encodeURIComponent(String(v)))).replace(/=/g, '');
                    previewUrl = /^https?:\/\//i.test(v) || v.startsWith('/')
                        ? v
                        : (previewBase ? (previewBase.replace(/\/$/, '') + '/' + encoded) : ('/filament-filemanager/file-preview/' + encoded));
                }

                let downloadUrl = null;
                if (origins) downloadUrl = Array.isArray(origins) ? origins[0] : origins;
                if (downloadUrl) {
                    if (!/^https?:\/\//i.test(downloadUrl) && !downloadUrl.startsWith('/')) {
                        downloadUrl = downloadBase ? (downloadBase.replace(/\/$/, '') + '/' + encodeURIComponent(String(downloadUrl))) : ('/filament-filemanager/file-manager/download/' + encodeURIComponent(String(downloadUrl)));
                    }
                } else {
                    downloadUrl = downloadBase ? (downloadBase.replace(/\/$/, '') + '/' + encodeURIComponent(String(v))) : ('/filament-filemanager/file-manager/download/' + encodeURIComponent(String(v)));
                }

                const itemDiv = document.createElement('div');
                itemDiv.style.width = '120px';
                itemDiv.style.position = 'relative';

                const a = document.createElement('a');
                a.href = previewUrl;
                a.target = '_blank';
                a.style.display = 'block';

                const img = document.createElement('img');
                img.src = previewUrl;
                img.style.width = '120px';
                img.style.height = '90px';
                img.style.objectFit = 'cover';
                img.style.borderRadius = '8px';
                img.style.display = 'block';
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

                const nameDiv = document.createElement('div');
                nameDiv.style.fontSize = '12px';
                nameDiv.style.overflow = 'hidden';
                nameDiv.style.textOverflow = 'ellipsis';
                nameDiv.style.whiteSpace = 'nowrap';
                nameDiv.textContent = name;
                itemDiv.appendChild(nameDiv);

                wrapper.appendChild(itemDiv);
            });

            // enable drag-and-drop reorder
            function moveArray(a, from, to) {
                const copy = a.slice();
                const it = copy.splice(from, 1)[0];
                copy.splice(to, 0, it);
                return copy;
            }

            Array.from(wrapper.children).forEach(function(ch, i){
                ch.setAttribute('draggable', 'true');
                ch.setAttribute('data-idx', i);
                ch.addEventListener('dragstart', function(ev){
                    try { ev.dataTransfer.setData('text/plain', String(i)); } catch(e){}
                    ch.style.opacity = '0.5';
                    // nice drag image
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

            function removeHighlights(){ Array.from(wrapper.querySelectorAll('[data-idx]')).forEach(function(c){ c.style.outline=''; c.style.boxShadow=''; }); }

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

                    // reorder input array
                    const inp = document.getElementById(inputId);
                    if (!inp) return;
                    let arrCur = [];
                    try { arrCur = JSON.parse(inp.value || '[]') || []; } catch(e){ arrCur = []; }
                    const newArr = moveArray(arrCur, from, to);
                    inp.value = JSON.stringify(newArr);
                    inp.dispatchEvent(new Event('input', { bubbles: true }));
                    inp.dispatchEvent(new Event('change', { bubbles: true }));

                    // reorder previewData when applicable
                    try {
                        if (previewData) {
                            if (Array.isArray(previewData)) {
                                previewData = moveArray(previewData, from, to);
                            } else if (Array.isArray(previewData.thumb) && Array.isArray(previewData.origin)) {
                                previewData.thumb = moveArray(previewData.thumb, from, to);
                                previewData.origin = moveArray(previewData.origin, from, to);
                            }
                        }
                    } catch (e) { console.error(e); }

                    // re-render
                    renderPreview(inp.value);
                } catch (e) { console.error(e); }
            });

            el.appendChild(wrapper);
            return;
        }

        // single
        if (!value) { el.innerHTML = ''; return; }

        let singleThumb = null;
        let singleOrigin = null;
        if (previewData) {
            try {
                if (previewData.thumb && previewData.origin) {
                    singleThumb = Array.isArray(previewData.thumb) ? previewData.thumb[0] : previewData.thumb;
                    singleOrigin = Array.isArray(previewData.origin) ? previewData.origin[0] : previewData.origin;
                } else if (previewData.value === value) {
                    singleThumb = previewData.thumb || null;
                    singleOrigin = previewData.origin || null;
                }
            } catch (e) { console.error(e); }
        }

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
        box.style.background = '#222';
        box.style.padding = '8px';
        box.style.borderRadius = '8px';
        box.style.color = '#fff';
        box.style.position = 'relative';
        box.style.display = 'flex';
        box.style.flexDirection = 'column';
        box.style.alignItems = 'center';
        box.style.justifyContent = 'center';

        const title = document.createElement('div');
        title.style.fontWeight = '600';
        title.style.marginBottom = '8px';
        title.style.textAlign = 'center';
        title.textContent = name;
        box.appendChild(title);

        const a2 = document.createElement('a');
        a2.href = u; a2.target = '_blank';
        const img2 = document.createElement('img');
        img2.src = u; img2.style.maxWidth = '100%'; img2.style.maxHeight = '240px'; img2.style.borderRadius = '6px';
        a2.appendChild(img2);
        box.appendChild(a2);

        if (enableDownload) {
            const dl2 = document.createElement('a');
            dl2.href = downloadLink; dl2.target = '_blank';
            dl2.style.marginTop = '8px'; dl2.style.background = '#fff'; dl2.style.color = '#000'; dl2.style.padding = '6px 10px'; dl2.style.borderRadius = '6px'; dl2.style.textDecoration = 'none';
            dl2.textContent = 'Download original';
            box.appendChild(dl2);
        }

        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.textContent = 'Clear';
        clearBtn.className = 'fm-clear';
        clearBtn.style.position = 'absolute';
        clearBtn.style.top = '8px';
        clearBtn.style.right = '8px';
        clearBtn.style.background = 'rgba(255,255,255,0.08)';
        clearBtn.style.color = '#fff';
        clearBtn.style.border = '0';
        clearBtn.style.borderRadius = '6px';
        clearBtn.style.padding = '6px 8px';
        clearBtn.style.cursor = 'pointer';
        box.appendChild(clearBtn);

        el.appendChild(box);
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
            // try to pass last visited path to file manager (non-destructive)
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
            try { observer.observe(el, { attributes: true, childList: true, subtree: true }); } catch (e) {}

            // attach event delegation handlers to preview container so buttons
            // can be simple elements (TALL-friendly) and closures can handle logic
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
            } catch (e) { /* ignore delegation errors */ }
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
            <button type="button" class="fi-btn" x-on:click="openPicker()" id="browse-btn-{{ $getId() }}">Browse</button>
            <button type="button" class="fi-btn" x-on:click="clear()">Clear</button>
        </div>

        <input
            type="hidden"
            id="{{ $getId() }}"
            name="{{ $getName() }}"
            {{ $applyStateBindingModifiers('wire:model') }}="{{ $getStatePath() }}"
            value="{{ is_array($getState()) ? json_encode($getState(), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : ($getState() ?? '') }}"
        />
    </div>
</x-dynamic-component>

