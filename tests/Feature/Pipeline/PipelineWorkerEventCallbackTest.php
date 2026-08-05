<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Models\PipelineTask;
use App\Services\Pipeline\PipelineWorkerEventSignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class PipelineWorkerEventCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/internal/pipeline/worker-events';

    private const SECRET = 'feature-test-worker-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('temporal.callbacks.secret', self::SECRET);
        config()->set('temporal.callbacks.max_age_seconds', 300);
    }

    public function test_a_valid_signed_event_updates_laravel_owned_pipeline_state(): void
    {
        $this->createExecution();
        $payload = $this->event([
            'event_id' => 'evt_valid_running',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'artifacts' => [[
                'uri' => 's3://artifacts/source-worker-1/raw',
                'media_type' => 'inode/directory',
            ]],
            'manifest' => ['uri' => 's3://artifacts/source-worker-1/manifest.json'],
        ]);
        unset($payload['task_id']);

        $this->sendEvent($payload)
            ->assertAccepted()
            ->assertExactJson([
                'event_id' => 'evt_valid_running',
                'accepted' => true,
                'duplicate' => false,
                'ignored' => false,
            ]);

        $this->assertDatabaseHas('pipeline_worker_events', [
            'event_id' => 'evt_valid_running',
            'job_id' => 'job-worker-1',
            'producer' => 'scraper',
            'stage' => 'scrape',
            'status' => 'running',
        ]);
        $this->assertDatabaseHas('pipeline_stage_states', [
            'job_id' => 'job-worker-1',
            'stage' => 'scrape',
            'status' => PipelineJob::STATUS_RUNNING,
        ]);

        $job = PipelineJob::query()->where('job_id', 'job-worker-1')->firstOrFail();
        $source = IngestionSource::query()->where('source_id', 'source-worker-1')->firstOrFail();
        $task = PipelineTask::query()->where('task_id', 'task-worker-1')->firstOrFail();
        $this->assertSame(PipelineJob::STATUS_RUNNING, $job->status);
        $this->assertSame('scrape', $job->current_stage);
        $this->assertSame(IngestionSource::STATUS_RUNNING, $source->index_status);
        $this->assertSame('evt_valid_running', $source->metadata['worker_event']['event_id']);
        $this->assertSame(PipelineTask::STATUS_RUNNING, $task->status);
    }

    public function test_same_id_and_body_is_a_duplicate_but_a_different_body_conflicts(): void
    {
        $this->createExecution();
        $payload = $this->event(['event_id' => 'evt_idempotent']);

        $this->sendEvent($payload)->assertAccepted();
        $this->sendEvent($payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('ignored', false);

        $changed = $payload;
        $changed['counts']['processed'] = 9;
        $this->sendEvent($changed)
            ->assertConflict()
            ->assertExactJson([
                'message' => 'The worker event ID has already been used with a different payload.',
                'error' => 'pipeline_worker_event_id_collision',
            ]);

        $this->assertDatabaseCount('pipeline_worker_events', 1);
        $stage = PipelineStageState::query()
            ->where('job_id', 'job-worker-1')
            ->where('stage', 'scrape')
            ->firstOrFail();
        $this->assertSame(0, $stage->counts['processed']);
    }

    public function test_signature_verification_fails_closed_and_rejects_stale_requests(): void
    {
        $this->createExecution();
        $payload = $this->event(['event_id' => 'evt_signature']);

        $this->sendUnsignedEvent($payload)
            ->assertUnauthorized()
            ->assertJsonPath('error', 'pipeline_worker_signature_invalid');

        $this->sendEvent($payload, time() - 301)
            ->assertUnauthorized()
            ->assertJsonPath('error', 'pipeline_worker_signature_invalid');

        config()->set('temporal.callbacks.secret', '');
        $this->app->forgetInstance(PipelineWorkerEventSignatureVerifier::class);
        $this->sendEvent($payload)
            ->assertServiceUnavailable()
            ->assertJsonPath('error', 'pipeline_worker_signature_unavailable');

        $this->assertDatabaseCount('pipeline_worker_events', 0);
    }

    public function test_worker_payload_cannot_supply_auth_or_storage_scope(): void
    {
        $this->createExecution();
        $payload = $this->event([
            'event_id' => 'evt_scope_rejected',
            'auth_context' => ['user_id' => 'attacker'],
            'authorized_scope' => ['dataset_id' => 'other'],
            'qdrant_collection' => 'other_collection',
            'neo4j_namespace' => 'other_graph',
        ]);

        $this->sendEvent($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'auth_context',
                'authorized_scope',
                'qdrant_collection',
                'neo4j_namespace',
            ]);

        $this->assertDatabaseCount('pipeline_worker_events', 0);
    }

    public function test_producer_stage_activity_and_target_identifiers_are_verified(): void
    {
        $this->createExecution();
        $invalidOwnership = $this->event([
            'event_id' => 'evt_wrong_owner',
            'stage' => 'ingest',
        ]);
        $this->sendEvent($invalidOwnership)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stage');

        $invalidTarget = $this->event([
            'event_id' => 'evt_wrong_target',
            'workflow_id' => 'workflow-other',
        ]);
        $this->sendEvent($invalidTarget)
            ->assertConflict()
            ->assertJsonPath('error', 'pipeline_worker_event_target_mismatch');

        $invalidRun = $this->event([
            'event_id' => 'evt_wrong_run',
            'run_id' => 'run-other',
        ]);
        $this->sendEvent($invalidRun)
            ->assertConflict()
            ->assertJsonPath('error', 'pipeline_worker_event_target_mismatch');

        $this->assertDatabaseCount('pipeline_worker_events', 0);
        $this->assertDatabaseCount('pipeline_stage_states', 0);
    }

    public function test_a_scheduled_refresh_adopts_its_new_run_and_resets_stage_projection(): void
    {
        $execution = $this->createExecution();
        $execution['job']->forceFill([
            'status' => PipelineJob::STATUS_COMPLETED,
            'current_stage' => 'ingest',
            'temporal_schedule_id' => 'schedule-worker-1',
            'index_status' => IngestionSource::STATUS_READY,
            'completed_at' => now(),
            'finished_at' => now(),
        ])->save();
        $execution['source']->forceFill([
            'temporal_schedule_id' => 'schedule-worker-1',
            'index_status' => IngestionSource::STATUS_READY,
            'metadata' => [
                'temporal' => [
                    'workflow_id' => 'workflow-worker-1',
                    'run_id' => 'run-worker-1',
                    'schedule_id' => 'schedule-worker-1',
                ],
            ],
        ])->save();
        foreach (['scrape', 'convert', 'ingest'] as $stage) {
            PipelineStageState::query()->create([
                'pipeline_job_id' => $execution['job']->id,
                'job_id' => $execution['job']->job_id,
                'stage' => $stage,
                'status' => PipelineJob::STATUS_COMPLETED,
                'counts' => ['total' => 2, 'processed' => 2, 'failed' => 0, 'skipped' => 0],
                'metadata' => ['worker_event' => ['run_id' => 'run-worker-1']],
                'started_at' => now(),
                'completed_at' => now(),
            ]);
        }

        $this->sendEvent($this->event([
            'event_id' => 'evt_scheduled_run_2_started',
            'run_id' => 'run-worker-2',
            'timestamp' => gmdate(DATE_ATOM),
        ]))
            ->assertAccepted()
            ->assertJsonPath('ignored', false);

        $job = PipelineJob::query()->where('job_id', 'job-worker-1')->firstOrFail();
        $source = IngestionSource::query()->where('source_id', 'source-worker-1')->firstOrFail();
        $this->assertSame('run-worker-2', $job->temporal_run_id);
        $this->assertSame(PipelineJob::STATUS_RUNNING, $job->status);
        $this->assertSame('scrape', $job->current_stage);
        $this->assertSame('run-worker-2', $job->metadata['temporal']['run_id']);
        $this->assertSame(IngestionSource::STATUS_RUNNING, $source->index_status);
        $this->assertSame('run-worker-2', $source->metadata['temporal']['run_id']);
        $this->assertDatabaseHas('pipeline_stage_states', [
            'job_id' => 'job-worker-1',
            'stage' => 'scrape',
            'status' => PipelineJob::STATUS_RUNNING,
        ]);
        foreach (['convert', 'ingest'] as $stage) {
            $this->assertDatabaseHas('pipeline_stage_states', [
                'job_id' => 'job-worker-1',
                'stage' => $stage,
                'status' => 'pending',
            ]);
        }
    }

    public function test_terminal_state_does_not_regress_from_a_late_running_event(): void
    {
        $this->createExecution();
        $base = time() - 10;

        $this->sendEvent($this->event([
            'event_id' => 'evt_scrape_running',
            'timestamp' => gmdate(DATE_ATOM, $base),
        ]))->assertAccepted();
        $this->sendEvent($this->event([
            'event_id' => 'evt_scrape_completed',
            'timestamp' => gmdate(DATE_ATOM, $base + 1),
            'status' => 'completed',
            'counts' => ['total' => 2, 'processed' => 2, 'failed' => 0, 'skipped' => 0],
        ]))->assertAccepted();

        $this->sendEvent($this->event([
            'event_id' => 'evt_scrape_late_running',
            'timestamp' => gmdate(DATE_ATOM, $base + 2),
        ]))
            ->assertAccepted()
            ->assertJsonPath('ignored', true);

        $this->assertDatabaseHas('pipeline_stage_states', [
            'job_id' => 'job-worker-1',
            'stage' => 'scrape',
            'status' => PipelineJob::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('pipeline_stage_states', [
            'job_id' => 'job-worker-1',
            'stage' => 'convert',
            'status' => 'pending',
        ]);
        $job = PipelineJob::query()->where('job_id', 'job-worker-1')->firstOrFail();
        $this->assertSame(PipelineJob::STATUS_RUNNING, $job->status);
        $this->assertSame('convert', $job->current_stage);
        $this->assertDatabaseCount('pipeline_worker_events', 3);
    }

    public function test_a_higher_temporal_attempt_recovers_a_failed_stage(): void
    {
        $this->createExecution();
        $base = time() - 20;

        $this->sendEvent($this->event([
            'event_id' => 'evt_scrape_attempt_1_failed',
            'attempt' => 1,
            'timestamp' => gmdate(DATE_ATOM, $base),
            'status' => 'failed',
            'counts' => ['total' => 2, 'processed' => 0, 'failed' => 1, 'skipped' => 0],
            'errors' => [[
                'code' => 'temporary_scrape_failure',
                'message' => 'The crawler was temporarily unavailable.',
                'retryable' => true,
            ]],
        ]))->assertAccepted();

        $this->sendEvent($this->event([
            'event_id' => 'evt_scrape_attempt_2_running',
            'attempt' => 2,
            'timestamp' => gmdate(DATE_ATOM, $base + 1),
        ]))
            ->assertAccepted()
            ->assertJsonPath('ignored', false);

        $this->sendEvent($this->event([
            'event_id' => 'evt_scrape_attempt_1_stale',
            'attempt' => 1,
            'timestamp' => gmdate(DATE_ATOM, $base + 2),
        ]))
            ->assertAccepted()
            ->assertJsonPath('ignored', true);

        $this->sendEvent($this->event([
            'event_id' => 'evt_scrape_attempt_2_completed',
            'attempt' => 2,
            'timestamp' => gmdate(DATE_ATOM, $base + 3),
            'status' => 'completed',
            'counts' => ['total' => 2, 'processed' => 2, 'failed' => 0, 'skipped' => 0],
        ]))
            ->assertAccepted()
            ->assertJsonPath('ignored', false);

        $stage = PipelineStageState::query()
            ->where('job_id', 'job-worker-1')
            ->where('stage', 'scrape')
            ->firstOrFail();
        $source = IngestionSource::query()->where('source_id', 'source-worker-1')->firstOrFail();
        $job = PipelineJob::query()->where('job_id', 'job-worker-1')->firstOrFail();

        $this->assertSame(PipelineJob::STATUS_COMPLETED, $stage->status);
        $this->assertSame(2, $stage->metadata['worker_event']['attempt']);
        $this->assertSame(IngestionSource::STATUS_RUNNING, $source->index_status);
        $this->assertArrayNotHasKey('error', $source->metadata);
        $this->assertSame('convert', $job->current_stage);
        $this->assertDatabaseCount('pipeline_worker_events', 4);
    }

    public function test_late_completed_stage_does_not_regress_a_later_running_stage(): void
    {
        $execution = $this->createExecution();
        $execution['job']->forceFill(['current_stage' => 'convert'])->save();
        $execution['source']->forceFill([
            'metadata' => [
                'worker_event' => [
                    'event_id' => 'evt_convert_running',
                    'producer' => 'converter',
                    'stage' => 'convert',
                    'status' => 'running',
                    'occurred_at' => gmdate(DATE_ATOM, time() - 1),
                    'workflow_id' => 'workflow-worker-1',
                    'run_id' => 'run-worker-1',
                ],
            ],
        ])->save();
        PipelineStageState::query()->create([
            'pipeline_job_id' => $execution['job']->id,
            'job_id' => $execution['job']->job_id,
            'stage' => 'convert',
            'status' => PipelineJob::STATUS_RUNNING,
            'counts' => ['total' => 2, 'processed' => 0, 'failed' => 0, 'skipped' => 0],
            'metadata' => [
                'worker_event' => [
                    'event_id' => 'evt_convert_running',
                    'run_id' => 'run-worker-1',
                ],
            ],
            'started_at' => now(),
        ]);

        $this->sendEvent($this->event([
            'event_id' => 'evt_scrape_completed_late',
            'timestamp' => gmdate(DATE_ATOM, time() - 2),
            'status' => 'completed',
            'counts' => ['total' => 2, 'processed' => 2, 'failed' => 0, 'skipped' => 0],
        ]))
            ->assertAccepted()
            ->assertJsonPath('ignored', false);

        $this->assertDatabaseHas('pipeline_stage_states', [
            'job_id' => 'job-worker-1',
            'stage' => 'scrape',
            'status' => PipelineJob::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('pipeline_stage_states', [
            'job_id' => 'job-worker-1',
            'stage' => 'convert',
            'status' => PipelineJob::STATUS_RUNNING,
        ]);
        $job = PipelineJob::query()->where('job_id', 'job-worker-1')->firstOrFail();
        $source = IngestionSource::query()->where('source_id', 'source-worker-1')->firstOrFail();
        $this->assertSame('convert', $job->current_stage);
        $this->assertSame('evt_convert_running', $source->metadata['worker_event']['event_id']);
    }

    public function test_completed_worker_sequence_marks_source_job_and_task_ready(): void
    {
        $this->createExecution();
        $base = time() - 10;

        $this->sendEvent($this->event([
            'event_id' => 'evt_scrape_done',
            'timestamp' => gmdate(DATE_ATOM, $base),
            'status' => 'completed',
            'counts' => ['total' => 2, 'processed' => 2, 'failed' => 0, 'skipped' => 0],
        ]))->assertAccepted();
        $this->sendEvent($this->event([
            'event_id' => 'evt_convert_done',
            'producer' => 'converter',
            'activity_id' => 'inspect_and_convert_files',
            'stage' => 'convert',
            'phase' => 'inspect_and_convert_files',
            'timestamp' => gmdate(DATE_ATOM, $base + 1),
            'status' => 'completed',
            'counts' => ['total' => 2, 'processed' => 2, 'failed' => 0, 'skipped' => 0],
            'metrics' => ['markdown_files_created' => 2],
        ]))->assertAccepted();
        $this->sendEvent($this->event([
            'event_id' => 'evt_index_running_attempt_2',
            'producer' => 'indexer',
            'activity_id' => 'ingest_markdown_files',
            'stage' => 'ingest',
            'phase' => 'ingest_markdown_files',
            'timestamp' => gmdate(DATE_ATOM, $base + 2),
            'attempt' => 2,
            'status' => 'running',
            'counts' => ['total' => 2, 'processed' => 0, 'failed' => 0, 'skipped' => 0],
        ]))->assertAccepted();
        $this->sendEvent($this->event([
            'event_id' => 'evt_index_done',
            'producer' => 'indexer',
            'activity_id' => 'mark_source_ready',
            'stage' => 'ingest',
            'phase' => 'mark_source_ready',
            'timestamp' => gmdate(DATE_ATOM, $base + 3),
            'attempt' => 1,
            'status' => 'completed',
            'counts' => ['total' => 2, 'processed' => 2, 'failed' => 0, 'skipped' => 0],
            'metrics' => [
                'documents_indexed' => 2,
                'chunks_indexed' => 8,
                'vectors_upserted' => 8,
                'graph_records_updated' => 3,
            ],
            'document_version' => 'abcdef1234567890',
        ]))->assertAccepted();

        foreach (['scrape', 'convert', 'ingest'] as $stage) {
            $this->assertDatabaseHas('pipeline_stage_states', [
                'job_id' => 'job-worker-1',
                'stage' => $stage,
                'status' => PipelineJob::STATUS_COMPLETED,
            ]);
        }

        $job = PipelineJob::query()->where('job_id', 'job-worker-1')->firstOrFail();
        $source = IngestionSource::query()->where('source_id', 'source-worker-1')->firstOrFail();
        $task = PipelineTask::query()->where('task_id', 'task-worker-1')->firstOrFail();
        $this->assertSame(PipelineJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(IngestionSource::STATUS_READY, $job->index_status);
        $this->assertNotNull($job->finished_at);
        $this->assertSame(IngestionSource::STATUS_READY, $source->index_status);
        $this->assertSame('abcdef1234567890', $source->document_version);
        $this->assertNotNull($source->ready_at);
        $this->assertSame(PipelineTask::STATUS_COMPLETED, $task->status);
        $this->assertDatabaseCount('pipeline_worker_events', 4);
    }

    /**
     * @return array{dataset:Dataset,task:PipelineTask,source:IngestionSource,job:PipelineJob}
     */
    private function createExecution(): array
    {
        $dataset = Dataset::query()->create([
            'dataset_id' => 'worker-dataset',
            'name' => 'Worker Dataset',
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_worker_dataset',
            'neo4j_namespace' => 'graph_worker_dataset',
            'embedding_provider' => 'ollama',
            'embedding_model' => 'bge-m3',
            'created_at' => now(),
        ]);
        $task = PipelineTask::query()->create([
            'task_id' => 'task-worker-1',
            'dataset_id' => $dataset->dataset_id,
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => now(),
            'counters' => [],
            'metadata' => [],
        ]);
        $source = IngestionSource::query()->create([
            'source_id' => 'source-worker-1',
            'source_url' => 'https://example.test/worker-source',
            'task_id' => $task->task_id,
            'dataset_id' => $dataset->dataset_id,
            'temporal_workflow_id' => 'workflow-worker-1',
            'index_status' => IngestionSource::STATUS_RUNNING,
            'metadata' => [],
        ]);
        $job = PipelineJob::query()->create([
            'job_id' => 'job-worker-1',
            'task_id' => $task->task_id,
            'source_id' => $source->source_id,
            'job_type' => PipelineJob::TYPE_INGEST,
            'status' => PipelineJob::STATUS_RUNNING,
            'current_stage' => 'temporal.workflow_started',
            'temporal_workflow_id' => 'workflow-worker-1',
            'temporal_run_id' => 'run-worker-1',
            'index_status' => IngestionSource::STATUS_RUNNING,
            'started_at' => now(),
            'metadata' => [],
        ]);

        return compact('dataset', 'task', 'source', 'job');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => 1,
            'event_id' => 'evt_default',
            'event_type' => 'pipeline.stage.status',
            'producer' => 'scraper',
            'timestamp' => gmdate(DATE_ATOM),
            'workflow_id' => 'workflow-worker-1',
            'run_id' => 'run-worker-1',
            'activity_id' => 'scrape_source',
            'attempt' => 1,
            'job_id' => 'job-worker-1',
            'task_id' => 'task-worker-1',
            'source_id' => 'source-worker-1',
            'stage' => 'scrape',
            'phase' => 'scrape_source',
            'status' => 'running',
            'counts' => ['total' => 2, 'processed' => 0, 'failed' => 0, 'skipped' => 0],
            'metrics' => [],
            'artifacts' => [],
            'manifest' => null,
            'errors' => [],
            'warnings' => [],
            'error_details' => null,
            'document_version' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendEvent(array $payload, ?int $requestTimestamp = null): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) ($requestTimestamp ?? time());
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

        return $this->call('POST', self::ENDPOINT, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HAWKI_TIMESTAMP' => $timestamp,
            'HTTP_X_HAWKI_SIGNATURE' => $signature,
        ], $body);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendUnsignedEvent(array $payload): TestResponse
    {
        return $this->call('POST', self::ENDPOINT, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
