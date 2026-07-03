<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\AuthorizationIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenCompatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_api_routes_require_api_authentication(): void
    {
        $this->getJson('/api/models')
            ->assertUnauthorized();
    }

    public function test_text_ingest_proxies_to_bridge_and_returns_external_document_shape(): void
    {
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        Http::fake([
            'http://bridge.test/ingest' => Http::response([
                'ok' => true,
                'points' => 2,
            ], 200),
        ]);

        $this->actingAsApiUser();

        $this->postJson('/api/ingest/text', [
            'id' => 'doc-text-1',
            'text' => 'external API compatible text ingest.',
            'filename' => 'text.txt',
            'collection' => 'compat',
            'metadata' => ['source' => 'test'],
        ], ['Idempotency-Key' => 'idem-1'])
            ->assertCreated()
            ->assertJsonPath('document.id', 'doc-text-1')
            ->assertJsonPath('document.filename', 'text.txt')
            ->assertJsonPath('document.collection', 'compat')
            ->assertJsonPath('bridge_response.ok', true);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'http://bridge.test/ingest'
                && $request->hasHeader('Idempotency-Key', 'idem-1')
                && $data['docs'][0]['id'] === 'doc-text-1'
                && $data['docs'][0]['payload']['source'] === 'test'
                && $data['collection'] === 'compat';
        });
    }

    public function test_retrieve_chunks_uses_bridge_query_and_shapes_hits(): void
    {
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        Http::fake([
            'http://bridge.test/query' => Http::response([
                'ok' => true,
                'count' => 1,
                'hits' => [[
                    'id' => 'point-1',
                    'score' => 0.91,
                    'payload' => [
                        'doc_id' => 'external-doc-1',
                        'content' => 'Matched text chunk',
                        'title' => 'Chunked',
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAsApiUser();

        $this->postJson('/api/retrieve/chunks', [
            'query' => 'matched',
            'top_k' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('chunks.0.id', 'point-1')
            ->assertJsonPath('chunks.0.document_id', 'external-doc-1')
            ->assertJsonPath('chunks.0.content', 'Matched text chunk')
            ->assertJsonPath('chunks.0.score', 0.91);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://bridge.test/query'
            && $request->data()['generate'] === false);
    }

    public function test_retrieve_chunks_sends_authorization_context_from_identity_mapping(): void
    {
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        Http::fake([
            'http://bridge.test/query' => Http::response([
                'ok' => true,
                'count' => 0,
                'hits' => [],
            ], 200),
        ]);

        $user = $this->actingAsApiUser();
        AuthorizationIdentity::query()->create([
            'user_id' => $user->id,
            'issuer' => 'https://issuer.test',
            'subject' => 'subject-123',
            'provider' => 'keycloak',
            'external_user_id' => 'learner-123',
        ]);

        $this->postJson('/api/retrieve/chunks', [
            'query' => 'policy',
            'top_k' => 1,
        ])->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://bridge.test/query'
            && ($request->data()['auth_context'] ?? null) === [
                'provider' => 'keycloak',
                'user_id' => 'learner-123',
            ]);
    }

    public function test_documents_list_docs_returns_compat_shape_without_rawki_wrapper(): void
    {
        Dataset::query()->create([
            'dataset_id' => 'compat-dataset',
            'name' => 'Compat Dataset',
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_compat_dataset',
            'neo4j_namespace' => 'hawki_compat_dataset',
            'created_at' => now(),
        ]);

        $document = Document::query()->create([
            'external_id' => 'external-doc-1',
            'dataset_id' => 'compat-dataset',
            'collection' => 'hawki_compat_dataset',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/doc.txt',
            'original_filename' => 'doc.txt',
            'storage_path' => '/tmp/doc.txt',
            'mime_type' => 'text/plain',
            'checksum_sha256' => hash('sha256', 'doc.txt'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => ['task_id' => 'task-1', 'job_id' => 'job-1'],
        ]);

        $this->actingAsApiUser();

        $this->postJson('/api/documents/list_docs', [
            'dataset_id' => 'compat-dataset',
        ])
            ->assertOk()
            ->assertJsonMissingPath('success')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('documents.0.id', $document->id)
            ->assertJsonPath('documents.0.filename', 'doc.txt')
            ->assertJsonPath('documents.0.metadata.dataset_id', 'compat-dataset')
            ->assertJsonPath('documents.0.system_metadata.task_id', 'task-1');
    }

    public function test_unsupported_external_semantics_are_explicit(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/migrate/document', [
            'document_id' => 'source-doc',
        ])
            ->assertStatus(501)
            ->assertJsonPath('error', 'unsupported')
            ->assertJsonPath('endpoint', 'migrate/document');
    }
}
