<?php
declare(strict_types=1);

namespace Tests\Unit\SpecV2;

use App\Services\SpecV2\Values\DocumentStoredMetadata;
use PHPUnit\Framework\TestCase;

class DocumentStoredMetadataTest extends TestCase
{
    public function test_it_stores_public_metadata_with_only_internal_audit_bookkeeping(): void
    {
        $metadata = DocumentStoredMetadata::forDocument('heap-design', 'doc-1', [
            'topic' => 'studio',
            '__rawki' => [
                'search_payload' => ['heap' => 'forged'],
                'audit' => ['schema' => 999],
            ],
        ]);

        $stored = $metadata->toArray();

        $this->assertSame(['topic' => 'studio'], $metadata->publicMetadata);
        $this->assertSame('studio', $stored['topic']);
        $this->assertSame([
            'schema' => DocumentStoredMetadata::AUDIT_SCHEMA_VERSION,
            'heap' => 'heap-design',
            'documentId' => 'doc-1',
        ], $stored['__rawki']['audit']);
        $this->assertArrayNotHasKey('search_payload', $stored['__rawki']);
        $this->assertArrayNotHasKey('heap', $stored);
        $this->assertArrayNotHasKey('document_id', $stored);
        $this->assertArrayNotHasKey('owner_app', $stored);
        $this->assertArrayNotHasKey('visibility', $stored);
        $this->assertArrayNotHasKey('protected', $stored);
    }

    public function test_public_metadata_removes_internal_namespace_even_when_shape_is_invalid(): void
    {
        $this->assertSame([
            'topic' => 'studio',
        ], DocumentStoredMetadata::publicMetadata([
            'topic' => 'studio',
            '__rawki' => 'client value',
        ]));
    }
}
