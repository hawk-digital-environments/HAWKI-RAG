<?php
declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use App\Models\PipelineJob;
use App\Services\FileConverter\DocumentConverter;
use App\Services\Pipeline\Exceptions\PipelineEventHandlerException;
use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\PipelineEventBus;
use App\Services\Pipeline\PipelineEventStateService;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\File;
use SplFileInfo;

#[Singleton]
class ConverterEventHandler implements PipelineEventHandler
{
    public function __construct(
        private readonly PipelineEventBus $events,
        private readonly PipelineEventStateService $state,
        private readonly PipelineJobRepository $jobs,
        private readonly DocumentConverter $converter,
    ) {
    }

    public function eventTypes(): array
    {
        return [
            PipelineEvent::FILE_DISCOVERED,
        ];
    }

    public function handle(array $event): void
    {
        $event = PipelineEvent::normalize((string) $event['event_type'], $event);
        $path = (string) $event['local_path'];
        if ($path === '' || !is_file($path)) {
            throw PipelineEventHandlerException::conversionRequiresExistingLocalPath($path);
        }

        if (!$this->isSupported($path)) {
            $this->state->upsertJob($event, PipelineJob::STATUS_SKIPPED, [
                'reason' => 'Unsupported file extension.',
            ]);
            return;
        }

        $contentHash = (string) ($event['content_hash'] ?: hash_file('sha256', $path));
        $event['content_hash'] = $contentHash;
        $cached = $this->cachedConversion($path, $contentHash);

        if ($cached !== null || $this->jobs->hasCompletedOrSkippedConversion($path, $contentHash)) {
            $this->state->upsertJob($event, PipelineJob::STATUS_SKIPPED, [
                'reason' => 'File/content_hash was already converted.',
                'converted_path' => $cached,
            ]);
            $this->events->publish(PipelineEvent::FILE_CONVERTED, array_merge($event, [
                'local_path' => $cached ?? $path,
                'status' => PipelineJob::STATUS_SKIPPED,
                'metadata' => array_merge($event['metadata'], [
                    'reason' => 'File/content_hash was already converted.',
                    'original_path' => $path,
                    'converted_path' => $cached,
                ]),
            ]));
            return;
        }

        $this->state->upsertJob($event, PipelineJob::STATUS_RUNNING, [
            'source' => self::class,
            'stage' => 'conversion_started',
        ]);

        $converted = $this->convert($event, $path, $contentHash);
        $this->state->upsertJob($event, PipelineJob::STATUS_COMPLETED, [
            'converted_path' => $converted['markdownPath'],
            'output_dir' => $converted['outputDir'],
        ]);

        $this->events->publish(PipelineEvent::FILE_CONVERTED, array_merge($event, [
            'local_path' => $converted['markdownPath'],
            'status' => PipelineJob::STATUS_COMPLETED,
            'metadata' => array_merge($event['metadata'], [
                'original_path' => $path,
                'converted_path' => $converted['markdownPath'],
                'output_dir' => $converted['outputDir'],
                'output_format' => 'markdown',
                'converter_name' => 'DocumentConverter',
            ]),
        ]));
    }

    public function failed(array $event, \Throwable $error, int $retryCount, int $maxRetries): void
    {
        $retryable = $retryCount < $maxRetries;
        $this->state->upsertJob($event, $retryable ? PipelineJob::STATUS_PENDING : PipelineJob::STATUS_FAILED, [
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'retry_scheduled' => $retryable,
            'error_type' => class_basename($error),
            'error_message' => $error->getMessage(),
        ]);
    }

    private function convert(array $event, string $path, string $contentHash): array
    {
        $file = new SplFileInfo($path);
        $outputDir = dirname($path) . DIRECTORY_SEPARATOR . 'converted_' . pathinfo($path, PATHINFO_FILENAME);
        File::ensureDirectoryExists($outputDir);

        $files = $this->converter->requestDocumentToMarkdown($file);
        if (!is_array($files) || $files === []) {
            throw PipelineEventHandlerException::converterReturnedNoFiles();
        }

        $markdownFiles = [];
        foreach ($files as $relative => $content) {
            if (!is_string($content)) {
                continue;
            }
            $target = $outputDir . DIRECTORY_SEPARATOR . ltrim((string) $relative, DIRECTORY_SEPARATOR);
            File::ensureDirectoryExists(dirname($target));
            File::put($target, $content);

            if (str_ends_with(strtolower((string) $relative), '.md')) {
                $markdownFiles[(string) $relative] = $content;
            }
        }

        $markdownFiles = $this->sortByNaturalPath($markdownFiles);
        $markdownPath = $this->writeCombinedMarkdown($outputDir, $markdownFiles);
        if ($markdownPath === null) {
            $markdownPath = dirname($path) . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_FILENAME) . '_converted.md';
            File::put($markdownPath, $this->fallbackTextContent($files));
        }

