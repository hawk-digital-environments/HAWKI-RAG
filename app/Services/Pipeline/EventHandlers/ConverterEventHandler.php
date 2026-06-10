<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use App\Models\PipelineJob;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Events\PipelineEventStateService;
use App\Services\Pipeline\Exceptions\PipelineEventHandlerException;
use App\Services\Pipeline\Repositories\Queries\ActivePipelineJobsQuery;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
class ConverterEventHandler implements PipelineEventHandler
{
    public function __construct(
        private readonly PipelineEventBus $events,
        private readonly PipelineEventStateService $state,
        private readonly ActivePipelineJobsQuery $jobs,
        private readonly ConversionOutputWriter $outputs,
        private readonly PipelineEventArtifactReader $artifacts,
        private readonly ConfigRepository $config,
    ) {}

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
        if ($path === '' || ! $this->artifacts->isFile($path)) {
            throw PipelineEventHandlerException::conversionRequiresExistingLocalPath($path);
        }

        if (! $this->isSupported($path)) {
            $this->state->upsertJob($event, PipelineJob::STATUS_SKIPPED, [
                'reason' => 'Unsupported file extension.',
            ]);

            return;
        }

        $existing = $this->jobs->findByJobId((string) $event['job_id']);
        if ($existing && in_array($existing->status, [PipelineJob::STATUS_COMPLETED, PipelineJob::STATUS_SKIPPED], true)) {
            return;
        }

        $contentHash = (string) ($event['content_hash'] ?: $this->artifacts->sha256($path));
        $event['content_hash'] = $contentHash;
        $cached = $this->outputs->cachedConversion($path, $contentHash);

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

        $converted = $this->outputs->convert($event, $path, $contentHash);
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

    private function isSupported(string $path): bool
    {
        $extensions = array_map('strtolower', $this->config->get('file_converter.supported_extensions', ['pdf', 'doc', 'docx']));

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true);
    }
}
