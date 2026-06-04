<?php

namespace App\Console\Commands;

use App\Console\Commands\Pipeline\ConverterEventWorkerCommand;
use App\Console\Commands\Pipeline\IngestionEventWorkerCommand;
use App\Console\Commands\Pipeline\ScraperEventWorkerCommand;
use App\Services\Rag\RagRabbitMQ;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

class PipelineHealthCommand extends Command
{
    protected $signature = 'pipeline:health
        {--timeout=5 : HTTP and connection timeout in seconds}';

    protected $description = 'Check MVP pipeline dependencies and print clear fix suggestions.';

    public function handle(): int
    {
        $timeout = max(1, (int) $this->option('timeout'));
        $results = [
            $this->checkDatabase(),
            $this->checkRabbitMQ(),
            $this->checkScraper($timeout),
            $this->checkConverter($timeout),
            $this->checkIngestion($timeout),
            $this->checkQdrant($timeout),
            $this->checkNeo4j($timeout),
            $this->checkSharedStorage(),
        ];

        $this->line('HAWKI RAG MVP pipeline health');
        $this->newLine();

        foreach ($results as $result) {
            $this->printResult($result);
        }

        $failed = collect($results)->contains(fn (array $result): bool => $result['status'] === 'fail');
        $this->newLine();
        $failed
            ? $this->error('Pipeline health failed. Fix the red checks above before starting a pipeline task.')
            : $this->info('Pipeline health passed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->select('select 1 as ok');

            return $this->ok(
                'Database',
                sprintf(
                    'Connected via %s to %s:%s/%s.',
                    config('database.default'),
                    config('database.connections.mysql.host'),
                    config('database.connections.mysql.port'),
                    config('database.connections.mysql.database'),
                ),
            );
        } catch (Throwable $exception) {
            return $this->failureResult(
                'Database',
                $exception->getMessage(),
                'Start MariaDB and verify DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, and DB_PASSWORD.',
            );
        }
    }

    private function checkRabbitMQ(): array
    {
        if (! (bool) config('communication.rabbitmq.pipeline_events.enabled', true)) {
            return $this->failureResult(
                'RabbitMQ',
                'Pipeline events are disabled.',
                'Set RABBITMQ_PIPELINE_EVENTS_ENABLED=true and start rabbitmq.',
            );
        }

        try {
            $rabbit = app(RagRabbitMQ::class);
            $rabbit->channel();
            $rabbit->close();

            return $this->ok(
                'RabbitMQ',
                sprintf(
                    'Connected to %s:%s, exchange %s.',
                    config('communication.rabbitmq.host'),
                    config('communication.rabbitmq.port'),
                    config('communication.rabbitmq.pipeline_events.exchange'),
                ),
            );
        } catch (Throwable $exception) {
            return $this->failureResult(
                'RabbitMQ',
                $exception->getMessage(),
                'Start rabbitmq and verify RABBITMQ_HOST, RABBITMQ_PORT, RABBITMQ_USER, RABBITMQ_PASSWORD, and RABBITMQ_VHOST.',
            );
        }
    }

    private function checkScraper(int $timeout): array
    {
        $worker = $this->workerConfig('scraper');
        $url = rtrim((string) config('scraper.api_url'), '/') . '/health';
        $configError = $this->workerConfigError(ScraperEventWorkerCommand::class, $worker, 'scraper');
        if ($configError !== null) {
            return $configError;
        }

        return $this->httpReachabilityCheck(
            'Scraper worker',
            $url,
            $timeout,
            sprintf('Worker command registered, queue %s listens to %s.', $worker['queue'], implode(', ', $worker['listen'])),
            'Start the scraper service or set CUSTOM_CRAWLER_URL. Start the consumer with php artisan pipeline:scraper-event-worker.',
        );
    }

