<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Manager</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 16px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
        }

        .card {
            border: 0;
            border-radius: 8px;
            padding: 0;
            text-align: center;
            cursor: pointer;
        }

        .thumb {
            width: 100%;
            height: 160px;
            object-fit: cover;
            border-radius: 6px;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .name {
            font-size: 14px;
            margin-top: 10px;
            color: #111827;
            word-break: break-word;
        }

        .empty {
            color: #6b7280;
            text-align: center;
            margin-top: 24px;
        }

        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        h1 {
            font-size: 16px;
            margin: 0;
        }

        button {
            background: #111827;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 8px 12px;
            cursor: pointer;
        }

        .toolbar {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        input[type=file] {
            display: none;
        }

        .upload-label {
            border: 1px solid #e5e7eb;
            background: #fff;
            color: #111827;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
        }

        .breadcrumbs {
            display: flex;
            gap: 6px;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .crumb {
            color: #2563eb;
            text-decoration: none;
            cursor: pointer;
        }

        .crumb-sep {
            color: #6b7280;
        }

        .row-actions {
            margin-top: 6px;
            display: flex;
            gap: 6px;
            justify-content: center;
        }

        .muted {
            color: #6b7280;
            font-size: 12px;
        }

        .bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
        }

        .search {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 8px 10px;
            width: 260px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th, .table td {
            padding: 10px;
            text-align: left;
        }

        .right {
            text-align: right;
        }

        .table tr {
            border-bottom: 1px solid #e5e7eb;
        }

        .view-toggle {
            border: 1px solid #e5e7eb;
            padding: 6px 10px;
            border-radius: 6px;
            background: #fff;
            color: #111827;
        }

        .plus-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: none;
            background: #2563eb;
            color: #fff;
            font-size: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dropdown {
            position: relative;
        }

        .menu {
            display: none;
            position: absolute;
            right: 0;
            top: 42px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            min-width: 200px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, .08);
            z-index: 20;
            overflow: hidden;
        }

        .menu button {
            width: 100%;
            text-align: left;
            padding: 10px 12px;
            background: #fff;
            border: none;
            display: flex;
            gap: 8px;
            align-items: center;
            color: #111827;
        }

        .menu button:hover {
            background: #eef2ff;
        }

        .selection-bar {
            display: none;
            position: sticky;
            top: 0;
            z-index: 15;
            background: #111827;
            color: #fff;
            border-bottom: 1px solid #111827;
            padding: 10px 12px;
            margin: -16px;
            margin-bottom: 12px;
            align-items: center;
            justify-content: space-between;
        }

        .selection-left {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            color: #fff;
        }

        .icon-btn {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
        }

        .row-selected {
            background: #eef2ff; /* lighter highlight */
            color: #111827;
        }

        .row-selected td {
            color: #111827;
        }

        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            left: 0; right: 0; top: 0; bottom: 0;
            background: rgba(255, 255, 255, 0.75);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .loading-box { display: flex; flex-direction: column; align-items: center; gap: 10px; }
        .loading-spinner {
            width: 36px; height: 36px; border-radius: 50%;
            border: 4px solid #e5e7eb; border-top-color: #2563eb;
            animation: spin 0.9s linear infinite;
        }
        .loading-text { color: #111827; font-weight: 600; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        /* Upload progress bar */
        .upload-progress-wrap { width: 220px; background: #e5e7eb; border-radius: 4px; height: 6px; display: none; }
        .upload-progress-bar { height: 100%; background: #2563eb; border-radius: 4px; transition: width 0.25s ease; width: 0%; }
        /* Toast */
        .ffm-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: #111827; color: #fff; padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 500; z-index: 2000; opacity: 0; transition: opacity 0.3s; pointer-events: none; white-space: nowrap; }
        .ffm-toast.show { opacity: 1; }
        /* Drag-and-drop overlay */
        .ffm-drag-overlay { position: fixed; inset: 0; background: rgba(37,99,235,0.08); border: 3px dashed #2563eb; z-index: 900; display: none; align-items: center; justify-content: center; pointer-events: none; box-sizing: border-box; }
        .ffm-drag-overlay.active { display: flex; }
        .ffm-drag-label { font-size: 24px; font-weight: 700; color: #2563eb; }
    </style>
</head>
<body>
<div id="ffmDragOverlay" class="ffm-drag-overlay"><span class="ffm-drag-label">⬆️ Drop files to upload</span></div>
<div id="ffmToast" class="ffm-toast"></div>
<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-box">
        <div class="loading-spinner"></div>
        <div id="loadingText" class="loading-text">Loading...</div>
        <div id="ffmProgressWrap" class="upload-progress-wrap"><div id="ffmProgressBar" class="upload-progress-bar"></div></div>
    </div>
</div>
<header>
    <h1>Files</h1>
    <div class="toolbar">
        <input id="search" class="search" placeholder="Search" oninput="ffmSearch()"/>
        <button class="view-toggle" onclick="toggleView()" id="viewBtn">List</button>
        <div class="dropdown">
            <button class="plus-btn" onclick="toggleAddMenu(event)">+</button>
            <div id="addMenu" class="menu">
                <button onclick="document.getElementById('picker').click(); hideAddMenu()">⬆️ Upload file</button>
                <button onclick="promptCreateFolder(); hideAddMenu()">➕ Create folder</button>
                @php
                    $ffmMaxMb = number_format(config('filament-filemanager.max_file_size', 10240) / 1024, 0);
                    $ffmMimes = implode(', ', config('filament-filemanager.allowed_mimes', []));
                @endphp
                <p style="margin:4px 8px 2px;font-size:11px;color:#6b7280;line-height:1.4;pointer-events:none;">
                    Max {{ $ffmMaxMb }} MB{{ $ffmMimes ? ' · ' . $ffmMimes : '' }}
                </p>
            </div>
        </div>
        @php $ffmAccept = array_map(fn($e) => '.'.$e, config('filament-filemanager.allowed_mimes', [])); @endphp
        <input id="picker" type="file" multiple @if($ffmAccept) accept="{{ implode(',', $ffmAccept) }}" @endif />
        <!-- <input id="altInput" type="text" placeholder="Alt text (optional)" style="border:1px solid #e5e7eb;border-radius:6px;padding:8px 10px;width:260px;" /> -->
    </div>
</header>
<div id="selectionBar" class="selection-bar">
    <div class="selection-left">
        <span id="selCount">0</span> Selected
    </div>
    <div style="display:flex; align-items:center; gap:10px;">
        <button class="view-toggle" style="background:#dc2626;color:#fff;border-color:#b91c1c" onclick="bulkDelete()">
            Delete
        </button>
        <button class="view-toggle" onclick="unselectAll()">Unselect All</button>
        <button id="useSelectedBtn" class="view-toggle" style="display:none;background:#16a34a;color:#fff;border-color:#15803d" onclick="useSelected()">Use Selected</button>
    </div>
</div>
<div class="breadcrumbs">
    <a class="crumb" href="#" onclick="goTo('')">root</a>
    @if(!empty($breadcrumbs))
        @foreach($breadcrumbs as $b)
            <span class="crumb-sep">/</span>
            <a class="crumb" href="#" onclick="goTo(@js($b['path']))">{{ $b['name'] }}</a>
        @endforeach
    @endif
    @if(!empty($path))
        <span class="muted">(current: /{{ $path }})</span>
    @endif
</div>


<div id="filesGrid" class="grid" style="display:none;"></div>
<table id="filesTable" class="table">
    <thead>
    <tr>
        <th style="width:34px"><input type="checkbox" onclick="toggleAll(this)"/></th>
        <th>Name</th>
        <th class="right" style="width:140px">Size</th>
        <th style="width:220px">Last modified</th>
        <th style="width:60px;" class="right">List</th>
    </tr>
    </thead>
    <tbody id="filesTbody"></tbody>
</table>
<div id="ffmLoadMore" style="display:none; justify-content:center; padding:20px 0 8px;">
    <button class="view-toggle" onclick="loadMoreItems()" style="padding:10px 32px; font-size:14px;">Load more</button>
</div>

<script>
    function showLoading(text, progress) {
        try {
            const o = document.getElementById('loadingOverlay');
            const t = document.getElementById('loadingText');
            const pw = document.getElementById('ffmProgressWrap');
            const pb = document.getElementById('ffmProgressBar');
            if (t && typeof text === 'string') t.textContent = text;
            if (o) o.style.display = 'flex';
            if (pw && pb) {
                if (progress != null) {
                    pw.style.display = 'block';
                    pb.style.width = Math.max(0, Math.min(100, progress)) + '%';
                } else {
                    pw.style.display = 'none';
                }
            }
        } catch (e) {}
    }
    function hideLoading() {
        try {
            const o = document.getElementById('loadingOverlay');
            const pw = document.getElementById('ffmProgressWrap');
            if (o) o.style.display = 'none';
            if (pw) pw.style.display = 'none';
        } catch (e) {}
    }
    function showToast(msg, duration) {
        try {
            const t = document.getElementById('ffmToast');
            if (!t) return;
            t.textContent = msg;
            t.classList.add('show');
            clearTimeout(t._ffmTimer);
            t._ffmTimer = setTimeout(() => t.classList.remove('show'), duration || 2500);
        } catch (e) {}
    }
    function selectFile(payload) {
        // normalize payload to object { url, path }
        const data = (typeof payload === 'string') ? {url: payload} : (payload || {});
        // Persist parent folder of the selected file (or current folder as fallback)
        try {
            const p = (data.path || '').toString();
            const parent = p ? normalizePath(p).split('/').slice(0, -1).join('/') : normalizePath(currentPath || '');
            localStorage.setItem('ffm:lastPath', parent);
        } catch (e) {}
        // Check if opened from Filament (popup)
            if (window.opener) {
                // Prefer per-instance callback map when provided via cb URL parameter
                try {
                    const urlParams = new URLSearchParams(window.location.search || '');
                    const cb = urlParams.get('cb');
                    if (cb && window.opener.__fileManagerSelectCallbacks && typeof window.opener.__fileManagerSelectCallbacks[cb] === 'function') {
                        try { window.opener.__fileManagerSelectCallbacks[cb](data); } catch (e) { /* ignore */ }
                        try { window.opener.postMessage({ fileManagerSelected: data }, '*'); } catch (e) { /* ignore */ }
                        window.close();
                        return;
                    }
                } catch (e) {
                    // ignore parsing errors and fallback to legacy
                }

                // Legacy single-callback support
                if (window.opener.__fileManagerSelectCallback) {
                    try {
                        window.opener.__fileManagerSelectCallback(data);
                    } catch (_) {}
                    try { window.opener.postMessage({ fileManagerSelected: data }, '*'); } catch (_) {}
                    window.close();
                    return;
                }
            }
        // Check if opened in an iframe (for Filament modal)
        if (window.self !== window.top) {
            window.parent.postMessage({
                fileManagerSelected: data
            }, '*');
            return;
        }
        // Fallback for other cases (e.g., TinyMCE legacy expects url)
        window.parent.postMessage({
            mceAction: 'fileSelected',
            url: data.url || data.path || ''
        }, '*');
    }

    const currentPath = @js($path ?? '');
    const currentDisk = @js($disk ?? 'local');
    const ffmAllowedExts = @json(config('filament-filemanager.allowed_mimes', []));
    const ffmMaxKb = {{ (int) config('filament-filemanager.max_file_size', 0) }};
    const isMultipleMode = (new URL(window.location.href)).searchParams.get('multiple') === '1';
    // Remember last visited path on load for next openings. If this opener has no path, try restoring saved path.
    try {
        const urlObj = new URL(window.location.href);
        const restoredFlag = urlObj.searchParams.get('restored');
        const saved = localStorage.getItem('ffm:lastPath');
        if ((!currentPath || String(currentPath) === '') && saved) {
            // avoid redirect loops by using a temporary flag
            if (restoredFlag !== '1') {
                urlObj.searchParams.set('path', saved);
                urlObj.searchParams.set('restored', '1');
                window.location.href = urlObj.toString();
            }
        } else if (currentPath) {
            try { localStorage.setItem('ffm:lastPath', (currentPath || '')); } catch (e) {}
        }
    } catch (e) {}

    function goTo(path) {
        const url = new URL(window.location.href);
        if (path) url.searchParams.set('path', normalizePath(path)); else url.searchParams.delete('path');
        // Persist target path before navigation
        try { localStorage.setItem('ffm:lastPath', normalizePath(path || '')); } catch (e) {}
        showLoading('Loading folder...');
        window.location.href = url.toString();
    }

    function normalizePath(p) {
        const s = String(p || '');
        return s
            .replace(/\\/g, '/')       // backslashes -> slashes
            .replace(/\/+?/g, '/')      // collapse multiple slashes
            .replace(/^\/+|\/+$/g, ''); // trim leading/trailing slashes
    }

    async function renameItem(path, isDir) {
        const newName = prompt('New name');
        if (!newName) return;
        const normPath = normalizePath(path);
        const res = await fetch(`{{ route('filament-filemanager.rename') }}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            credentials: 'same-origin',
            body: JSON.stringify({path: normPath, name: newName}),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || json.ok === false) {
            alert(json.error || 'Rename failed');
            return;
        }
        // Update local arrays without full reload
        const parent = normalizePath(normPath).split('/').slice(0, -1).join('/');
        const updatedName = json.name || newName;
        const updatedPath = normalizePath((parent ? parent + '/' : '') + updatedName);
        if (isDir) {
            const d = (initialDirs || []).find(x => normalizePath(x.path) === normPath);
            if (d) {
                d.name = updatedName;
                d.path = updatedPath;
            }
        } else {
            const f = (initialFiles || []).find(x => normalizePath(x.path) === normPath);
            if (f) {
                f.name = updatedName;
                f.path = updatedPath;
            }
        }
        // Update selection and rerender
        if (selected.has(normPath)) {
            selected.delete(normPath);
            selected.add(updatedPath);
        }
        render();
        updateSelectionUI();
    }

    async function removeItem(path) {
        if (!confirm('Are you sure you want to delete this?')) return;
        const normPath = normalizePath(path);

        console.group('Delete Operation');
        console.log('Path to delete:', normPath);

        try {
            // Show loading state
            const deleteButton = document.querySelector(`[onclick*="removeItem('${path.replace(/"/g, '\"').replace(/'/g, "\\'")}')"]`);
            const originalText = deleteButton?.textContent;
            if (deleteButton) {
                deleteButton.disabled = true;
                deleteButton.textContent = 'Deleting...';
            }

            // Build the URL with the path as a query parameter
            const url = new URL('{{ route('filament-filemanager.delete') }}');
            url.searchParams.append('path', normPath);

            console.log('Sending DELETE request to:', url.toString());

            const response = await fetch(url, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                cache: 'no-cache',
                referrerPolicy: 'no-referrer'
            });

            console.log('Response status:', response.status, response.statusText);

            let json;
            try {
                json = await response.json();
                console.log('Response JSON:', json);
            } catch (e) {
                console.error('Failed to parse JSON response:', e);
                throw new Error('Invalid server response');
            }

            if (!response.ok || (json && json.ok === false)) {
                throw new Error(json?.error || `Delete failed with status ${response.status}`);
            }

            // Only update UI if the deletion was successful
            initialFiles = (initialFiles || []).filter(x => normalizePath(x.path) !== normPath);
            if (Array.isArray(initialDirs)) {
                initialDirs = initialDirs.filter(d => normalizePath(d.path) !== normPath);
            }
            if (selected.has(normPath)) selected.delete(normPath);

            render();
            updateSelectionUI();

            console.log('Delete successful');

        } catch (error) {
            console.error('Delete error:', error);
            alert(`Delete error: ${error.message}`);

            // Re-render to ensure UI consistency
            render();
            updateSelectionUI();
        } finally {
            // Restore button state
            const deleteButton = document.querySelector(`[onclick*="removeItem('${path.replace(/"/g, '\\\"').replace(/'/g, "\\'")}')"]`);
            if (deleteButton) {
                deleteButton.disabled = false;
                if (originalText) deleteButton.textContent = originalText;
            }
            console.groupEnd();
        }
    }

    // hydrate data from blade to JS
    let initialFiles = @js($files ?? []);
    let initialDirs = @js($dirs ?? []);
    const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
    let view = 'list';
    let selected = new Set();
    const clickTimers = Object.create(null);

    function toggleView() {
        view = view === 'list' ? 'grid' : 'list';
        document.getElementById('viewBtn').textContent = view === 'list' ? 'Grid' : 'List';
        render();
    }

    function formatSize(bytes) {
        if (!bytes) return '—';
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0;
        let n = bytes;
        while (n >= 1024 && i < units.length - 1) {
            n /= 1024;
            i++;
        }
        return `${n.toFixed(i ? 1 : 0)} ${units[i]}`;
    }

    function formatTime(ts) {
        if (!ts) return '';
        try {
            return new Date(ts * 1000).toLocaleString();
        } catch {
            return '';
        }
    }

    const FFM_PAGE_SIZE = 50;
    let ffmRenderedCount = FFM_PAGE_SIZE;

    function ffmSearch() {
        ffmRenderedCount = FFM_PAGE_SIZE;
        render();
    }

    function loadMoreItems() {
        ffmRenderedCount += FFM_PAGE_SIZE;
        render();
    }

    function render() {
        const q = (document.getElementById('search')?.value || '').toLowerCase();
        const folders = initialDirs
            .map(d => ({...d, path: normalizePath(d.path)}))
            .filter(d => !q || d.name.toLowerCase().includes(q))
            .map(d => ({...d, _type: 'dir'}));
        const files = initialFiles
            .map(f => ({...f, path: normalizePath(f.path)}))
            .filter(f => !q || f.name.toLowerCase().includes(q))
            .map(f => ({...f, _type: 'file'}));
        const data = [...folders, ...files];
        const visible = data.slice(0, ffmRenderedCount);
        const remaining = data.length - visible.length;
        const ffmLm = document.getElementById('ffmLoadMore');
        if (ffmLm) {
            ffmLm.style.display = remaining > 0 ? 'flex' : 'none';
            const ffmLmBtn = ffmLm.querySelector('button');
            if (ffmLmBtn) ffmLmBtn.textContent = 'Load ' + Math.min(FFM_PAGE_SIZE, remaining) + ' more  (' + visible.length + ' / ' + data.length + ')';
        }

        const table = document.getElementById('filesTable');
        const grid = document.getElementById('filesGrid');
        const body = document.getElementById('filesTbody');
        if (view === 'list') {
            table.style.display = '';
            grid.style.display = 'none';
            body.innerHTML = '';
            visible.forEach(f => {
                const tr = document.createElement('tr');
                const isImg = f._type === 'file' && imageExts.includes(f.ext);
                const icon = f._type === 'dir'
                    ? `<span style="display:inline-flex;width:28px;height:28px;border-radius:4px;align-items:center;justify-content:center;margin-right:8px;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24"><path fill="#1d4ed8" d="M10 4l2 2h6a2 2 0 012 2v9a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h4z"/></svg></span>`
                    : (isImg ? `<img src="/filament-filemanager/file-preview/${base64url(f.path)}" loading="lazy" style="width:28px;height:28px;object-fit:cover;border-radius:4px;margin-right:8px;">` : `<span style="display:inline-flex;width:28px;height:28px;border:1px solid #e5e7eb;border-radius:4px;align-items:center;justify-content:center;margin-right:8px;">📄</span>`);
                const mid = base64Id(f.path);
                const checkboxCell = f._type === 'file' ? `<input type=\"checkbox\" data-type=\"file\" data-path=\"${f.path}\" ${selected.has(f.path) ? 'checked' : ''}>` : '';
        tr.innerHTML = `
            <td>${checkboxCell}</td>
            <td style=\"display:flex;align-items:center;\">${icon}<span style=\"cursor:pointer;color:#111827;\" onclick=\"onItemClick('${f._type}','${(f.path || '').replace(/'/g, "\\'")}','${(f.url || '').replace(/'/g, "\\'")}')\" ondblclick=\"onItemDblClick('${f._type}','${(f.path || '').replace(/'/g, "\\'")}','${(f.url || '').replace(/'/g, "\\'")}')\">${f.name}</span></td>
                        <td class=\"right\">${f._type === 'dir' ? '—' : formatSize(f.size)}</td>
                        <td>${formatTime(f.mtime)}</td>
                        <td class=\"right\">
                            <div style=\"position:relative;display:inline-block;\">\n
                                <button class=\"view-toggle\" onclick=\"toggleRowMenu(event,'${mid}')\">⋯</button>
                                <div id=\"${mid}\" class=\"menu\" style=\"right:-6px; top:32px;\">\n
                                    <button onclick=\"renameItem('${f.path}', ${f._type === 'dir' ? 'true' : 'false'}); hideMenus()\">Rename</button>
                                    <button onclick=\"removeItem('${f.path}'); hideMenus()\">Delete</button>
                                </div>
                            </div>
                        </td>`;
                if (selected.has(f.path)) tr.classList.add('row-selected');
                body.appendChild(tr);
            });
            // attach checkbox listeners (files only)
            body.querySelectorAll('input[type="checkbox"][data-type="file"]').forEach(cb => {
                cb.addEventListener('change', (e) => {
                    const p = normalizePath(e.target.getAttribute('data-path'));
                    if (e.target.checked) selected.add(p); else selected.delete(p);
                    updateSelectionUI();
                });
            });
        } else {
            table.style.display = 'none';
            grid.style.display = '';
            grid.innerHTML = '';
            visible.forEach(f => {
                const card = document.createElement('div');
                card.className = 'card';
                card.onclick = () => onItemClick(f._type, f.path, f.url);
                card.ondblclick = () => onItemDblClick(f._type, f.path, f.url);
                const isImg = f._type === 'file' && imageExts.includes(f.ext);
                const content = f._type === 'dir'
                    ? `<div class="thumb"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="96" height="72"><path fill="#1d4ed8" d="M10 4l2 2h6a2 2 0 012 2v9a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h4z"/></svg></div>`
                    : (isImg ? `<img class="thumb" loading="lazy" src="/filament-filemanager/file-preview/${base64url(f.path)}">` : `<div class="thumb"><span style="display:inline-flex;width:64px;height:64px;border:1px solid #e5e7eb;border-radius:8px;align-items:center;justify-content:center;">📄</span></div>`);
                card.innerHTML = `
                        ${content}
                        <div class="name">${f.name}</div>
                    `;
                grid.appendChild(card);
            });
        }
    }

    render();
    updateSelectionUI();

    function updateSelectionUI() {
        const bar = document.getElementById('selectionBar');
        const count = selected.size;
        document.getElementById('selCount').textContent = count;
        bar.style.display = count ? 'flex' : 'none';
        document.getElementById('useSelectedBtn').style.display = (isMultipleMode && count) ? 'inline-block' : 'none';
        // re-render to update row highlight
        const q = (document.getElementById('search')?.value || '').toLowerCase();
        const folders = initialDirs
            .map(d => ({...d, path: normalizePath(d.path)}))
            .filter(d => !q || d.name.toLowerCase().includes(q))
            .map(d => ({...d, _type: 'dir'}));
        const files = initialFiles
            .map(f => ({...f, path: normalizePath(f.path)}))
            .filter(f => !q || f.name.toLowerCase().includes(q))
            .map(f => ({...f, _type: 'file'}));
        const data = [...folders, ...files];
        const tbody = document.getElementById('filesTbody');
        tbody.querySelectorAll('tr').forEach((tr, idx) => {
            const p = data[idx]?.path;
            if (!p) return;
            tr.classList.toggle('row-selected', selected.has(p));
        });
    }

    function unselectAll() {
        selected.clear();
        updateSelectionUI();
        render();
    }

    function toggleSelect(path, url) {
        const p = normalizePath(path);
        if (selected.has(p)) selected.delete(p); else selected.add(p);
        updateSelectionUI();
    }

    function useSelected() {
        if (!selected.size) return;
        const payloads = Array.from(selected).map(p => {
            const f = (initialFiles.find(f=>normalizePath(f.path)===normalizePath(p))||{});
            return { path: p, url: f.url || '', alt: (f.alt || '') };
        });
        // Persist current folder as last path for future openings
        try { localStorage.setItem('ffm:lastPath', normalizePath(currentPath || '')); } catch (e) {}
        if (window.opener) {
            try {
                const urlParams = new URLSearchParams(window.location.search || '');
                const cb = urlParams.get('cb');
                if (cb && window.opener.__fileManagerSelectCallbacks && typeof window.opener.__fileManagerSelectCallbacks[cb] === 'function') {
                    try { window.opener.__fileManagerSelectCallbacks[cb](payloads); } catch (e) { /* ignore */ }
                    try { window.opener.postMessage({ fileManagerSelected: payloads }, '*'); } catch (e) { /* ignore */ }
                    window.close();
                    return;
                }
            } catch (e) { /* ignore */ }

            if (window.opener.__fileManagerSelectCallback) {
                try { window.opener.__fileManagerSelectCallback(payloads); } catch (_) {}
                try { window.opener.postMessage({ fileManagerSelected: payloads }, '*'); } catch (_) {}
                window.close();
                return;
            }
        }
        if (window.self !== window.top) {
            window.parent.postMessage({ fileManagerSelected: payloads }, '*');
            return;
        }
        window.parent.postMessage({ mceAction: 'fileSelected', url: (payloads[0]||{}).url || '' }, '*');
    }

    async function bulkDelete() {
        if (!selected.size) return;
        if (!confirm(`Are you sure you want to delete ${selected.size} selected items?`)) return;

        const items = Array.from(selected);

        // Optimistic UI update - remove items immediately
        const normPaths = items.map(p => normalizePath(p));
        initialFiles = (initialFiles || []).filter(x => !normPaths.includes(normalizePath(x.path)));
        if (Array.isArray(initialDirs)) {
            initialDirs = initialDirs.filter(d => !normPaths.includes(normalizePath(d.path)));
        }
        selected.clear();
        render();
        updateSelectionUI();

        // Process deletions in background
        for (const p of items) {
            try {
                const url = new URL('{{ route('filament-filemanager.delete') }}');
                url.searchParams.append('path', normalizePath(p));

                const res = await fetch(url, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                });

                const json = await res.json().catch(() => ({}));

                if (!res.ok || json.ok === false) {
                    console.error(`Failed to delete ${p}:`, json.error || 'Unknown error');
                }

            } catch (error) {
                console.error('Bulk delete error:', error);
            }
        }
    }

    function toggleAll(master) {
        document.querySelectorAll('#filesTbody input[type="checkbox"][data-type="file"]').forEach(cb => {
            cb.checked = master.checked;
            const p = normalizePath(cb.getAttribute('data-path'));
            if (master.checked) selected.add(p); else selected.delete(p);
        });
        updateSelectionUI();
    }

    function onItemClick(type, path, url) {
        const p = normalizePath(path || '');
        if (type === 'dir') { goTo(p); return; }

        if (!isMultipleMode) {
            // 单选：立即回填
            selectFile({ url: url, path: p });
            return;
        }

        // 多选：延时触发，若发生双击则取消该延时
        if (clickTimers[p]) { clearTimeout(clickTimers[p]); }
        clickTimers[p] = setTimeout(() => {
            toggleSelect(p, url);
            delete clickTimers[p];
        }, 220);
    }

    function onItemDblClick(type, path, url) {
        const p = normalizePath(path || '');
        // 取消等待中的单击动作
        if (clickTimers[p]) { clearTimeout(clickTimers[p]); delete clickTimers[p]; }
        if (type === 'dir') { goTo(p); return; }
        // 直接回填
        selectFile({ url: url, path: p });
    }

    function toggleAddMenu(e) {
        e.stopPropagation();
        const menu = document.getElementById('addMenu');
        menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        document.addEventListener('click', hideAddMenu, {once: true});
    }

    function hideAddMenu() {
        const menu = document.getElementById('addMenu');
        if (menu) menu.style.display = 'none';
    }

    function toggleRowMenu(e, id) {
        e.stopPropagation();
        hideMenus();
        const el = document.getElementById(id);
        if (el) el.style.display = 'block';
        document.addEventListener('click', hideMenus, {once: true});
    }

    function hideMenus() {
        document.querySelectorAll('.menu').forEach(m => {
            // keep the addMenu state unchanged; only close row menus here if needed
            if (m.id && m.id.startsWith('rm_')) m.style.display = 'none';
        });
    }

    function promptCreateFolder() {
        const name = prompt('Folder name');
        if (!name) return;
        fetch(`{{ route('filament-filemanager.folder') }}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            credentials: 'same-origin',
            body: JSON.stringify({name, path: normalizePath(currentPath)}),
        }).then(() => goTo(currentPath));
    }

    function base64Id(str) {
        try {
            return 'rm_' + btoa(unescape(encodeURIComponent(str))).replace(/=/g, '');
        } catch (_) {
            return 'rm_' + Math.random().toString(36).slice(2);
        }
    }

    function base64url(str) {
        try {
            return btoa(unescape(encodeURIComponent(str))).replace(/=/g, '').replace(/\+/g, '-').replace(/\//g, '_');
        } catch (_) {
            return Math.random().toString(36).slice(2);
        }
    }

    const picker = document.getElementById('picker');
    const altInput = document.getElementById('altInput');

    async function uploadFiles(files) {
        files = Array.from(files);
        if (!files.length) return;

        const altText = (altInput?.value || '').trim();
        const uploaded = [];
        const errors = [];

        // Validate all files first, collect errors in batch
        const validFiles = files.filter(file => {
            if (ffmAllowedExts.length) {
                const ext = file.name.split('.').pop().toLowerCase();
                if (!ffmAllowedExts.includes(ext)) {
                    errors.push(file.name + ': unsupported type');
                    return false;
                }
            }
            if (ffmMaxKb && file.size > ffmMaxKb * 1024) {
                errors.push(file.name + ': exceeds ' + (ffmMaxKb / 1024).toFixed(1) + ' MB');
                return false;
            }
            return true;
        });

        if (errors.length) {
            const hint = ffmAllowedExts.length ? '\n\nAllowed: ' + ffmAllowedExts.join(', ') : '';
            alert('Cannot upload the following files:\n\n' + errors.join('\n') + hint);
            if (!validFiles.length) return;
        }

        const total = validFiles.length;
        for (let i = 0; i < total; i++) {
            const file = validFiles[i];
            const form = new FormData();
            form.append('file', file);
            form.append('path', normalizePath(currentPath));
            try {
                showLoading(
                    total > 1 ? `Uploading ${i + 1} / ${total}...` : 'Uploading...',
                    total > 1 ? Math.round((i / total) * 100) : null
                );
                const res = await fetch(`{{ route('filament-filemanager.upload') }}`, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    credentials: 'same-origin',
                    body: form,
                });
                let data = {};
                try { data = await res.json(); } catch (_) { data = {}; }
                if (!res.ok || data?.ok === false) {
                    const msg = (data && data.error) ? data.error
                        : (data && data.errors?.file?.[0]) ? data.errors.file[0]
                        : (data && data.message) ? data.message : 'Upload failed';
                    errors.push(file.name + ': ' + msg);
                    continue;
                }
                const entry = {
                    name: data.name, url: data.url, ext: data.ext,
                    path: normalizePath(data.path), size: data.size, mtime: data.mtime, alt: altText,
                };
                initialFiles.unshift(entry);
                uploaded.push(entry);
                if (isMultipleMode) selected.add(normalizePath(data.path));
            } catch (err) {
                errors.push(file.name + ': Network error');
                console.error(err);
            }
        }

        hideLoading();
        if (altInput) altInput.value = '';

        if (!uploaded.length) {
            if (errors.length) alert('Upload failed:\n\n' + errors.join('\n'));
            return;
        }

        render();

        if (!isMultipleMode) {
            const last = uploaded[uploaded.length - 1];
            selectFile({url: last.url, path: last.path, alt: altText});
        } else {
            updateSelectionUI();
            showToast('Uploaded ' + uploaded.length + (uploaded.length === 1 ? ' file' : ' files') + ' successfully');
        }

        if (errors.length) {
            setTimeout(() => alert('Some files failed to upload:\n\n' + errors.join('\n')), 300);
        }
    }

    if (picker) {
        picker.addEventListener('change', async (e) => {
            const files = Array.from(e.target.files || []);
            e.target.value = '';
            await uploadFiles(files);
        });
    }

    // Drag-and-drop upload
    const ffmDragOverlay = document.getElementById('ffmDragOverlay');
    let ffmDragCounter = 0;
    document.addEventListener('dragenter', (e) => {
        if (!e.dataTransfer || !e.dataTransfer.types.includes('Files')) return;
        e.preventDefault();
        ffmDragCounter++;
        if (ffmDragOverlay) ffmDragOverlay.classList.add('active');
    });
    document.addEventListener('dragleave', () => {
        ffmDragCounter = Math.max(0, ffmDragCounter - 1);
        if (ffmDragCounter === 0 && ffmDragOverlay) ffmDragOverlay.classList.remove('active');
    });
    document.addEventListener('dragover', (e) => { e.preventDefault(); });
    document.addEventListener('drop', async (e) => {
        e.preventDefault();
        ffmDragCounter = 0;
        if (ffmDragOverlay) ffmDragOverlay.classList.remove('active');
        const files = Array.from(e.dataTransfer.files || []);
        if (files.length) await uploadFiles(files);
    });
</script>
</body>
</html>
