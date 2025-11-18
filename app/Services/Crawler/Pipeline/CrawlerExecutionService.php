<?php

namespace App\Services\Crawler\Pipeline;

use App\Services\Crawler\Data\CrawlerConfig;
use App\Services\Crawler\Data\CrawlerResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class CrawlerExecutionService
{
    /**
     * Execute the Node.js web crawler with the provided configuration.
     *
     * Prepares and runs the Node.js crawler process with the given configuration.
     * The process involves:
     * 1. Converting config to JSON and validating
     * 2. Verifying the Node.js crawler directory exists
     * 3. Installing npm dependencies if needed
     * 4. Executing the crawler.js script with configuration
     * 5. Streaming output via optional callback
     *
     * The process runs without timeout to handle long-running crawls.
     *
     * @param CrawlerConfig $config Complete crawler configuration object
     * @param callable|null $outputCallback Optional callback for streaming output (callable(string $type, string $buffer))
     * @return CrawlerResult Result object with success status, output, and any errors
     */
    public function execute(CrawlerConfig $config, ?callable $outputCallback = null): CrawlerResult
    {
        // Convert configuration to JSON for passing to Node.js
        $jsonConfig = $config->toJson();
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new CrawlerResult(
                success: false,
                output: '',
                error: "JSON encoding error: " . json_last_error_msg()
            );
        }

        // Verify the Node.js crawler directory exists
        $nodePath = resource_path('js/crawler');
        if (!File::exists($nodePath)) {
            return new CrawlerResult(
                success: false,
                output: '',
                error: 'Node.js crawler directory not found. Please run the setup first.'
            );
        }

        // Install npm dependencies if not already installed
        if (!File::exists("$nodePath/node_modules")) {
            $installResult = $this->installDependencies($nodePath);
            if ($installResult->isFailed()) {
                return $installResult;
            }
        }

        // Buffers to capture process output
        $outputBuffer = '';
        $errorBuffer = '';

        // Execute the Node.js crawler with optional streaming output
        $process = Process::path($nodePath)
            ->timeout(0)        // No timeout for long-running crawls
            ->idleTimeout(0)    // No idle timeout
            ->run(
                'node crawler.js ' . escapeshellarg($jsonConfig),
                function (string $type, string $buffer) use (&$outputBuffer, &$errorBuffer, $outputCallback) {
                    // Buffer output for the result
                    if ($type === 'out') {
                        $outputBuffer .= $buffer;
                    } else {
                        $errorBuffer .= $buffer;
                    }

                    // Stream output via callback if provided
                    if ($outputCallback) {
                        $outputCallback($type, $buffer);
                    }
                }
            );

        // Return result based on process exit code
        if ($process->successful()) {
            return new CrawlerResult(
                success: true,
                output: $outputBuffer
            );
        }

        return new CrawlerResult(
            success: false,
            output: $outputBuffer,
            error: $errorBuffer !== '' ? $errorBuffer : $process->errorOutput()
        );
    }

    /**
     * Install Node.js dependencies for the crawler.
     *
     * Runs 'npm install' in the Node.js crawler directory to install all required
     * packages. This is automatically called if node_modules directory is missing.
     *
     * @param string $nodePath Path to the Node.js crawler directory
     * @return CrawlerResult Result indicating success or failure of installation
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
     * Save crawl progress after successful completion.
     *
     * Records the final state of the crawl for potential resumption. The approach
     * differs based on source type:
     * - For local file sources: calculates final index and total URLs processed
     * - For remote sources (sitemap/direct): uses directory count as progress indicator
     *
     * This enables accurate continuation when resuming incomplete crawls.
     *
     * @param CrawlerConfig $config Crawler configuration used for this crawl
     * @param string $label Label identifying the crawl session
     * @param int $startFromIndex Index where this crawl started
     * @param bool $shouldContinue Whether this was a continuation of a previous crawl
     * @param array $sitemapUrls Full list of URLs from sitemap (for local sources)
     * @param CrawlerProgressService $progressService Service for saving progress data
     * @param CrawlerUrlService $urlService Service for URL processing operations
     * @param CrawlerDirectoryService $directoryService Service for directory operations
     * @return void
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
        // For local file sources with explicit URL list
        if ($config->sourceType === 'local' && isset($config->urls)) {
            // Calculate the final directory index based on URLs processed
            $finalIndex = $startFromIndex + count($config->urls) - 1;

            // Calculate total URLs attempted (previous + current batch)
            $totalUrlsAttempted = ($shouldContinue
                    ? $urlService->countProcessedUrls($config->outputDir, $label, $sitemapUrls)
                    : 0) + count($config->urls);

            $progressService->saveProgress($label, $finalIndex, $totalUrlsAttempted);
        } else {
            // For remote sources, use actual directory count as progress metric
            $currentDirCount = count($directoryService->getExistingDirectories($config->outputDir, $label));
            $progressService->saveProgress($label, $currentDirCount, $currentDirCount);
        }
    }
}
