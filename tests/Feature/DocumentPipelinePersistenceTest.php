<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentProcessingState;
use App\Services\DocumentPipeline\InitializeDocumentPipelineState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentPipelinePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_can_be_created(): void
    {
        $document = $this->makeDocument();

        $this->assertNotEmpty($document->id);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'checksum_sha256' => $document->checksum_sha256,
            'status' => Document::STATUS_CREATED,
        ]);
    }

    public function test_pipeline_states_initialize_once_only(): void
    {
        $document = $this->makeDocument();
        $initializer = app(InitializeDocumentPipelineState::class);

        $result = $initializer->handle($document);

        $this->assertCount(6, $result['created']);
        $this->assertCount(0, $result['existing']);
        $this->assertEquals(
            InitializeDocumentPipelineState::STAGES,
            DocumentProcessingState::query()
                ->where('document_id', $document->id)
                ->orderBy('id')
                ->pluck('stage')
                ->all()
        );
        $this->assertDatabaseCount('document_processing_state', 6);
    }

    public function test_duplicate_initialization_does_not_create_duplicate_stage_rows(): void
    {
        $document = $this->makeDocument();
        $initializer = app(InitializeDocumentPipelineState::class);

        $initializer->handle($document);
        $result = $initializer->handle($document);

        $this->assertCount(0, $result['created']);
        $this->assertCount(6, $result['existing']);
        $this->assertDatabaseCount('document_processing_state', 6);
    }

    public function test_chunks_can_be_attached_to_a_document(): void
    {
        $document = $this->makeDocument();

        $chunk = $document->chunks()->create([
            'chunk_index' => 0,
            'chunk_text' => 'This is the first chunk.',
            'token_count' => 6,
            'page_number' => 1,
            'section_title' => 'Intro',
            'metadata_json' => ['source' => 'test'],
        ]);

        $this->assertNotEmpty($chunk->id);
        $this->assertSame($document->id, $chunk->document_id);
        $this->assertDatabaseHas('document_chunks', [
            'id' => $chunk->id,
            'document_id' => $document->id,
            'chunk_index' => 0,
        ]);
    }

    public function test_cascade_delete_removes_states_and_chunks(): void
    {
        $document = $this->makeDocument();
        $initializer = app(InitializeDocumentPipelineState::class);
        $initializer->handle($document);

        DocumentChunk::query()->create([
            'document_id' => $document->id,
            'chunk_index' => 0,
            'chunk_text' => 'chunk-0',
        ]);
        DocumentChunk::query()->create([
            'document_id' => $document->id,
            'chunk_index' => 1,
            'chunk_text' => 'chunk-1',
        ]);

        $document->delete();

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        $this->assertDatabaseCount('document_processing_state', 0);
        $this->assertDatabaseCount('document_chunks', 0);
    }

    private function makeDocument(): Document
    {
        return Document::query()->create([
            'source_type' => Document::SOURCE_UPLOAD,
            'storage_path' => 'documents/testing/file.md',
            'checksum_sha256' => hash('sha256', Str::uuid()->toString()),
            'status' => Document::STATUS_CREATED,
        ]);
    }
}

