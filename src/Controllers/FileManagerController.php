<?php

namespace Lrony94\FilamentFileManager\Controllers;

use Lrony94\FilamentFileManager\Http\Middleware\AccessPanelPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FileManagerController
{
    public static function routes()
    {
        Route::middleware(['web', 'auth'])
            ->prefix('filament-filemanager')
            ->name('filament-filemanager.')
            ->group(function () {
                Route::get('/file-manager', [self::class, 'index'])->name('file-manager');
                Route::post('/file-manager/upload', [self::class, 'upload'])->name('upload');
                Route::post('/file-manager/rename', [self::class, 'rename'])->name('rename');
                Route::post('/file-manager/folder', [self::class, 'folder'])->name('folder');
                Route::delete('/file-manager/file', [self::class, 'delete'])->name('delete');
                Route::get('/file-manager/serve/{path}', [self::class, 'serveFile'])->where('path', '.*')->name('file-manager.serve');
                Route::get('/file-manager/download/{path}', [self::class, 'downloadFile'])->where('path', '.*')->name('file-manager.download');
                Route::get('/file-preview/{encodedPath}', [self::class, 'previewFile'])->where('encodedPath', '.*')->name('filament-filemanager.file-preview');

            });
    }

    protected function normalizeRelativePath(?string $path): string
    {
        $normalized = str_replace(['..', '\\'], ['.', '/'], (string) $path);
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;

        return trim($normalized, '/');
    }

    protected function decodeRelativePath(?string $path): string
    {
        $normalized = $this->normalizeRelativePath($path);

        if ($normalized === '') {
            return '';
        }

        return implode('/', array_map(
            static fn (string $segment): string => rawurldecode($segment),
            array_filter(explode('/', $normalized), static fn (string $segment): bool => $segment !== '')
        ));
    }

    protected function encodeRelativePath(?string $path): string
    {
        $normalized = $this->normalizeRelativePath($path);

        if ($normalized === '') {
            return '';
        }

        return implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            array_filter(explode('/', $normalized), static fn (string $segment): bool => $segment !== '')
        ));
    }

    protected function buildAbsolutePath(string $root, string $relativePath): string
    {
        return $relativePath === ''
            ? $root
            : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    protected function getConfiguredPathResolvers(string $disk): array
    {
        $configuredResolvers = config('filament-filemanager.path_resolvers', []);
        $fallbackRoot = Storage::disk($disk)->path('');

        if (!is_array($configuredResolvers) || $configuredResolvers === []) {
            return [[
                'root' => $fallbackRoot,
                'prefixes' => [''],
            ]];
        }

        $resolvers = [];
        foreach ($configuredResolvers as $resolver) {
            if (!is_array($resolver)) {
                continue;
            }

            $root = isset($resolver['root']) ? (string) $resolver['root'] : '';
            if ($root === '' && !empty($resolver['disk'])) {
                try {
                    $root = Storage::disk((string) $resolver['disk'])->path('');
                } catch (\Throwable $_) {
                    $root = '';
                }
            }

            if ($root === '') {
                continue;
            }

            $prefixes = $resolver['prefixes'] ?? [''];
            if (!is_array($prefixes)) {
                $prefixes = [$prefixes];
            }

            $normalizedPrefixes = array_values(array_unique(array_map(
                static fn ($prefix): string => trim(str_replace('\\', '/', (string) $prefix), '/'),
                $prefixes,
            )));

            if ($normalizedPrefixes === []) {
                $normalizedPrefixes = [''];
            }

            $resolvers[] = [
                'root' => rtrim($root, DIRECTORY_SEPARATOR),
                'prefixes' => $normalizedPrefixes,
            ];
        }

        return $resolvers !== [] ? $resolvers : [[
            'root' => $fallbackRoot,
            'prefixes' => [''],
        ]];
    }

    protected function stripResolverPrefix(string $candidate, string $prefix): ?string
    {
        $normalizedCandidate = trim($candidate, '/');
        $normalizedPrefix = trim($prefix, '/');

        if ($normalizedPrefix === '') {
            return $normalizedCandidate;
        }

        if ($normalizedCandidate === $normalizedPrefix) {
            return '';
        }

        if (str_starts_with($normalizedCandidate, $normalizedPrefix . '/')) {
            return substr($normalizedCandidate, strlen($normalizedPrefix) + 1);
        }

        return null;
    }

    protected function resolveConfiguredPath(string $disk, ?string $path, ?callable $predicate = null): ?array
    {
        foreach ($this->getRelativePathCandidates($path) as $candidate) {
            foreach ($this->getConfiguredPathResolvers($disk) as $resolver) {
                $root = $resolver['root'];
                $realRoot = realpath($root) ?: $root;

                foreach ($resolver['prefixes'] as $prefix) {
                    $relativePath = $this->stripResolverPrefix($candidate, (string) $prefix);
                    if ($relativePath === null) {
                        continue;
                    }

                    $absolutePath = $this->buildAbsolutePath($root, $relativePath);
                    $realPath = realpath($absolutePath) ?: $absolutePath;

                    if (!str_starts_with($realPath, $realRoot)) {
                        continue;
                    }

                    $matches = $predicate === null
                        ? file_exists($absolutePath)
                        : $predicate($absolutePath);

                    if ($matches) {
                        return [
                            'relative' => $relativePath,
                            'absolute' => $absolutePath,
                            'root' => $root,
                        ];
                    }
                }
            }
        }

        return null;
    }

    protected function getRelativePathCandidates(?string $path): array
    {
        $normalized = $this->normalizeRelativePath($path);

        if ($normalized === '') {
            return [''];
        }

        $decoded = $this->decodeRelativePath($path);
        $candidates = [];

        foreach ([$decoded, $normalized] as $candidate) {
            $candidate = trim((string) $candidate, '/');

            if ($candidate !== '' && !in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    protected function resolveExistingRelativePath(string $root, ?string $path, ?callable $predicate = null): ?string
    {
        $realRoot = realpath($root) ?: $root;

        foreach ($this->getRelativePathCandidates($path) as $candidate) {
            $absolutePath = $this->buildAbsolutePath($root, $candidate);
            $realPath = realpath($absolutePath) ?: $absolutePath;

            if (!str_starts_with($realPath, $realRoot)) {
                continue;
            }

            if (($predicate === null && file_exists($absolutePath)) || ($predicate !== null && $predicate($absolutePath))) {
                return $candidate;
            }
        }

        return null;
    }

    protected function sanitizeStorageName(string $name): string
    {
        $candidate = trim(basename($name));

        if (($candidate === '') || ($candidate === '.') || ($candidate === '..')) {
            throw new InvalidArgumentException('Invalid filename');
        }

        if (str_contains($candidate, "\0") || str_contains($candidate, '/') || str_contains($candidate, '\\')) {
            throw new InvalidArgumentException('Invalid filename: cannot contain path separators');
        }

        return $candidate;
    }

    protected function encodeStorageSegment(string $name): string
    {
        return rawurlencode(rawurldecode($name));
    }

    protected function validateEncodedStorageName(string $encodedName): void
    {
        $validationRegex = config('filament-filemanager.filename_validation_regex', '/^[A-Za-z0-9_\-\.%]+$/');

        if (($encodedName === '') || ($encodedName === '.') || ($encodedName === '..')) {
            throw new InvalidArgumentException('Invalid filename');
        }

        if (!preg_match($validationRegex, $encodedName)) {
            throw new InvalidArgumentException('Invalid filename after URL encoding');
        }
    }

    public function previewFile(string $encodedPath)
    {
        try {
            $disk = config('filament-filemanager.disk', 'local');
            $rawPath = (string) base64_decode(strtr($encodedPath, '-_', '+/'));
            $resolved = $this->resolveConfiguredPath($disk, $rawPath, static fn (string $candidatePath): bool => is_file($candidatePath));

            if ($resolved !== null) {
                $mimeType = mime_content_type($resolved['absolute']) ?: 'application/octet-stream';

                return response()->file($resolved['absolute'], [
                    'Content-Type' => $mimeType,
                    'Cache-Control' => 'public, max-age=3600',
                ]);
            }

            $normalizedPath = $this->decodeRelativePath($rawPath);
            if ($normalizedPath !== '' && Storage::disk($disk)->exists($normalizedPath)) {
                if ($disk === 'local') {
                    $filePath = Storage::disk($disk)->path($normalizedPath);
                    if (file_exists($filePath)) {
                        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

                        return response()->file($filePath, [
                            'Content-Type' => $mimeType,
                            'Cache-Control' => 'public, max-age=3600',
                        ]);
                    }
                }

                try {
                    return redirect(Storage::disk($disk)->url($normalizedPath));
                } catch (\Throwable $_) {
                }
            }

            abort(404, 'File not found');
        } catch (\Throwable $e) {
            abort(500, 'File preview error: ' . $e->getMessage());
        }
    }

    public function downloadFile(Request $request, $path)
    {
        $disk = config('filament-filemanager.disk', 'local');
        $resolved = $this->resolveConfiguredPath($disk, $path, static fn (string $candidatePath): bool => is_file($candidatePath));
        if ($resolved === null) {
            abort(404, 'File not found');
        }
        $name = rawurldecode(basename($resolved['relative']));
        return response()->download($resolved['absolute'], $name);
    }

    public function index(Request $request)
    {
        $disk = config("filament-filemanager.disk", 'local');
        $type = $request->query('type');
        $root = Storage::disk($disk)->path('');
        $requestedPath = (string) $request->query('path', '');
        if (!is_dir($root) && !mkdir($root, 0755, true)) {
            return response()->json(['ok' => false, 'error' => 'Failed to create root directory'], 500);
        }
        $path = $this->resolveExistingRelativePath($root, $requestedPath, static fn (string $candidatePath): bool => is_dir($candidatePath))
            ?? $this->decodeRelativePath($requestedPath);
        $current = $this->buildAbsolutePath($root, $path);
        $realCurrent = realpath($current) ?: $current;
        $realRoot = realpath($root) ?: $root;
        if (!str_starts_with($realCurrent, $realRoot)) {
            abort(403, 'Access denied');
        }
        if (!is_dir($current)) {
            $current = $root;
            $path = '';
        }
        $dirs = collect(glob($current . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR))
            ->map(function ($dir) use ($root) {
                $name = basename($dir);
                $rel = trim(str_replace($root, '', $dir), '\\/');
                return [
                    'name' => rawurldecode($name),
                    'path' => $this->encodeRelativePath(str_replace('\\', '/', $rel)),
                    'size' => 0,
                    'mtime' => filemtime($dir) ?: null,
                ];
            })->values();
        $allFiles = glob($current . DIRECTORY_SEPARATOR . '*');
        $files = collect($allFiles)
            ->filter(fn($p) => is_file($p))
            ->map(function ($p) use ($root, $disk) {
                $filename = basename($p);
                $rel = trim(str_replace($root, '', $p), '\\/');
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $normalized = ltrim(str_replace('\\', '/', $rel), '/');
                $encodedPath = $this->encodeRelativePath($normalized);
                $url = route('filament-filemanager.file-manager.serve', ['path' => $encodedPath]) . '?disk=' . urlencode($disk);
                return [
                    'name' => rawurldecode($filename),
                    'url' => $url,
                    'ext' => $ext,
                    'path' => $encodedPath,
                    'size' => filesize($p) ?: 0,
                    'mtime' => filemtime($p) ?: null,
                ];
            })->values();
        if ($type === 'image') {
            $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $files = $files->filter(fn($f) => in_array($f['ext'], $imageExts));
        }
        $breadcrumbs = [];
        $acc = '';
        if ($path !== '') {
            foreach (explode('/', $path) as $seg) {
                $acc = ltrim($acc . '/' . $seg, '/');
                $breadcrumbs[] = ['name' => rawurldecode($seg), 'path' => $this->encodeRelativePath($acc)];
            }
        }
        return view('filament-filemanager::file-manager', [
            'type' => $type,
            'path' => $this->encodeRelativePath($path),
            'displayPath' => implode('/', array_map(static fn (string $segment): string => rawurldecode($segment), array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''))),
            'breadcrumbs' => $breadcrumbs,
            'dirs' => $dirs,
            'files' => $files,
            'disk' => $disk,
        ]);
    }

    public function upload(Request $request)
    {
        $allowedMimes = config('filament-filemanager.allowed_mimes', []);
        $mimeRule = $allowedMimes ? ['mimes:' . implode(',', $allowedMimes)] : [];
        $request->validate([
            'file' => array_merge(['required', 'file'], $mimeRule),
            'path' => ['nullable', 'string'],
        ]);
        $file = $request->file('file');
        $disk = config('filament-filemanager.disk', 'local');
        $root = Storage::disk($disk)->path('');
        $requestedPath = (string) $request->string('path');
        $path = $this->resolveExistingRelativePath($root, $requestedPath, static fn (string $candidatePath): bool => is_dir($candidatePath))
            ?? $this->decodeRelativePath($requestedPath);
        $directory = $this->buildAbsolutePath($root, $path);
        $realDirectory = realpath($directory) ?: $directory;
        $realRoot = realpath($root) ?: $root;
        if (!str_starts_with($realDirectory, $realRoot)) {
            return response()->json(['ok' => false, 'error' => 'Access denied'], 403);
        }
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            return response()->json(['ok' => false, 'error' => 'Failed to create directory'], 500);
        }
        if (!is_writable($directory)) {
            return response()->json(['ok' => false, 'error' => 'Directory is not writable'], 403);
        }
        $ext = strtolower($file->getClientOriginalExtension());
        // 根据配置决定是否保留源文件名
        $preserve = (bool) config('filament-filemanager.preserve_original_filename', false);
        if ($preserve) {
            try {
                $originalName = $this->sanitizeStorageName((string) $file->getClientOriginalName());
                $base = pathinfo($originalName, PATHINFO_FILENAME);
                $this->validateEncodedStorageName($this->encodeStorageSegment($base) . ($ext ? '.' . $ext : ''));
            } catch (InvalidArgumentException $exception) {
                return response()->json(['ok' => false, 'error' => $exception->getMessage()], 422);
            }

            // 统一扩展名为已解析的 $ext
            $candidate = $base . ($ext ? '.' . $ext : '');

            // 若存在重名，追加序号避免覆盖
            $safeName = $candidate;
            if (file_exists($directory . DIRECTORY_SEPARATOR . $safeName)) {
                $i = 1;
                $nameBase = $base;
                do {
                    $safeName = $nameBase . '_' . $i . ($ext ? '.' . $ext : '');
                    $i++;
                } while (file_exists($directory . DIRECTORY_SEPARATOR . $safeName));
            }
        } else {
            $safeName = time() . '_' . Str::random(8) . ($ext ? '.' . $ext : '');
        }
        try {
            $file->move($directory, $safeName);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'error' => 'Failed to upload file: ' . $e->getMessage()], 500);
        }
        $relPath = ($path ? trim($path, '/') . '/' : '') . $safeName;
        $encodedRelPath = $this->encodeRelativePath($relPath);
        $fullPath = $directory . DIRECTORY_SEPARATOR . $safeName;
        $size = @filesize($fullPath) ?: 0;
        $mtime = @filemtime($fullPath) ?: null;

        // Build a public URL for the file if the disk supports it, otherwise fall back to serve route
        if ($disk === 'local') {
            $publicUrl = route('filament-filemanager.file-manager.serve', ['path' => $encodedRelPath]) . '?disk=' . urlencode($disk);
        } else {
            try {
                $publicUrl = Storage::disk($disk)->url(str_replace('\\', '/', $relPath));
            } catch (\Throwable $e) {
                $publicUrl = route('filament-filemanager.file-manager.serve', ['path' => $encodedRelPath]) . '?disk=' . urlencode($disk);
            }
        }

        return response()->json([
            'ok' => true,
            'name' => $safeName,
            'path' => $encodedRelPath,
            'url' => $publicUrl,
            'ext' => $ext,
            'size' => $size,
            'mtime' => $mtime,
        ]);
    }

    public function rename(Request $request)
    {
        $request->validate([
            'path' => ['required', 'string'],
            'name' => ['required', 'string'],
        ]);
        $disk = config('filament-filemanager.disk', 'local');
        $root = Storage::disk($disk)->path('');
        $rel = $this->resolveExistingRelativePath($root, (string) $request->path);
        if ($rel === null) {
            return response()->json(['ok' => false, 'error' => 'File/folder not found'], 404);
        }
        $src = $this->buildAbsolutePath($root, $rel);
        $realSrc = realpath($src) ?: $src;
        $realRoot = realpath($root) ?: $root;
        if (!str_starts_with($realSrc, $realRoot)) {
            return response()->json(['ok' => false, 'error' => 'Access denied'], 403);
        }

        try {
            $newName = $this->sanitizeStorageName((string) $request->name);
            $newBase = pathinfo($newName, PATHINFO_FILENAME);
            $newExt = strtolower(pathinfo($newName, PATHINFO_EXTENSION));
            $this->validateEncodedStorageName($this->encodeStorageSegment($newBase) . ($newExt !== '' ? '.' . $newExt : ''));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }

        $parent = dirname($src);
        $dst = $parent . DIRECTORY_SEPARATOR . $newName;
        if (!file_exists($src)) {
            return response()->json(['ok' => false, 'error' => 'File/folder not found'], 404);
        }
        if (file_exists($dst)) {
            return response()->json(['ok' => false, 'error' => 'Target already exists'], 409);
        }
        if (!is_writable($parent)) {
            return response()->json(['ok' => false, 'error' => 'Directory is not writable'], 403);
        }
        try {
            if (!rename($src, $dst)) {
                throw new \Exception('Rename failed');
            }
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'error' => 'Rename failed: ' . $e->getMessage(),
                'src' => $src,
                'dst' => $dst,
            ], 422);
        }
        $renamed = rawurldecode(basename($dst));
        $newRel = ltrim(str_replace($root, '', $dst), '\\/');
        $encodedPath = $this->encodeRelativePath(str_replace('\\', '/', $newRel));

        $response = [
            'ok' => true,
            'name' => $renamed,
            'path' => $encodedPath,
        ];

        if (is_file($dst)) {
            $response['url'] = route('filament-filemanager.file-manager.serve', ['path' => $encodedPath]) . '?disk=' . urlencode($disk);
        }

        return response()->json($response);
    }

    public function folder(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string'],
            'path' => ['nullable', 'string'],
        ]);
        $disk = config('filament-filemanager.disk', 'local');
        $root = Storage::disk($disk)->path('');
        $requestedPath = (string) $request->string('path');
        $path = $this->resolveExistingRelativePath($root, $requestedPath, static fn (string $candidatePath): bool => is_dir($candidatePath))
            ?? $this->decodeRelativePath($requestedPath);

        try {
            $folderName = $this->sanitizeStorageName((string) $request->name);
            $this->validateEncodedStorageName($this->encodeStorageSegment($folderName));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }

        $dir = $root . DIRECTORY_SEPARATOR . ($path ? str_replace('/', DIRECTORY_SEPARATOR, $path) . DIRECTORY_SEPARATOR : '') . $folderName;
        $realDir = realpath($dir) ?: $dir;
        $realRoot = realpath($root) ?: $root;
        if (str_starts_with($realDir, $realRoot) && is_dir($dir)) {
            return response()->json(['ok' => false, 'error' => 'Folder already exists'], 409);
        }
        try {
            if (!mkdir($dir, 0755, true)) {
                throw new \Exception('Failed to create folder');
            }
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'error' => 'Failed to create folder: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'ok' => true,
            'name' => $folderName,
            'path' => $this->encodeRelativePath(($path ? trim($path, '/') . '/' : '') . $folderName),
        ]);
    }

    public function delete(Request $request)
    {
        try {
            $request->validate([
                'path' => ['required', 'string'],
            ]);
            $disk = config('filament-filemanager.disk', 'local');
            $root = Storage::disk($disk)->path('');
            $rel = $this->resolveExistingRelativePath($root, (string) $request->path);
            if ($rel === null) {
                return response()->json(['ok' => false, 'error' => 'File or directory not found'], 404);
            }
            $target = $this->buildAbsolutePath($root, $rel);
            $realTarget = realpath($target) ?: $target;
            $realRoot = realpath($root) ?: $root;
            if (!str_starts_with($realTarget, $realRoot)) {
                return response()->json(['ok' => false, 'error' => 'Access denied'], 403);
            }
            if (!file_exists($target)) {
                return response()->json(['ok' => false, 'error' => 'File or directory not found'], 404);
            }
            if (!is_writable($target)) {
                return response()->json(['ok' => false, 'error' => 'File or directory not writable'], 403);
            }
            if (is_dir($target)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                $success = true;
                foreach ($files as $file) {
                    $filePath = $file->getRealPath();
                    if ($file->isDir()) {
                        if (!rmdir($filePath)) {
                            $success = false;
                            break;
                        }
                    } else {
                        if (!unlink($filePath)) {
                            $success = false;
                            break;
                        }
                    }
                }
                if ($success && !rmdir($target)) {
                    $success = false;
                }
                if (!$success) {
                    throw new \Exception('Failed to delete directory');
                }
            } else {
                if (!unlink($target)) {
                    throw new \Exception('Failed to delete file');
                }
            }
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'error' => 'Failed to delete file or directory: ' . $e->getMessage()
            ], 500);
        }
    }

    public function serveFile(Request $request, $path)
    {
        $disk = config('filament-filemanager.disk', 'local');
        $resolved = $this->resolveConfiguredPath($disk, $path, static fn (string $candidatePath): bool => is_file($candidatePath));
        if ($resolved === null) {
            abort(404, 'File not found');
        }
        $mime = mime_content_type($resolved['absolute']) ?: 'application/octet-stream';
        return response()->file($resolved['absolute'], ['Content-Type' => $mime]);
    }
}
