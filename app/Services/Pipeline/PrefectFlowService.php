<?php

namespace App\Services\Pipeline;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PrefectFlowService
{
    public function startFlowRun(string $taskId, array $parameters = []): array
    {
        if (! (bool) config('prefect.enabled', false)) {
            return [
                'enabled' => false,
                'started' => false,
                'message' => 'Prefect start is disabled. Set PREFECT_ENABLED=true to launch flow runs.',
            ];
        }

        $payload = array_merge([
            'task_id' => $taskId,
            'laravel_base_url' => (string) config('prefect.laravel_base_url'),
            'api_token' => (string) config('prefect.api_token', ''),
            'poll_interval_seconds' => (int) config('prefect.poll_interval_seconds', 10),
            'max_idle_seconds' => (int) config('prefect.max_idle_seconds', 3600),
        ], $parameters);

        if ((string) config('prefect.start_mode', 'cli') === 'api') {
            return $this->startViaApi($taskId, $payload);
        }

        $process = new Process([
            (string) config('prefect.command', 'prefect'),
            'deployment',
            'run',
            (string) config('prefect.deployment_name', 'rag-task-flow/rag-task-flow'),
            '--params',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ], base_path(), null, null, (int) config('prefect.timeout', 30));

        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            return [
                'enabled' => true,
                'started' => false,
                'message' => $exception->getMessage(),
                'stdout' => $process->getOutput(),
                'stderr' => $process->getErrorOutput(),
            ];
        }

        $stdout = $process->getOutput();

        return [
            'enabled' => true,
            'started' => true,
            'message' => 'Prefect flow run requested.',
            'deployment' => (string) config('prefect.deployment_name'),
            'flow_run_id' => $this->extractUuid($stdout),
            'stdout' => $stdout,
            'stderr' => $process->getErrorOutput(),
            'parameters' => [
                'task_id' => $taskId,
            ],
        ];
    }

    private function startViaApi(string $taskId, array $parameters): array
    {
        $apiUrl = rtrim((string) config('prefect.api_url', ''), '/');
        if ($apiUrl === '') {
            return [
                'enabled' => true,
                'started' => false,
                'message' => 'PREFECT_START_MODE=api requires PREFECT_API_URL.',
            ];
        }

        $deploymentId = trim((string) config('prefect.deployment_id', ''));
        $request = Http::timeout((int) config('prefect.timeout', 30))
            ->acceptJson()
            ->asJson();

        $apiKey = trim((string) config('prefect.api_key', ''));
        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        if ($deploymentId === '') {
            $deploymentName = $this->deploymentNameParts();
            $deployment = $request->get(
                "{$apiUrl}/deployments/name/"
                . rawurlencode($deploymentName['flow'])
                . '/'
                . rawurlencode($deploymentName['deployment']),
            );

            if (!$deployment->successful()) {
                return [
                    'enabled' => true,
                    'started' => false,
                    'message' => "Prefect deployment lookup failed with HTTP {$deployment->status()}.",
                    'body' => $deployment->json() ?? $deployment->body(),
                ];
            }

            $deploymentId = (string) ($deployment->json('id') ?? '');
        }

        if ($deploymentId === '') {
            return [
                'enabled' => true,
                'started' => false,
                'message' => 'Prefect deployment ID could not be resolved.',
            ];
        }

        $response = $request->post("{$apiUrl}/deployments/{$deploymentId}/create_flow_run", [
            'name' => "hawki-rag-{$taskId}",
            'parameters' => $parameters,
            'tags' => ['hawki-rag', "task:{$taskId}"],
        ]);

        return [
            'enabled' => true,
            'started' => $response->successful(),
            'message' => $response->successful()
                ? 'Prefect flow run requested through API.'
                : "Prefect flow run request failed with HTTP {$response->status()}.",
            'deployment_id' => $deploymentId,
            'flow_run_id' => (string) ($response->json('id') ?? ''),
            'body' => $response->json() ?? $response->body(),
            'parameters' => [
                'task_id' => $taskId,
            ],
        ];
    }

    private function deploymentNameParts(): array
    {
        $configured = trim((string) config('prefect.deployment_name', 'rag-task-flow/rag-task-flow'));
        $parts = explode('/', $configured, 2);

        return [
            'flow' => trim((string) config('prefect.flow_name', $parts[0] ?: 'rag_task_flow')),
            'deployment' => $parts[1] ?? $parts[0] ?? 'rag-task-flow',
        ];
    }

    private function extractUuid(string $text): ?string
    {
        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $text, $match) === 1) {
            return Str::lower($match[0]);
        }

        return null;
    }
}
