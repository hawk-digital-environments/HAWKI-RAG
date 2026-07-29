<?php

$baseUrl = rtrim((string) env('EXTERNAL_CONVERTER_URL', env('FILE_CONVERTER_BASE_URL', 'http://file-converter:8000')), '/');
$customConverterStatusPath = env('CUSTOM_CONVERTER_STATUS_PATH');
if (! is_string($customConverterStatusPath) || trim($customConverterStatusPath) === '') {
    $customConverterStatusPath = env('EXTERNAL_CONVERTER_STATUS_PATH');
}
if (! is_string($customConverterStatusPath) || trim($customConverterStatusPath) === '') {
    $customConverterStatusPath = '/jobs/{job_id}';
}

return [
    'health_url'      => env('FILE_CONVERTER_HEALTH_URL', $baseUrl . '/health'),
    'retries'         => (int) env('FILE_CONVERTER_RETRIES', 3),
    'raganything_supported_extensions' => array_values(array_filter(array_map(
        static fn ($extension) => ltrim(strtolower(trim($extension)), '.'),
        explode(',', env('RAGANYTHING_SUPPORTED_UPLOAD_EXTENSIONS', 'pdf,doc,docx,ppt,pptx,xls,xlsx,jpg,jpeg,png,bmp,tif,tiff,gif,webp,txt,md'))
    ))),
    'supported_extensions' => array_values(array_filter(array_map(
        static fn ($extension) => ltrim(strtolower(trim($extension)), '.'),
        explode(',', env('FILE_CONVERTER_SUPPORTED_EXTENSIONS', ''))
    ))),
    'custom_converter_status_path' => $customConverterStatusPath,
];
