<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Validation\PipelineDataValidator;
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

    public function test_it_normalizes_the_first_scalar_value(): void
    {
        $validator = app(PipelineDataValidator::class);

        $this->assertSame('first', $validator->firstScalar([' first ', 'second']));
        $this->assertSame('value', $validator->firstScalar(' value '));
        $this->assertNull($validator->firstScalar('   '));
        $this->assertNull($validator->firstScalar(null));
    }
}
