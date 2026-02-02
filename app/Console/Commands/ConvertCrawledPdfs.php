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
    protected $signature = 'convert:crawled-pdfs
        {outputDir? : Path to crawler output directory}
        {--extensions=pdf,doc,docx : Comma-separated list of extensions to convert}
        {--scan-all : Scan all files under outputDir (not just **/files/)}';

    protected $description = 'Convert documents under OUTPUT_DIR to Markdown using DocumentConverter, skipping already-converted files, and log failures to storage/logs/failed_conversion.json';

    public function handle(): int
    {
        $outputDirArg = $this->argument('outputDir');
        if ($outputDirArg) {
            $outputDir = base_path($outputDirArg);
            if (!is_dir($outputDir)) {
                $this->error("Output dir not found: $outputDir");
                return Command::FAILURE;
            }
        } else {
            $outputDir = $this->pickOutputDir();
            if (!$outputDir) {
                return Command::FAILURE;
            }
        }

        $converter = new DocumentConverter();

        // Find documents under outputDir (recursive)
        $extensions = $this->parseExtensions((string) $this->option('extensions'));
        $scanAll = (bool) $this->option('scan-all');
        $docPaths = $this->collectDocumentPaths($outputDir, $extensions, $scanAll);

        if (empty($docPaths)) {
            $extLabel = implode(',', $extensions);
            $scopeLabel = $scanAll ? 'recursive' : '**/files/*';
            $this->warn("No documents found under $outputDir (extensions: {$extLabel}; scope: {$scopeLabel})");
            $this->writeFailedJson([], 0, 0, 0); // write empty report
            return Command::SUCCESS;
        }

        $this->info('Found ' . count($docPaths) . ' document(s). Converting…');

        $existingMetaCount = 0;
        foreach ($docPaths as $docPath) {
            $destDir = dirname($docPath) . '/converted_' . pathinfo($docPath, PATHINFO_FILENAME);
            if (is_file($destDir . '/conversion_meta.json')) {
                $existingMetaCount++;
            }
        }

        $forceReprocess = false;
        if ($existingMetaCount > 0) {
            $this->line("Detected {$existingMetaCount} previously converted document(s) in this directory.");
            $choice = $this->choice(
                'How would you like to proceed?',
                ['continue', 'restart', 'cancel'],
                0
            );

            if ($choice === 'cancel') {
                $this->info('Conversion cancelled by user request.');
                return Command::SUCCESS;
            }

            if ($choice === 'restart') {
                $forceReprocess = true;
                $this->warn('Restart selected — existing converted outputs will be re-generated.');
            } else {
                $this->info('Continuing will skip already converted documents when their hashes match.');
            }
        }

        $failed = [];
        $processed = 0;
        $skipped = 0;

        // Read retry config (set these in config/services.php via env)
        $maxRetries    = (int) config('file_converter.retries', 3);
        $retryDelayMs  = (int) config('file_converter.retry_delay_ms', 1500);

        $bar = $this->output->createProgressBar(count($docPaths));
        $bar->start();

        foreach ($docPaths as $docPath) {
            $bar->advance();

            try {
                $docInfo = new SplFileInfo($docPath);

                // Compute converted_id (sha256 of file contents)
                $convertedId = @hash_file('sha256', $docInfo->getPathname());
                if ($convertedId === false) {
                    throw new \RuntimeException('Unable to hash document (hash_file returned false).');
                }

                // Destination folder next to the document
                $destDir = dirname($docPath) . '/converted_' . pathinfo($docInfo->getFilename(), PATHINFO_FILENAME);
                $metaPath = $destDir . '/conversion_meta.json';

                if ($forceReprocess && File::isDirectory($destDir)) {
                    if (!File::deleteDirectory($destDir)) {
                        throw new \RuntimeException("Unable to remove existing conversion output at {$destDir}.");
                    }
                }

                // Skip if meta exists and converted_id matches
                if (!$forceReprocess && is_file($metaPath)) {
                    $meta = json_decode(@file_get_contents($metaPath), true);
                    if (is_array($meta) && ($meta['converted_id'] ?? null) === $convertedId) {
                        $flatPath = dirname($pdfPath) . '/' . pathinfo($pdfInfo->getFilename(), PATHINFO_FILENAME) . '_converted.md';
                        if (!is_file($flatPath)) {
                            $flatContent = $this->loadMarkdownFromMeta($meta, $destDir);
                            if ($flatContent !== null) {
                                File::put($flatPath, $flatContent);
                            }
                        }
                        $skipped++;
                        continue;
                    }
                }

                // Run conversion with retry (returns [relative_path => content])
                $files = $this->convertWithRetry($converter, $docInfo, $maxRetries, $retryDelayMs);

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
                    'source_pdf'     => $docPath, // kept for backward compatibility
                    'source_file'    => $docPath,
                    'source_size'    => @filesize($docPath),
                    'source_mtime'   => @filemtime($docPath) ? date('c', filemtime($docPath)) : null,
                    'output_dir'     => $destDir,
                    'files'          => $written,   // relative to $destDir
                    'converted_at'   => now()->toIso8601String(),
                    'tool'           => 'DocumentConverter::requestDocumentToMarkdown',
                    'version'        => 1,
                ];
                File::put($metaPath, json_encode($metaPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                $flatPath = dirname($docPath) . '/' . pathinfo($docInfo->getFilename(), PATHINFO_FILENAME) . '_converted.md';
                $flatContent = $this->pickMarkdownContent($files);
                if ($flatContent !== null) {
                    File::put($flatPath, $flatContent);
                }

                $processed++;
            } catch (\Throwable $e) {
                $failed[] = [
                    'pdf_local_path' => $docPath,
                    'file_local_path' => $docPath,
                    'error'          => $e->getMessage(),
                ];
                Log::warning("ConvertCrawledPdfs failed: {$docPath} :: {$e->getMessage()}");
            }
        }

        $bar->finish();
        $this->newLine(2);

        // Write failed_conversion.json in public/
        $this->writeFailedJson($failed, $processed, count($docPaths), $skipped);

        // Console summary
        $this->info("Processed docs : {$processed}");
        $this->info("Skipped (cached): {$skipped}");
        $this->info("Failed docs    : " . count($failed));

        if (!empty($failed)) {
            $this->warn('See storage/logs/failed_conversion.json for details.');
        }

        return Command::SUCCESS;
    }

    private function pickOutputDir(): ?string
    {
        $candidates = [
            '/app/shared',
        ];

        $roots = array_values(array_filter($candidates, static fn ($path) => is_dir($path)));
        if (empty($roots)) {
            $this->error('No shared crawl directories found. Provide outputDir explicitly.');
            return null;
        }

        $root = count($roots) === 1
            ? $roots[0]
            : $this->choice('Select the crawl root to inspect', $roots, 0);

        $dirs = File::directories($root);
        if (empty($dirs)) {
            $this->error("No crawl folders found under: $root");
            return null;
        }

        $selected = $this->choice('Select a crawl folder', $dirs, 0);
        $this->info("Selected: {$selected}");
        return $selected;
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

        $dest = storage_path('logs/failed_conversion.json');

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

    /**
     * Collect document files under the output directory.
     *
     * @return array<int,string>
     */
    private function collectDocumentPaths(string $outputDir, array $extensions, bool $scanAll): array
    {
        $paths = [];
        $root = rtrim($outputDir, DIRECTORY_SEPARATOR);
        foreach (File::allFiles($root) as $file) {
            if (!in_array(strtolower($file->getExtension()), $extensions, true)) {
                continue;
            }
            $path = $file->getPathname();
            if (!$scanAll && !str_contains(str_replace('\\', '/', $path), '/files/')) {
                continue;
            }
            $paths[] = $path;
        }
        sort($paths);
        return $paths;
    }

    /**
     * Normalize extension list from CLI option.
     *
     * @return array<int,string>
     */
    private function parseExtensions(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['pdf', 'doc', 'docx'];
        }
        $parts = array_map('trim', explode(',', $raw));
        $parts = array_filter($parts, static fn ($ext) => $ext !== '');
        $parts = array_map(static fn ($ext) => ltrim(strtolower($ext), '.'), $parts);
        return $parts ?: ['pdf', 'doc', 'docx'];
    }

    /**
     * Pick a reasonable markdown payload from the converter output.
     *
     * @param array<string,string> $files
     */
    private function pickMarkdownContent(array $files): ?string
    {
        foreach ($files as $relative => $content) {
            if (str_ends_with(strtolower($relative), '.md')) {
                return $content;
            }
        }

        if ($files === []) {
            return null;
        }

        return implode("\n\n", array_values($files));
    }

    private function loadMarkdownFromMeta(array $meta, string $destDir): ?string
    {
        $files = $meta['files'] ?? [];
        if (!is_array($files)) {
            return null;
        }

        foreach ($files as $relative) {
            if (!is_string($relative)) {
                continue;
            }
            if (!str_ends_with(strtolower($relative), '.md')) {
                continue;
            }
            $path = $destDir . '/' . ltrim($relative, '/');
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        return null;
    }
}
