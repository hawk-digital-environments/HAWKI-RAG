<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration for Scrape Events
    |--------------------------------------------------------------------------
    |
    | Configuration for Redis Pub/Sub communication between the Python
    | microservice (CustomCrawler) and Laravel application.
    |
    */

    'redis_channel' => env('SCRAPE_REDIS_CHANNEL', 'scrape-events'),

    /*
    |--------------------------------------------------------------------------
    | Python Microservice Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Python CustomCrawler microservice.
    |
    */

    'microservice_url' => env('CUSTOM_CRAWLER_URL', 'http://custom-crawler:8000'),

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for storing scraped data and job outputs.
    |
    */

    'storage_path' => env('SCRAPE_STORAGE_PATH', storage_path('app/scrape-jobs')),

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

    /*
    |--------------------------------------------------------------------------
    | Event Listener Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Redis event listener that processes scrape events.
    |
    */

    'max_job_duration' => env('SCRAPE_MAX_JOB_DURATION', 3600), // Maximum time (in seconds) to listen for job events

];
