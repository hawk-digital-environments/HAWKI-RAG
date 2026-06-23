<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Clients;

use App\Services\Pipeline\Values\TemporalWorkflowExecution;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class PythonTemporalBridgeClient
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

        $payload = [
            'workflow_id' => $workflowId ?? $this->workflowIdFor($input),
            'workflow_input' => $input,
        ];

        $body = $this->post('/temporal/workflows/ingest', $payload);

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
     * @param array<string, mixed> $input
     */
    public function upsertIngestSchedule(string $scheduleId, string $workflowId, string $cadence, array $input): TemporalWorkflowExecution
    {
        $this->ensureEnabled();

        $body = $this->post('/temporal/schedules/ingest', [
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
            $this->post('/temporal/schedules/delete', [
                'schedule_id' => $scheduleId,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Temporal ingest schedule delete through Python bridge failed.', [
                'schedule_id' => $scheduleId,
                'error' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }

    public function cancelWorkflow(string $workflowId, ?string $runId = null): void
    {
        $this->ensureEnabled();

        $this->post('/temporal/workflows/cancel', [
            'workflow_id' => $workflowId,
            'run_id' => $runId,
        ]);

        $this->logger->info('Temporal ingest workflow cancellation requested through Python bridge.', [
            'workflow_id' => $workflowId,
            'run_id' => $runId,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $response = Http::timeout($this->timeout())
            ->acceptJson()
            ->asJson()
            ->post($this->bridgeUrl().$path, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'Python Temporal bridge request failed [%s %s]: %s',
                $response->status(),
                $path,
                $response->body(),
            ));
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new \RuntimeException("Python Temporal bridge returned non-object JSON for [{$path}].");
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $input
     */
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
        return rtrim((string) $this->config->get('config.hawki_rag_bridge_url', 'http://hawki_rag_bridge:8000'), '/');
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
