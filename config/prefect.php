<?php

return [
    'enabled' => env('PREFECT_ENABLED', false),
    'start_mode' => env('PREFECT_START_MODE', env('PREFECT_API_URL') ? 'api' : 'cli'),
    'api_url' => env('PREFECT_API_URL', ''),
    'api_key' => env('PREFECT_API_KEY', ''),
    'deployment_id' => env('PREFECT_DEPLOYMENT_ID', ''),
    'flow_name' => env('PREFECT_FLOW_NAME', 'rag_task_flow'),
    'command' => env('PREFECT_COMMAND', 'prefect'),
    'deployment_name' => env('PREFECT_DEPLOYMENT_NAME', 'rag_task_flow/rag-task-flow'),
    'timeout' => env('PREFECT_START_TIMEOUT', 30),
    'laravel_base_url' => env('PREFECT_LARAVEL_BASE_URL', env('APP_URL', 'http://hawki_rag_app')),
    'api_token' => env('PREFECT_LARAVEL_API_TOKEN', ''),
    'poll_interval_seconds' => env('PREFECT_TASK_POLL_INTERVAL_SECONDS', 10),
    'max_idle_seconds' => env('PREFECT_TASK_MAX_IDLE_SECONDS', 3600),
];
