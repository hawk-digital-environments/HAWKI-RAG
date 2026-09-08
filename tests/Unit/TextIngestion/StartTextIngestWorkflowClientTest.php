<?php

declare(strict_types=1);

namespace Tests\Unit\TextIngestion;

use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class StartTextIngestWorkflowClientTest extends TestCase
{
    public function test_it_starts_the_text_ingestion_workflow(): void
    {
        config()->set([
            'temporal.enabled' => true,
            'config.hawki_rag_bridge_url' => 'http://bridge.test',
        ]);

        Http::fake([
            'http://bridge.test/temporal/workflows/ingest-text' => Http::response([
                'workflow_id' => 'ingest-text-workflow-123',
                'run_id' => 'run-123',
            ], 202),
        ]);

        $input = [
            'source_id' => 'source-123',
            'dataset_id' => 'default',
            'markdown_path' => '/shared/sources/source-123/markdown/document.md',
            'ingestion' => [
                'graph' => false,
            ],
        ];

        $execution = app(PythonTemporalBridgeClient::class)
            ->startTextIngestWorkflow($input, 'ingest-text-workflow-123');

        self::assertSame('ingest-text-workflow-123', $execution->workflowId);
        self::assertSame('run-123', $execution->runId);
        self::assertNull($execution->scheduleId);

        Http::assertSent(static function (Request $request) use ($input): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://bridge.test/temporal/workflows/ingest-text'
                && $request->data() === [
                    'workflow_id' => 'ingest-text-workflow-123',
                    'workflow_input' => $input,
                ];
        });
    }

    public function test_it_rejects_a_different_workflow_id_from_the_bridge(): void
    {
        config()->set([
            'temporal.enabled' => true,
            'config.hawki_rag_bridge_url' => 'http://bridge.test',
        ]);
        Http::fake([
            'http://bridge.test/temporal/workflows/ingest-text' => Http::response([
                'workflow_id' => 'unexpected-workflow',
                'run_id' => 'run-123',
            ]),
        ]);

        $this->expectExceptionMessage('unexpected workflow ID');

        app(PythonTemporalBridgeClient::class)->startTextIngestWorkflow(
            ['source_id' => 'source-123'],
            'expected-workflow',
        );
    }
}
