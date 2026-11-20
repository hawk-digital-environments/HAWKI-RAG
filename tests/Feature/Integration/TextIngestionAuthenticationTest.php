<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TextIngestionAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private Dataset $dataset;

    private string $sharedRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sharedRoot = storage_path('framework/testing/text-ingestion-authentication');
        File::deleteDirectory($this->sharedRoot);
        config()->set([
            'temporal.storage.shared_root' => $this->sharedRoot,
            'temporal.enabled' => true,
        ]);
        $this->dataset = Dataset::query()->create([
            'dataset_id' => 'assistant_auth',
            'name' => 'Assistant auth',
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_assistant_auth',
            'neo4j_namespace' => 'hawki_assistant_auth',
            'embedding_provider' => 'ollama',
            'embedding_model' => 'bge-m3',
            'created_at' => now(),
        ]);
        Http::fake([
            '*temporal/workflows/ingest-text' => static fn ($request) => Http::response([
                'workflow_id' => $request->data()['workflow_id'],
                'run_id' => 'run-auth',
            ], 202),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sharedRoot);

        parent::tearDown();
    }

    public function test_no_token_is_rejected(): void
    {
        $this->postJson('/api/integrations/text-ingestions', $this->payload(), $this->headers())
            ->assertUnauthorized();
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->withToken('invalid-token')
            ->postJson('/api/integrations/text-ingestions', $this->payload(), $this->headers())
            ->assertUnauthorized();
    }

    public function test_session_only_authentication_is_rejected(): void
    {
        $user = $this->user('session-only');
        $this->grant($user, $this->dataset);

        $this->actingAs($user)
            ->postJson('/api/integrations/text-ingestions', $this->payload(), $this->headers())
            ->assertUnauthorized();
    }

    public function test_token_without_the_required_ability_is_rejected(): void
    {
        $user = $this->user('wrong-ability');
        $this->grant($user, $this->dataset);

        $this->withToken($user->createToken('wrong', ['query'])->plainTextToken)
            ->postJson('/api/integrations/text-ingestions', $this->payload(), $this->headers())
            ->assertForbidden();
    }

    public function test_wildcard_ability_does_not_replace_the_exact_ability(): void
    {
        $user = $this->user('wildcard-ability');
        $this->grant($user, $this->dataset);

        $this->withToken($user->createToken('wildcard', ['*'])->plainTextToken)
            ->postJson('/api/integrations/text-ingestions', $this->payload(), $this->headers())
            ->assertForbidden();
    }

    public function test_token_cannot_ingest_into_an_ungranted_existing_dataset(): void
    {
        $user = $this->user('ungranted');

        $this->withToken($user->createToken('ingest', ['rag:text-ingest'])->plainTextToken)
            ->postJson('/api/integrations/text-ingestions', $this->payload(), $this->headers())
            ->assertNotFound()
            ->assertJsonPath('error', 'dataset_not_found');

        $this->assertFalse(File::exists($this->sharedRoot.DIRECTORY_SEPARATOR.'sources'));
        Http::assertNothingSent();
    }

    public function test_exact_ability_and_dataset_grant_authorize_ingestion(): void
    {
        $user = $this->user('authorized');
        $this->grant($user, $this->dataset);

        $this->withToken($user->createToken('ingest', ['rag:text-ingest'])->plainTextToken)
            ->postJson('/api/integrations/text-ingestions', $this->payload(), $this->headers())
            ->assertAccepted();
    }

    public function test_oversized_request_body_is_rejected(): void
    {
        $user = $this->user('oversized-request');
        $this->grant($user, $this->dataset);
        config()->set('config.text_ingestion.max_request_bytes', 256);
        $body = json_encode([
            ...$this->payload(),
            'unused' => str_repeat('x', 512),
        ], JSON_THROW_ON_ERROR);

        $plainTextToken = $user->createToken('ingest', ['rag:text-ingest'])->plainTextToken;

        $this->call('POST', '/api/integrations/text-ingestions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainTextToken,
            'HTTP_IDEMPOTENCY_KEY' => 'oversized-request-v1',
        ], $body)
            ->assertStatus(413)
            ->assertJsonPath('error', 'text_ingestion_request_too_large');

        Http::assertNothingSent();
    }

    private function user(string $username): User
    {
        return User::query()->create([
            'username' => $username,
            'email' => $username.'@example.test',
            'ip' => '127.0.0.'.(User::query()->count() + 20),
        ]);
    }

    private function grant(User $user, Dataset $dataset): void
    {
        DatasetGrant::query()->create([
            'dataset_id' => $dataset->dataset_id,
            'principal_type' => DatasetGrant::PRINCIPAL_USER,
            'principal_id' => (string) $user->getAuthIdentifier(),
            'permission' => DatasetGrant::PERMISSION_INGEST,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Idempotency-Key' => 'authentication-test-v1'];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'external_document_id' => 'authentication-test',
            'dataset_id' => $this->dataset->dataset_id,
            'text' => 'Authenticated direct text.',
            'content_format' => 'plain_text',
        ];
    }
}
