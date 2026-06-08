<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\PipelineDataValidator;
use Tests\TestCase;

class PipelineDataValidatorTest extends TestCase
{
    public function test_it_validates_scrape_elements(): void
    {
        $validator = app(PipelineDataValidator::class);

        $valid = $validator->validateScrapeElement([
            'page_url' => 'https://example.test/page',
            'url_hash' => 'hash-page',
            'title' => 'Example page',
            'content_hash' => 'hash-content',
        ]);

        $this->assertSame([], $valid['errors']);
        $this->assertSame([], $valid['warnings']);

        $invalid = $validator->validateScrapeElement([
            'page_url' => 'not-a-url',
        ]);

        $this->assertContains('page_url is missing or invalid.', $invalid['errors']);
        $this->assertContains('url_hash is missing.', $invalid['errors']);
        $this->assertContains('title is missing.', $invalid['warnings']);
        $this->assertContains('content_hash is missing.', $invalid['warnings']);
    }

    public function test_it_validates_converted_files(): void
    {
        $validator = app(PipelineDataValidator::class);

        $valid = $validator->validateConvertedFiles([
            'page.md' => '# Page Title' . PHP_EOL . PHP_EOL . 'Long enough markdown content.',
        ]);

        $this->assertSame([], $valid['errors']);

        $invalid = $validator->validateConvertedFiles([
            '../escape.md' => 'Unsafe',
            'empty.md' => '',
            'asset.txt' => 'Text only',
        ]);

        $this->assertContains('converter returned unsafe relative path: ../escape.md', $invalid['errors']);
        $this->assertContains('empty.md: Markdown content is empty.', $invalid['errors']);
    }

    public function test_it_validates_conversion_metadata(): void
    {
        $validator = app(PipelineDataValidator::class);

        $metadata = $validator->validateConversionMetadata([
            'converted_id' => 'converted-1',
            'source_file' => '/missing/source.pdf',
            'output_dir' => '/missing/output',
            'files' => ['page.md'],
            'converted_at' => '2026-06-09T10:00:00+00:00',
        ]);

        $this->assertSame([], $metadata['errors']);
        $this->assertContains('source_file does not exist on disk.', $metadata['warnings']);
        $this->assertContains('doc_id is missing.', $metadata['warnings']);
        $this->assertContains('title is missing.', $metadata['warnings']);

        $invalid = $validator->validateConversionMetadata([
            'files' => ['../escape.md'],
        ]);

        $this->assertContains('converted_id is missing.', $invalid['errors']);
        $this->assertContains('files contains unsafe relative path: ../escape.md', $invalid['errors']);
        $this->assertContains('files must include at least one Markdown file.', $invalid['errors']);
    }
}
