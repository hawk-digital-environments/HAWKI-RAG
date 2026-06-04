<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\PipelineJob;
use App\Services\FileConverter\DocumentConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class PipelineSmokeTestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_smoke_test_runs_end_to_end_without_graph(): void
    {
        $this->configureSmokeEnvironment(graph: false);
        $this->mockConverter();
        Http::fake([
            '*/ingest' => Http::response([
                'ok' => true,
                'points' => 1,
                'summary' => [
                    'planned_points' => 1,
                    'graph' => ['enabled' => false],
                ],
            ], 200),
            'http://qdrant.test/*' => Http::response([
                'result' => [
                    'points' => [[
                        'id' => 'point-1',
                        'payload' => ['job_id' => 'ingest-smoke'],
                    ]],
                ],
            ], 200),
        ]);

        $this->artisan('pipeline:smoke-test --dataset=smoke-command --graph=false')
            ->expectsOutputToContain('PASS Task:')
            ->expectsOutputToContain('PASS Convert:')
            ->expectsOutputToContain('PASS Ingest:')
            ->expectsOutputToContain('PASS Qdrant write:')
            ->expectsOutputToContain('SKIP Neo4j write:')
            ->expectsOutputToContain('Smoke test PASS.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('datasets', [
            'dataset_id' => 'smoke-command',
            'qdrant_collection' => 'hawki_smoke_command',
        ]);
        $this->assertDatabaseHas('pipeline_tasks', [
            'dataset_id' => 'smoke-command',
            'profile_id' => 'pipeline-smoke',
        ]);
        $this->assertDatabaseHas('pipeline_jobs', [
            'job_type' => PipelineJob::TYPE_CONVERT,
            'status' => PipelineJob::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('pipeline_jobs', [
            'job_type' => PipelineJob::TYPE_INGEST,
            'status' => PipelineJob::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('documents', [
            'dataset_id' => 'smoke-command',
            'status' => Document::STATUS_COMPLETED,
        ]);
    }

    public function test_smoke_test_fails_with_clear_qdrant_stage_message(): void
    {
        $this->configureSmokeEnvironment(graph: false);
        $this->mockConverter();
        Http::fake([
            '*/ingest' => Http::response([
                'ok' => true,
                'points' => 1,
                'summary' => [
                    'planned_points' => 1,
                    'graph' => ['enabled' => false],
                ],
            ], 200),
            'http://qdrant.test/*' => Http::response([
                'result' => ['points' => []],
            ], 200),
        ]);

        $this->artisan('pipeline:smoke-test --dataset=smoke-qdrant-fail --graph=false')
            ->expectsOutputToContain('FAIL Qdrant write:')
            ->expectsOutputToContain('No Qdrant point found')
            ->assertExitCode(1);
    }

    public function test_smoke_test_verifies_neo4j_when_graph_mode_is_enabled(): void
    {
        $this->configureSmokeEnvironment(graph: true);
        $this->mockConverter();
        Http::fake([
            '*/ingest' => Http::response([
                'ok' => true,
                'points' => 1,
                'summary' => [
                    'planned_points' => 1,
                    'graph' => ['enabled' => true],
                    'graph_preview' => [
                        'planned_entities' => 2,
                        'total_triplets' => 1,
                    ],
                ],
            ], 200),
            'http://qdrant.test/*' => Http::response([
                'result' => [
                    'points' => [[
                        'id' => 'point-1',
                        'payload' => ['job_id' => 'ingest-smoke'],
                    ]],
                ],
            ], 200),
            'http://neo4j.test/*' => Http::response([
                'results' => [[
                    'columns' => ['nodes', 'relationships'],
                    'data' => [[
                        'row' => [2, 1],
                    ]],
                ]],
                'errors' => [],
            ], 200),
        ]);

        $this->artisan('pipeline:smoke-test --dataset=smoke-graph --graph=true')
            ->expectsOutputToContain('PASS Neo4j write:')
            ->expectsOutputToContain('Smoke test PASS.')
            ->assertExitCode(0);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/ingest')
            && ($request['graph'] ?? null) === true);
    }

    private function configureSmokeEnvironment(bool $graph): void
    {
        config()->set('communication.rabbitmq.pipeline_events.enabled', false);
        config()->set('communication.rabbitmq.pipeline_ingestion.graph', $graph);
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        config()->set('config.neo4j_http_url', 'http://neo4j.test');
        config()->set('config.neo4j_user', 'neo4j');
        config()->set('config.neo4j_password', 'secret');
    }

    private function mockConverter(): void
    {
        $this->mock(DocumentConverter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestDocumentToMarkdown')
                ->once()
                ->andReturn([
                    'smoke.md' => "# HAWKI smoke\n\nLaravel scraped, converted, and ingested this document.",
                ]);
        });
    }
}
