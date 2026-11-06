<?php

declare(strict_types=1);

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\HealthCheckRequest;
use App\Services\Pipeline\Clients\PythonUrlIngestionClient;
use App\Services\Pipeline\Health\PipelineHealthService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;

class PipelineHealthController extends Controller
{
    public function __construct(
        private readonly PipelineHealthService $health,
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

        return response()->json($payload);
    }
}
