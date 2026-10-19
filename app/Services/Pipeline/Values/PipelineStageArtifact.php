<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Values;

/** Immutable view of an artifact recorded in a completed stage's metadata. */
readonly class PipelineStageArtifact
{
    private function __construct(
        public ?string $uri,
        public ?string $relativePath,
        public ?string $mediaType,
    ) {}

    /**
     * Builds from one PipelineStageState metadata artifacts[] entry;
     * non-string fields coerce to null, non-array entries to no artifact.
     */
    public static function tryFromArtifactMetadata(mixed $artifact): ?self
    {
        if (! is_array($artifact)) {
            return null;
        }

        return new self(
            is_string($artifact['uri'] ?? null) ? $artifact['uri'] : null,
            is_string($artifact['relative_path'] ?? null) ? $artifact['relative_path'] : null,
            is_string($artifact['media_type'] ?? null) ? $artifact['media_type'] : null,
        );
    }

    /** True when this artifact points at the stage's output directory itself. */
    public function isDirectoryOutput(): bool
    {
        return $this->mediaType === 'inode/directory' && $this->uri !== null;
    }

    /** True when this artifact describes a converted markdown file output. */
    public function isMarkdownOutput(): bool
    {
        return $this->mediaType === 'text/markdown';
    }

    public function hasRelativePath(): bool
    {
        return $this->relativePath !== null && $this->relativePath !== '';
    }

    /**
     * Converter callbacks can contain file references rather than a directory.
     * Their relative paths retain the actual output root.
     */
    public function outputRoot(): ?string
    {
        if ($this->uri === null || ! $this->hasRelativePath()
            || ! str_ends_with($this->uri, '/'.$this->relativePath)) {
            return null;
        }

        return substr($this->uri, 0, -strlen('/'.$this->relativePath));
    }
}