    private function checkConverter(int $timeout): array
    {
        $worker = $this->workerConfig('converter');
        $url = (string) config('file_converter.url');
        $configError = $this->workerConfigError(ConverterEventWorkerCommand::class, $worker, 'converter');
        if ($configError !== null) {
            return $configError;
        }

        if (trim($url) === '') {
            return $this->failureResult(
                'Converter worker',
                'FILE_CONVERTER_URL is empty.',
                'Set FILE_CONVERTER_URL and start php artisan pipeline:converter-event-worker.',
            );
        }

        return $this->httpReachabilityCheck(
            'Converter worker',
            $url,
            $timeout,
            sprintf('Worker command registered, queue %s listens to %s.', $worker['queue'], implode(', ', $worker['listen'])),
            'Start the file converter service or set FILE_CONVERTER_URL. Start the consumer with php artisan pipeline:converter-event-worker.',
        );
    }

    private function checkIngestion(int $timeout): array
    {
        $worker = $this->workerConfig('ingestion');
        $bridge = rtrim((string) config('config.hawki_rag_bridge_url'), '/');
        $configError = $this->workerConfigError(IngestionEventWorkerCommand::class, $worker, 'ingestion');
        if ($configError !== null) {
            return $configError;
        }

        if ($bridge === '') {
            return $this->failureResult(
                'Ingestion worker',
                'HAWKI_RAG_BRIDGE_URL is empty.',
                'Set HAWKI_RAG_BRIDGE_URL and start php artisan pipeline:ingestion-event-worker.',
            );
        }

        return $this->httpSuccessCheck(
            'Ingestion worker',
            $bridge . '/health',
            $timeout,
            sprintf(
                'Worker command registered, queue %s listens to %s. Provider: %s, graph: %s.',
                $worker['queue'],
                implode(', ', $worker['listen']),
                config('communication.rabbitmq.pipeline_ingestion.provider'),
                filter_var(config('communication.rabbitmq.pipeline_ingestion.graph'), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            ),
            'Start hawki_rag_bridge or set HAWKI_RAG_BRIDGE_URL. Start the consumer with php artisan pipeline:ingestion-event-worker.',
        );
    }

    private function checkQdrant(int $timeout): array
    {
        $url = rtrim((string) config('config.qdrant_http_url'), '/');
        if ($url === '') {
            $qdrant = config('model_providers.vector_stores.qdrant', []);
            $url = sprintf('%s://%s:%s', $qdrant['scheme'] ?? 'http', $qdrant['host'] ?? 'qdrant', $qdrant['port'] ?? 6333);
        }

        try {
            $request = Http::timeout($timeout)->connectTimeout($timeout)->acceptJson();
            if ($apiKey = config('model_providers.vector_stores.qdrant.api_key')) {
                $request = $request->withHeader('api-key', (string) $apiKey);
            }

            $response = $request->get($url . '/collections');
            if ($response->successful()) {
                return $this->ok('Qdrant', 'Connected to ' . $url . '.');
            }

            return $this->failureResult(
                'Qdrant',
                "HTTP {$response->status()} from {$url}/collections.",
                'Start qdrant and verify QDRANT_HTTP_URL, QDRANT_HOST, QDRANT_PORT, and QDRANT_API_KEY.',
            );
        } catch (Throwable $exception) {
            return $this->failureResult(
                'Qdrant',
                $exception->getMessage(),
                'Start qdrant and verify QDRANT_HTTP_URL, QDRANT_HOST, QDRANT_PORT, and QDRANT_API_KEY.',
            );
        }
    }

    private function checkNeo4j(int $timeout): array
    {
        $url = rtrim((string) config('config.neo4j_http_url'), '/');
        $database = trim((string) env('NEO4J_DATABASE', 'neo4j')) ?: 'neo4j';

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->withBasicAuth((string) config('config.neo4j_user'), (string) config('config.neo4j_password'))
                ->acceptJson()
                ->asJson()
                ->post($url . "/db/{$database}/tx/commit", [
                    'statements' => [[
                        'statement' => 'RETURN 1 AS ok',
                    ]],
                ]);

            $errors = $response->json('errors') ?? [];
            if ($response->successful() && $errors === []) {
                return $this->ok('Neo4j', "Connected to {$url}, database {$database}.");
            }

            return $this->failureResult(
                'Neo4j',
                $response->successful()
                    ? 'Neo4j returned errors: ' . json_encode($errors, JSON_UNESCAPED_SLASHES)
                    : "HTTP {$response->status()} from {$url}.",
                'Start Neo4j and verify NEO4J_HTTP_URL, NEO4J_USER, NEO4J_PASSWORD, and NEO4J_DATABASE.',
            );
        } catch (Throwable $exception) {
            return $this->failureResult(
                'Neo4j',
                $exception->getMessage(),
                'Start Neo4j and verify NEO4J_HTTP_URL, NEO4J_USER, NEO4J_PASSWORD, and NEO4J_DATABASE.',
            );
        }
    }

