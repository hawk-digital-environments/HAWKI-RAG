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

    public function test_retrieve_chunks_sends_gateway_filters_from_user_identity_mapping(): void
    {
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        config()->set('authz.enabled', true);
        Http::fake([
            'http://bridge.test/query' => Http::response([
                'ok' => true,
                'count' => 0,
                'hits' => [],
            ], 200),
        ]);

        $this->issueApplicationToken([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
            'permissions' => ['reads'],
        ]);

        Dataset::query()->create([
            'dataset_id' => 'compat-public',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Compat Public',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => [],
            'qdrant_collection' => 'compat_public',
            'neo4j_namespace' => 'compat_public',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Dataset::query()->create([
            'dataset_id' => 'compat-protected',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Compat Protected',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => true,
            'metadata_json' => [],
            'qdrant_collection' => 'compat_protected',
            'neo4j_namespace' => 'compat_protected',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $publicDocument = Document::query()->create([
            'dataset_id' => 'compat-public',
            'collection' => 'compat_public',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/public',
            'storage_path' => '/tmp/public.txt',
            'checksum_sha256' => hash('sha256', 'compat-public'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);

        $protectedDocument = Document::query()->create([
            'dataset_id' => 'compat-protected',
            'collection' => 'compat_protected',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/protected',
            'storage_path' => '/tmp/protected.txt',
            'checksum_sha256' => hash('sha256', 'compat-protected'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);

        $user = $this->actingAsApiUser();
        AuthorizationIdentity::query()->create([
            'user_id' => $user->id,
            'issuer' => 'https://issuer.test',
            'subject' => 'subject-123',
            'provider' => 'keycloak',
            'external_user_id' => 'learner-123',
            'tenant_id' => 'default',
            'application_id' => 'rawki-default',
        ]);
        \App\Models\AuthorizationPermissionEvent::query()->create([
            'provider' => 'keycloak',
            'external_user_id' => 'learner-123',
            'course_id' => 'course-a',
            'role' => 'member',
            'document_id' => null,
            'payload' => ['type' => 'membership'],
        ]);
        \App\Models\AuthorizationPermissionEvent::query()->create([
            'provider' => 'keycloak',
            'external_user_id' => null,
            'course_id' => 'course-a',
            'role' => null,
            'document_id' => $protectedDocument->id,
            'payload' => ['type' => 'document_relation'],
        ]);

        $this->postJson('/api/retrieve/chunks', [
            'query' => 'policy',
            'top_k' => 1,
        ])->assertOk();

        Http::assertSent(function (Request $request) use ($protectedDocument, $publicDocument): bool {
            if ($request->url() !== 'http://bridge.test/query') {
                return false;
            }

            $filters = $request->data()['filters'] ?? [];
            $docIds = array_map(
                static fn (array $match): ?string => $match['match']['value'] ?? null,
                is_array($filters['should'] ?? null) ? $filters['should'] : [],
            );
            sort($docIds);
            $expected = [$protectedDocument->id, $publicDocument->id];
            sort($expected);

            return ($request->data()['auth_context'] ?? null) === null
                && $docIds === $expected;
        });
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
