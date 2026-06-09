<?php

declare(strict_types=1);

namespace App\Services\DirectIngest;

use App\Services\DirectIngest\Values\DirectIngestLaunchResult;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class DirectIngestLaunchService
{
    public function __construct(
        private DirectIngestCollectionProbe $collections,
        private DirectIngestCommandBuilder $commands,
        private CrawledDataFolderService $folders,
        private DirectIngestProcessLauncher $processes,
        private DirectIngestStatusStore $statuses,
        private DirectIngestStatusEntryFactory $entries,
        private DirectIngestLaunchStageReporter $reporter,
        private DirectIngestConfig $config,
        private Filesystem $files,
    ) {}

    public function launch(array $data): DirectIngestLaunchResult
    {
        $root = $this->folders->root();
        if (! $root) {
            return DirectIngestLaunchResult::fromPayload(['ok' => false, 'message' => 'Crawled-data root not found.'], 404);
        }

        $path = (string) $data['path'];
        if (! $this->files->isDirectory($path)) {
            return DirectIngestLaunchResult::fromPayload(['ok' => false, 'message' => "Path not found: {$path}"], 404);
        }
        if (! $this->folders->isPathWithinRoot($path, $root)) {
            return DirectIngestLaunchResult::fromPayload(['ok' => false, 'message' => 'Path must be within the crawled-data root.'], 422);
        }

        $script = $this->config->ingestScriptPath();
        if (! $this->files->isFile($script)) {
            return DirectIngestLaunchResult::fromPayload(['ok' => false, 'message' => 'ingest_crawled.py not found'], 500);
        }

        $statusMode = $this->statuses->modeForPayload($data);
        $statusPaths = $this->statuses->paths($statusMode);
        $this->statuses->ensureDirectories($statusPaths);

        $collection = (string) ($data['collection'] ?? basename($path));
        $summaryPath = $this->config->ingestSummaryPath();
        $cmd = $this->commands->build($data, $script, $path, $this->config->hawkiRagBridgeUrl(), $summaryPath);

        $collectionExists = $this->collections->exists($collection);
        $pipelineJobId = $this->entries->pipelineJobId($data);

        $entry = $this->entries->running($data, $cmd, $path, $collection, $collectionExists, $pipelineJobId, $summaryPath, $statusMode);
        $this->statuses->append($statusPaths->statusPath, $entry);
        $this->statuses->appendStartedLines($statusPaths, $path);

        $this->reporter->started($pipelineJobId, $path, $collection, $statusMode, $data);

        $pid = $this->processes->launch($cmd, $data, $statusPaths);
        if ($pid <= 0) {
            return $this->launchFailure($entry, $statusPaths->statusPath, $path, $collection, $pipelineJobId);
        }

        $entry = $this->entries->withPid($entry, $pid);
        $this->statuses->replaceById($statusPaths->statusPath, (string) $entry['id'], $entry);
        $this->reporter->launched($pipelineJobId, $path, $collection, $pid, $statusPaths->statusPath);

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

    private function launchFailure(array $entry, string $statusPath, string $path, string $collection, string $pipelineJobId): DirectIngestLaunchResult
    {
        $entry = $this->entries->failed($entry);
        $this->statuses->replaceById($statusPath, (string) $entry['id'], $entry);
        $this->reporter->launchFailed($pipelineJobId, $path, $collection);

        return DirectIngestLaunchResult::fromPayload([
            'ok' => false,
            'message' => 'Failed to launch ingest process.',
        ], 500);
    }
}
