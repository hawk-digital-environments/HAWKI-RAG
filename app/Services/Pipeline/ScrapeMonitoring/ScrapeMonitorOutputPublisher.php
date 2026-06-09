<?php

declare(strict_types=1);

namespace App\Services\Pipeline\ScrapeMonitoring;

use App\Models\PipelineJob;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Events\PipelineEventStateService;
use App\Services\Pipeline\Uploads\PipelineUploadPolicy;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeMonitorOutputPublisher
{
    public function __construct(
        private PipelineEventBus $events,
        private PipelineEventStateService $state,
        private ScrapeMonitorPayloadService $payloads,
        private PipelineUploadPolicy $policy,
    ) {}

    public function publish(PipelineJob $job, string $datasetPath): void
    {
        $scrapedEvent = $this->payloads->pageScrapedEvent($job, $datasetPath);
        $this->state->upsertJob($scrapedEvent, PipelineJob::STATUS_COMPLETED, [
            'dataset_path' => $datasetPath,
        ]);
        $this->events->publish(PipelineEvent::PAGE_SCRAPED, $scrapedEvent);

        foreach ($this->supportedFiles($datasetPath) as $path) {
            $this->events->publish(
                PipelineEvent::FILE_DISCOVERED,
                $this->payloads->fileDiscoveredPayload($job, $datasetPath, $path),
            );
        }
    }

    private function supportedFiles(string $datasetPath): array
    {
        $resolved = realpath($datasetPath);
        if ($resolved === false || ! is_dir($resolved)) {
            return [];
        }

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $this->policy->supports($file->getExtension())) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
