<?php

return [
    "disk" => "local",
    "allowed_mimes" => [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'mp4', 'mp3'
    ],
    // 是否保留源文件名称（true 保留，false 使用随机文件名）
    'preserve_original_filename' => env('FFM_PRESERVE_ORIGINAL_FILENAME', false),
    // 当保留源文件名称时用于校验文件名合法性的正则（包含扩展名）。
    // 默认仅允许字母、数字、下划线、短横线及点。
    'filename_validation_regex' => env('FFM_FILENAME_VALIDATION_REGEX', '/^[A-Za-z0-9_\-\.]+$/'),
    // Optional base URL for previews. If set, preview URLs will be constructed as {preview_base}/{encodedPath}
    // Example: 'https://cdn.example.com/previews'
    'preview_base' => env('FFM_PREVIEW_BASE', null),

    // Whether to show a download-original button in the picker preview UI
    'enable_download' => env('FFM_ENABLE_DOWNLOAD', true),
    // If using remote download base, specify base URL; otherwise the package download route will be used
    'download_base' => env('FFM_DOWNLOAD_BASE', null),
];
