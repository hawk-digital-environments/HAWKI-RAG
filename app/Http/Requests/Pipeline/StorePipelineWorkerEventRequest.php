<?php

declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use App\Services\Pipeline\Values\PipelineStage;
use App\Services\Pipeline\Values\PipelineStageStatus;
use App\Services\Pipeline\Values\PipelineWorker;
use App\Services\Pipeline\Values\PipelineWorkerEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePipelineWorkerEventRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'schema_version',
        'event_id',
        'event_type',
        'producer',
        'timestamp',
        'workflow_id',
        'run_id',
        'activity_id',
        'attempt',
        'job_id',
        'task_id',
        'source_id',
        'stage',
        'phase',
        'status',
        'counts',
        'metrics',
        'artifacts',
        'manifest',
        'errors',
        'warnings',
        'error_details',
        'document_version',
        'monitor_artifacts',
    ];

    /** @var list<string> */
    private const PROHIBITED_SCOPE_FIELDS = [
        'auth_context',
        'authorized_scope',
        'collection',
        'dataset_id',
        'embedding_model',
        'embedding_provider',
        'filters',
        'graph_enabled',
        'neo4j_database',
        'neo4j_namespace',
        'permissions',
        'principal',
        'provider',
        'qdrant_collection',
        'user_id',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'schema_version' => ['required', 'integer', Rule::in([1])],
            'event_id' => ['required', 'string', 'max:191'],
            'event_type' => ['required', 'string', Rule::in([PipelineWorkerEvent::EVENT_TYPE])],
            'producer' => ['required', Rule::enum(PipelineWorker::class)],
            'timestamp' => [
                'required',
                'string',
                'date',
                'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/',
            ],
            'workflow_id' => ['required', 'string', 'max:255'],
            'run_id' => ['required', 'string', 'max:255'],
            'activity_id' => ['required', 'string', 'max:255'],
            'attempt' => ['required', 'integer', 'min:1', 'max:1000000'],
            'job_id' => ['required', 'string', 'max:191'],
            'task_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'source_id' => ['required', 'string', 'max:191'],
            'stage' => ['required', Rule::enum(PipelineStage::class)],
            'phase' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::enum(PipelineStageStatus::class)],
            'counts' => ['sometimes', 'array:total,processed,failed,skipped'],
            'counts.total' => ['sometimes', 'integer', 'min:0'],
            'counts.processed' => ['sometimes', 'integer', 'min:0'],
            'counts.failed' => ['sometimes', 'integer', 'min:0'],
            'counts.skipped' => ['sometimes', 'integer', 'min:0'],
            'metrics' => ['sometimes', 'array'],
            'artifacts' => ['sometimes', 'array'],
            'artifacts.*' => ['array:uri,relative_path,sha256,size_bytes,media_type'],
            'artifacts.*.uri' => ['required', 'string', 'max:4096'],
            'artifacts.*.relative_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'artifacts.*.sha256' => ['sometimes', 'nullable', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'artifacts.*.size_bytes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'artifacts.*.media_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'manifest' => ['sometimes', 'nullable', 'array:uri,relative_path,sha256,size_bytes,media_type'],
            'manifest.uri' => ['required_with:manifest', 'string', 'max:4096'],
            'manifest.relative_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'manifest.sha256' => ['sometimes', 'nullable', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'manifest.size_bytes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'manifest.media_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'errors' => ['sometimes', 'array'],
            'errors.*' => ['array:code,message,retryable'],
            'errors.*.code' => ['required', 'string', 'max:120'],
            'errors.*.message' => ['required', 'string', 'max:2048'],
            'errors.*.retryable' => ['sometimes', 'boolean'],
            'warnings' => ['sometimes', 'array'],
            'warnings.*' => ['string'],
            'error_details' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'document_version' => ['sometimes', 'nullable', 'string', 'max:191'],
            'monitor_artifacts' => ['sometimes', 'nullable', 'array:summary,graph_preview,graph_failures'],
            'monitor_artifacts.summary' => ['required_with:monitor_artifacts', 'array'],
            'monitor_artifacts.graph_preview' => ['sometimes', 'nullable', 'array'],
            'monitor_artifacts.graph_failures' => ['sometimes', 'array'],
            'monitor_artifacts.graph_failures.*' => ['array:doc_id,file_path,chunks,chars,error,timestamp'],
            'monitor_artifacts.graph_failures.*.doc_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'monitor_artifacts.graph_failures.*.file_path' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'monitor_artifacts.graph_failures.*.chunks' => ['sometimes', 'integer', 'min:0'],
            'monitor_artifacts.graph_failures.*.chars' => ['sometimes', 'integer', 'min:0'],
            'monitor_artifacts.graph_failures.*.error' => ['required', 'string', 'max:2048'],
            'monitor_artifacts.graph_failures.*.timestamp' => ['sometimes', 'string', 'date'],
        ];

        foreach (self::PROHIBITED_SCOPE_FIELDS as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $unexpectedFields = array_diff(
                array_keys($this->all()),
                self::ALLOWED_FIELDS,
                self::PROHIBITED_SCOPE_FIELDS,
            );
            foreach ($unexpectedFields as $field) {
                $validator->errors()->add($field, 'The field is not part of the worker event contract.');
            }

            $producerValue = $this->input('producer');
            $stageValue = $this->input('stage');
            $activityValue = $this->input('activity_id');
            $producer = is_string($producerValue) ? PipelineWorker::tryFrom($producerValue) : null;
            $stage = is_string($stageValue) ? PipelineStage::tryFrom($stageValue) : null;
            $activityId = is_string($activityValue) ? $activityValue : '';

            if ($producer !== null && $stage !== null && $producer->stage() !== $stage) {
                $validator->errors()->add('stage', 'The stage does not belong to the selected worker producer.');
            }

            if ($producer !== null && ! $producer->acceptsActivity($activityId)) {
                $validator->errors()->add('activity_id', 'The activity does not belong to the selected worker producer.');
            }

            if ($this->filled('document_version') && $producer !== PipelineWorker::Indexer) {
                $validator->errors()->add('document_version', 'Only the indexer may report a document version.');
            }

            if ($this->has('monitor_artifacts') && $this->input('monitor_artifacts') !== null) {
                $status = $this->input('status');
                if (
                    $producer !== PipelineWorker::Indexer
                    || $activityId !== 'mark_source_ready'
                    || ! in_array($status, ['completed', 'failed', 'skipped'], true)
                ) {
                    $validator->errors()->add(
                        'monitor_artifacts',
                        'Only a terminal mark_source_ready indexer event may report monitor artifacts.',
                    );
                }
            }
        }];
    }

    public function event(): PipelineWorkerEvent
    {
        return PipelineWorkerEvent::fromValidated(
            $this->validated(),
            (string) $this->getContent(),
        );
    }
}
