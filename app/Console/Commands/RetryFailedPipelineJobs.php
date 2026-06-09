<?php

namespace App\Console\Commands;

use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Console\Command;

class RetryFailedPipelineJobs extends Command
{
    protected $signature = 'pipeline:retry-failed-jobs
        {task_id : Pipeline task ID}';

    protected $description = 'Retry failed jobs for a Laravel-owned pipeline task.';

    public function handle(PipelineTaskService $tasks): int
    {
        $task = $tasks->retryFailedJobs((string) $this->argument('task_id'));
        if (! $task) {
            $this->error('Pipeline task was not found.');

            return self::FAILURE;
        }

        $this->line(json_encode($tasks->show($task->task_id), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
