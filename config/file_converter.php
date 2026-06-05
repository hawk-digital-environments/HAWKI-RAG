<?php

$url = env('FILE_CONVERTER_URL', 'http://127.0.0.1:8002/extract');
$baseUrl = preg_replace('#/extract/?$#', '', rtrim((string) $url, '/')) ?: rtrim((string) $url, '/');
$defaultSupportedExtensions = [
    'pdf',
    'doc',
    'docx',
    'txt',
    'md',
    'markdown',
    'html',
    'htm',
    'csv',
    'xls',
    'xlsx',
    'ppt',
    'pptx',
    'odt',
    'ods',
    'odp',
    'rtf',
    'json',
    'xml',
    'yaml',
    'yml',
    'zip',
    'png',
    'jpg',
    'jpeg',
    'tif',
    'tiff',
    'webp',
    'bmp',
    'gif',
];

return [
    'url'             => $url,
    'health_url'      => env('FILE_CONVERTER_HEALTH_URL', $baseUrl . '/health'),
    'info_url'        => env('FILE_CONVERTER_INFO_URL', $baseUrl . '/'),
    'timeout'         => (int) env('FILE_CONVERTER_TIMEOUT', 600),
    'connect_timeout' => (int) env('FILE_CONVERTER_CONNECT_TIMEOUT', 20),
    'retries'         => (int) env('FILE_CONVERTER_RETRIES', 3),
    'retry_delay_ms'  => (int) env('FILE_CONVERTER_RETRY_DELAY_MS', 1500),
    'token'           => env('FILE_CONVERTER_TOKEN'),
    'auth_header'     => env('FILE_CONVERTER_AUTH_HEADER', 'bearer'),
    'supported_extensions' => array_values(array_filter(array_map(
        static fn ($extension) => ltrim(strtolower(trim($extension)), '.'),
        explode(',', env('FILE_CONVERTER_SUPPORTED_EXTENSIONS', implode(',', $defaultSupportedExtensions)))
    ))),
    'output_contract' => [
        'archive' => 'application/zip',
        'root' => 'output',
        'metadata' => 'output/meta.json',
        'chunks' => 'output/chunks/*.md',
        'assets' => 'output/assets/*',
    ],
];
