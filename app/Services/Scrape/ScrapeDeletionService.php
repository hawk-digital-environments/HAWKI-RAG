<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use App\Services\Scrape\Repositories\ScrapeProcessRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;

class ScrapeDeletionService
{
    public function __construct(
        private readonly ScrapeProcessRepository $processes,
        private readonly ConfigRepository $config,
        private readonly Filesystem $files,
        private readonly LoggerInterface $logger,
    ) {}

    public function deleteJob(string $jobId): bool
    {
        try {
            $process = $this->processes->findByJobIdOrFail($jobId);
            $this->processes->deleteWithRelations($process);

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('failed to delete scrape job '.$jobId.': '.$exception->getMessage(), ['exception' => $exception]);

            return false;
        }
    }

    public function deleteContent(string $jobId): bool
    {
        try {
            $process = $this->processes->findByJobIdOrFail($jobId);
            $request = $process->request ?? [];
            $outputDir = (string) ($request['output_dir'] ?? $request['outputDir'] ?? '');

            if ($outputDir === '') {
                return true;
            }

            $storageRoot = realpath((string) $this->config->get('scraper.storage_path'));
            $target = realpath($outputDir);

            if ($storageRoot === false || $target === false) {
                return true;
            }

            if ($target === $storageRoot || ! str_starts_with($target, $storageRoot.DIRECTORY_SEPARATOR)) {
                $this->logger->warning("refusing to delete scrape content outside storage root for job {$jobId}", [
                    'storage_root' => $storageRoot,
                    'target' => $target,
                ]);

                return false;
            }

            return $this->files->deleteDirectory($target);
        } catch (\Throwable $exception) {
            $this->logger->error('failed to delete scrape content '.$jobId.': '.$exception->getMessage(), ['exception' => $exception]);

            return false;
        }
    }
}
