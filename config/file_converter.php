<?php

$baseUrl = rtrim((string) env('EXTERNAL_CONVERTER_URL', env('FILE_CONVERTER_BASE_URL', 'http://file-converter:8000')), '/');
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
    'health_url'      => env('FILE_CONVERTER_HEALTH_URL', $baseUrl . '/health'),
    'retries'         => (int) env('FILE_CONVERTER_RETRIES', 3),
    'supported_extensions' => array_values(array_filter(array_map(
        static fn ($extension) => ltrim(strtolower(trim($extension)), '.'),
        explode(',', env('FILE_CONVERTER_SUPPORTED_EXTENSIONS', implode(',', $defaultSupportedExtensions)))
    ))),
];
