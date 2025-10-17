<?php

namespace Amirhellboy\FilamentFileManager\Controllers;

use Amirhellboy\FilamentFileManager\Http\Middleware\AccessPanelPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
                Route::get('/file-preview/{encodedPath}', function ($encodedPath) {
                    try {
                        $path = urldecode(base64_decode(strtr($encodedPath, '-_', '+/')));
                        $disk = config('filament-filemanager.disk', 'local');
                        
                        \Log::info('[FFM] File preview request:', [
                            'encodedPath' => $encodedPath,
                            'decodedPath' => $path,
                            'disk' => $disk
                        ]);
                        
                        if (!Storage::disk($disk)->exists($path)) {
                            \Log::error('[FFM] File not found:', [
                                'disk' => $disk,
                                'path' => $path
                            ]);
                            abort(404, 'File not found on disk: ' . $disk . ' path: ' . $path);
                        }
                        
                        // For local disk, serve the file directly instead of redirecting
                        if ($disk === 'local') {
                            $filePath = Storage::disk($disk)->path($path);
                            if (!file_exists($filePath)) {
                                \Log::error('[FFM] File path not found:', $filePath);
                                abort(404, 'File not found: ' . $filePath);
                            }
                            
                            $mimeType = mime_content_type($filePath);
                            \Log::info('[FFM] Serving file:', [
                                'filePath' => $filePath,
                                'mimeType' => $mimeType
                            ]);
                            
                            return response()->file($filePath, [
                                'Content-Type' => $mimeType,
                                'Cache-Control' => 'public, max-age=3600'
                            ]);
                        }
                        
                        // For other disks, try to get the URL
                        try {
                            $url = Storage::disk($disk)->url($path);
                            return redirect($url);
                        } catch (\Exception $e) {
                            // If URL generation fails, serve the file directly
                            $filePath = Storage::disk($disk)->path($path);
                            if (file_exists($filePath)) {
                                $mimeType = mime_content_type($filePath);
                                return response()->file($filePath, [
                                    'Content-Type' => $mimeType,
                                    'Cache-Control' => 'public, max-age=3600'
                                ]);
                            }
                            abort(404, 'File not accessible');
                        }
                    } catch (\Exception $e) {
                        \Log::error('[FFM] File preview error:', [
                            'encodedPath' => $encodedPath,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        abort(500, 'File preview error: ' . $e->getMessage());
                    }
                })->where('encodedPath', '.*')->name('filament-filemanager.file-preview');

            });
    }

    public function index(Request $request)
    {
        $disk = config("filament-filemanager.disk", 'local');
        $type = $request->query('type');
        $path = trim(str_replace(['..', '\\'], ['.', '/'], (string)$request->query('path', '')), '/');
        $root = Storage::disk($disk)->path('');
        if (!is_dir($root) && !mkdir($root, 0755, true)) {
            return response()->json(['ok' => false, 'error' => 'Failed to create root directory'], 500);
        }
        $current = $path ? $root . DIRECTORY_SEPARATOR . $path : $root;
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
                    'name' => $name,
                    'path' => $rel,
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
                $url = route('filament-filemanager.file-manager.serve', ['path' => $normalized]) . '?disk=' . urlencode($disk);
                return [
                    'name' => $filename,
                    'url' => $url,
                    'ext' => $ext,
                    'path' => $normalized,
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
                $breadcrumbs[] = ['name' => $seg, 'path' => $acc];
            }
        }
        return view('filament-filemanager::file-manager', [
            'type' => $type,
            'path' => $path,
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
        $path = trim(str_replace(['..', '\\'], ['.', '/'], (string)$request->string('path')), '/');
        $disk = config('filament-filemanager.disk', 'local');
        $root = Storage::disk($disk)->path('');
        $directory = $path ? $root . DIRECTORY_SEPARATOR . $path : $root;
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
        $safeName = time() . '_' . Str::random(8) . ($ext ? '.' . $ext : '');
        try {
            $file->move($directory, $safeName);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'error' => 'Failed to upload file: ' . $e->getMessage()], 500);
        }
        $relPath = ($path ? trim($path, '/') . '/' : '') . $safeName;
        $fullPath = $directory . DIRECTORY_SEPARATOR . $safeName;
        $size = @filesize($fullPath) ?: 0;
        $mtime = @filemtime($fullPath) ?: null;

        // Build a public URL for the file if the disk supports it, otherwise fall back to serve route
        try {
            $publicUrl = Storage::disk($disk)->url(str_replace('\\', '/', $relPath));
        } catch (\Throwable $e) {
            $publicUrl = route('filament-filemanager.file-manager.serve', ['path' => $relPath, 'disk' => $disk]);
        }

        return response()->json([
            'ok' => true,
            'name' => $safeName,
            'path' => $relPath,
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
            'name' => ['required', 'string', 'regex:/^[A-Za-z0-9_\-\.]+$/'],
        ]);
        $disk = config('filament-filemanager.disk', 'local');
        $root = Storage::disk($disk)->path('');
        $rel = trim(str_replace(['..', '\\'], ['.', '/'], (string)$request->path), '/');
        $relDs = str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $src = $root . DIRECTORY_SEPARATOR . $relDs;
        $realSrc = realpath($src) ?: $src;
        $realRoot = realpath($root) ?: $root;
        if (!str_starts_with($realSrc, $realRoot)) {
            return response()->json(['ok' => false, 'error' => 'Access denied'], 403);
        }
        $parent = dirname($src);
        $dst = $parent . DIRECTORY_SEPARATOR . $request->name;
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
        $newName = basename($dst);
        return response()->json(['ok' => true, 'name' => $newName]);
    }

    public function folder(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'regex:/^[A-Za-z0-9_\-\.]+$/'],
            'path' => ['nullable', 'string'],
        ]);
        $disk = config('filament-filemanager.disk', 'local');
        $root = Storage::disk($disk)->path('');
        $path = trim(str_replace(['..', '\\'], ['.', '/'], (string)$request->string('path')), '/');
        $dir = $root . DIRECTORY_SEPARATOR . ($path ? $path . DIRECTORY_SEPARATOR : '') . $request->name;
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
        return response()->json(['ok' => true]);
    }

    public function delete(Request $request)
    {
        try {
            $request->validate([
                'path' => ['required', 'string'],
            ]);
            $disk = config('filament-filemanager.disk', 'local');
            $root = Storage::disk($disk)->path('');
            $rel = trim(str_replace(['..', '\\'], ['.', '/'], (string)$request->path), '/');
            $relDs = str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $target = $root . DIRECTORY_SEPARATOR . $relDs;
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
        $root = Storage::disk($disk)->path('');
        $filePath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $realFilePath = realpath($filePath) ?: $filePath;
        $realRoot = realpath($root) ?: $root;
        if (!str_starts_with($realFilePath, $realRoot) || !is_file($filePath)) {
            abort(404, 'File not found');
        }
        $mime = mime_content_type($filePath);
        return response()->file($filePath, ['Content-Type' => $mime]);
    }
}
