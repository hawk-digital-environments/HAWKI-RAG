<?php

declare(strict_types=1);

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\HealthCheckRequest;
use App\Services\Pipeline\Clients\PythonUrlIngestionClient;
use App\Services\Pipeline\Health\PipelineHealthService;
use App\Services\Pipeline\PipelineExecutionBackend;
use App\Services\Pipeline\Repositories\PipelineOwnershipRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;

class PipelineHealthController extends Controller
{
    public function __construct(
        private readonly PipelineHealthService $health,
        private readonly PipelineExecutionBackend $execution,
        private readonly PipelineOwnershipRepository $legacy,
        private readonly ConfigRepository $config,
        private readonly PythonUrlIngestionClient $python,
    ) {}

    public function show(HealthCheckRequest $request): JsonResponse
    {
        $payload = [
            'success' => true,
            'checkedAt' => now()->toAtomString(),
            'checks' => $this->health->check($request->timeout() ?? 5),
        ];
        $backend = $this->config->get('config.crawler_ingestion_backend', 'laravel');
        if ($backend !== 'laravel') {
            $payload['url_ingestion'] = $backend === 'python' ? $this->python->readiness() : ['ready' => false, 'configuration_valid' => false];
        }

        $payload['pipeline_execution_backend'] = $this->execution->value();
        $payload += $this->legacy->drainCounts();
        if ($this->execution->value() !== 'laravel') {
            $payload['python_execution'] = $this->execution->valid() ? $this->python->readiness() : ['ready' => false, 'configuration_valid' => false];
            $payload['success'] = ($payload['python_execution']['ready'] ?? false) === true;
        }

        return response()->json($payload, $payload['success'] ? 200 : 503);
    }
}
