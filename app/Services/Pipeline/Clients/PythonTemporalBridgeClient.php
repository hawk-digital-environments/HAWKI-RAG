<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Clients;

use App\Services\Pipeline\Values\TemporalWorkflowExecution;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
/** Compatibility facade: Python owns workflow identity validation and Temporal policies. */
readonly class PythonTemporalBridgeClient
{
    public function __construct(
        private ConfigRepository $config,
        private LoggerInterface $logger,
        private HttpFactory $http,
        private ClockInterface $clock = new Clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function startIngestWorkflow(array $input, ?string $workflowId = null, bool $managedReplacement = false): TemporalWorkflowExecution
    {
        $this->ensureEnabled();

        $payload = [
            'workflow_id' => $workflowId ?? $this->workflowIdFor($input),
            'workflow_input' => $input,
        ];

        if ($managedReplacement) {
            $payload['managed_replacement'] = true;
        }
        $body = $managedReplacement ? $this->postSigned('/source/start', $payload)
            : $this->post('/source/start', $payload);
        if ($managedReplacement && (($body['workflow_id'] ?? null) !== $payload['workflow_id'] || empty($body['run_id']))) {
            throw new \RuntimeException('Managed replacement workflow identity is unconfirmed.');
        }

        $execution = new TemporalWorkflowExecution(
            $this->stringValue($body['workflow_id'] ?? $payload['workflow_id']),
            $this->nullableString($body['run_id'] ?? null),
            $this->nullableString($body['schedule_id'] ?? null),
        );

        $this->logger->info('Temporal ingest workflow requested through Python bridge.', [
            'source_id' => $input['source_id'] ?? null,
            'workflow_id' => $execution->workflowId,
            'run_id' => $execution->runId,
        ]);

        return $execution;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function startTextIngestWorkflow(
        array $input,
        string $workflowId,
        int $recoveryAttempt = 0,
    ): TemporalWorkflowExecution {
        $this->ensureEnabled();

        $payload = [
            'workflow_id' => $workflowId,
            'workflow_input' => $input,
            ...($recoveryAttempt > 0 ? ['recovery_attempt' => $recoveryAttempt] : []),
        ];

        $body = $this->post(
            '/text/start',
            $payload,
        );

        $returnedWorkflowId = $this->stringValue($body['workflow_id'] ?? $workflowId);
        if (! hash_equals($workflowId, $returnedWorkflowId)) {
            throw new \RuntimeException('Python Temporal bridge returned an unexpected workflow ID.');
        }

        $execution = new TemporalWorkflowExecution(
            $returnedWorkflowId,
            $this->nullableString($body['run_id'] ?? null),
            null,
        );

        $this->logger->info(
            'Temporal text ingestion workflow requested through Python bridge.',
            [
                'source_id' => $input['source_id'] ?? null,
                'workflow_id' => $execution->workflowId,
                'run_id' => $execution->runId,
            ],
        );

        return $execution;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function upsertIngestSchedule(string $scheduleId, string $workflowId, string $cadence, array $input): TemporalWorkflowExecution
    {
        $this->ensureEnabled();

        $body = $this->post('/schedule/upsert', [
            'schedule_id' => $scheduleId,
            'workflow_id' => $workflowId,
            'cadence' => $cadence,
            'workflow_input' => $input,
        ]);

        $execution = new TemporalWorkflowExecution(
            $this->stringValue($body['workflow_id'] ?? $workflowId),
            $this->nullableString($body['run_id'] ?? null),
            $this->nullableString($body['schedule_id'] ?? $scheduleId),
        );

        $this->logger->info('Temporal ingest schedule requested through Python bridge.', [
            'source_id' => $input['source_id'] ?? null,
            'workflow_id' => $execution->workflowId,
            'schedule_id' => $execution->scheduleId,
            'cadence' => $cadence,
        ]);

        return $execution;
    }

    public function deleteSchedule(string $scheduleId): void
    {
        $this->ensureEnabled();

        try {
            $this->post('/schedule/delete', [
                'schedule_id' => $scheduleId,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Temporal ingest schedule delete through Python bridge failed.', [
                'schedule_id' => $scheduleId,
                'exception_class' => $exception::class,
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function workflowStatus(string $workflowId, string $runId): array
    {
        $this->ensureEnabled();

        return $this->post('/execution/status', [
            'workflow_id' => $workflowId,
            'run_id' => $runId,
        ]);
    }

    /** @return array<string, mixed> */
    public function deleteOutputs(string $workflowId, array $input): array
    {
        $this->ensureEnabled();
        $result = $this->postSigned('/deletion/start', [
            'workflow_id' => $workflowId,
            'workflow_input' => $input,
        ]);
        if (($result['workflow_id'] ?? null) !== $workflowId) {
            throw new \RuntimeException('Deletion workflow acknowledgement has an unexpected identity.');
        }

        return $result;
    }

    public function cancelWorkflow(string $workflowId, ?string $runId = null): void
    {
        $this->ensureEnabled();

        $this->post('/execution/cancel', [
            'workflow_id' => $workflowId,
            'run_id' => $runId,
        ]);

        $this->logger->info('Temporal ingest workflow cancellation requested through Python bridge.', [
            'workflow_id' => $workflowId,
            'run_id' => $runId,
        ]);
    }

    public function cancelWorkflowAndWait(string $workflowId, ?string $runId = null): void
    {
        $this->ensureEnabled();

        $this->post('/execution/stop-for-deletion', [
            'workflow_id' => $workflowId,
            'run_id' => $runId,
        ]);

        $this->logger->info('Temporal ingest workflow stopped before deletion.', [
            'workflow_id' => $workflowId,
            'run_id' => $runId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        return $this->postSigned($path, $payload);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function postSigned(string $path, array $payload): array
    {
        $secret = (string) $this->config->get('temporal.callbacks.secret');
        if ($secret === '') {
            throw new \RuntimeException('Authenticated managed Temporal control is not configured.');
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) $this->clock->now()->getTimestamp();
        $signingInput = "hawki-workflow-control-v1\nPOST\n/internal/workflow-control{$path}\n{$timestamp}\n".$body;
        try {
            $response = $this->http->timeout($this->timeout())->acceptJson()->withoutRedirecting()
                ->withOptions(['cookies' => false])->withHeaders([
                    'X-Hawki-Timestamp' => $timestamp,
                    'X-Hawki-Signature' => 'v1='.hash_hmac('sha256', $signingInput, $secret),
                ])->withBody($body, 'application/json')->post($this->bridgeUrl().$path);
            $result = $response->json();
            if (! $response->successful() || ! is_array($result)) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            // Never retain raw HTTP/Temporal errors or authenticated request bodies.
            throw new \RuntimeException('Python workflow control is unconfirmed; reconcile the same prepared operation.');
        }

        return $result;
    }

    /** Python decides retry identities; Laravel persists the returned preparation. */
    public function sourceRetryIdentity(string $sourceId, ?string $previous, int $attempt, string $jobId): string
    {
        $this->ensureEnabled();
        $body = $this->post('/source/retry-identity', [
            'source_id' => $sourceId, 'previous_workflow_id' => $previous,
            'attempt' => max(1, $attempt), 'job_id' => $jobId,
        ]);
        if (! is_string($body['workflow_id'] ?? null) || $body['workflow_id'] === '') {
            throw new \RuntimeException('Python workflow preparation has no confirmed identity.');
        }

        return $body['workflow_id'];
    }

    private function workflowIdFor(array $input): string
    {
        $sourceId = is_scalar($input['source_id'] ?? null) ? (string) $input['source_id'] : 'unknown';

        return 'ingest-source-'.$sourceId;
    }

    private function ensureEnabled(): void
    {
        if (! (bool) $this->config->get('temporal.enabled', true)) {
            throw new \RuntimeException('Temporal orchestration is disabled. Set HAWKI_RAG_TEMPORAL_ENABLED=true.');
        }
    }

    private function bridgeUrl(): string
    {
        return rtrim((string) $this->config->get('temporal.control_url', 'http://hawki_backend'), '/').'/internal/workflow-control';
    }

    private function timeout(): int
    {
        return max(1, (int) $this->config->get('temporal.bridge_timeout', 30));
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : 'unknown';
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }
}
