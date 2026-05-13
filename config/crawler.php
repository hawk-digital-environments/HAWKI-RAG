<?php

$pipelineRoot = rtrim((string) env('HAWKI_RAG_PIPELINE_ROOT', '/app/shared'), DIRECTORY_SEPARATOR);
$crawledDataRoot = rtrim((string) env('HAWKI_RAG_CRAWLED_DATA_ROOT', env('DEFAULT_CRAWLED_ROOT', $pipelineRoot)), DIRECTORY_SEPARATOR);

return [

    /*
    |--------------------------------------------------------------------------
    | Crawler Storage Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk to use for storing crawled data. This should reference
    | a disk name from config/filesystems.php (e.g., 'local', 's3', 'sftp').
    |
    */

    'storage_disk' => env('CRAWLER_STORAGE_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Crawler Storage Path
    |--------------------------------------------------------------------------
    |
    | The base path within the storage disk where crawler data will be stored.
    | For local disk, this is relative to the disk's root.
    |
    */

    'storage_path' => env('CRAWLER_STORAGE_PATH', $crawledDataRoot),

];
