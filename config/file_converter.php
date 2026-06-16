<?php

$baseUrl = rtrim((string) env('EXTERNAL_CONVERTER_URL', env('FILE_CONVERTER_BASE_URL', 'http://file-converter:8000')), '/');

return [
    'health_url'      => env('FILE_CONVERTER_HEALTH_URL', $baseUrl . '/health'),
    'retries'         => (int) env('FILE_CONVERTER_RETRIES', 3),
    'supported_extensions' => array_values(array_filter(array_map(
        static fn ($extension) => ltrim(strtolower(trim($extension)), '.'),
        explode(',', env('FILE_CONVERTER_SUPPORTED_EXTENSIONS', ''))
    ))),
];
