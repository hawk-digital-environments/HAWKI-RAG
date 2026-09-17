<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Recovery;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Services\Pipeline\Exceptions\PipelineRetryException;
use App\Services\Pipeline\Repositories\PipelineStageStateRepository;
use App\Services\Pipeline\Values\PipelineStage;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class PipelineRetryResumeService
{
    public function __construct(
        private PipelineStageStateRepository $stages,
        private Filesystem $files,
        private ConfigRepository $config,
    ) {}

    /** @return array{stage: string, raw_dir?: string, markdown_dir?: string} */
    public function forJob(PipelineJob $job, IngestionSource $source): array
    {
        $states = $this->stages->forPipelineJob($job)->keyBy('stage');
        $stage = PipelineStage::tryFrom((string) $job->current_stage);
        foreach (PipelineStage::cases() as $candidate) {
            if ($states->get($candidate->value)?->status === 'failed') {
                $stage = $candidate;
                break;
            }
        }
        if ($stage === null) {
            // Workflow timeouts may leave only a running stage, while a failure
            // before the first worker callback has no stage record at all.
            foreach (PipelineStage::cases() as $candidate) {
                if ($states->get($candidate->value)?->status !== 'completed') {
                    $stage = $candidate;
                    break;
                }
            }
        }
        $stage ??= PipelineStage::Ingest;
        $resume = ['stage' => $stage->value];

        if ($stage === PipelineStage::Convert) {
            $resume['raw_dir'] = $this->completedDirectory(
                $states->get('scrape'), 'scrape', (string) $source->raw_storage_path,
            );
        } elseif ($stage === PipelineStage::Ingest) {
            // Ingestion needs only converted artifacts; raw files may have expired.
            $resume['markdown_dir'] = $this->completedDirectory(
                $states->get('convert'), 'convert', (string) $source->markdown_storage_path,
            );
        }

        return $resume;
    }

    private function completedDirectory(?PipelineStageState $state, string $stage, string $fallback): string
    {
        if ($state?->status !== 'completed') {
            throw PipelineRetryException::incompleteStage($stage);
        }

        $path = $fallback;
        $artifacts = $state->metadata['artifacts'] ?? [];
        foreach ($artifacts as $artifact) {
            if (($artifact['media_type'] ?? null) === 'inode/directory' && is_string($artifact['uri'] ?? null)) {
                $path = $artifact['uri'];
                break;
            }
            // Converter callbacks can contain file references rather than a
            // directory. Their relative paths retain the actual output root.
            if ($stage === 'convert' && is_string($artifact['uri'] ?? null)
                && is_string($artifact['relative_path'] ?? null) && $artifact['relative_path'] !== ''
                && str_ends_with($artifact['uri'], '/'.$artifact['relative_path'])) {
                $path = substr($artifact['uri'], 0, -strlen('/'.$artifact['relative_path']));
                break;
            }
        }

        $root = realpath((string) $this->config->get('temporal.storage.shared_root', '/shared'));
        $resolved = realpath($path);
        if ($root === false || $resolved === false
            || ! str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            || ! $this->files->isDirectory($resolved) || ! $this->files->isReadable($resolved)) {
            throw PipelineRetryException::unavailableArtifact($stage);
        }

        foreach ($artifacts as $artifact) {
            if ($stage !== 'convert' || ($artifact['media_type'] ?? null) !== 'text/markdown') {
                continue;
            }
            $file = realpath((string) ($artifact['uri'] ?? ''));
            if ($file === false || ! str_starts_with($file, $resolved.DIRECTORY_SEPARATOR)
                || ! $this->files->isFile($file) || ! $this->files->isReadable($file)) {
                throw PipelineRetryException::unavailableArtifact($stage);
            }
        }

        foreach ($this->files->allFiles($resolved) as $file) {
            if ($file->isReadable() && ($stage !== 'convert' || strtolower($file->getExtension()) === 'md')) {
                return $path;
            }
        }

        throw PipelineRetryException::unavailableArtifact($stage);
    }
}