        $metadata = [
            'pipeline_job_id' => $event['parent_job_id'] ?: $event['job_id'],
            'task_id' => $event['task_id'],
            'conversion_job_id' => $event['job_id'],
            'converted_id' => $contentHash,
            'doc_id' => $contentHash,
            'source_file' => $path,
            'source_url' => $event['source_url'],
            'output_dir' => $outputDir,
            'files' => array_keys($files),
            'markdown_files' => array_keys($markdownFiles),
            'combined_markdown_path' => $markdownPath,
            'tool' => 'DocumentConverter',
            'version' => 'event-worker',
            'converted_at' => now()->toIso8601String(),
        ];
        File::put($outputDir . DIRECTORY_SEPARATOR . 'conversion_meta.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [
            'outputDir' => $outputDir,
            'markdownPath' => $markdownPath,
        ];
    }

    /**
     * @param array<string,string> $markdownFiles
     */
    private function writeCombinedMarkdown(string $outputDir, array $markdownFiles): ?string
    {
        if ($markdownFiles === []) {
            return null;
        }

        $sections = [];
        foreach ($markdownFiles as $relative => $content) {
            if (trim($content) === '') {
                continue;
            }

            $sections[] = "<!-- converter-source: {$relative} -->\n\n" . trim($content);
        }

        if ($sections === []) {
            return null;
        }

        $path = $outputDir . DIRECTORY_SEPARATOR . 'content_markdown.md';
        File::put($path, implode("\n\n---\n\n", $sections) . "\n");

        return $path;
    }

    /**
     * @param array<string,string> $files
     * @return array<string,string>
     */
    private function sortByNaturalPath(array $files): array
    {
        uksort($files, 'strnatcasecmp');

        return $files;
    }

    /**
     * @param array<string,string> $files
     */
    private function fallbackTextContent(array $files): string
    {
        $textFiles = [];
        foreach ($files as $relative => $content) {
            if (!is_string($content) || trim($content) === '') {
                continue;
            }

            if (preg_match('/\.(txt|html?|csv|json|ya?ml|xml)$/i', (string) $relative) === 1) {
                $textFiles[(string) $relative] = $content;
            }
        }

        if ($textFiles === []) {
            return '';
        }

        uksort($textFiles, 'strnatcasecmp');

        return implode("\n\n---\n\n", array_map('trim', $textFiles)) . "\n";
    }

    private function cachedConversion(string $path, string $contentHash): ?string
    {
        $outputDir = dirname($path) . DIRECTORY_SEPARATOR . 'converted_' . pathinfo($path, PATHINFO_FILENAME);
        $metaPath = $outputDir . DIRECTORY_SEPARATOR . 'conversion_meta.json';
        if (!is_file($metaPath)) {
            return null;
        }

        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (!is_array($meta) || (string) ($meta['converted_id'] ?? '') !== $contentHash) {
            return null;
        }

        $combined = (string) ($meta['combined_markdown_path'] ?? '');
        if ($combined !== '' && is_file($combined)) {
            return $combined;
        }

        foreach (($meta['files'] ?? []) as $relative) {
            $candidate = $outputDir . DIRECTORY_SEPARATOR . ltrim((string) $relative, DIRECTORY_SEPARATOR);
            if (is_file($candidate) && str_ends_with(strtolower($candidate), '.md')) {
                return $candidate;
            }
        }

        $flat = dirname($path) . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_FILENAME) . '_converted.md';

        return is_file($flat) ? $flat : null;
    }

    private function isSupported(string $path): bool
    {
        $extensions = array_map('strtolower', config('file_converter.supported_extensions', ['pdf', 'doc', 'docx']));

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true);
    }
}
