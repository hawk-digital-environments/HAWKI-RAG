<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RagSearch;

use App\Services\RagSearch\RagSearchResponseFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(RagSearchResponseFilter::class)]
class RagSearchResponseFilterTest extends TestCase
{
    public function testHitCarriesItsManagedDocumentId(): void
    {
        $response = (new RagSearchResponseFilter)->filter([
            'hits' => [
                [
                    'collection' => 'hawki_assistant_12',
                    'payload' => [
                        'content' => 'chunk text',
                        'managed_document_id' => 'adoc_test1',
                    ],
                ],
            ],
        ]);

        static::assertSame('adoc_test1', $response['results'][0]['metadata']['document_id']);
    }

    public function testHitWithoutManagedDocumentIdOmitsTheKey(): void
    {
        $response = (new RagSearchResponseFilter)->filter([
            'hits' => [
                [
                    'payload' => [
                        'content' => 'chunk text',
                        'page_url' => 'https://example.com/page',
                    ],
                ],
            ],
        ]);

        static::assertArrayNotHasKey('document_id', $response['results'][0]['metadata']);
        static::assertSame('https://example.com/page', $response['results'][0]['metadata']['url']);
    }

    public function testHitCarriesItsExternalDocumentId(): void
    {
        $response = (new RagSearchResponseFilter)->filter([
            'hits' => [
                [
                    'collection' => 'hawki_assistant_12',
                    'payload' => [
                        'content' => 'chunk text',
                        'external_document_id' => 'attach-uuid-1',
                    ],
                ],
            ],
        ]);

        static::assertSame('attach-uuid-1', $response['results'][0]['metadata']['external_document_id']);
    }

    public function testClientMetadataIsNotExposed(): void
    {
        $response = (new RagSearchResponseFilter)->filter([
            'hits' => [
                [
                    'payload' => [
                        'content' => 'chunk text',
                        'metadata' => ['attachment_uuid' => 'uuid-1', 'assistant_id' => 12],
                    ],
                ],
            ],
        ]);

        static::assertArrayNotHasKey('meta', $response['results'][0], 'the client metadata bag stays internal to the ingest pipeline');
    }
}
