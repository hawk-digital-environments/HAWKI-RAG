<?php

declare(strict_types=1);

namespace Tests\Feature\Rag;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RagStatsControllerTest extends TestCase
{
    public function test_deprecated_collection_delete_is_gone_without_a_qdrant_call(): void
    {
        Http::fake();
        foreach (['owned', 'hawki@test', 'hawki%253A1'] as $name) {
            $this->deleteJson('/api/rag/qdrant/collections/'.$name)->assertStatus(410)
                ->assertJsonPath('error', 'collection_delete_removed');
        }
        Http::assertNothingSent();
    }
}