    private function checkSharedStorage(): array
    {
        $paths = array_values(array_unique(array_filter([
            (string) config('communication.rabbitmq.pipeline_ingestion.shared_storage_root'),
            (string) config('scraper.storage_path'),
            (string) config('config.shared_root'),
        ])));

        foreach ($paths as $path) {
            if (! File::isDirectory($path)) {
                return $this->failureResult(
                    'Shared storage',
                    "Path does not exist: {$path}.",
                    'Create the shared path or mount the Docker shared_storage volume at the configured path.',
                );
            }

            if (! is_writable($path)) {
                return $this->failureResult(
                    'Shared storage',
                    "Path is not writable: {$path}.",
                    'Fix permissions for SHARED_STORAGE_ROOT, SCRAPE_STORAGE_PATH, and HAWKI_RAG_PIPELINE_ROOT.',
                );
            }
        }

        return $this->ok('Shared storage', 'Writable paths: ' . implode(', ', $paths) . '.');
    }

    private function httpReachabilityCheck(string $name, string $url, int $timeout, string $detail, string $fix): array
    {
        try {
            $response = Http::timeout($timeout)->connectTimeout($timeout)->acceptJson()->get($url);
            if ($response->status() < 500) {
                return $this->ok($name, "{$detail} Service reachable at {$url} with HTTP {$response->status()}.");
            }

            return $this->failureResult($name, "HTTP {$response->status()} from {$url}.", $fix);
        } catch (Throwable $exception) {
            return $this->failureResult($name, $exception->getMessage(), $fix);
        }
    }

    private function httpSuccessCheck(string $name, string $url, int $timeout, string $detail, string $fix): array
    {
        try {
            $response = Http::timeout($timeout)->connectTimeout($timeout)->acceptJson()->get($url);
            if ($response->successful()) {
                return $this->ok($name, "{$detail} Service healthy at {$url}.");
            }

            return $this->failureResult($name, "HTTP {$response->status()} from {$url}.", $fix);
        } catch (Throwable $exception) {
            return $this->failureResult($name, $exception->getMessage(), $fix);
        }
    }

    private function workerConfig(string $worker): array
    {
        $config = config("communication.rabbitmq.pipeline_events.workers.{$worker}");

        return is_array($config) ? $config : [];
    }

    private function workerConfigError(string $commandClass, array $worker, string $name): ?array
    {
        if (! class_exists($commandClass)) {
            return $this->failureResult(
                ucfirst($name) . ' worker',
                "Command class {$commandClass} is missing.",
                'Restore the MVP pipeline worker command class.',
            );
        }

        if (($worker['queue'] ?? '') === '' || ! is_array($worker['listen'] ?? null) || $worker['listen'] === []) {
            return $this->failureResult(
                ucfirst($name) . ' worker',
                'RabbitMQ worker queue or listen events are not configured.',
                "Set communication.rabbitmq.pipeline_events.workers.{$name}.queue and listen events.",
            );
        }

        return null;
    }

    private function printResult(array $result): void
    {
        $line = sprintf('[%s] %s - %s', strtoupper($result['status']), $result['name'], $result['detail']);

        match ($result['status']) {
            'ok' => $this->info($line),
            'warn' => $this->warn($line),
            default => $this->error($line),
        };

        if ($result['status'] === 'fail' && ($result['fix'] ?? '') !== '') {
            $this->line('      Fix: ' . $result['fix']);
        }
    }

    private function ok(string $name, string $detail): array
    {
        return [
            'name' => $name,
            'status' => 'ok',
            'detail' => $detail,
            'fix' => '',
        ];
    }

    private function failureResult(string $name, string $detail, string $fix): array
    {
        return [
            'name' => $name,
            'status' => 'fail',
            'detail' => $detail,
            'fix' => $fix,
        ];
    }
}
