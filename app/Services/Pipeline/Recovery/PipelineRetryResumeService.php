<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Recovery;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Services\Pipeline\Exceptions\PipelineRetryException;
use App\Services\Pipeline\Repositories\PipelineStageStateRepository;
use App\Services\Pipeline\Values\PipelineResumePlan;
use App\Services\Pipeline\Values\PipelineStage;
use App\Services\Pipeline\Values\PipelineStageArtifact;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

#[Singleton]
readonly class PipelineRetryResumeService
{
    public function __construct(
        private PipelineStageStateRepository $stages,
        private Filesystem $files,
        private ConfigRepository $config,
    ) {}

    public function forJob(PipelineJob $job, IngestionSource $source): PipelineResumePlan
    {
        $states = $this->stages->forPipelineJob($job)->keyBy('stage');
        $stage = $this->resolveResumeStageFromStageStates($job, $states);

        $rawDir = null;
        $markdownDir = null;
        if ($stage === PipelineStage::Convert) {
            $rawDir = $this->resolveCompletedStageDirectory(
                $states->get(PipelineStage::Scrape->value),
                PipelineStage::Scrape,
                (string) $source->raw_storage_path,
            );
        } elseif ($stage === PipelineStage::Ingest) {
            // Ingestion needs only converted artifacts; raw files may have expired.
            $markdownDir = $this->resolveCompletedStageDirectory(
                $states->get(PipelineStage::Convert->value),
                PipelineStage::Convert,
                (string) $source->markdown_storage_path,
            );
        }

        return new PipelineResumePlan($stage, $rawDir, $markdownDir);
    }

    /**
     * Derives the resume stage from PipelineStageState records and the job's
     * current_stage pointer; a failed stage record wins over both.
     *
     * @param  Collection<string, PipelineStageState>  $states
     */
    private function resolveResumeStageFromStageStates(PipelineJob $job, Collection $states): PipelineStage
    {
        $failed = $this->firstFailedStage($states);
        if ($failed !== null) {
            return $failed;
        }

        $current = PipelineStage::tryFrom((string) $job->current_stage);
        if ($current !== null) {
            return $current;
        }

        // Workflow timeouts may leave only a running stage, while a failure
        // before the first worker callback has no stage record at all.
        return $this->firstUnfinishedStage($states) ?? PipelineStage::Ingest;
    }

    /**
     * Returns the first stage whose recorded status is 'failed'.
     *
     * @param  Collection<string, PipelineStageState>  $states
     */
    private function firstFailedStage(Collection $states): ?PipelineStage
    {
        foreach (PipelineStage::cases() as $candidate) {
            if ($states->get($candidate->value)?->status === 'failed') {
                return $candidate;
            }
        }

        return null;
    }

    /** @param Collection<string, PipelineStageState> $states */
    private function firstUnfinishedStage(Collection $states): ?PipelineStage
    {
        foreach (PipelineStage::cases() as $candidate) {
            if ($states->get($candidate->value)?->status !== 'completed') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Resolves a completed stage's output directory from its recorded
     * artifacts, falling back to the source's configured storage path.
     */
    private function resolveCompletedStageDirectory(?PipelineStageState $state, PipelineStage $stage, string $fallback): string
    {
        if ($state?->status !== 'completed') {
            throw PipelineRetryException::incompleteStage($stage->value);
        }

        $artifacts = $state->metadata['artifacts'] ?? [];
        $path = $this->directoryFromArtifacts($artifacts, $stage, $fallback);

        $resolved = realpath($path);
        if (! $this->isValidSharedStorageDirectory($resolved)) {
            throw PipelineRetryException::unavailableArtifact($stage->value);
        }

        $this->assertMarkdownArtifactsIntact($artifacts, $resolved, $stage);

        if ($this->hasReadableOutput($resolved, $stage)) {
            return $path;
        }

        throw PipelineRetryException::unavailableArtifact($stage->value);
    }

    /** @param array<mixed> $artifacts */
    private function directoryFromArtifacts(array $artifacts, PipelineStage $stage, string $fallback): string
    {
        foreach ($artifacts as $metadata) {
            $artifact = PipelineStageArtifact::tryFromArtifactMetadata($metadata);
            if ($artifact?->isDirectoryOutput()) {
                return $artifact->uri;
            }
            $outputRoot = $stage === PipelineStage::Convert ? $artifact?->outputRoot() : null;
            if ($outputRoot !== null) {
                return $outputRoot;
            }
        }

        return $fallback;
    }

    /** True when the resolved path is a readable directory inside the configured shared storage root. */
    private function isValidSharedStorageDirectory(string|false $resolved): bool
    {
        $root = realpath((string) $this->config->get('temporal.storage.shared_root', '/shared'));

        return $root !== false && $resolved !== false
            && str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            && $this->files->isDirectory($resolved)
            && $this->files->isReadable($resolved);
    }

    /** @param array<mixed> $artifacts */
    private function assertMarkdownArtifactsIntact(array $artifacts, string $resolved, PipelineStage $stage): void
    {
        if ($stage !== PipelineStage::Convert) {
            return;
        }

        foreach ($artifacts as $metadata) {
            $artifact = PipelineStageArtifact::tryFromArtifactMetadata($metadata);
            if (! $artifact?->isMarkdownOutput()) {
                continue;
            }
            if (! $this->isValidMarkdownArtifact($artifact, $resolved)) {
                throw PipelineRetryException::unavailableArtifact($stage->value);
            }
        }
    }

    private function isValidMarkdownArtifact(PipelineStageArtifact $artifact, string $resolved): bool
    {
        if ($artifact->uri === null) {
            return false;
        }

        $file = realpath($artifact->uri);

        return $file !== false
            && str_starts_with($file, $resolved.DIRECTORY_SEPARATOR)
            && $this->files->isFile($file)
            && $this->files->isReadable($file);
    }

    private function hasReadableOutput(string $resolved, PipelineStage $stage): bool
    {
        foreach ($this->files->allFiles($resolved) as $file) {
            if ($file->isReadable()
                && ($stage !== PipelineStage::Convert || strtolower($file->getExtension()) === 'md')) {
                return true;
            }
        }

        return false;
    }
}
