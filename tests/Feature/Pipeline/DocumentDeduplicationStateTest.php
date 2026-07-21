<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Models\DocumentDeduplicationState;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentDeduplicationStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_logical_document_identity_is_unique_inside_a_scope(): void
    {
        DocumentDeduplicationState::query()->create([
            'scope_key' => 'collection-a',
            'document_id' => 'doc-a',
            'completed_content_hash' => str_repeat('a', 64),
            'status' => DocumentDeduplicationState::STATUS_COMPLETED,
            'decision' => DocumentDeduplicationState::DECISION_NEW,
            'metadata' => ['source_url' => 'https://example.test/a'],
        ]);

        $this->expectException(QueryException::class);

        DocumentDeduplicationState::query()->create([
            'scope_key' => 'collection-a',
            'document_id' => 'doc-a',
            'completed_content_hash' => str_repeat('b', 64),
            'status' => DocumentDeduplicationState::STATUS_PROCESSING,
        ]);
    }

    public function test_same_content_hash_can_track_distinct_documents_and_scopes(): void
    {
        $hash = str_repeat('c', 64);
        foreach ([
            ['collection-a', 'doc-a'],
            ['collection-a', 'doc-b'],
            ['collection-b', 'doc-a'],
        ] as [$scopeKey, $documentId]) {
            DocumentDeduplicationState::query()->create([
                'scope_key' => $scopeKey,
                'document_id' => $documentId,
                'completed_content_hash' => $hash,
                'status' => DocumentDeduplicationState::STATUS_COMPLETED,
                'decision' => DocumentDeduplicationState::DECISION_DUPLICATE,
                'metadata' => ['scope' => $scopeKey],
            ]);
        }

        $this->assertDatabaseCount('document_deduplication_states', 3);
        $this->assertSame(
            ['scope' => 'collection-a'],
            DocumentDeduplicationState::query()
                ->where('scope_key', 'collection-a')
                ->where('document_id', 'doc-a')
                ->firstOrFail()
                ->metadata,
        );
    }
}
