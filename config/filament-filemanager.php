<?php

return [
    "disk" => "public",
    "allowed_mimes" => [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx'
    ],
    // 單檔上傳大小上限（單位：KB）。null 表示不限制。例如 10240 = 10 MB。
    'max_file_size' => env('FFM_MAX_FILE_SIZE', 10240),
    // 是否保留源文件名称（true 保留，false 使用随机文件名）
    'preserve_original_filename' => env('FFM_PRESERVE_ORIGINAL_FILENAME', true),
    // 当保留源文件名称时，会先对原文件名做 URL 编码，再按该编码结果做校验。
    // 默认允许字母、数字、下划线、短横线、点和 URL 编码产生的百分号。
    'filename_validation_regex' => env('FFM_FILENAME_VALIDATION_REGEX', '/^[A-Za-z0-9_\-\.%]+$/'),
    // 本地文件解析根按顺序尝试匹配。用于 preview/download/serve 等后端路由，
    // 避免在前端写死 storage、asset-files 等访问前缀。
    'path_resolvers' => [
        [
            'root' => storage_path('app/public'),
            'prefixes' => ['', 'storage'],
        ],
        [
            'root' => public_path(),
            'prefixes' => [''],
        ],
    ],
    'preview_base' => env('FFM_PREVIEW_BASE', null),
    'enable_download' => env('FFM_ENABLE_DOWNLOAD', true),
    'download_base' => env('FFM_DOWNLOAD_BASE', null),
];
