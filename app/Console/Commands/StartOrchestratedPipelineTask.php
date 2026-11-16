<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Authorization\ManagedMutationPrincipalService;
use App\Services\Pipeline\Clients\PythonUrlIngestionClient;
use App\Services\Pipeline\PipelineExecutionBackend;
use App\Services\Pipeline\PipelineOwnership;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

class StartOrchestratedPipelineTask extends Command
{
    protected $signature = 'pipeline:start-task
        {--task-id= : Optional explicit task ID}
        {--idempotency-key= : Required for Python starts; reuse with the same options after an uncertain outcome}
        {--dataset-id= : Dataset identifier}
        {--sitemap-url= : Remote sitemap URL}
        {--sitemap-path= : Local sitemap path}
        {--source-url= : Single source URL}
        {--url=* : Source URL, can be repeated}
        {--refresh-cadence= : Optional Temporal schedule cadence: daily, weekly, or monthly}';

    protected $description = 'Start a URL task through the configured ingestion owner.';

    public function handle(PipelineTaskService $tasks, ConfigRepository $config, ManagedMutationPrincipalService $principals, PythonUrlIngestionClient $python, PipelineExecutionBackend $execution, PipelineOwnership $ownership): int
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

        $backend = $execution->value();
        if (! $execution->valid()) {
            $this->error('Pipeline execution backend configuration is invalid.');

            return self::FAILURE;
        }
        $key = $this->option('idempotency-key');
        if ($config->get('config.crawler_ingestion_backend', 'laravel') === 'python' && is_string($key) && $key !== '' && $backend === 'laravel') {
            $user = $principals->resolve(Request::create('/api/pipeline/tasks/start', 'POST'));
            if ($user === null) {
                $this->error('A concrete principal is required.');

                return self::FAILURE;
            }
            $previous = $python->forward($user, 'GET', 'pipeline/start-request', ['key' => $key], null);
            if ($previous['status'] !== 200 || ! in_array($previous['payload']['owner'] ?? null, ['url', 'absent'], true)) {
                $this->error('Start ownership is unavailable. Retry with the same key.');

                return self::FAILURE;
            }
            if ($previous['payload']['owner'] === 'url') {
                $backend = 'python';
            }
        }
        if ($config->get('config.crawler_ingestion_backend', 'laravel') === 'python' && is_string($input['task_id']) && trim($input['task_id']) !== '') {
            $owner = $ownership->classify('task_id', trim($input['task_id']), allowAbsent: true);
            if ($owner['status'] !== 200 || ! in_array($owner['payload']['owner'] ?? null, ['url', 'absent'], true)) {
                $this->error('Task identity belongs to existing or conflicting work; use its recovery API.');

                return self::FAILURE;
            }
            if ($owner['payload']['owner'] === 'url') {
                $backend = 'python';
            }
        }
        if ($backend !== 'laravel') {
            if (! is_string($key) || trim($key) === '') {
                $this->error('Python starts require --idempotency-key. Retain it and reuse the same options when retrying.');

                return self::FAILURE;
            }
            $user = $principals->resolve(Request::create('/api/pipeline/tasks/start', 'POST'));
            if ($backend !== 'python' || $user === null || ($python->readiness()['ready'] ?? false) !== true) {
                $this->error('URL ingestion requires a valid backend and a concrete principal; use the authenticated API in multi-user deployments.');

                return self::FAILURE;
            }
            $result = $python->forward($user, 'POST', 'pipeline/tasks/start', $input, $key);
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
