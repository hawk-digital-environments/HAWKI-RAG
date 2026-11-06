<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Authorization\ManagedMutationPrincipalService;
use App\Services\Pipeline\Clients\PythonUrlIngestionClient;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

class StartOrchestratedPipelineTask extends Command
{
    protected $signature = 'pipeline:start-task
        {--task-id= : Optional explicit task ID}
        {--dataset-id= : Dataset identifier}
        {--sitemap-url= : Remote sitemap URL}
        {--sitemap-path= : Local sitemap path}
        {--source-url= : Single source URL}
        {--url=* : Source URL, can be repeated}
        {--refresh-cadence= : Optional Temporal schedule cadence: daily, weekly, or monthly}';

    protected $description = 'Start a URL task through the configured ingestion owner.';

    public function handle(PipelineTaskService $tasks, ConfigRepository $config, ManagedMutationPrincipalService $principals, PythonUrlIngestionClient $python): int
    {
        $input = [
            'task_id' => $this->option('task-id'),
            'dataset_id' => $this->option('dataset-id'),
            'sitemap_url' => $this->option('sitemap-url'),
            'sitemap_path' => $this->option('sitemap-path'),
            'source_url' => $this->option('source-url'),
            'urls' => $this->option('url') ?: [],
            'refresh_cadence' => $this->option('refresh-cadence'),
        ];

        $backend = $config->get('config.crawler_ingestion_backend', 'laravel');
        if ($backend !== 'laravel') {
            $user = $principals->resolve(Request::create('/api/pipeline/tasks/start', 'POST'));
            if ($backend !== 'python' || $user === null) {
                $this->error('URL ingestion requires a valid backend and a concrete principal; use the authenticated API in multi-user deployments.');

                return self::FAILURE;
            }
            $result = $python->forward($user, 'POST', 'pipeline/tasks/start', $input, null);
            $this->line(json_encode($result['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['status'] === 201 ? self::SUCCESS : self::FAILURE;
        }
        $task = $tasks->start($input);
        $status = $tasks->show($task->task_id);

        $this->info("Created pipeline task {$task->task_id}");
        $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
