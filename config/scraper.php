<?php

$pipelineRoot = rtrim((string) env('HAWKI_RAG_PIPELINE_ROOT', '/shared'), DIRECTORY_SEPARATOR);
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
    'tasks_path' => env('CUSTOM_CRAWLER_TASKS_PATH', '/tasks'),
    'task_start_path' => env('CUSTOM_CRAWLER_TASK_START_PATH', '/tasks/{task}/run'),
    'task_start_method' => env('CUSTOM_CRAWLER_TASK_START_METHOD', 'POST'),
    'task_ui_url' => env('CUSTOM_CRAWLER_TASK_UI_URL', env('CUSTOM_CRAWLER_UI_URL', 'http://host.docker.internal:5173')),
    'task_ui_profiles_path' => env('CUSTOM_CRAWLER_TASK_UI_PROFILES_PATH', '/api/profiles'),
    'task_ui_tasks_path' => env('CUSTOM_CRAWLER_TASK_UI_TASKS_PATH', '/api/tasks'),
    'task_ui_submit_path' => env('CUSTOM_CRAWLER_TASK_UI_SUBMIT_PATH', '/api/crawler/submit'),

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for storing scraped data and job outputs.
    |
    */

    'storage_path' => env('SCRAPE_STORAGE_PATH', $crawledDataRoot),
    'allowed_local_roots' => array_values(array_filter(array_map(
        static fn (string $path): string => rtrim($path, DIRECTORY_SEPARATOR),
        explode(',', (string) env(
            'SCRAPE_ALLOWED_LOCAL_ROOTS',
            implode(',', array_unique(array_filter([
                $pipelineRoot,
                $crawledDataRoot,
                storage_path('app'),
            ])))
        ))
    ))),

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
