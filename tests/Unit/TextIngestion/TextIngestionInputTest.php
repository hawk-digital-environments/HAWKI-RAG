<?php

declare(strict_types=1);

namespace Tests\Unit\TextIngestion;

use App\Services\TextIngestion\Values\TextContentFormat;
use App\Services\TextIngestion\Values\TextIngestionInput;
use PHPUnit\Framework\TestCase;

final class TextIngestionInputTest extends TestCase
{
    public function test_it_creates_a_typed_input_from_validated_data(): void
    {
        $input = TextIngestionInput::fromValidated([
            'external_document_id' => 'document-123',
            'dataset_id' => 'default',
            'text' => '# Example',
            'content_format' => 'markdown',
            'display_name' => 'API document',
            'source_url' => 'https://hawki.example/documents/document-123',
            'metadata' => ['assistant_id' => 'assistant-42'],
        ]);

        self::assertSame('document-123', $input->externalDocumentId);
        self::assertSame(TextContentFormat::Markdown, $input->contentFormat);
        self::assertSame('markdown', $input->toArray()['content_format']);
    }

    public function test_optional_values_use_empty_defaults(): void
    {
        $input = TextIngestionInput::fromValidated([
            'external_document_id' => 'document-123',
            'dataset_id' => 'default',
            'text' => 'Example',
            'content_format' => 'plain_text',
        ]);

        self::assertNull($input->displayName);
        self::assertNull($input->sourceUrl);
        self::assertSame([], $input->metadata);
    }
}
