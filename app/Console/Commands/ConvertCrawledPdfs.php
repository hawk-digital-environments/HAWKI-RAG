<?php

namespace App\Console\Commands;

use App\Services\FileConverter\DocumentConverter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use SplFileInfo;

class ConvertCrawledPdfs extends Command
{
    /**
     * Usage:
     *   php artisan convert:crawled-pdfs storage/app/private/crawled-data/hawk
     */
    protected $signature = 'convert:crawled-pdfs {outputDir : Path to crawler output directory}';

    protected $description = 'Convert all PDFs under OUTPUT_DIR/**/files/*.pdf to Markdown using DocumentConverter, skipping already-converted PDFs, and log failures to public/failed_conversion.json';

    public function handle(): int
    {
        $outputDir = base_path($this->argument('outputDir'));
        if (!is_dir($outputDir)) {
            $this->error("Output dir not found: $outputDir");
            return Command::FAILURE;
        }

        $converter = new DocumentConverter();

        // Find all PDFs under **/files/*.pdf
        $pattern  = rtrim($outputDir, DIRECTORY_SEPARATOR) . '/**/files/*.pdf';
        $pdfPaths = File::glob($pattern);

        if (empty($pdfPaths)) {
            $this->warn("No PDFs found under $outputDir (pattern: $pattern)");
            $this->writeFailedJson([], 0, 0, 0); // write empty report
            return Command::SUCCESS;
        }

        $this->info('Found ' . count($pdfPaths) . ' PDF(s). Converting…');

        $failed = [];
        $processed = 0;
        $skipped = 0;

        // Read retry config (set these in config/services.php via env as we discussed)
        $maxRetries    = (int) config('services.file_converter.retries', 3);
        $retryDelayMs  = (int) config('services.file_converter.retry_delay_ms', 1500);

        $bar = $this->output->createProgressBar(count($pdfPaths));
        $bar->start();

        foreach ($pdfPaths as $pdfPath) {
            $bar->advance();

            try {
                $pdfInfo = new SplFileInfo($pdfPath);

                // Compute converted_id (sha256 of file contents)
                $convertedId = @hash_file('sha256', $pdfInfo->getPathname());
                if ($convertedId === false) {
                    throw new \RuntimeException('Unable to hash PDF (hash_file returned false).');
                }

                // Destination folder next to the PDF
                $destDir = dirname($pdfPath) . '/converted_' . pathinfo($pdfInfo->getFilename(), PATHINFO_FILENAME);
                $metaPath = $destDir . '/conversion_meta.json';

                // Skip if meta exists and converted_id matches
                if (is_file($metaPath)) {
                    $meta = json_decode(@file_get_contents($metaPath), true);
                    if (is_array($meta) && ($meta['converted_id'] ?? null) === $convertedId) {
                        $skipped++;
                        continue;
                    }
                }

                // Run conversion with retry (returns [relative_path => content])
                $files = $this->convertWithRetry($converter, $pdfInfo, $maxRetries, $retryDelayMs);

                // Write extracted files
                File::makeDirectory($destDir, 0755, true, true);
                $written = [];
                foreach ($files as $relative => $content) {
                    $outPath = $destDir . '/' . ltrim($relative, '/');
                    File::ensureDirectoryExists(dirname($outPath));
                    File::put($outPath, $content);
                    $written[] = $this->makePathRelative($outPath, $destDir);
                }

                // Write / refresh conversion_meta.json
                $metaPayload = [
                    'converted_id'   => $convertedId,
                    'source_pdf'     => $pdfPath,
                    'source_size'    => @filesize($pdfPath),
                    'source_mtime'   => @filemtime($pdfPath) ? date('c', filemtime($pdfPath)) : null,
                    'output_dir'     => $destDir,
                    'files'          => $written,   // relative to $destDir
                    'converted_at'   => now()->toIso8601String(),
                    'tool'           => 'DocumentConverter::requestDocumentToMarkdown',
                    'version'        => 1,
                ];
                File::put($metaPath, json_encode($metaPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                $processed++;
            } catch (\Throwable $e) {
                $failed[] = [
                    'pdf_local_path' => $pdfPath,
                    'error'          => $e->getMessage(),
                ];
                Log::warning("ConvertCrawledPdfs failed: {$pdfPath} :: {$e->getMessage()}");
            }
        }

        $bar->finish();
        $this->newLine(2);

        // Write failed_conversion.json in public/
        $this->writeFailedJson($failed, $processed, count($pdfPaths), $skipped);

        // Console summary
        $this->info("Processed PDFs : {$processed}");
        $this->info("Skipped (cached): {$skipped}");
        $this->info("Failed PDFs    : " . count($failed));

        if (!empty($failed)) {
            $this->warn('See public/failed_conversion.json for details.');
        }

        return Command::SUCCESS;
    }

    /**
     * Try converting a PDF up to $maxRetries times with a delay.
     * Retries only on likely-transient errors (timeouts / 5xx).
     *
     * @return array<string,string> files map [relativePath => content]
     */
    private function convertWithRetry(
        DocumentConverter $converter,
        SplFileInfo $pdfInfo,
        int $maxRetries,
        int $retryDelayMs
    ): array {
        $attempt = 0;
        $lastEx = null;

        while ($attempt <= $maxRetries) {
            try {
                return $converter->requestDocumentToMarkdown($pdfInfo);
            } catch (\Throwable $e) {
                $lastEx = $e;
                $msg = (string) $e->getMessage();

                // Decide if this is worth retrying
                $isTimeout = str_contains($msg, 'cURL error 28') || str_contains($msg, 'Operation timed out');
                $is5xx     = preg_match('/\bHTTP\/?1\.[01]\s+5\d{2}\b/i', $msg) === 1 || str_contains($msg, ' 5');

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
     * Write failed_conversion.json into public/ with summary stats.
     */
    private function writeFailedJson(array $failed, int $processed, int $total, int $skipped): void
    {
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'total'        => $total,
            'processed'    => $processed,
            'skipped'      => $skipped,
            'failed'       => count($failed),
            'failures'     => $failed, // each: { pdf_local_path, error }
        ];

        $dest = public_path('failed_conversion.json');

        // Write atomically
        $tmp  = $dest . '.tmp';
        File::ensureDirectoryExists(dirname($dest));
        File::put($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $dest);
    }

    private function makePathRelative(string $path, string $baseDir): string
    {
        $path    = str_replace('\\', '/', realpath($path) ?: $path);
        $baseDir = str_replace('\\', '/', realpath($baseDir) ?: $baseDir);
        if (str_starts_with($path, $baseDir)) {
            return ltrim(substr($path, strlen($baseDir)), '/');
        }
        return $path;
    }
}
