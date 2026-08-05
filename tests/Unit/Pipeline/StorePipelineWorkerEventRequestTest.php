<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Http\Requests\Pipeline\StorePipelineWorkerEventRequest;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\Validator;
use Tests\TestCase;

final class StorePipelineWorkerEventRequestTest extends TestCase
{
    public function test_empty_metrics_errors_and_warnings_are_valid_contract_values(): void
    {
        $validator = $this->validatorFor($this->payload());

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_typed_artifacts_errors_and_an_absent_task_id_are_valid(): void
    {
        $payload = $this->payload([
            'artifacts' => [[
                'uri' => 's3://artifacts/source-1/raw',
                'sha256' => str_repeat('a', 64),
                'size_bytes' => 42,
                'media_type' => 'inode/directory',
            ]],
            'manifest' => ['uri' => 's3://artifacts/source-1/manifest.json'],
            'errors' => [[
                'code' => 'temporary_failure',
                'message' => 'The worker will retry.',
                'retryable' => true,
            ]],
            'error_details' => 'Sanitized diagnostic context.',
        ]);
        unset($payload['task_id']);

        $validator = $this->validatorFor($payload);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_scope_storage_and_unknown_fields_are_rejected(): void
    {
        $validator = $this->validatorFor($this->payload([
            'authorized_scope' => ['dataset_id' => 'other'],
            'qdrant_collection' => 'other_collection',
            'raw_storage_path' => '/untrusted/path',
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('authorized_scope', $validator->errors()->toArray());
        $this->assertArrayHasKey('qdrant_collection', $validator->errors()->toArray());
        $this->assertArrayHasKey('raw_storage_path', $validator->errors()->toArray());
    }

    public function test_producer_must_own_the_reported_stage_and_activity(): void
    {
        $validator = $this->validatorFor($this->payload([
            'stage' => 'ingest',
            'activity_id' => 'ingest_markdown_files',
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('stage', $validator->errors()->toArray());
        $this->assertArrayHasKey('activity_id', $validator->errors()->toArray());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatorFor(array $payload): Validator
    {
        $request = StorePipelineWorkerEventRequest::create('/', 'POST', $payload);
        $validator = $this->app->make(Factory::class)->make($request->all(), $request->rules());

        foreach ($request->after() as $after) {
            $validator->after($after);
        }

        return $validator;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => 1,
            'event_id' => 'evt_validation',
            'event_type' => 'pipeline.stage.status',
            'producer' => 'scraper',
            'timestamp' => '2026-08-03T12:00:00.123456Z',
            'workflow_id' => 'workflow-1',
            'run_id' => 'run-1',
            'activity_id' => 'scrape_source',
            'attempt' => 1,
            'job_id' => 'job-1',
            'task_id' => 'task-1',
            'source_id' => 'source-1',
            'stage' => 'scrape',
            'phase' => 'scrape_source',
            'status' => 'running',
            'counts' => ['total' => 1, 'processed' => 0, 'failed' => 0, 'skipped' => 0],
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
