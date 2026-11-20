<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline\Values;

use App\Services\Pipeline\Values\PipelineStageArtifact;
use PHPUnit\Framework\TestCase;

class PipelineStageArtifactTest extends TestCase
{
    public function test_non_array_metadata_is_rejected(): void
    {
        $this->assertNull(PipelineStageArtifact::tryFromArtifactMetadata('page.md'));
    }

    public function test_non_string_fields_become_null(): void
    {
        $artifact = PipelineStageArtifact::tryFromArtifactMetadata([
            'uri' => 42,
            'relative_path' => ['page.md'],
            'media_type' => true,
        ]);

        $this->assertNotNull($artifact);
        $this->assertNull($artifact->uri);
        $this->assertNull($artifact->relativePath);
        $this->assertNull($artifact->mediaType);
    }

    public function test_directory_output_reference_requires_a_string_uri(): void
    {
        $withUri = PipelineStageArtifact::tryFromArtifactMetadata([
            'uri' => '/shared/crawler-output',
            'media_type' => 'inode/directory',
        ]);
        $withoutUri = PipelineStageArtifact::tryFromArtifactMetadata([
            'media_type' => 'inode/directory',
        ]);

        $this->assertTrue($withUri?->isDirectoryOutput());
        $this->assertFalse($withoutUri?->isDirectoryOutput());
    }

    public function test_markdown_output_is_recognized_even_without_a_uri(): void
    {
        $artifact = PipelineStageArtifact::tryFromArtifactMetadata([
            'media_type' => 'text/markdown',
        ]);

        $this->assertTrue($artifact?->isMarkdownOutput());
    }

    public function test_output_root_is_derived_from_a_matching_relative_path(): void
    {
        $artifact = PipelineStageArtifact::tryFromArtifactMetadata([
            'uri' => '/shared/markdown/page.md',
            'relative_path' => 'page.md',
            'media_type' => 'text/markdown',
        ]);

        $this->assertSame('/shared/markdown', $artifact?->outputRoot());
    }

    public function test_output_root_supports_nested_relative_paths(): void
    {
        $artifact = PipelineStageArtifact::tryFromArtifactMetadata([
            'uri' => '/shared/markdown/docs/page.md',
            'relative_path' => 'docs/page.md',
        ]);

        $this->assertSame('/shared/markdown', $artifact?->outputRoot());
    }

    public function test_output_root_requires_a_suffix_match(): void
    {
        $artifact = PipelineStageArtifact::tryFromArtifactMetadata([
            'uri' => '/shared/markdown/page.md',
            'relative_path' => 'other.md',
        ]);

        $this->assertNull($artifact?->outputRoot());
    }

    public function test_output_root_requires_a_nonempty_relative_path(): void
    {
        $artifact = PipelineStageArtifact::tryFromArtifactMetadata([
            'uri' => '/shared/markdown/page.md',
            'relative_path' => '',
        ]);

        $this->assertNull($artifact?->outputRoot());
    }

    public function test_output_root_requires_a_uri(): void
    {
        $artifact = PipelineStageArtifact::tryFromArtifactMetadata([
            'relative_path' => 'page.md',
        ]);

        $this->assertNull($artifact?->outputRoot());
    }
}
