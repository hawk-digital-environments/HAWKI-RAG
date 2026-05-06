<?php

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_can_be_created(): void
    {
        $document = $this->makeDocument();

        $this->assertNotEmpty($document->id);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'collection' => $document->collection,
            'checksum_sha256' => $document->checksum_sha256,
            'status' => Document::STATUS_CREATED,
        ]);
    }

    public function test_document_checksum_is_unique_within_a_collection(): void
    {
        $checksum = hash('sha256', 'same-document');

        Document::query()->create([
            'collection' => 'main',
            'source_type' => Document::SOURCE_UPLOAD,
            'storage_path' => 'documents/testing/first.md',
            'checksum_sha256' => $checksum,
            'status' => Document::STATUS_CREATED,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Document::query()->create([
            'collection' => 'main',
            'source_type' => Document::SOURCE_UPLOAD,
            'storage_path' => 'documents/testing/second.md',
            'checksum_sha256' => $checksum,
            'status' => Document::STATUS_CREATED,
        ]);
    }

    public function test_same_checksum_can_exist_in_different_collections(): void
    {
        $checksum = hash('sha256', 'shared-document');

        Document::query()->create([
            'collection' => 'main',
            'source_type' => Document::SOURCE_UPLOAD,
            'storage_path' => 'documents/testing/main.md',
            'checksum_sha256' => $checksum,
            'status' => Document::STATUS_CREATED,
        ]);

        $document = Document::query()->create([
            'collection' => 'archive',
            'source_type' => Document::SOURCE_UPLOAD,
            'storage_path' => 'documents/testing/archive.md',
            'checksum_sha256' => $checksum,
            'status' => Document::STATUS_CREATED,
        ]);

        $this->assertNotEmpty($document->id);
        $this->assertDatabaseCount('documents', 2);
    }

    private function makeDocument(): Document
    {
        return Document::query()->create([
            'collection' => 'main',
            'source_type' => Document::SOURCE_UPLOAD,
            'storage_path' => 'documents/testing/file.md',
            'checksum_sha256' => hash('sha256', Str::uuid()->toString()),
            'status' => Document::STATUS_CREATED,
        ]);
    }
}
