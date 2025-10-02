<?php

namespace App\Services\Concerns\Crawler;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

trait ExecutesCrawler
{
    /**
     * Execute the Node.js crawler
     */
    protected function executeNodeCrawler(array $config, bool $isLocalFile, bool $shouldContinue, int $startFromIndex, string $outputDir, string $label): bool
    {
        $jsonConfig = json_encode($config);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (method_exists($this, 'error')) {
                $this->error("JSON encoding error: " . json_last_error_msg());
            }
            return false;
        }

        $nodePath = resource_path('js/crawler');
        if (!File::exists($nodePath)) {
            if (method_exists($this, 'error')) {
                $this->error('Node.js crawler directory not found. Please run the setup first.');
            }
            return false;
        }

        if (!File::exists("$nodePath/node_modules")) {
            if (method_exists($this, 'info')) {
                $this->info('Installing Node.js dependencies...');
            }
            $process = Process::path($nodePath)->run('npm install');

            if (!$process->successful()) {
                if (method_exists($this, 'error')) {
                    $this->error('Failed to install Node.js dependencies:');
                    $this->error($process->errorOutput());
                }
                return false;
            }
        }

        $urlCount = $isLocalFile ? count(data_get($config, 'urls', [])) : 'unknown';

        if (method_exists($this, 'info')) {
            $this->info("Starting crawler for " . ($isLocalFile ? "local file with $urlCount URLs" : $config['url']));
            $this->info("Output will be saved to $outputDir/{$config['label']}");
            if ($shouldContinue) {
                $this->info("Continuing from directory: " . Str::padLeft($startFromIndex, 5, '0'));
            }
        }

        $process = Process::path($nodePath)
            ->timeout(0)
            ->idleTimeout(0)
            ->run('node crawler.js ' . escapeshellarg($jsonConfig));

        if ($process->successful()) {
            if (method_exists($this, 'info')) {
                $this->info('Crawling completed successfully.');
                $this->info($process->output());
                if (!$config['skipImages']) {
                    $this->info('Images have been downloaded to the crawl directory.');
                }
            }
            return true;
        } else {
            if (method_exists($this, 'error')) {
                $this->error('Crawling failed:');
                $this->error($process->errorOutput());
            }
            return false;
        }
    }

    /**
     * Handle progress saving after successful completion
     */
    protected function handleSuccessfulCompletion(string $sourceType, array $config, int $startFromIndex, bool $shouldContinue, string $outputDir, string $label, array $sitemapUrls): void
    {
        if (!isset($this->progressService) || !isset($this->urlService)) {
            return;
        }

        if ($sourceType === 'local' && isset($config['urls'])) {
            $finalIndex = $startFromIndex + count($config['urls']) - 1;
            $totalUrlsAttempted = ($shouldContinue ? $this->urlService->countProcessedUrls($outputDir, $label, $sitemapUrls) : 0) + count($config['urls']);
            $this->progressService->saveProgress($label, $finalIndex, $totalUrlsAttempted);
        } else {
            $currentDirCount = count($this->getExistingDirectories($outputDir, $label));
            $this->progressService->saveProgress($label, $currentDirCount, $currentDirCount);
        }
    }
}
