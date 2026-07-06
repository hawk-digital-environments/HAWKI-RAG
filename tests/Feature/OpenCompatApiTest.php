<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\SpecV2\Group;
use App\Models\SpecV2\GroupMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use App\Services\Authorization\IdentityProvisioningService;
use App\Services\SpecV2\Repositories\DocumentGrantRepository;
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

        $this->actingAsApplication();

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

        $this->actingAsApplication();

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

        ['token' => $token] = $this->issueApplicationToken([
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

        $assignments = app(IdentityProvisioningService::class)->groupMemberAssignments(
            'default',
            'rawki-default',
            ['learner-123'],
        );
        Group::query()->create([
            'id' => 'rawki-default:course_a',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Course A',
            'metadata_json' => [],
        ]);
        GroupMember::query()->create([
            'group_id' => 'rawki-default:course_a',
            'user_identifier' => 'learner-123',
            'internal_user_id' => $assignments[0]->internalUserId,
        ]);
        app(DocumentGrantRepository::class)->add((string) $protectedDocument->id, ['rawki-default:course_a']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/retrieve/chunks', [
                'query' => 'policy',
                'top_k' => 1,
                'user_identifier' => 'learner-123',
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

        $this->actingAsApplication();

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

    public function test_update_document_metadata_refreshes_canonical_search_payload(): void
    {
        Dataset::query()->create([
            'dataset_id' => 'compat-update',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Compat Update',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => ['course' => 'design'],
            'qdrant_collection' => 'compat_update',
            'neo4j_namespace' => 'compat_update',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $document = Document::query()->create([
            'dataset_id' => 'compat-update',
            'collection' => 'compat_update',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/update',
            'original_filename' => 'update.md',
            'storage_path' => '/tmp/update.md',
            'checksum_sha256' => hash('sha256', 'update'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => ['topic' => 'before'],
        ]);

        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        Http::fake([
            'http://qdrant.test/*' => Http::response(['status' => 'ok', 'result' => ['status' => 'acknowledged']], 200),
        ]);

        $this->actingAsApplication();

        $this->postJson('/api/documents/'.$document->id.'/update_metadata', [
            'metadata' => ['topic' => 'after', 'level' => 'advanced'],
        ])->assertOk()
            ->assertJsonPath('document.metadata.topic', 'after')
            ->assertJsonPath('document.metadata.level', 'advanced');

        $document->refresh();

        $this->assertSame('after', $document->metadata_json['topic']);
        $this->assertSame('advanced', $document->metadata_json['level']);
        $this->assertSame('after', $document->metadata_json['__rawki']['search_payload']['topic']);
        $this->assertSame('advanced', $document->metadata_json['__rawki']['search_payload']['level']);
        $this->assertSame('design', $document->metadata_json['__rawki']['search_payload']['course']);

        Http::assertSent(function (Request $request) use ($document): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://qdrant.test/collections/compat_update/points/payload/delete'
                && ($request['filter']['must'][0]['match']['value'] ?? null) === $document->id
                && ($request['keys'] ?? null) === ['course', 'topic', 'level', 'heap', 'document_id', 'owner_app', 'visibility', 'protected'];
        });

        Http::assertSent(function (Request $request) use ($document): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://qdrant.test/collections/compat_update/points/payload'
                && ($request['filter']['must'][0]['match']['value'] ?? null) === $document->id
                && ($request['payload']['topic'] ?? null) === 'after'
                && ($request['payload']['level'] ?? null) === 'advanced'
                && ($request['payload']['course'] ?? null) === 'design';
        });
    }

    public function test_search_documents_is_scoped_to_application_heaps(): void
    {
        ['application' => $application, 'token' => $token] = $this->issueApplicationToken([
            'id' => 'hawki-web',
            'tenant_id' => 'uni-hawk',
            'permissions' => ['reads'],
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-owned',
            'tenant_id' => 'uni-hawk',
            'owner_application_id' => $application->id,
            'name' => 'Owned Heap',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => [],
            'qdrant_collection' => 'owned_heap',
            'neo4j_namespace' => 'owned_heap',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-other',
            'tenant_id' => 'uni-hawk',
            'owner_application_id' => 'other-app',
            'name' => 'Other Heap',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => [],
            'qdrant_collection' => 'other_heap',
            'neo4j_namespace' => 'other_heap',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $owned = Document::query()->create([
            'dataset_id' => 'heap-owned',
            'collection' => 'owned_heap',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/owned',
            'original_filename' => 'owned.pdf',
            'storage_path' => '/tmp/owned.pdf',
            'checksum_sha256' => hash('sha256', 'owned'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);

        Document::query()->create([
            'dataset_id' => 'heap-other',
            'collection' => 'other_heap',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/other',
            'original_filename' => 'other.pdf',
            'storage_path' => '/tmp/other.pdf',
            'checksum_sha256' => hash('sha256', 'other'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/search/documents', [
                'query' => 'pdf',
            ])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('documents.0.id', $owned->id);
    }

    public function test_batch_documents_respects_same_application_scope(): void
    {
        ['application' => $application, 'token' => $token] = $this->issueApplicationToken([
            'id' => 'hawki-batch',
            'tenant_id' => 'uni-hawk',
            'permissions' => ['reads'],
        ]);

        Dataset::query()->create([
            'dataset_id' => 'batch-owned',
            'tenant_id' => 'uni-hawk',
            'owner_application_id' => $application->id,
            'name' => 'Batch Owned',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => [],
            'qdrant_collection' => 'batch_owned',
            'neo4j_namespace' => 'batch_owned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Dataset::query()->create([
            'dataset_id' => 'batch-other',
            'tenant_id' => 'uni-hawk',
            'owner_application_id' => 'other-app',
            'name' => 'Batch Other',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => [],
            'qdrant_collection' => 'batch_other',
            'neo4j_namespace' => 'batch_other',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $owned = Document::query()->create([
            'dataset_id' => 'batch-owned',
            'collection' => 'batch_owned',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/owned-batch',
            'original_filename' => 'owned-batch.pdf',
            'storage_path' => '/tmp/owned-batch.pdf',
            'checksum_sha256' => hash('sha256', 'owned-batch'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);

        $other = Document::query()->create([
            'dataset_id' => 'batch-other',
            'collection' => 'batch_other',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/other-batch',
            'original_filename' => 'other-batch.pdf',
            'storage_path' => '/tmp/other-batch.pdf',
            'checksum_sha256' => hash('sha256', 'other-batch'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/batch/documents', [
                'document_ids' => [$owned->id, $other->id],
            ])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('documents.0.id', $owned->id);
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
