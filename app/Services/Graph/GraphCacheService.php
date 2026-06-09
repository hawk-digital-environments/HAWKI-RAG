<?php

declare(strict_types=1);

namespace App\Services\Graph;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class GraphCacheService
{
    public function __construct(
        private ConfigRepository $config,
        private Filesystem $files,
        private HttpFactory $http,
        private ClockInterface $clock = new Clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function clearBridgeCache(): array
    {
        $baseUrl = rtrim((string) $this->config->get('config.hawki_rag_bridge_url', 'http://hawki_rag_bridge:8000'), '/');

        try {
            $response = $this->http->timeout(30)
                ->acceptJson()
                ->post($baseUrl.'/graph/cache/clear');

            if ($response->failed()) {
                return [
                    'ok' => false,
                    'status' => $response->status(),
                    'message' => 'Python RAG bridge failed to clear graph cache.',
                ];
            }

            return $response->json() ?? ['ok' => true];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function writeEmptyVisualizationSnapshot(): void
    {
        $this->writeVisualizationSnapshot([
            'ok' => true,
            'generated_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
            'limit' => null,
            'node_count' => 0,
            'relationship_count' => 0,
            'recent_doc_id' => null,
            'recent_relationship_count' => 0,
            'document_count' => 0,
            'nodes' => [],
            'links' => [],
        ]);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function writeVisualizationSnapshot(array $snapshot): void
    {
        $path = (string) $this->config->get('config.graph_visualization_path');
        $payload = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

        if ($this->files->exists($path) && ! is_writable($path)) {
            $this->files->delete($path);
        }

        $this->files->put($path, $payload);
        @chmod($path, 0666);
    }
}
