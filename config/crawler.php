<?php

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

    'storage_path' => env('CRAWLER_STORAGE_PATH', 'crawled-data'),

];
