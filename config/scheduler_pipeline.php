<?php

return [
    'pipeline_mode' => env('SCHEDULER_PIPELINE_MODE', 'make-sync'),

    'scraper_repo_path' => env('SCRAPER_REPO_PATH', ''),
    'rag_repo_path' => env('RAG_REPO_PATH', base_path()),

    'scraper_make_target' => env('SCRAPER_MAKE_TARGET', 'crawl'),
    'rag_make_target' => env('RAG_MAKE_TARGET', 'ingest'),

    'default_crawled_root' => env('DEFAULT_CRAWLED_ROOT', '/app/shared/crawled-data'),
    'default_sitemap_pages' => (int) env('DEFAULT_SITEMAP_PAGES', 100),
    'default_max_pages_full' => env('DEFAULT_MAX_PAGES_FULL', ''),
    'default_rescrape_failed' => filter_var(env('DEFAULT_RESCRAPE_FAILED', false), FILTER_VALIDATE_BOOL),
    'default_skip_images' => filter_var(env('DEFAULT_SKIP_IMAGES', true), FILTER_VALIDATE_BOOL),

    'check_before_run' => filter_var(env('PIPELINE_CHECK_BEFORE_RUN', true), FILTER_VALIDATE_BOOL),
    'command_timeout_seconds' => (int) env('PIPELINE_COMMAND_TIMEOUT_SECONDS', 3600),
    'dry_check_timeout_seconds' => (int) env('PIPELINE_DRY_CHECK_TIMEOUT_SECONDS', 30),
];
