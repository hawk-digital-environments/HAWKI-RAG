<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Services\Graph\Exceptions\GraphCacheException;
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
                $exception = GraphCacheException::bridgeClearFailed($response->status());

                return [
                    'ok' => false,
                    'status' => $response->status(),
                    'message' => $exception->getMessage(),
                ];
            }

            return $response->json() ?? ['ok' => true];
        } catch (\Throwable $exception) {
            $graphException = $exception instanceof GraphCacheException
                ? $exception
                : GraphCacheException::bridgeClearRequestFailed($exception);

            return [
                'ok' => false,
                'message' => $graphException->getMessage(),
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
        $payload = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($payload)) {
            throw GraphCacheException::snapshotEncodingFailed($path);
        }

        try {
            $this->files->ensureDirectoryExists(dirname($path));

            if ($this->files->exists($path) && ! is_writable($path)) {
                $this->files->delete($path);
            }

            $this->files->put($path, $payload.PHP_EOL);
            @chmod($path, 0666);
        } catch (\Throwable $exception) {
            throw GraphCacheException::snapshotWriteFailed($path, $exception);
        }
    }
}
