<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Services\Graph\GraphOpsBackend;
use App\Services\Graph\PythonGraphOpsClient;
use App\Services\Settings\SettingsService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class RagMonitorService
{
    public function __construct(
        private RagBridgeHealthClient $bridge,
        private RagMonitorArtifactReader $artifacts,
        private RagGraphConfigReporter $graphConfig,
        private RagLatestDocumentGraphReporter $documentGraph,
        private GraphOpsBackend $backend,
        private PythonGraphOpsClient $python,
        private RagStatsService $stats,
        private RagHealthService $health,
        private SettingsService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(): array
    {
        if (! $this->backend->valid()) {
            return ['ok' => false, 'status' => 503];
        }
        if ($this->backend->value() === 'python') {
            $config = $this->python->request('config', manager: true);
            $stats = $this->stats->show();
            $health = $this->health->show();
            if (! ($config['ok'] ?? false) || ! ($stats['ok'] ?? false) || $health['status'] !== 200) {
                return ['ok' => false, 'status' => 503, 'authority' => 'python'];
            }

            return ['ok' => true, 'authority' => 'python', 'config' => $config, 'runtime' => $config,
                'stats' => $stats, 'health' => $health['payload'],
                'legacy_telemetry' => ['notice' => 'Laravel compatibility telemetry; not Python runtime state.',
                    'summary' => $this->artifacts->latestSummary(), 'graph_failures' => $this->artifacts->graphFailures(5)]];
        }
        $bridge = $this->bridge->health(includeRuntime: true);
        $runtime = $this->bridge->runtime($bridge);

        return [
            'ok' => true,
            'bridge' => $bridge,
            'runtime' => $runtime,
            'config' => [...$this->graphConfig->report($runtime), ...$this->settings->authority()],
            'summary' => $this->artifacts->latestSummary(),
            'graph_preview' => $this->artifacts->latestGraphPreview(),
            'latest_document_graph' => $this->documentGraph->report(),
            'graph_failures' => $this->artifacts->graphFailures(5),
        ];
    }
}
