<?php

namespace App\Console\Commands;

use App\Services\Pipeline\PipelineTaskService;
use Illuminate\Console\Command;

class StartOrchestratedPipelineTask extends Command
{
    protected $signature = 'pipeline:start-task
        {--task-id= : Optional explicit task ID}
        {--dataset-id= : Dataset identifier}
        {--profile-id= : Scraper profile identifier}
        {--sitemap-url= : Remote sitemap URL}
        {--sitemap-path= : Local sitemap path}
        {--source-url= : Single source URL}
        {--url=* : Source URL, can be repeated}';

    protected $description = 'Create a Laravel-owned pipeline task and start its Prefect supervisor flow.';

    public function handle(PipelineTaskService $tasks): int
    {
        $input = [
            'task_id' => $this->option('task-id'),
            'dataset_id' => $this->option('dataset-id'),
            'profile_id' => $this->option('profile-id'),
            'sitemap_url' => $this->option('sitemap-url'),
            'sitemap_path' => $this->option('sitemap-path'),
            'source_url' => $this->option('source-url'),
            'urls' => $this->option('url') ?: [],
        ];

        $task = $tasks->start($input);
        $status = $tasks->show($task->task_id);

        $this->info("Created pipeline task {$task->task_id}");
        $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
