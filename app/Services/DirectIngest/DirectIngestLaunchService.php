<?php

declare(strict_types=1);

namespace App\Services\DirectIngest;

use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\State\PipelineStateService;
use App\Services\DirectIngest\Values\DirectIngestLaunchResult;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;

#[Singleton]
readonly class DirectIngestLaunchService
{
    public function __construct(
        private DirectIngestCollectionProbe $collections,
        private DirectIngestCommandBuilder $commands,
        private CrawledDataFolderService $folders,
        private PipelineStageLogger $logger,
        private DirectIngestProcessLauncher $processes,
        private PipelineStateService $pipelineState,
        private DirectIngestStatusStore $statuses,
    ) {}

    public function launch(array $data): DirectIngestLaunchResult
    {
        $root = $this->folders->root();
        if (! $root) {
            return DirectIngestLaunchResult::fromPayload(['ok' => false, 'message' => 'Crawled-data root not found.'], 404);
        }

        $path = (string) $data['path'];
        if (! is_dir($path)) {
            return DirectIngestLaunchResult::fromPayload(['ok' => false, 'message' => "Path not found: {$path}"], 404);
        }
        if (! $this->folders->isPathWithinRoot($path, $root)) {
            return DirectIngestLaunchResult::fromPayload(['ok' => false, 'message' => 'Path must be within the crawled-data root.'], 422);
        }

        $script = base_path('python_rag/ingest/ingest_crawled.py');
        if (! is_file($script)) {
            return DirectIngestLaunchResult::fromPayload(['ok' => false, 'message' => 'ingest_crawled.py not found'], 500);
        }

        $baseUrl = (string) config('config.hawki_rag_bridge_url', 'http://hawki_rag_bridge:8000');
        $statusMode = $this->statuses->modeForPayload($data);
        $statusPaths = $this->statuses->paths($statusMode);
        $this->statuses->ensureDirectories($statusPaths);

        $collection = (string) ($data['collection'] ?? basename($path));
        $summaryPath = storage_path('logs/ingest_summary.json');
        $cmd = $this->commands->build($data, $script, $path, $baseUrl, $summaryPath);

        $collectionExists = $this->collections->exists($collection);
        $pipelineJobId = trim((string) ($data['job_id'] ?? ''));
        if ($pipelineJobId === '') {
            $pipelineJobId = (string) Str::uuid();
        }

        $entry = $this->statusEntry($data, $cmd, $path, $collection, $collectionExists, $pipelineJobId, $summaryPath, $statusMode);
        $this->statuses->append($statusPaths->statusPath, $entry);
        $this->statuses->appendStartedLines($statusPaths, $path);

        $this->logger->started('ingest', [
            'job_id' => $pipelineJobId,
            'file_path' => $path,
            'collection' => $collection,
            'pipeline_stage' => 'process_launch',
            'graph' => ! empty($data['graph']),
            'graph_only' => ! empty($data['graph_only']),
        ]);
        $this->pipelineState->startStage($pipelineJobId, PipelineStateService::STAGE_INGEST, [
            'dataset_path' => $path,
            'counts' => [
                'total' => 0,
                'received' => 0,
                'processing' => 1,
                'completed' => 0,
                'failed' => 0,
            ],
            'metadata' => [
                'mode' => 'direct-ui',
                'statusMode' => $statusMode,
                'collection' => $collection,
                'graph' => ! empty($data['graph']),
                'graphOnly' => ! empty($data['graph_only']),
                'resumeMode' => $data['resume_mode'] ?? 'resume',
            ],
        ]);

        $pid = $this->processes->launch($cmd, $data, $statusPaths);
        if ($pid <= 0) {
            return $this->launchFailure($entry, $statusPaths->statusPath, $path, $collection, $pipelineJobId);
        }

        $entry['pid'] = $pid;
        $entry['updated_at'] = now()->toIso8601String();
        $this->statuses->replaceById($statusPaths->statusPath, (string) $entry['id'], $entry);
        $this->logger->success('ingest', [
            'job_id' => $pipelineJobId,
            'file_path' => $path,
            'collection' => $collection,
            'pipeline_stage' => 'process_launch',
            'pid' => $pid,
            'status_path' => $statusPaths->statusPath,
        ]);

        return DirectIngestLaunchResult::fromPayload([
            'ok' => true,
            'job_id' => $pipelineJobId,
            'pid' => $pid,
            'status_path' => $statusPaths->statusPath,
            'log_path' => $statusPaths->cacheLogPath,
            'full_log_path' => $statusPaths->fullLogPath,
            'collection_exists' => $collectionExists,
        ]);
    }

    private function statusEntry(
        array $data,
        array $cmd,
        string $path,
        string $collection,
        bool $collectionExists,
        string $pipelineJobId,
        string $summaryPath,
        string $statusMode,
    ): array {
        $now = now()->toIso8601String();

        return [
            'id' => (string) Str::uuid(),
            'pipeline_job_id' => $pipelineJobId,
            'started_at' => $now,
            'updated_at' => $now,
            'status' => 'running',
            'progress' => null,
            'last_line' => null,
            'summary_path' => $summaryPath,
            'command' => $cmd,
            'path' => $path,
            'collection' => $collection,
            'collection_exists' => $collectionExists,
            'source' => 'api',
            'resume_mode' => $data['resume_mode'] ?? 'resume',
            'graph' => ! empty($data['graph']),
            'graph_only' => ! empty($data['graph_only']),
            'neo4j_database' => isset($data['neo4j_database']) ? trim((string) $data['neo4j_database']) : null,
            'status_mode' => $statusMode,
        ];
    }

    private function launchFailure(array $entry, string $statusPath, string $path, string $collection, string $pipelineJobId): DirectIngestLaunchResult
    {
        $entry['status'] = 'failed';
        $entry['updated_at'] = now()->toIso8601String();
        $this->statuses->replaceById($statusPath, (string) $entry['id'], $entry);
        $this->logger->failed('ingest', [
            'job_id' => $pipelineJobId,
            'file_path' => $path,
            'collection' => $collection,
            'pipeline_stage' => 'process_launch',
            'error_message' => 'Failed to launch ingest process.',
        ]);
        $this->pipelineState->failStage($pipelineJobId, PipelineStateService::STAGE_INGEST, [
            'dataset_path' => $path,
            'counts' => [
                'total' => 0,
                'received' => 0,
                'processing' => 0,
                'completed' => 0,
                'failed' => 1,
            ],
            'errors' => [[
                'message' => 'Failed to launch ingest process.',
                'updatedAt' => now()->toIso8601String(),
            ]],
            'metadata' => ['mode' => 'direct-ui'],
        ]);

        return DirectIngestLaunchResult::fromPayload([
            'ok' => false,
            'message' => 'Failed to launch ingest process.',
        ], 500);
    }
}
