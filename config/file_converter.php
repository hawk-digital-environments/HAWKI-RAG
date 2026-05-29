<?php


return [
    'url'             => env('FILE_CONVERTER_URL', 'http://127.0.0.1:8002/extract'),
    'timeout'         => (int) env('FILE_CONVERTER_TIMEOUT', 600),
    'connect_timeout' => (int) env('FILE_CONVERTER_CONNECT_TIMEOUT', 20),
    'retries'         => (int) env('FILE_CONVERTER_RETRIES', 3),
    'retry_delay_ms'  => (int) env('FILE_CONVERTER_RETRY_DELAY_MS', 1500),
    'token'           => env('FILE_CONVERTER_TOKEN'),
    'supported_extensions' => array_values(array_filter(array_map(
        static fn ($extension) => ltrim(strtolower(trim($extension)), '.'),
        explode(',', env('FILE_CONVERTER_SUPPORTED_EXTENSIONS', 'pdf,doc,docx'))
    ))),
];
