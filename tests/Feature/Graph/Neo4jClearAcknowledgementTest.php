<?php

declare(strict_types=1);

namespace Tests\Feature\Graph;

use App\Models\User;
use App\Services\User\Values\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\QueryBffFixture;
use Tests\TestCase;

class Neo4jClearAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    public static function validEnvelope(int $nodes = 3, int $relationships = 2): array
    {
        return ['results' => [
            ['columns' => ['count(r)'], 'data' => [['row' => [$relationships]]]],
            ['columns' => ['count(n)'], 'data' => [['row' => [$nodes]]]],
        ], 'errors' => []];
    }

    public static function acknowledgements(): array
    {
        $valid = self::validEnvelope();
        $cases = [
            'valid' => [$valid, true],
            'valid zero' => [self::validEnvelope(0, 0), true],
            'invalid json' => ['not-json', false],
            'empty object' => ['{}', false],
            'errors object' => [str_replace('"errors":[]', '"errors":{}', json_encode($valid)), false],
            'row object' => [str_replace('"row":[2]', '"row":{"0":2}', json_encode($valid)), false],
            'missing results' => [['errors' => []], false],
            'missing errors' => [['results' => $valid['results']], false],
            'non-list results' => [['results' => ['other' => $valid['results'][0]], 'errors' => []], false],
            'missing statement' => [['results' => [$valid['results'][0]], 'errors' => []], false],
            'errors' => [['results' => $valid['results'], 'errors' => [['code' => 'error', 'message' => 'failed']]], false],
            'malformed errors' => [['results' => $valid['results'], 'errors' => 'error'], false],
            'timeout' => [null, false],
        ];
        foreach ([0, 1] as $index) {
            foreach (['missing', -1, '0', 1.5, true, null] as $count) {
                $body = $valid;
                $body['results'][$index]['data'][0]['row'] = $count === 'missing' ? [] : [$count];
                $cases['count '.$index.' '.var_export($count, true)] = [$body, false];
            }
        }
        foreach (['columns', 'data'] as $field) {
            $body = $valid;
            unset($body['results'][1][$field]);
            $cases['missing '.$field] = [$body, false];
        }
        $body = $valid;
        $body['results'][0] = 'malformed';
        $cases['malformed result'] = [$body, false];

        return $cases;
    }

    #[DataProvider('acknowledgements')]
    public function test_only_valid_sink_evidence_can_confirm_clear(array|string|null $body, bool $success): void
    {
        $fixture = new QueryBffFixture;
        config()->set(['config.graph_ops_backend' => 'laravel', 'config.dataset_backend' => 'python',
            'config.query_backend' => 'python', 'config.text_ingestion_backend' => 'python',
            'config.managed_document_backend' => 'python', 'config.crawler_ingestion_backend' => 'python',
            'config.query_auth.all_datasets_by_default' => false,
            'config.python_graph_ops_url' => 'http://python.test/v1/ops',
            'config.neo4j_http_url' => 'http://neo4j.test', 'config.neo4j_database' => 'neo4j',
            'config.query_bff' => $fixture->config->get('config.query_bff')]);
        $user = User::query()->create(['username' => 'manager', 'email' => 'manager@example.test', 'ip' => '127.0.0.1']);
        $user->forceFill(['role' => UserRole::Admin])->save();
        Http::preventStrayRequests();
        Http::fake([
            'http://python.test/v1/ops/graph/clear-admission' => Http::response([
                'ok' => true, 'dataset_id' => 'one', 'neo4j_namespace' => 'trusted-scope', 'operation_id' => str_repeat('a', 32)]),
            'http://python.test/v1/ops/graph/clear-receipt' => Http::response(['ok' => true]),
            'http://neo4j.test/*' => $body === null
                ? fn () => throw new ConnectionException('timeout after possible execution')
                : Http::response($body),
        ]);
        try {
            $response = $this->actingAs($user)->postJson('/api/rag/neo4j/clear', ['dataset_id' => 'one', 'confirm' => 'CLEAR_DATASET_GRAPH']);
            $response->assertStatus($success ? 200 : 503);
            Http::assertSentCount($body === null ? 2 : 3);
            Http::assertSent(function ($request) use ($success): bool {
                if (! str_ends_with($request->url(), '/clear-receipt')) {
                    return false;
                }
                $this->assertSame($success, $request['success']);
                if (! $success) {
                    $this->assertArrayNotHasKey('deleted_nodes', $request->data());
                    $this->assertArrayNotHasKey('deleted_relationships', $request->data());
                }

                return true;
            });
            if ($success) {
                $response->assertJsonPath('deleted_nodes', $body['results'][1]['data'][0]['row'][0])
                    ->assertJsonPath('deleted_relationships', $body['results'][0]['data'][0]['row'][0]);
            } else {
                $response->assertJsonPath('error', 'graph_clear_reconciliation_required');
            }
        } finally {
            $fixture->close();
        }
    }

    public function test_valid_receipt_failure_does_not_report_success(): void
    {
        // Sink success still requires Python to confirm its durable receipt.
        $fixture = new QueryBffFixture;
        config()->set(['config.graph_ops_backend' => 'laravel', 'config.dataset_backend' => 'python',
            'config.query_backend' => 'python', 'config.text_ingestion_backend' => 'python',
            'config.managed_document_backend' => 'python', 'config.crawler_ingestion_backend' => 'python',
            'config.query_auth.all_datasets_by_default' => false,
            'config.python_graph_ops_url' => 'http://python.test/v1/ops',
            'config.neo4j_http_url' => 'http://neo4j.test',
            'config.query_bff' => $fixture->config->get('config.query_bff')]);
        $user = User::query()->create(['username' => 'manager', 'email' => 'manager@example.test', 'ip' => '127.0.0.1']);
        $user->forceFill(['role' => UserRole::Admin])->save();
        Http::fake([
            'http://python.test/v1/ops/graph/clear-admission' => Http::response([
                'ok' => true, 'dataset_id' => 'one', 'neo4j_namespace' => 'trusted', 'operation_id' => str_repeat('b', 32)]),
            'http://python.test/v1/ops/graph/clear-receipt' => Http::response([], 503),
            'http://neo4j.test/*' => Http::response(self::validEnvelope()),
        ]);
        try {
            $this->actingAs($user)->postJson('/api/rag/neo4j/clear', ['dataset_id' => 'one', 'confirm' => 'CLEAR_DATASET_GRAPH'])->assertStatus(503);
        } finally {
            $fixture->close();
        }
    }
}
