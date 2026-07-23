<?php

declare(strict_types=1);

namespace Tests\Feature\Rag;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RagStatsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_a_collection_with_a_valid_name(): void
    {
        $this->actingAsApiUser();
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        Http::fake([
            'http://qdrant.test/collections/*' => Http::response([], 200),
        ]);

        $this->deleteJson('/api/rag/qdrant/collections/hawki_test%3A1')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'collection' => 'hawki_test:1',
            ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'http://qdrant.test/collections/hawki_test%3A1');
    }

    public function test_invalid_collection_name_is_rejected_before_qdrant_is_called(): void
    {
        $this->actingAsApiUser();
        Http::fake();

        $this->deleteJson('/api/rag/qdrant/collections/hawki@test')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('collection');

        Http::assertNothingSent();
    }

    public function test_double_encoded_collection_name_is_not_decoded_twice(): void
    {
        $this->actingAsApiUser();
        Http::fake();

        $this->deleteJson('/api/rag/qdrant/collections/hawki%253A1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('collection');

        Http::assertNothingSent();
    }
}
