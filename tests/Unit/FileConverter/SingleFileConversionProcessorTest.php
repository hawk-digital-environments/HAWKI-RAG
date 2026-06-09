<?php

declare(strict_types=1);

namespace Tests\Unit\FileConverter;

use App\Services\FileConverter\DocumentConverter;
use App\Services\FileConverter\SingleFileConversionProcessor;
use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\Validation\PipelineDataValidator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use SplFileInfo;
use Tests\TestCase;

class SingleFileConversionProcessorTest extends TestCase
{
    public function test_it_processes_a_file_and_skips_valid_cached_output(): void
    {
        $root = storage_path('framework/testing/file-converter/'.(string) Str::uuid());
        File::ensureDirectoryExists($root);
        $source = $root.'/source.pdf';
        File::put($source, 'source document bytes');

        try {
            $processor = app(SingleFileConversionProcessor::class);
            $validator = app(PipelineDataValidator::class);
            $logger = app(PipelineStageLogger::class);

            $converter = Mockery::mock(DocumentConverter::class);
            $converter->shouldReceive('requestDocumentToMarkdown')
                ->once()
                ->with(Mockery::type(SplFileInfo::class))
                ->andReturn([
                    'source.md' => '# Source'.PHP_EOL.PHP_EOL.'Converted markdown content for the source document.',
                ]);

            $processed = $processor->process($source, 'job-convert-unit', false, 0, 0, $converter, $validator, $logger);

            $this->assertTrue($processed->isProcessed());
            $this->assertFileExists($root.'/converted_source/source.md');
            $this->assertFileExists($root.'/source_converted.md');
            $this->assertFileExists($root.'/converted_source/conversion_meta.json');

            $cachedConverter = Mockery::mock(DocumentConverter::class);
            $cachedConverter->shouldReceive('requestDocumentToMarkdown')->never();

            $skipped = $processor->process($source, 'job-convert-unit', false, 0, 0, $cachedConverter, $validator, $logger);

            $this->assertTrue($skipped->isSkipped());
        } finally {
            File::deleteDirectory($root);
        }
    }
}
