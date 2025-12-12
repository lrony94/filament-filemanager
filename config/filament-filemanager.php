<?php

return [
    "disk" => "local",
    "allowed_mimes" => [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'mp4', 'mp3'
    ],
    // Optional base URL for previews. If set, preview URLs will be constructed as {preview_base}/{encodedPath}
    // Example: 'https://cdn.example.com/previews'
    'preview_base' => env('FFM_PREVIEW_BASE', null),

    // Whether to show a download-original button in the picker preview UI
    'enable_download' => env('FFM_ENABLE_DOWNLOAD', true),
    // If using remote download base, specify base URL; otherwise the package download route will be used
    'download_base' => env('FFM_DOWNLOAD_BASE', null),
];
