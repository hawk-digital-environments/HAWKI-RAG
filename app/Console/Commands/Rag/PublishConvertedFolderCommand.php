<?php

namespace App\Console\Commands\Rag;

use App\Services\Rag\RagRabbitMQ;
use App\Support\PipelineExitCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class PublishConvertedFolderCommand extends Command
{
    protected $signature = 'rag:publish-converted-folder
        {folder : Scraped/converted folder under shared storage}
        {--limit=0 : Maximum events to publish, 0 means all}';

    protected $description = 'Publish convert.document.completed RabbitMQ events for converted Markdown files in a folder.';

    public function handle(RagRabbitMQ $rabbit): int
    {
        $folder = $this->resolveFolder((string) $this->argument('folder'));
        if (!is_dir($folder)) {
            $this->error("Folder not found: {$folder}");
            return PipelineExitCode::VALIDATION_FAILURE;
        }

        try {
            $rabbit->declareRagIngestionTopology();
            $limit = max(0, (int) $this->option('limit'));
            $published = 0;
            $skipped = 0;

            foreach (File::allFiles($folder) as $file) {
                if ($file->getFilename() !== 'conversion_meta.json') {
                    continue;
                }

                $meta = json_decode((string) file_get_contents($file->getPathname()), true);
                if (!is_array($meta)) {
                    $this->warn("Skipping invalid metadata: {$file->getPathname()}");
                    $skipped++;
                    continue;
                }

                $markdownPath = $this->markdownPathFromMeta($meta, $file->getPath());
                if ($markdownPath === null) {
                    $this->warn("Skipping metadata without Markdown output: {$file->getPathname()}");
                    $skipped++;
                    continue;
                }

                $rabbit->publishConvertedDocument($this->eventFromMeta($meta, $markdownPath));
                $published++;

                if ($limit > 0 && $published >= $limit) {
                    break;
                }
            }

            $rabbit->close();
            $this->info("Published {$published} convert.document.completed event(s).");
            if ($skipped > 0) {
                $this->warn("Skipped {$skipped} invalid converted document metadata file(s).");
            }

            return $published > 0 && $skipped === 0
                ? PipelineExitCode::SUCCESS
                : PipelineExitCode::PARTIAL_SUCCESS;
        } catch (Throwable $e) {
            $rabbit->close();
            $this->error('Failed to publish converted document events: ' . $e->getMessage());
            return PipelineExitCode::RUNTIME_FAILURE;
        }
    }

    private function eventFromMeta(array $meta, string $markdownPath): array
    {
        $sourceFile = (string) ($meta['source_file'] ?? $meta['source_pdf'] ?? '');
        $jobId = (string) ($meta['doc_id'] ?? $meta['converted_id'] ?? hash('sha256', $markdownPath));

        return [
            'event_id' => (string) Str::uuid(),
            'job_id' => $jobId,
            'parent_event_id' => (string) Str::uuid(),
            'schema_version' => (string) config('communication.rabbitmq.rag_ingestion.schema_version', '1'),
            'event_type' => 'convert.document.completed',
            'source' => 'hawki-rag-laravel',
            'original_url' => (string) ($meta['original_url'] ?? $meta['source_url'] ?? $sourceFile ?: $markdownPath),
            'original_path' => $sourceFile ?: $markdownPath,
            'original_relative_path' => $this->relativeToShared($sourceFile),
            'converted_path' => $markdownPath,
            'converted_relative_path' => $this->relativeToShared($markdownPath),
            'output_format' => 'markdown',
            'converter_name' => (string) ($meta['tool'] ?? 'DocumentConverter'),
            'converter_version' => isset($meta['version']) ? (string) $meta['version'] : null,
            'input_checksum_sha256' => is_file($sourceFile) ? hash_file('sha256', $sourceFile) : null,
            'output_checksum_sha256' => hash_file('sha256', $markdownPath) ?: null,
            'converted_at' => (string) ($meta['converted_at'] ?? now()->toIso8601String()),
            'trace_id' => $jobId,
            'payload' => [
                'title' => $meta['title'] ?? pathinfo($markdownPath, PATHINFO_FILENAME),
            ],
        ];
    }

    private function markdownPathFromMeta(array $meta, string $metaDir): ?string
    {
        $sourceFile = (string) ($meta['source_file'] ?? $meta['source_pdf'] ?? '');
        if ($sourceFile !== '') {
            $flatPath = dirname($sourceFile) . DIRECTORY_SEPARATOR . pathinfo($sourceFile, PATHINFO_FILENAME) . '_converted.md';
            if (is_file($flatPath)) {
                return realpath($flatPath) ?: $flatPath;
            }
        }

        foreach (($meta['files'] ?? []) as $relative) {
            if (is_string($relative) && str_ends_with(strtolower($relative), '.md')) {
                $candidate = $metaDir . DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR);
                if (is_file($candidate)) {
                    return realpath($candidate) ?: $candidate;
                }
            }
        }

        return null;
    }

    private function resolveFolder(string $folder): string
    {
        if (Str::startsWith($folder, ['/','\\'])) {
            return $folder;
        }

        return rtrim((string) config('communication.rabbitmq.rag_ingestion.shared_storage_root', '/app/shared'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . ltrim($folder, DIRECTORY_SEPARATOR);
    }

    private function relativeToShared(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $root = realpath((string) config('communication.rabbitmq.rag_ingestion.shared_storage_root', '/app/shared'));
        $resolved = realpath($path);
        if ($root === false || $resolved === false || !Str::startsWith($resolved, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return ltrim(substr($resolved, strlen($root)), DIRECTORY_SEPARATOR);
    }
}
