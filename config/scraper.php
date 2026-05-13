<?php

$pipelineRoot = rtrim((string) env('HAWKI_RAG_PIPELINE_ROOT', '/app/shared'), DIRECTORY_SEPARATOR);
$crawledDataRoot = rtrim((string) env('HAWKI_RAG_CRAWLED_DATA_ROOT', env('DEFAULT_CRAWLED_ROOT', $pipelineRoot)), DIRECTORY_SEPARATOR);

return [

    /*
    |--------------------------------------------------------------------------
    | Python Microservice Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Python CustomCrawler microservice.
    |
    */

    'api_url' => env('CUSTOM_CRAWLER_URL', 'http://crawler:8000'),
    'api_key' => env('CUSTOM_CRAWLER_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for storing scraped data and job outputs.
    |
    */

    'storage_path' => env('SCRAPE_STORAGE_PATH', $crawledDataRoot),

    /*
    |--------------------------------------------------------------------------
    | Job Configuration
    |--------------------------------------------------------------------------
    |
    | Default configuration for scrape jobs.
    |
    */

    'defaults' => [
        'max_pages' => env('SCRAPE_DEFAULT_MAX_PAGES', 100),
        'max_concurrency' => env('SCRAPE_DEFAULT_CONCURRENCY', 4),
        'max_rpm' => env('SCRAPE_DEFAULT_MAX_RPM', 60),
        'skip_images' => env('SCRAPE_DEFAULT_SKIP_IMAGES', false),
        'discovery_mode' => env('SCRAPE_DEFAULT_DISCOVERY_MODE', false),
    ],

];
