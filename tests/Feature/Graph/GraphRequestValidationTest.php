<?php

declare(strict_types=1);

namespace Tests\Feature\Graph;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QueryBffFixture;
use Tests\TestCase;

class GraphRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        $identity = 'epoch14-'.bin2hex(random_bytes(4));

        $user = User::query()->create(['username' => $identity, 'email' => $identity.'@example.test', 'ip' => '127.0.0.1']);
        $user->forceFill($attributes)->save();

        return $user;
    }

    public function test_graph_reads_require_dataset_and_validate_limits(): void
    {
        $this->actingAs($this->user());
        Http::fake();
        foreach (['overview', 'search?q=alpha', 'node?node_id=x', 'semantic-search?q=alpha'] as $path) {
            $this->getJson('/api/rag/neo4j/graph/'.$path)->assertUnprocessable()->assertJsonValidationErrors('dataset_id');
        }
        $this->getJson('/api/rag/neo4j/graph/overview?dataset_id=one&limit=301')->assertUnprocessable();
        $this->postJson('/api/rag/neo4j/graph/expand', ['node_id' => 'node'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_graph_requests_forward_dataset_and_clamp_expansion(): void
    {
        $fixture = new QueryBffFixture;
        try {
            config()->set(['config.graph_ops_backend' => 'python', 'config.dataset_backend' => 'python',
                'config.pipeline_execution_backend' => 'python', 'config.python_query_dataset_source' => 'database',
                'config.python_graph_ops_url' => 'http://python.test/v1/ops',
                'config.query_backend' => 'python', 'config.text_ingestion_backend' => 'python',
                'config.managed_document_backend' => 'python', 'config.crawler_ingestion_backend' => 'python',
                'config.query_auth.all_datasets_by_default' => false,
                'temporal.enabled' => true, 'temporal.callbacks.secret' => 'epoch14-test-secret',
                'temporal.control_url' => 'http://python.test', 'config.python_url_ingestion_url' => 'http://python.test',

                'config.query_bff' => $fixture->config->get('config.query_bff')]);
            $this->actingAs($this->user());
            Http::fake(['http://python.test/v1/ops/*' => Http::response(['ok' => true, 'nodes' => [], 'edges' => []])]);
            $this->getJson('/api/rag/neo4j/graph/overview?dataset_id=one')->assertOk();
            $this->postJson('/api/rag/neo4j/graph/expand', ['dataset_id' => 'one', 'node_id' => 'node', 'depth' => 99])->assertOk();
            Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request['dataset_id'] === 'one' && $request['depth'] === 3);
            Http::assertSentCount(2);
        } finally {
            $fixture->close();
        }
    }
}
