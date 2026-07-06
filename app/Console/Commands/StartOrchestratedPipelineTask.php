<?php

namespace App\Console\Commands;

use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Console\Command;

class StartOrchestratedPipelineTask extends Command
{
    protected $signature = 'pipeline:start-task
        {--task-id= : Optional explicit task ID}
        {--heap-id= : Heap identifier}
        {--sitemap-url= : Remote sitemap URL}
        {--sitemap-path= : Local sitemap path}
        {--source-url= : Single source URL}
        {--url=* : Source URL, can be repeated}
        {--refresh-cadence= : Optional Temporal schedule cadence: daily, weekly, or monthly}';

    protected $description = 'Create a Laravel-owned pipeline task and start Temporal source ingestion workflows.';

    public function handle(PipelineTaskService $tasks): int
    {
        $input = [
            'task_id' => $this->option('task-id'),
            'heap_id' => $this->option('heap-id'),
            'sitemap_url' => $this->option('sitemap-url'),
            'sitemap_path' => $this->option('sitemap-path'),
            'source_url' => $this->option('source-url'),
            'urls' => $this->option('url') ?: [],
            'refresh_cadence' => $this->option('refresh-cadence'),
        ];

        $task = $tasks->start($input);
        $status = $tasks->show($task->task_id);

        $this->info("Created pipeline task {$task->task_id}");
        $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
