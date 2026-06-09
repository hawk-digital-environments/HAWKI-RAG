<?php

namespace App\Console\Commands;

use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Console\Command;

class ShowPipelineTask extends Command
{
    protected $signature = 'pipeline:show-task
        {task_id : Pipeline task ID}';

    protected $description = 'Show Laravel-owned pipeline task status and counters.';

    public function handle(PipelineTaskService $tasks): int
    {
        $task = $tasks->show((string) $this->argument('task_id'));
        if (! $task) {
            $this->error('Pipeline task was not found.');

            return self::FAILURE;
        }

        $this->line(json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
