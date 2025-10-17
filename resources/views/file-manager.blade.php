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
            background: #111827;
            color: #fff;
        }

        .row-selected td {
            color: #fff;
        }
    </style>
</head>
<body>
<header>
    <h1>Files</h1>
    <div class="toolbar">
        <input id="search" class="search" placeholder="Search" oninput="render()"/>
        <button class="view-toggle" onclick="toggleView()" id="viewBtn">List</button>
        <div class="dropdown">
            <button class="plus-btn" onclick="toggleAddMenu(event)">+</button>
            <div id="addMenu" class="menu">
                <button onclick="document.getElementById('picker').click(); hideAddMenu()">⬆️ Upload file</button>
                <button onclick="promptCreateFolder(); hideAddMenu()">➕ Create folder</button>
            </div>
        </div>
        <input id="picker" type="file"/>
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

<script>
    function selectFile(payload) {
        // normalize payload to object { url, path }
        const data = (typeof payload === 'string') ? {url: payload} : (payload || {});
        // Check if opened from Filament (popup)
        if (window.opener && window.opener.__fileManagerSelectCallback) {
            try {
                window.opener.__fileManagerSelectCallback(data);
            } catch (_) {}
            // Also try postMessage to opener for robustness
            try {
                window.opener.postMessage({ fileManagerSelected: data }, '*');
            } catch (_) {}
            window.close();
            return;
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

    function goTo(path) {
        const url = new URL(window.location.href);
        if (path) url.searchParams.set('path', normalizePath(path)); else url.searchParams.delete('path');
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

        const table = document.getElementById('filesTable');
        const grid = document.getElementById('filesGrid');
        const body = document.getElementById('filesTbody');
        if (view === 'list') {
            table.style.display = '';
            grid.style.display = 'none';
            body.innerHTML = '';
            data.forEach(f => {
                const tr = document.createElement('tr');
                const isImg = f._type === 'file' && imageExts.includes(f.ext);
                const icon = f._type === 'dir'
                    ? `<span style="display:inline-flex;width:28px;height:28px;border-radius:4px;align-items:center;justify-content:center;margin-right:8px;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24"><path fill="#1d4ed8" d="M10 4l2 2h6a2 2 0 012 2v9a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h4z"/></svg></span>`
                    : (isImg ? `<img src="/filament-filemanager/file-preview/${base64url(f.path)}" style="width:28px;height:28px;object-fit:cover;border-radius:4px;margin-right:8px;">` : `<span style="display:inline-flex;width:28px;height:28px;border:1px solid #e5e7eb;border-radius:4px;align-items:center;justify-content:center;margin-right:8px;">📄</span>`);
                const mid = base64Id(f.path);
                tr.innerHTML = `
                        <td><input type=\"checkbox\" data-path=\"${f.path}\" ${selected.has(f.path) ? 'checked' : ''}></td>
                        <td style=\"display:flex;align-items:center;\">${icon}<span style=\"cursor:pointer;color:#111827;\" onclick=\"${f._type === 'dir' ? `goTo('${f.path}')` : `selectFile({url: '${(f.url || '').replace(/'/g, "\\'")}', path: '${(f.path || '').replace(/'/g, "\\'")}'})`}\">${f.name}</span></td>
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
            // attach checkbox listeners
            body.querySelectorAll('input[type="checkbox"]').forEach(cb => {
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
            data.forEach(f => {
                const card = document.createElement('div');
                card.className = 'card';
                card.onclick = () => f._type === 'dir' ? goTo(f.path) : selectFile({url: f.url, path: f.path});
                const isImg = f._type === 'file' && imageExts.includes(f.ext);
                const content = f._type === 'dir'
                    ? `<div class="thumb"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="96" height="72"><path fill="#1d4ed8" d="M10 4l2 2h6a2 2 0 012 2v9a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h4z"/></svg></div>`
                    : (isImg ? `<img class="thumb" src="/filament-filemanager/file-preview/${base64url(f.path)}">` : `<div class="thumb"><span style="display:inline-flex;width:64px;height:64px;border:1px solid #e5e7eb;border-radius:8px;align-items:center;justify-content:center;">📄</span></div>`);
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
        document.querySelectorAll('#filesTbody input[type="checkbox"]').forEach(cb => {
            cb.checked = master.checked;
            const p = normalizePath(cb.getAttribute('data-path'));
            if (master.checked) selected.add(p); else selected.delete(p);
        });
        updateSelectionUI();
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
    if (picker) {
        picker.addEventListener('change', async (e) => {
            const file = e.target.files?.[0];
            if (!file) return;
            const form = new FormData();
            form.append('file', file);
            form.append('path', normalizePath(currentPath));
            try {
                const res = await fetch(`{{ route('filament-filemanager.upload') }}`, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    credentials: 'same-origin',
                    body: form,
                });
                if (!res.ok) throw new Error('Upload failed');
                const data = await res.json();
                // add to list immediately
                initialFiles.unshift({
                    name: data.name,
                    url: data.url,
                    ext: data.ext,
                    path: normalizePath(data.path),
                    size: data.size,
                    mtime: data.mtime,
                });
                render();
                // and insert to editor immediately
                selectFile({url: data.url, path: data.path});
            } catch (err) {
                alert('Upload failed');
                console.error(err);
            } finally {
                e.target.value = '';
            }
        });
    }
</script>
</body>
</html>
