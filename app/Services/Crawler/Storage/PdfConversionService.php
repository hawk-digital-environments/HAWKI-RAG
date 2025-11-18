<?php

namespace App\Services\Crawler\Storage;

use App\Services\FileConverter\DocumentConverter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use SplFileInfo;

/**
 * Service for converting PDF files to Markdown.
 *
 * This service handles the conversion of PDF files to Markdown format
 * with retry logic, progress tracking, and metadata generation. It can
 * be used standalone or integrated into the crawler pipeline for
 * automated PDF processing.
 */
class PdfConversionService
{
    /**
     * Statistics for the conversion process.
     *
     * @var array
     */
    private array $statistics = [
        'processed' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];

    /**
     * List of failed conversions.
     *
     * @var array
     */
    private array $failures = [];

    public function __construct(
        private DocumentConverter $converter
    ) {}

    /**
     * Convert all PDFs in a directory.
     *
     * @param string $directory Base directory containing PDFs
     * @param bool $forceReprocess Whether to force reprocessing of cached conversions
     * @param int $maxRetries Maximum number of retry attempts
     * @param int $retryDelayMs Delay between retries in milliseconds
     * @param callable|null $progressCallback Optional callback for progress updates
     * @return array Statistics about the conversion
     */
    public function convertDirectory(
        string $directory,
        bool $forceReprocess = false,
        int $maxRetries = 3,
        int $retryDelayMs = 1500,
        ?callable $progressCallback = null
    ): array {
        $this->resetStatistics();

        // Find all PDFs
        $pattern = rtrim($directory, DIRECTORY_SEPARATOR) . '/**/files/*.pdf';
        $pdfPaths = File::glob($pattern);

        if (empty($pdfPaths)) {
            return $this->getStatistics();
        }

        foreach ($pdfPaths as $pdfPath) {
            try {
                $result = $this->convertPdf(
                    pdfPath: $pdfPath,
                    forceReprocess: $forceReprocess,
                    maxRetries: $maxRetries,
                    retryDelayMs: $retryDelayMs
                );

                if ($result['status'] === 'processed') {
                    $this->statistics['processed']++;
                } elseif ($result['status'] === 'skipped') {
                    $this->statistics['skipped']++;
                }

                if ($progressCallback) {
                    $progressCallback($pdfPath, $result);
                }

            } catch (\Throwable $e) {
                $this->statistics['failed']++;
                $this->failures[] = [
                    'pdf_local_path' => $pdfPath,
                    'error' => $e->getMessage(),
                ];

                Log::warning("PDF conversion failed: {$pdfPath} :: {$e->getMessage()}");

                if ($progressCallback) {
                    $progressCallback($pdfPath, ['status' => 'failed', 'error' => $e->getMessage()]);
                }
            }
        }

        return $this->getStatistics();
    }

