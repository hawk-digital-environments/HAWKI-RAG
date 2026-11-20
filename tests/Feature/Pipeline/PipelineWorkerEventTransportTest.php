<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class PipelineWorkerEventTransportTest extends TestCase
{
    private const ENDPOINT = '/api/internal/pipeline/worker-events';

    private const SECRET = 'transport-test-worker-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('temporal.callbacks.secret', self::SECRET);
        config()->set('temporal.callbacks.max_age_seconds', 300);
    }

    public function test_route_rejects_an_unsigned_request_before_database_access(): void
    {
        $this->call('POST', self::ENDPOINT, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($this->payload(), JSON_THROW_ON_ERROR))
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'The pipeline worker callback signature is invalid or expired.',
                'error' => 'pipeline_worker_signature_invalid',
            ]);
    }

    public function test_route_fails_closed_when_the_secret_is_not_configured(): void
    {
        config()->set('temporal.callbacks.secret', '');

        $this->sendSigned($this->payload())
            ->assertServiceUnavailable()
            ->assertJsonPath('error', 'pipeline_worker_signature_unavailable');
    }

    public function test_signed_scope_and_unknown_fields_are_rejected_before_database_access(): void
    {
        $this->sendSigned($this->payload([
            'authorized_scope' => ['dataset_id' => 'other'],
            'raw_storage_path' => '/untrusted/path',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['authorized_scope', 'raw_storage_path']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendSigned(array $payload): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

        return $this->call('POST', self::ENDPOINT, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HAWKI_TIMESTAMP' => $timestamp,
            'HTTP_X_HAWKI_SIGNATURE' => $signature,
        ], $body);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => 1,
            'event_id' => 'evt_transport',
            'event_type' => 'pipeline.stage.status',
            'producer' => 'scraper',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'workflow_id' => 'workflow-1',
            'run_id' => 'run-1',
            'activity_id' => 'scrape_source',
            'attempt' => 1,
            'job_id' => 'job-1',
            'task_id' => null,
            'source_id' => 'source-1',
            'stage' => 'scrape',
            'phase' => 'scrape_source',
            'status' => 'running',
            'counts' => ['total' => 0, 'processed' => 0, 'failed' => 0, 'skipped' => 0],
            'metrics' => [],
            'artifacts' => [],
            'manifest' => null,
            'errors' => [],
            'warnings' => [],
            'error_details' => null,
            'document_version' => null,
        ], $overrides);
    }
}
