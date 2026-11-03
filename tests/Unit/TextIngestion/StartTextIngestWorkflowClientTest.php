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
            'temporal.control_url' => 'http://bridge.test',
        ]);

        Http::fake([
            'http://bridge.test/internal/workflow-control/text/start' => Http::response([
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
                && $request->url() === 'http://bridge.test/internal/workflow-control/text/start'
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
            'temporal.control_url' => 'http://bridge.test',
        ]);
        Http::fake([
            'http://bridge.test/internal/workflow-control/text/start' => Http::response([
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

    public function test_it_waits_for_workflow_cancellation_before_deletion(): void
    {
        config()->set([
            'temporal.enabled' => true,
            'temporal.control_url' => 'http://bridge.test',
        ]);
        Http::fake([
            'http://bridge.test/internal/workflow-control/execution/stop-for-deletion' => Http::response([
                'ok' => true,
            ]),
        ]);

        app(PythonTemporalBridgeClient::class)->cancelWorkflowAndWait(
            'ingest-text-workflow-123',
            'run-123',
        );

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://bridge.test/internal/workflow-control/execution/stop-for-deletion'
            && $request->data() === [
                'workflow_id' => 'ingest-text-workflow-123',
                'run_id' => 'run-123',
            ]);
    }

    public function test_control_is_signed_and_failure_never_falls_back_or_exposes_remote_body(): void
    {
        config()->set('temporal.control_url', 'http://backend.test');
        Http::fake(['http://backend.test/internal/workflow-control/execution/status' => Http::response(['detail' => 'private-address provider-secret'], 502)]);

        try {
            app(PythonTemporalBridgeClient::class)->workflowStatus('ingest-source-source-a', 'run-a');
            self::fail('Unconfirmed workflow control must fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('unconfirmed', $error->getMessage());
            self::assertStringNotContainsString('provider-secret', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        Http::assertSentCount(1);
        Http::assertSent(static function (Request $request): bool {
            $stamp = $request->header('X-Hawki-Timestamp')[0];
            $expected = 'v1='.hash_hmac('sha256', "hawki-workflow-control-v1\nPOST\n/internal/workflow-control/execution/status\n{$stamp}\n".$request->body(), config('temporal.callbacks.secret'));

            return $request->url() === 'http://backend.test/internal/workflow-control/execution/status'
                && $request->header('X-Hawki-Signature')[0] === $expected;
        });
    }
}
