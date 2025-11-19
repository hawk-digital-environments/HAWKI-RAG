<?php

namespace App\Services\Crawler\Pipeline;

use App\Services\Crawler\Data\DirectoryAnalysis;
use App\Services\Crawler\Storage\CrawlerStorageManager;

class CrawlerDirectoryService
{
    public function __construct(
        private CrawlerStorageManager $storage,
        private CrawlerUrlService $urlService
    ) {}

    public function getExistingDirectories(string $outputDir, string $label): array
    {
        return $this->storage->getNumberedDirectories($label);
    }

    public function isDirectoryComplete(string $outputDir, string $label, int $dirNumber): bool
    {
        return $this->storage->isDirectoryComplete($label, $dirNumber);
    }

    public function scanDirectoriesForCompleteness(string $outputDir, string $label): DirectoryAnalysis
    {
        $directories = $this->getExistingDirectories($outputDir, $label);

        if (empty($directories)) {
            return new DirectoryAnalysis(
                complete: [],
                incomplete: [],
                lastComplete: 0,
                incompleteUrls: []
            );
        }

        // Partition directories into complete and incomplete
        [$completeDirectories, $incompleteDirectories] = collect($directories)
            ->partition(fn($dirNumber) => $this->storage->isDirectoryComplete($label, $dirNumber));

        // Extract URLs from incomplete directories
        $incompleteUrls = $incompleteDirectories
            ->mapWithKeys(function ($dirNumber) use ($label) {
                $dirPath = $this->storage->directoryPath($label, $dirNumber);
                $extractedUrl = $this->urlService->extractUrlFromIncompleteDirectory($dirPath, $dirNumber);
                return filled($extractedUrl) ? [$dirNumber => $extractedUrl] : [];
            })
            ->toArray();

        $lastComplete = $completeDirectories->isEmpty() ? 0 : $completeDirectories->max();

        return new DirectoryAnalysis(
            complete: $completeDirectories->values()->toArray(),
            incomplete: $incompleteDirectories->values()->toArray(),
            lastComplete: $lastComplete,
            incompleteUrls: $incompleteUrls
        );
    }

    public function setupOutputDirectory(): ?string
    {
        // Return the full path for the Node.js crawler
        // Note: PHP storage operations use CrawlerStorageManager instead
        return storage_path('app/private/crawled-data');
    }

    public function deleteLabel(string $label): void
    {
        if ($this->storage->isDirectory($label)) {
            $this->storage->deleteDirectory($label);
        }
    }

    public function clearIncompleteDirectories(string $outputDir, string $label, array $incompleteDirectories): void
    {
        foreach ($incompleteDirectories as $dirNumber) {
            $dirPath = $this->storage->directoryPath($label, $dirNumber);
            if ($this->storage->isDirectory($dirPath)) {
                $this->storage->deleteDirectory($dirPath);
            }
        }
    }
}
