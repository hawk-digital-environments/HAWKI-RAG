<?php

namespace App\Services\Crawler;

use App\Services\Crawler\Data\CrawlerConfig;
use App\Services\Crawler\Data\CrawlerResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class CrawlerExecutionService
{
    /**
     * Execute the Node.js crawler
     */
    public function execute(CrawlerConfig $config): CrawlerResult
    {
        $jsonConfig = $config->toJson();
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new CrawlerResult(
                success: false,
                output: '',
                error: "JSON encoding error: " . json_last_error_msg()
            );
        }

        $nodePath = resource_path('js/crawler');
        if (!File::exists($nodePath)) {
            return new CrawlerResult(
                success: false,
                output: '',
                error: 'Node.js crawler directory not found. Please run the setup first.'
            );
        }

        if (!File::exists("$nodePath/node_modules")) {
            $installResult = $this->installDependencies($nodePath);
            if ($installResult->isFailed()) {
                return $installResult;
            }
        }

        $process = Process::path($nodePath)
            ->timeout(0)
            ->idleTimeout(0)
            ->run('node crawler.js ' . escapeshellarg($jsonConfig));

        if ($process->successful()) {
            return new CrawlerResult(
                success: true,
                output: $process->output()
            );
        }

        return new CrawlerResult(
            success: false,
            output: $process->output(),
            error: $process->errorOutput()
        );
    }

    /**
     * Install Node.js dependencies
     */
    private function installDependencies(string $nodePath): CrawlerResult
    {
        $process = Process::path($nodePath)->run('npm install');

        if ($process->successful()) {
            return new CrawlerResult(
                success: true,
                output: 'Dependencies installed successfully'
            );
        }

        return new CrawlerResult(
            success: false,
            output: $process->output(),
            error: 'Failed to install Node.js dependencies: ' . $process->errorOutput()
        );
    }

    /**
     * Save crawl progress after successful completion
     */
    public function saveProgress(
        CrawlerConfig $config,
        string $label,
        int $startFromIndex,
        bool $shouldContinue,
        array $sitemapUrls,
        CrawlerProgressService $progressService,
        CrawlerUrlService $urlService,
        CrawlerDirectoryService $directoryService
    ): void {
        if ($config->sourceType === 'local' && isset($config->urls)) {
            $finalIndex = $startFromIndex + count($config->urls) - 1;
            $totalUrlsAttempted = ($shouldContinue
                    ? $urlService->countProcessedUrls($config->outputDir, $label, $sitemapUrls)
                    : 0) + count($config->urls);
            $progressService->saveProgress($label, $finalIndex, $totalUrlsAttempted);
        } else {
            $currentDirCount = count($directoryService->getExistingDirectories($config->outputDir, $label));
            $progressService->saveProgress($label, $currentDirCount, $currentDirCount);
        }
    }
}
