<?php

declare(strict_types=1);

namespace Tests\Feature\Datasets;

use App\Models\Dataset;
use App\Models\User;
use App\Services\Dataset\DatasetService;
use App\Services\Dataset\Exceptions\DatasetBackendUnavailableException;
use App\Services\Dataset\Exceptions\DatasetCreationConflictException;
use App\Services\Dataset\Exceptions\DatasetInactiveException;
use App\Services\Profile\Values\ApiTokenAbility;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Support\QueryBffFixture;
use Tests\TestCase;

/**
 * The public dataset routes remain a thin BFF while Python owns the authority.
 *
 * Every test proves the same three things: the request reaches Python with the
 * documented token and payload, the Python answer maps back onto the existing
 * public envelope, and Laravel's frozen dataset tables are never written.
 */
final class PythonDatasetBffTest extends TestCase
{
    use RefreshDatabase;

    private QueryBffFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new QueryBffFixture;
        config()->set([
            'config.dataset_backend' => 'python',
            'config.query_backend' => 'python',
            'config.text_ingestion_backend' => 'python',
            'config.managed_document_backend' => 'python',
            'config.crawler_ingestion_backend' => 'python',
            'config.query_auth.all_datasets_by_default' => false,
            'config.python_dataset_url' => 'http://python.test/v1/datasets',
            'config.python_query_datasets_url' => 'http://python.test/v1/query/datasets',
            'config.query_bff' => $this->fixture->config->get('config.query_bff'),
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->fixture->close();

        parent::tearDown();
    }

    public function test_list_is_forwarded_with_the_dataset_admin_token(): void
    {
        $datasets = [$this->dataset('python-a', 'Python A')];
        Http::fake([
            'http://python.test/v1/datasets*' => Http::response([
                'success' => true,
                'datasets' => $datasets,
            ]),
        ]);

        $this->getJson('/api/datasets?limit=25')
            ->assertOk()
            ->assertExactJson(['success' => true, 'datasets' => $datasets]);

        Http::assertSent(function (Request $request): bool {
            $claims = $this->claims($request);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'http://python.test/v1/datasets')
                && str_contains($request->url(), 'limit=25')
                && $claims->sub === 'hawki-bff'
                && $claims->azp === 'hawki-bff'
                && $claims->tenant_id === 'default'
                && $claims->scope === 'rag:datasets:manage';
        });
        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_show_returns_the_python_dataset_and_maps_the_legacy_not_found_envelope(): void
    {
        $dataset = $this->dataset();
        Http::fake([
            'http://python.test/v1/datasets/python-a' => Http::response([
                'success' => true,
                'dataset' => $dataset,
            ]),
        ]);

        $this->getJson('/api/datasets/python-a')
            ->assertOk()
            ->assertExactJson(['success' => true, 'dataset' => $dataset]);

        Http::fake([
            'http://python.test/v1/datasets/missing' => Http::response([
                'success' => false,
                'message' => 'Dataset missing was not found.',
            ], 404),
        ]);

        $this->getJson('/api/datasets/missing')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Dataset missing was not found.',
            ]);

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_create_forwards_only_validated_public_fields_and_preserves_201(): void
    {
        $this->authenticateAdmin();
        $validated = [
            'dataset_id' => 'python-a',
            'name' => 'Python A',
            'description' => 'Created through the BFF.',
            'status' => 'active',
        ];
        $dataset = $this->dataset();
        $payload = [
            'success' => true,
            'dataset_id' => 'python-a',
            'dataset' => $dataset,
            'grant_token' => 'dgt_'.str_repeat('a', 64),
        ];
        Http::fake([
            'http://python.test/v1/datasets' => Http::response($payload, 201),
        ]);

        $this->postJson('/api/datasets', [
            ...$validated,
            'embedding_provider' => 'must-not-be-forwarded',
            'tenant_id' => 'another-tenant',
        ])
            ->assertCreated()
            ->assertExactJson($payload);

        Http::assertSent(function (Request $request) use ($validated): bool {
            $claims = $this->claims($request);

            return $request->method() === 'POST'
                && $request->url() === 'http://python.test/v1/datasets'
                && $request->data() === $validated
                && $claims->sub === 'hawki-bff'
                && $claims->scope === 'rag:datasets:manage';
        });
        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_a_compatible_create_replay_preserves_200_without_a_token(): void
    {
        $this->authenticateAdmin();
        $dataset = $this->dataset();
        Http::fake([
            'http://python.test/v1/datasets' => Http::response([
                'success' => true,
                'dataset_id' => 'python-a',
                'dataset' => $dataset,
            ]),
        ]);

        $this->postJson('/api/datasets', ['dataset_id' => 'python-a'])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'dataset_id' => 'python-a',
                'dataset' => $dataset,
            ]);

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_delete_preserves_the_pending_cleanup_envelope(): void
    {
        $this->authenticateAdmin();
        $pending = [
            'success' => true,
            'message' => 'Dataset python-a is hidden. Scoped cleanup will continue automatically in the background.',
            'cleanup' => [
                'dataset_id' => 'python-a',
                'status' => 'archived',
                'cleanup_pending' => true,
            ],
        ];
        Http::fake([
            'http://python.test/v1/datasets/python-a/storage' => Http::response($pending, 202),
        ]);

        $this->deleteJson('/api/datasets/python-a/storage')
            ->assertStatus(202)
            ->assertExactJson($pending);

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_delete_preserves_the_complete_envelope(): void
    {
        $this->authenticateAdmin();
        $complete = [
            'success' => true,
            'message' => 'Dataset python-a cleanup is complete.',
            'cleanup' => [
                'dataset_id' => 'python-a',
                'status' => 'deleted',
                'cleanup_pending' => false,
            ],
        ];
        Http::fake([
            'http://python.test/v1/datasets/python-a/storage' => Http::response($complete),
        ]);

        $this->deleteJson('/api/datasets/python-a/storage')
            ->assertOk()
            ->assertExactJson($complete);

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_create_rejects_a_caller_selected_physical_scope_before_forwarding(): void
    {
        $this->authenticateAdmin();

        foreach ([
            'qdrant_collection' => 'caller_collection',
            'qdrantCollection' => 'callerCollection',
            'neo4j_namespace' => 'caller_namespace',
            'neo4jNamespace' => 'callerNamespace',
        ] as $field => $value) {
            $this->postJson('/api/datasets', ['dataset_id' => 'python-a', $field => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        Http::assertNothingSent();
        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_create_requires_an_authenticated_dataset_manager(): void
    {
        Http::fake();

        $this->postJson('/api/datasets', ['dataset_id' => 'python-a'])->assertUnauthorized();

        $this->authenticate($this->user('ordinary-creator'), ['query']);
        $this->postJson('/api/datasets', ['dataset_id' => 'python-a'])->assertForbidden();

        Http::assertNothingSent();
        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_delete_requires_an_authenticated_dataset_manager(): void
    {
        Http::fake();

        $this->deleteJson('/api/datasets/python-a/storage')->assertUnauthorized();

        $this->authenticate($this->user('ordinary-deleter'), ['query']);
        $this->deleteJson('/api/datasets/python-a/storage')->assertForbidden();

        Http::assertNothingSent();
        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_delete_refuses_legacy_state_before_forwarding_to_python(): void
    {
        $this->authenticateAdmin();
        Dataset::query()->create([
            'dataset_id' => 'python-a',
            'name' => 'Frozen Legacy A',
            'status' => 'active',
            'qdrant_collection' => 'hawki_legacy_a',
            'neo4j_namespace' => 'hawki_legacy_a',
            'created_at' => now(),
        ]);

        $this->deleteJson('/api/datasets/python-a/storage')
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'error' => 'legacy_dataset_state_present',
                'blocking' => ['dataset'],
            ]);

        Http::assertNothingSent();
    }

    public function test_create_conflict_with_an_unknown_field_fails_closed(): void
    {
        $this->authenticateAdmin();
        Http::fake([
            'http://python.test/v1/datasets' => Http::response([
                'message' => 'Dataset [python-a] already exists with a different [secret].',
                'error' => 'dataset_creation_conflict',
            ], 409),
        ]);

        $this->postJson('/api/datasets', ['dataset_id' => 'python-a'])
            ->assertStatus(503)
            ->assertExactJson([
                'error' => 'dataset_unavailable',
                'message' => 'The dataset service is not available.',
            ]);

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_create_conflict_with_malformed_bracket_syntax_fails_closed(): void
    {
        $this->authenticateAdmin();
        Http::fake([
            'http://python.test/v1/datasets' => Http::response([
                'message' => 'Dataset python-a already exists with a different name.',
                'error' => 'dataset_creation_conflict',
            ], 409),
        ]);

        $this->postJson('/api/datasets', ['dataset_id' => 'python-a'])
            ->assertStatus(503)
            ->assertExactJson([
                'error' => 'dataset_unavailable',
                'message' => 'The dataset service is not available.',
            ]);

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_query_self_grant_uses_the_exact_user_query_token(): void
    {
        $user = $this->user('query-grant');
        $this->authenticate($user, ['query']);
        $payload = [
            'success' => true,
            'dataset_id' => 'python-a',
            'query_access' => ['granted' => true, 'permission' => 'query'],
        ];
        Http::fake([
            'http://python.test/v1/datasets/python-a/query-grants/self' => Http::response($payload),
        ]);

        $this->postJson('/api/datasets/python-a/query-grants/self')
            ->assertOk()
            ->assertExactJson($payload);

        Http::assertSent(function (Request $request) use ($user): bool {
            $claims = $this->claims($request);

            return $request->method() === 'POST'
                && $request->url() === 'http://python.test/v1/datasets/python-a/query-grants/self'
                && $request->data() === []
                && $claims->sub === (string) $user->getAuthIdentifier()
                && $claims->azp === 'hawki-bff'
                && $claims->tenant_id === 'default'
                && $claims->scope === 'rag:query';
        });
        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_ingest_self_grant_forwards_the_one_time_token_with_the_ingest_scope(): void
    {
        $user = $this->user('ingest-grant');
        $this->authenticate($user, ['rag:text-ingest']);
        $grantToken = 'dgt_'.str_repeat('b', 64);
        $payload = [
            'success' => true,
            'dataset_id' => 'python-a',
            'ingest_access' => ['granted' => true, 'permission' => 'ingest'],
            'replayed' => false,
        ];
        Http::fake([
            'http://python.test/v1/datasets/python-a/ingest-grants/self' => Http::response($payload),
        ]);

        $this->postJson('/api/datasets/python-a/ingest-grants/self', [
            'grant_token' => $grantToken,
        ])
            ->assertOk()
            ->assertExactJson($payload);

        Http::assertSent(function (Request $request) use ($grantToken, $user): bool {
            $claims = $this->claims($request);

            return $request->method() === 'POST'
                && $request->url() === 'http://python.test/v1/datasets/python-a/ingest-grants/self'
                && $request->data() === ['grant_token' => $grantToken]
                && $claims->sub === (string) $user->getAuthIdentifier()
                && $claims->scope === 'rag:ingest';
        });
        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_query_datasets_uses_the_user_query_token_and_returns_the_legacy_envelope(): void
    {
        $user = $this->user('query-datasets');
        $this->authenticate($user, ['query']);
        Http::fake([
            'http://python.test/v1/query/datasets' => Http::response([
                'success' => true,
                'datasets' => [['dataset_id' => 'python-a', 'name' => 'Python A']],
            ]),
        ]);

        $this->getJson('/api/query/datasets')
            ->assertOk()
            ->assertExactJson([
                'datasets' => [['dataset_id' => 'python-a', 'name' => 'Python A']],
            ]);

        Http::assertSent(function (Request $request) use ($user): bool {
            $claims = $this->claims($request);

            return $request->method() === 'GET'
                && $request->url() === 'http://python.test/v1/query/datasets'
                && $claims->sub === (string) $user->getAuthIdentifier()
                && $claims->scope === 'rag:query';
        });
        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_query_grant_maps_the_not_ready_error(): void
    {
        $this->authenticate($this->user('not-ready'), ['query']);
        Http::fake([
            'http://python.test/v1/datasets/python-a/query-grants/self' => Http::response([
                'message' => 'The requested dataset is not ready for querying.',
                'error' => 'dataset_not_ready',
            ], 409),
        ]);

        $this->postJson('/api/datasets/python-a/query-grants/self')
            ->assertStatus(409)
            ->assertExactJson([
                'error' => 'dataset_not_ready',
                'message' => 'The requested dataset is not ready for querying.',
            ]);

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_ingest_grant_maps_the_required_token_error(): void
    {
        $this->authenticate($this->user('token-required'), ['rag:text-ingest']);
        Http::fake([
            'http://python.test/v1/datasets/python-a/ingest-grants/self' => Http::response([
                'message' => 'A one-time dataset grant token is required to self-grant ingest access.',
                'error' => 'grant_token_required',
            ], 403),
        ]);

        $this->postJson('/api/datasets/python-a/ingest-grants/self')
            ->assertForbidden()
            ->assertExactJson([
                'error' => 'grant_token_required',
                'message' => 'A one-time dataset grant token is required to self-grant ingest access.',
            ]);

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_ingest_grant_maps_the_invalid_token_error(): void
    {
        $this->authenticate($this->user('token-invalid'), ['rag:text-ingest']);
        Http::fake([
            'http://python.test/v1/datasets/python-a/ingest-grants/self' => Http::response([
                'message' => 'The provided dataset grant token is invalid, expired, or already used.',
                'error' => 'grant_token_invalid',
            ], 403),
        ]);

        $this->postJson('/api/datasets/python-a/ingest-grants/self', [
            'grant_token' => 'dgt_'.str_repeat('c', 64),
        ])
            ->assertForbidden()
            ->assertExactJson([
                'error' => 'grant_token_invalid',
                'message' => 'The provided dataset grant token is invalid, expired, or already used.',
            ]);

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_create_maps_the_creation_conflict_error(): void
    {
        $this->authenticateAdmin();
        Http::fake([
            'http://python.test/v1/datasets' => Http::response([
                'message' => 'Dataset [python-a] already exists with a different [name].',
                'error' => 'dataset_creation_conflict',
            ], 409),
        ]);

        $this->postJson('/api/datasets', ['dataset_id' => 'python-a'])
            ->assertStatus(409)
            ->assertExactJson([
                'error' => 'dataset_creation_conflict',
                'message' => 'Dataset [python-a] already exists with a different [name].',
            ]);

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_ensure_maps_a_metadata_creation_conflict_to_the_conflict_exception(): void
    {
        Http::fake([
            'http://python.test/v1/datasets' => Http::response([
                'message' => 'Dataset [python-a] already exists with a different [name].',
                'error' => 'dataset_creation_conflict',
            ], 409),
        ]);

        try {
            app(DatasetService::class)->ensure('python-a');
            $this->fail('Expected a dataset creation conflict.');
        } catch (DatasetCreationConflictException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame([
                'error' => 'dataset_creation_conflict',
                'message' => 'Dataset [python-a] already exists with a different [name].',
            ], $exception->render(HttpRequest::create('/api/datasets'))->getData(true));
        }

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_ensure_maps_a_status_creation_conflict_to_the_inactive_exception(): void
    {
        Http::fake([
            'http://python.test/v1/datasets' => Http::response([
                'message' => 'Dataset [python-a] already exists with a different [status].',
                'error' => 'dataset_creation_conflict',
            ], 409),
        ]);

        try {
            app(DatasetService::class)->ensure('python-a');
            $this->fail('Expected an inactive dataset conflict.');
        } catch (DatasetInactiveException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame([
                'error' => 'dataset_inactive',
                'message' => 'Dataset [python-a] is not active.',
            ], $exception->render(HttpRequest::create('/api/datasets'))->getData(true));
        }

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_ensure_fails_closed_on_a_creation_conflict_for_another_dataset(): void
    {
        Http::fake([
            'http://python.test/v1/datasets' => Http::response([
                'message' => 'Dataset [python-b] already exists with a different [name].',
                'error' => 'dataset_creation_conflict',
            ], 409),
        ]);

        try {
            app(DatasetService::class)->ensure('python-a');
            $this->fail('Expected the dataset boundary to fail closed.');
        } catch (DatasetBackendUnavailableException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame([
                'error' => 'dataset_unavailable',
                'message' => 'The dataset service is not available.',
            ], $exception->render(HttpRequest::create('/api/datasets'))->getData(true));
        }

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_create_accepts_a_dataset_name_that_merely_contains_the_token_prefix(): void
    {
        $this->authenticateAdmin();
        $dataset = $this->dataset('python-a', 'dgt_report_2024');
        $payload = [
            'success' => true,
            'dataset_id' => 'python-a',
            'dataset' => $dataset,
        ];
        Http::fake([
            'http://python.test/v1/datasets' => Http::response($payload, 201),
        ]);

        $this->postJson('/api/datasets', ['dataset_id' => 'python-a'])
            ->assertCreated()
            ->assertExactJson($payload);

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_a_real_grant_token_in_a_non_token_field_fails_closed(): void
    {
        $this->authenticateAdmin();
        Http::fake([
            'http://python.test/v1/datasets' => Http::response([
                'success' => true,
                'dataset_id' => 'python-a',
                'dataset' => $this->dataset('python-a', 'leaked dgt_'.str_repeat('f', 64)),
            ], 201),
        ]);

        $this->postJson('/api/datasets', ['dataset_id' => 'python-a'])
            ->assertStatus(503)
            ->assertExactJson([
                'error' => 'dataset_unavailable',
                'message' => 'The dataset service is not available.',
            ]);

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_show_maps_the_not_found_error_without_disclosing_scope(): void
    {
        Http::fake([
            'http://python.test/v1/datasets/missing' => Http::response([
                'message' => 'The requested dataset is not available.',
                'error' => 'dataset_not_found',
            ], 404),
        ]);

        $this->getJson('/api/datasets/missing')
            ->assertNotFound()
            ->assertExactJson([
                'error' => 'dataset_not_found',
                'message' => 'The requested dataset is not available.',
            ]);

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_query_datasets_maps_an_authority_outage(): void
    {
        $this->authenticate($this->user('query-outage'), ['query']);
        Http::fake([
            'http://python.test/v1/query/datasets' => Http::response([
                'message' => 'The dataset service is not available.',
                'error' => 'dataset_unavailable',
            ], 503),
        ]);

        $this->getJson('/api/query/datasets')
            ->assertStatus(503)
            ->assertExactJson([
                'error' => 'dataset_unavailable',
                'message' => 'The dataset service is not available.',
            ]);

        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_an_unreachable_python_authority_fails_closed_instead_of_returning_legacy_data(): void
    {
        User::query()->create([
            'username' => 'frozen-legacy-user',
            'email' => 'frozen-legacy-user@example.test',
            'ip' => '127.0.0.90',
        ]);
        Dataset::query()->create([
            'dataset_id' => 'legacy-a',
            'name' => 'Frozen Legacy A',
            'status' => 'active',
            'qdrant_collection' => 'hawki_legacy_a',
            'neo4j_namespace' => 'hawki_legacy_a',
            'created_at' => now(),
        ]);
        Http::fake([
            'http://python.test/v1/datasets*' => Http::response([
                'message' => 'The dataset service is not available.',
                'error' => 'dataset_unavailable',
            ], 503),
        ]);

        $this->getJson('/api/datasets')
            ->assertStatus(503)
            ->assertExactJson([
                'error' => 'dataset_unavailable',
                'message' => 'The dataset service is not available.',
            ]);
        $this->assertStringNotContainsString('legacy-a', $this->getJson('/api/datasets')->getContent());
    }

    public function test_a_malformed_python_success_body_is_not_trusted_or_retried_legacy(): void
    {
        Http::fake([
            'http://python.test/v1/datasets*' => Http::response(['success' => true], 200),
        ]);

        $this->getJson('/api/datasets')
            ->assertStatus(503)
            ->assertExactJson([
                'error' => 'dataset_unavailable',
                'message' => 'The dataset service is not available.',
            ]);

        Http::assertSentCount(1);
        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_an_invalid_selector_fails_closed_on_every_dataset_route(): void
    {
        config()->set('config.dataset_backend', 'pyhton');
        $user = $this->user('invalid-selector');
        $this->authenticate($user, ['query', 'rag:text-ingest', ApiTokenAbility::Admin->value]);
        $error = [
            'error' => 'dataset_backend_configuration_invalid',
            'message' => 'Dataset management is not available.',
        ];

        $this->getJson('/api/datasets')->assertStatus(503)->assertExactJson($error);
        $this->getJson('/api/datasets/python-a')->assertStatus(503)->assertExactJson($error);
        $this->postJson('/api/datasets', ['dataset_id' => 'python-a'])->assertStatus(503)->assertExactJson($error);
        $this->deleteJson('/api/datasets/python-a/storage')->assertStatus(503)->assertExactJson($error);
        $this->postJson('/api/datasets/python-a/query-grants/self')->assertStatus(503)->assertExactJson($error);
        $this->postJson('/api/datasets/python-a/ingest-grants/self', [
            'grant_token' => 'dgt_'.str_repeat('d', 64),
        ])->assertStatus(503)->assertExactJson($error);
        $this->getJson('/api/query/datasets')->assertStatus(503)->assertExactJson($error);

        Http::assertNothingSent();
        $this->assertNoLegacyAuthorityWrites();
    }

    public function test_an_incomplete_python_activation_fails_closed_on_every_selector(): void
    {
        $error = [
            'error' => 'dataset_backend_configuration_invalid',
            'message' => 'Dataset management is not available.',
        ];

        foreach ([
            'query_backend',
            'text_ingestion_backend',
            'managed_document_backend',
            'crawler_ingestion_backend',
        ] as $selector) {
            config()->set("config.{$selector}", 'laravel');
            $this->getJson('/api/datasets')->assertStatus(503)->assertExactJson($error);
            config()->set("config.{$selector}", 'python');
        }

        config()->set('config.query_auth.all_datasets_by_default', true);
        $this->getJson('/api/datasets')->assertStatus(503)->assertExactJson($error);

        Http::assertNothingSent();
        $this->assertNoLegacyAuthorityWrites();
    }

    private function dataset(string $datasetId = 'python-a', string $name = 'Python A'): array
    {
        $safe = str_replace('-', '_', $datasetId);

        return [
            'id' => null,
            'dataset_id' => $datasetId,
            'name' => $name,
            'description' => 'Managed by the Python authority.',
            'status' => 'active',
            'qdrant_collection' => 'hawki_'.$safe,
            'neo4j_namespace' => 'hawki_'.$safe,
            'embedding_provider' => 'ollama',
            'embedding_model' => 'bge-m3',
            'created_at' => '2026-11-10T12:00:00+00:00',
            'document_count' => 0,
            'task_count' => 0,
            'last_ingestion' => null,
            'graph_stats' => [
                'qdrant' => [
                    'ok' => true,
                    'collection' => 'hawki_'.$safe,
                    'points' => 0,
                    'status' => 'not_created',
                    'message' => 'Collection not created yet',
                ],
                'neo4j' => [
                    'ok' => false,
                    'error' => 'Neo4j statistics are not available in dataset authority mode.',
                ],
            ],
        ];
    }

    private function user(string $name): User
    {
        return User::query()->create([
            'username' => $name,
            'email' => $name.'@example.test',
            'ip' => '127.0.'.random_int(1, 254).'.'.random_int(1, 254),
        ]);
    }

    private function authenticateAdmin(string $name = 'dataset-admin'): User
    {
        $user = $this->user($name);
        $this->authenticate($user, [ApiTokenAbility::Admin->value]);

        return $user;
    }

    /**
     * @param  list<string>  $abilities
     */
    private function authenticate(User $user, array $abilities): void
    {
        $this->withToken(
            $user->createToken('python-dataset-bff', $abilities)->plainTextToken,
        );
    }

    private function claims(Request $request): object
    {
        $header = $request->header('Authorization');
        $value = is_array($header) ? (string) ($header[0] ?? '') : (string) $header;
        $this->assertStringStartsWith('Bearer ', $value);

        return JWT::decode(
            substr($value, 7),
            new Key($this->fixture->publicPem, 'RS256'),
        );
    }

    private function assertNoLegacyAuthorityWrites(): void
    {
        // The legacy migration seeds one frozen "default" row. Nothing in the
        // BFF request may add to it.
        $this->assertDatabaseCount('datasets', 1);
        $this->assertDatabaseHas('datasets', ['dataset_id' => 'default']);
        $this->assertDatabaseCount('dataset_grants', 0);
        $this->assertDatabaseCount('dataset_grant_tokens', 0);
    }
}