    /**
     * Convert a single PDF file.
     *
     * @param string $pdfPath Path to the PDF file
     * @param bool $forceReprocess Whether to force reprocessing
     * @param int $maxRetries Maximum number of retry attempts
     * @param int $retryDelayMs Delay between retries in milliseconds
     * @return array Result information
     */
    public function convertPdf(
        string $pdfPath,
        bool $forceReprocess = false,
        int $maxRetries = 3,
        int $retryDelayMs = 1500
    ): array {
        $pdfInfo = new SplFileInfo($pdfPath);

        // Compute converted_id (sha256 of file contents)
        $convertedId = @hash_file('sha256', $pdfInfo->getPathname());
        if ($convertedId === false) {
            throw new \RuntimeException('Unable to hash PDF file.');
        }

        // Destination folder next to the PDF
        $destDir = dirname($pdfPath) . '/converted_' . pathinfo($pdfInfo->getFilename(), PATHINFO_FILENAME);
        $metaPath = $destDir . '/conversion_meta.json';

        // Handle force reprocess
        if ($forceReprocess && File::isDirectory($destDir)) {
            if (!File::deleteDirectory($destDir)) {
                throw new \RuntimeException("Unable to remove existing conversion output at {$destDir}.");
            }
        }

        // Skip if meta exists and converted_id matches
        if (!$forceReprocess && is_file($metaPath)) {
            $meta = json_decode(@file_get_contents($metaPath), true);
            if (is_array($meta) && ($meta['converted_id'] ?? null) === $convertedId) {
                return [
                    'status' => 'skipped',
                    'reason' => 'Already converted (hash matches)',
                    'destDir' => $destDir,
                ];
            }
        }

        // Run conversion with retry
        $files = $this->convertWithRetry($pdfInfo, $maxRetries, $retryDelayMs);

        // Write extracted files
        File::makeDirectory($destDir, 0755, true, true);
        $written = [];
        foreach ($files as $relative => $content) {
            $outPath = $destDir . '/' . ltrim($relative, '/');
            File::ensureDirectoryExists(dirname($outPath));
            File::put($outPath, $content);
            $written[] = $this->makePathRelative($outPath, $destDir);
        }

        // Write conversion metadata
        $metaPayload = [
            'converted_id' => $convertedId,
            'source_pdf' => $pdfPath,
            'source_size' => @filesize($pdfPath),
            'source_mtime' => @filemtime($pdfPath) ? date('c', filemtime($pdfPath)) : null,
            'output_dir' => $destDir,
            'files' => $written,
            'converted_at' => now()->toIso8601String(),
            'tool' => 'DocumentConverter::requestDocumentToMarkdown',
            'version' => 1,
        ];
        File::put($metaPath, json_encode($metaPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [
            'status' => 'processed',
            'destDir' => $destDir,
            'files' => $written,
        ];
    }

    /**
     * Try converting a PDF up to maxRetries times with a delay.
     *
     * Retries only on likely-transient errors (timeouts / 5xx).
     *
     * @param SplFileInfo $pdfInfo PDF file info
     * @param int $maxRetries Maximum number of retries
     * @param int $retryDelayMs Delay between retries in milliseconds
     * @return array Files map [relativePath => content]
     */
    private function convertWithRetry(
        SplFileInfo $pdfInfo,
        int $maxRetries,
        int $retryDelayMs
    ): array {
        $attempt = 0;
        $lastEx = null;

        while ($attempt <= $maxRetries) {
            try {
                return $this->converter->requestDocumentToMarkdown($pdfInfo);
            } catch (\Throwable $e) {
                $lastEx = $e;
                $msg = (string) $e->getMessage();

                // Decide if this is worth retrying
                $isTimeout = str_contains($msg, 'cURL error 28') || str_contains($msg, 'Operation timed out');
                $is5xx = preg_match('/\bHTTP\/?1\.[01]\s+5\d{2}\b/i', $msg) === 1 || str_contains($msg, ' 5');

                if ($attempt === $maxRetries || (!($isTimeout || $is5xx))) {
                    break; // give up
                }

                // Backoff
                usleep($retryDelayMs * 1000);
                $attempt++;
            }
        }

        // If we get here, all attempts failed
        throw $lastEx ?? new \RuntimeException('Unknown error during conversion.');
    }

    /**
     * Make a path relative to a base directory.
     *
     * @param string $path Absolute path
     * @param string $baseDir Base directory
     * @return string Relative path
     */
    private function makePathRelative(string $path, string $baseDir): string
    {
        $path = str_replace('\\', '/', realpath($path) ?: $path);
        $baseDir = str_replace('\\', '/', realpath($baseDir) ?: $baseDir);
        if (str_starts_with($path, $baseDir)) {
            return ltrim(substr($path, strlen($baseDir)), '/');
        }
        return $path;
    }

    /**
     * Reset statistics.
     *
     * @return void
     */
    private function resetStatistics(): void
    {
        $this->statistics = [
            'processed' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $this->failures = [];
    }

    /**
     * Get conversion statistics.
     *
     * @return array
     */
    public function getStatistics(): array
    {
        return array_merge($this->statistics, [
            'failures' => $this->failures,
        ]);
    }

    /**
     * Get list of failed conversions.
     *
     * @return array
     */
    public function getFailures(): array
    {
        return $this->failures;
    }

    /**
     * Save failures to a JSON file.
     *
     * @param string $path Path to save the failures
     * @param array $additionalData Additional data to include
     * @return void
     */
    public function saveFailuresToFile(string $path, array $additionalData = []): void
    {
        $payload = array_merge([
            'generated_at' => now()->toIso8601String(),
            'total' => $this->statistics['processed'] + $this->statistics['skipped'] + $this->statistics['failed'],
            'processed' => $this->statistics['processed'],
            'skipped' => $this->statistics['skipped'],
            'failed' => $this->statistics['failed'],
            'failures' => $this->failures,
        ], $additionalData);

        // Write atomically
        $tmp = $path . '.tmp';
        File::ensureDirectoryExists(dirname($path));
        File::put($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }
}
