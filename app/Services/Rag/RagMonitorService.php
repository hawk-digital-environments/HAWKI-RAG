<?php

declare(strict_types=1);

namespace App\Services\Rag;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class RagMonitorService
{
    public function __construct(
        private RagBridgeHealthClient $bridge,
        private RagMonitorArtifactReader $artifacts,
        private RagGraphConfigReporter $graphConfig,
        private RagLatestDocumentGraphReporter $documentGraph,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function show(): array
    {
        $bridge = $this->bridge->health(includeRuntime: true);
        $runtime = $this->bridge->runtime($bridge);

        return [
            'ok' => true,
            'bridge' => $bridge,
            'runtime' => $runtime,
            'config' => $this->graphConfig->report($runtime),
            'summary' => $this->artifacts->latestSummary(),
            'graph_preview' => $this->artifacts->latestGraphPreview(),
            'latest_document_graph' => $this->documentGraph->report(),
            'graph_failures' => $this->artifacts->graphFailures(5),
        ];
    }
}
