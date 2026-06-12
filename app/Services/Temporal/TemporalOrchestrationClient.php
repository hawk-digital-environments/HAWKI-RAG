<?php

declare(strict_types=1);

namespace App\Services\Temporal;

use App\Services\Temporal\Values\TemporalWorkflowExecution;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Attributes\Singleton;
use Psr\Log\LoggerInterface;
use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\Schedule\Action\StartWorkflowAction;
use Temporal\Client\Schedule\Policy\ScheduleOverlapPolicy;
use Temporal\Client\Schedule\Policy\SchedulePolicies;
use Temporal\Client\Schedule\Schedule;
use Temporal\Client\Schedule\Spec\ScheduleSpec;
use Temporal\Client\ScheduleClient;
use Temporal\Client\ScheduleClientInterface;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Temporal\Common\WorkflowIdConflictPolicy;

#[Singleton]
readonly class TemporalOrchestrationClient
{
    public function __construct(
        private ConfigRepository $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function startIngestWorkflow(array $input, ?string $workflowId = null): TemporalWorkflowExecution
    {
        $this->ensureEnabled();

        $workflowId ??= $this->workflowIdFor($input);
        $client = $this->workflowClient();
        $stub = $client->newUntypedWorkflowStub(
            $this->workflowType(),
            $this->workflowOptions($workflowId),
        );
        $run = $client->start($stub, $input);
        $execution = $run->getExecution();

        $this->logger->info('Temporal ingest workflow started.', [
            'source_id' => $input['source_id'] ?? null,
            'workflow_id' => $execution->getID(),
            'run_id' => $execution->getRunID(),
            'task_queue' => $this->workflowTaskQueue(),
        ]);

        return new TemporalWorkflowExecution($execution->getID(), $execution->getRunID());
    }

    /**
     * @param array<string, mixed> $input
     */
    public function upsertIngestSchedule(string $scheduleId, string $workflowId, string $cadence, array $input): TemporalWorkflowExecution
    {
        $this->ensureEnabled();

        $schedule = $this->buildSchedule($workflowId, $cadence, $input);
        $handle = $this->scheduleClient()->getHandle($scheduleId, $this->namespace());

        try {
            $description = $handle->describe();
            $handle->update($schedule, $description->conflictToken);
        } catch (\Throwable) {
            $handle = $this->scheduleClient()->createSchedule($schedule, scheduleId: $scheduleId);
        }

        $this->logger->info('Temporal ingest schedule upserted.', [
            'source_id' => $input['source_id'] ?? null,
            'workflow_id' => $workflowId,
            'schedule_id' => $scheduleId,
            'cadence' => $cadence,
            'task_queue' => $this->workflowTaskQueue(),
        ]);

        return new TemporalWorkflowExecution($workflowId, null, $handle->getID());
    }

    public function deleteSchedule(string $scheduleId): void
    {
        $this->ensureEnabled();

        try {
            $this->scheduleClient()->getHandle($scheduleId, $this->namespace())->delete();
        } catch (\Throwable $exception) {
            $this->logger->warning('Temporal ingest schedule delete failed.', [
                'schedule_id' => $scheduleId,
                'error' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }

    public function cancelWorkflow(string $workflowId, ?string $runId = null): void
    {
        $this->ensureEnabled();

        $this->workflowClient()
            ->newUntypedRunningWorkflowStub($workflowId, $runId, $this->workflowType())
            ->cancel();

        $this->logger->info('Temporal ingest workflow cancellation requested.', [
            'workflow_id' => $workflowId,
            'run_id' => $runId,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function buildSchedule(string $workflowId, string $cadence, array $input): Schedule
    {
        $cron = $this->cronForCadence($cadence);
        $action = StartWorkflowAction::new($this->workflowType())
            ->withWorkflowId($workflowId)
            ->withTaskQueue($this->workflowTaskQueue())
            ->withInput([$input])
            ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicate)
            ->withWorkflowExecutionTimeout($this->workflowExecutionTimeout())
            ->withWorkflowRunTimeout($this->workflowRunTimeout())
            ->withWorkflowTaskTimeout($this->workflowTaskTimeout());

        $spec = ScheduleSpec::new()
            ->withAddedCronString($cron)
            ->withTimezoneName('UTC');

        $policies = SchedulePolicies::new()
            ->withOverlapPolicy(ScheduleOverlapPolicy::Skip)
            ->withCatchupWindow('1 hour');

        return Schedule::new()
            ->withAction($action)
            ->withSpec($spec)
            ->withPolicies($policies);
    }

    private function workflowOptions(string $workflowId): WorkflowOptions
    {
        return (new WorkflowOptions())
            ->withWorkflowId($workflowId)
            ->withTaskQueue($this->workflowTaskQueue())
            ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicate)
            ->withWorkflowIdConflictPolicy(WorkflowIdConflictPolicy::UseExisting)
            ->withWorkflowExecutionTimeout($this->workflowExecutionTimeout())
            ->withWorkflowRunTimeout($this->workflowRunTimeout())
            ->withWorkflowTaskTimeout($this->workflowTaskTimeout());
    }

    private function workflowClient(): WorkflowClientInterface
    {
        return WorkflowClient::create(
            ServiceClient::create($this->address()),
            (new ClientOptions())
                ->withNamespace($this->namespace())
                ->withIdentity($this->identity()),
        );
    }

    private function scheduleClient(): ScheduleClientInterface
    {
        return ScheduleClient::create(
            ServiceClient::create($this->address()),
            (new ClientOptions())
                ->withNamespace($this->namespace())
                ->withIdentity($this->identity()),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function workflowIdFor(array $input): string
    {
        $sourceId = is_scalar($input['source_id'] ?? null) ? (string) $input['source_id'] : 'unknown';

        return 'ingest-source-'.$sourceId;
    }

    private function cronForCadence(string $cadence): string
    {
        $key = strtolower(trim($cadence));
        $cron = $this->config->get("temporal.refresh_cadences.{$key}");

        if (! is_string($cron) || trim($cron) === '') {
            throw new \InvalidArgumentException("Unsupported Temporal refresh cadence [{$cadence}].");
        }

        return trim($cron);
    }

    private function ensureEnabled(): void
    {
        if (! (bool) $this->config->get('temporal.enabled', true)) {
            throw new \RuntimeException('Temporal orchestration is disabled. Set HAWKI_RAG_TEMPORAL_ENABLED=true.');
        }
    }

    private function workflowType(): string
    {
        return (string) $this->config->get('temporal.workflow.type', 'IngestSourceWorkflow');
    }

    private function workflowTaskQueue(): string
    {
        return (string) $this->config->get('temporal.task_queues.workflow', 'rag-workflow-task-queue');
    }

    private function workflowExecutionTimeout(): string
    {
        return (string) $this->config->get('temporal.workflow.execution_timeout', '86400 seconds');
    }

    private function workflowRunTimeout(): string
    {
        return (string) $this->config->get('temporal.workflow.run_timeout', '21600 seconds');
    }

    private function workflowTaskTimeout(): string
    {
        return (string) $this->config->get('temporal.workflow.task_timeout', '30 seconds');
    }

    private function address(): string
    {
        return (string) $this->config->get('temporal.address', 'temporal:7233');
    }

    private function namespace(): string
    {
        return (string) $this->config->get('temporal.namespace', 'default');
    }

    private function identity(): string
    {
        return (string) $this->config->get('temporal.identity', 'hawki-rag-laravel');
    }
}
