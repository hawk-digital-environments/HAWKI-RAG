<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\Repositories\ManagedWriterReconciliationRepository;
use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository;

/** Operator evidence is an assertion, never permission to override ownership or live writers. */
#[Singleton]
readonly class ManagedWriterReconciliationAssessment
{
    public function __construct(private ManagedWriterReconciliationRepository $repository,
        private PythonTemporalBridgeClient $bridge, private Repository $config) {}

    public function snapshot(string $sourceId, bool $lock = false): array
    {
        return $this->repository->snapshot($sourceId, $lock);
    }

    public function inspect(string $sourceId, string $manifest, bool $confirmed, ?string $reference): array
    {
        $plan = ['report' => ['status' => 'refused', 'dry_run' => true, 'source_id' => $sourceId,
            'dataset_id' => null, 'document_id' => null, 'workflow_id' => null, 'run_id' => null,
            'writers' => [], 'protocol' => null, 'manifest_complete' => null, 'manifest_path' => null,
            'manifest_sha256' => null, 'target_count' => null, 'temporal' => [],
            'recovery_replacement_blockers' => [], 'evidence_ref' => $reference,
            'evidence_ref_required' => false, 'graph_disposition_ref_required' => false, 'eligible' => false, 'reason' => null]];
        try {
            if ($reference !== null && ! preg_match('/\A[\x21-\x7E]{1,512}\z/', $reference)) {
                $this->refuse('invalid_evidence_reference');
            }
            $plan['snapshot'] = $this->snapshot($sourceId);
            $snapshot = $plan['snapshot'];
            $source = $snapshot['source'];
            $metadata = $this->object($source['metadata'] ?? null);
            $report = &$plan['report'];
            $report['dataset_id'] = $source['dataset_id'] ?? null;
            $report['document_id'] = $snapshot['owner'];
            $report['workflow_id'] = $source['temporal_workflow_id'] ?? null;
            $report['run_id'] = $metadata['temporal']['run_id'] ?? $metadata['worker_event']['run_id'] ?? null;
            $report['writers'] = $metadata['managed_writers'] ?? [];
            $report['protocol'] = $metadata['managed_writer_protocol'] ?? null;
            $report['manifest_complete'] = $metadata['managed_target_manifest_complete'] ?? null;
            if (! $source || ! $this->identity($sourceId) || ! $this->identity($source['dataset_id'])
                || ! $this->identity($source['task_id']) || $metadata === null || count($snapshot['documents']) !== 1
                || $snapshot['documents'][0]['document_id'] !== $snapshot['owner']
                || $snapshot['documents'][0]['dataset_id'] !== $source['dataset_id']
                || $snapshot['documents'][0]['deleted_at'] !== null || $snapshot['documents'][0]['status'] === 'deleted') {
                $this->refuse('missing_or_conflicting_ownership');
            }
            if (! empty($source['temporal_schedule_id']) || ! in_array($source['index_status'], ['ready', 'failed', 'cancelled'], true)) {
                $this->refuse('source_active_scheduled_or_deleting');
            }
            if (isset($metadata['url_ingestion_operation_id']) || isset($metadata['owner'])) {
                $this->refuse('source_is_not_legacy_managed');
            }
            $writers = $report['writers'];
            if (! is_array($writers) || ($writers !== [] && array_is_list($writers))) {
                $this->refuse('unknown_writer_registry');
            }
            foreach ($writers as $writer) {
                if (! is_array($writer) || ($writer['status'] ?? null) !== 'released') {
                    $this->refuse('active_or_uncertain_writer');
                }
            }
            if (isset($metadata['managed_writer_protocol']) && $metadata['managed_writer_protocol'] !== 1) {
                $this->refuse('unsupported_writer_protocol');
            }
            $task = $snapshot['task'];
            if (! $task || $task['dataset_id'] !== $source['dataset_id'] || ! in_array($task['status'], ['failed', 'completed'], true)
                || $snapshot['jobs'] === []) {
                $this->refuse('task_or_job_not_terminal');
            }
            foreach ($snapshot['jobs'] as $job) {
                $jobMetadata = $this->object($job['metadata'], true);
                if ($jobMetadata === null || (array_key_exists('pending_recovery', $jobMetadata)
                    && (! is_array($jobMetadata['pending_recovery']) || ($jobMetadata['pending_recovery']['state'] ?? null) !== 'confirmed'))) {
                    $report['recovery_replacement_blockers'][] = 'unresolved_recovery';
                    $this->refuse('unresolved_recovery');
                }
                if ($job['task_id'] !== $source['task_id'] || ! in_array($job['status'], ['completed', 'failed', 'skipped'], true)
                    || ! empty($job['temporal_schedule_id'])) {
                    $this->refuse('task_or_job_not_terminal');
                }
            }
            foreach ($snapshot['operations'] as $operation) {
                if ($operation['dataset_id'] !== $source['dataset_id'] || $operation['purpose'] !== 'delete'
                    && ! ($operation['purpose'] === 'update' && in_array($operation['status'], ['continued', 'abandoned'], true))) {
                    $report['recovery_replacement_blockers'][] = 'unresolved_replacement_or_operation';
                    $this->refuse('unresolved_replacement_or_operation');
                }
            }
            $plan['job'] = $snapshot['jobs'][count($snapshot['jobs']) - 1];
            if ($plan['job']['temporal_workflow_id'] !== $report['workflow_id'] || $plan['job']['temporal_run_id'] !== $report['run_id']
                || isset($metadata['worker_event']['run_id']) && $metadata['worker_event']['run_id'] !== $report['run_id']) {
                $this->refuse('workflow_run_identity_conflict');
            }
            foreach ($writers as $writer) {
                $registeredJob = array_values(array_filter($snapshot['jobs'], fn (array $job) => $job['job_id'] === ($writer['job_id'] ?? null)));
                if (count($registeredJob) !== 1 || $registeredJob[0]['temporal_workflow_id'] !== ($writer['workflow_id'] ?? null)
                    || $registeredJob[0]['temporal_run_id'] !== ($writer['run_id'] ?? null)) {
                    $this->refuse('writer_registration_identity_conflict');
                }
            }
            $plan['targets'] = $this->manifest($manifest, $source, $metadata, $plan['job'], $report);
            foreach ($metadata['managed_targets'] ?? [] as $docId => $target) {
                $actual = $plan['targets'][$docId] ?? null;
                if (! is_array($target) || ! $actual || ($target['relative_path'] ?? null) !== $actual['relative_path']
                    || ($target['collection'] ?? null) !== $actual['collection']
                    || isset($target['job_id']) && $target['job_id'] !== $actual['job_id']
                    || isset($target['task_id']) && $target['task_id'] !== $actual['task_id']
                    || (($target['neo4j_namespace'] ?? null) !== null && $target['neo4j_namespace'] !== $actual['neo4j_namespace'])) {
                    $this->refuse('manifest_omits_or_changes_registered_target');
                }
            }
            foreach ($snapshot['outputs'] as $output) {
                if ($output['source_id'] !== $sourceId && ! in_array($output['job_id'], array_column($snapshot['jobs'], 'job_id'), true)) {
                    continue; // Other revisions are locked, but are not this source's targets.
                }
                if ($output['deleted_at'] !== null || $output['status'] === 'deleted' || ! $output['active']) {
                    $this->refuse('retired_output_cannot_be_reconciled');
                }
                $target = $plan['targets'][$output['bridge_document_id']] ?? null;
                if ($output['document_id'] !== $snapshot['owner'] || $output['source_id'] !== $sourceId || ! $target
                    || $output['qdrant_collection'] !== $target['collection']
                    || $output['job_id'] !== null && $output['job_id'] !== $target['job_id']
                    || $output['task_id'] !== null && $output['task_id'] !== $target['task_id']
                    || ($output['neo4j_namespace'] !== null && $output['neo4j_namespace'] !== $target['neo4j_namespace'])) {
                    $this->refuse('conflicting_output_ownership_or_scope');
                }
            }
            $executions = [...($metadata['managed_writer_history'] ?? []), ...$writers,
                ['workflow_id' => $report['workflow_id'], 'run_id' => $report['run_id']]];
            foreach ($snapshot['jobs'] as $job) {
                $executions[] = ['workflow_id' => $job['temporal_workflow_id'], 'run_id' => $job['temporal_run_id']];
            }
            foreach ($snapshot['operations'] as $operation) {
                if (! in_array($operation['status'], ['completed', 'continued', 'abandoned'], true)) {
                    $executions[] = ['workflow_id' => $operation['workflow_id'], 'run_id' => $operation['run_id']];
                }
            }
            foreach ($executions as $execution) {
                if (! is_array($execution) || ! $this->identity($execution['workflow_id'] ?? null) || ! $this->identity($execution['run_id'] ?? null)) {
                    $this->refuse('missing_workflow_run_identity');
                }
                $key = $execution['workflow_id'].'|'.$execution['run_id'];
                if (isset($report['temporal'][$key])) {
                    continue;
                }
                $classification = $this->temporal($execution);
                $report['temporal'][$key] = ['workflow_id' => $execution['workflow_id'], 'run_id' => $execution['run_id'], 'classification' => $classification];
                if ($classification === 'not_found_requires_retention_approval') {
                    $report['evidence_ref_required'] = true;
                } elseif ($classification !== 'terminal') {
                    $this->refuse('temporal_'.$classification);
                }
            }
            if (($report['graph_disposition_ref_required'] ?? false) && $reference === null) {
                $this->refuse('approved_no_graph_disposition_ref_required');
            }
            if ($report['evidence_ref_required'] && $reference === null) {
                $this->refuse('approved_retention_evidence_ref_required');
            }
            if (! $confirmed) {
                $this->refuse('physical_quiescence_confirmation_required');
            }
            $plan['fingerprint'] = hash('sha256', json_encode([$sourceId, $source['dataset_id'], $snapshot['owner'],
                $report['manifest_sha256'], $plan['targets'], $writers, $report['temporal'], $reference], JSON_THROW_ON_ERROR));
            $report['status'] = 'candidate';
            $report['eligible'] = true;
        } catch (ManagedDeletionException $error) {
            $plan['report']['reason'] = $error->getMessage();
        } catch (\Throwable) {
            $plan['report']['reason'] = 'assessment_unavailable_or_malformed_evidence';
        }

        return $plan;
    }

    public function manifestUnchanged(string $manifest, array $plan): bool
    {
        try {
            $report = [];
            $source = $plan['snapshot']['source'];
            $targets = $this->manifest($manifest, $source, $this->object($source['metadata']), $plan['job'], $report);

            return $report['manifest_path'] === $plan['report']['manifest_path']
                && $report['manifest_sha256'] === $plan['report']['manifest_sha256'] && $targets === $plan['targets'];
        } catch (\Throwable) {
            return false;
        }
    }

    private function manifest(string $manifest, array $source, array $metadata, array $job, array &$report): array
    {
        $root = realpath((string) $this->config->get('temporal.storage.shared_root'));
        $path = realpath($manifest);
        if (! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)
            || is_link($manifest) || filesize($path) > 8 * 1024 * 1024) {
            $this->refuse('manifest_missing_or_outside_shared_storage');
        }
        $bytes = file_get_contents($path);
        $report['manifest_path'] = $path;
        $report['manifest_sha256'] = hash('sha256', $bytes);
        $records = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($records) || ! array_is_list($records) || $records === [] || count($records) > 2000) {
            $this->refuse('invalid_manifest');
        }
        $scope = $metadata['managed_dispatch']['input']['ingestion'] ?? $metadata['dataset'] ?? [];
        if (array_key_exists('graph', $scope) && ! is_bool($scope['graph'])) {
            $this->refuse('invalid_frozen_graph_scope');
        }
        $frozen = $metadata['managed_dispatch']['input'] ?? [];
        foreach (['source_id', 'dataset_id', 'task_id'] as $key) {
            if (isset($frozen[$key]) && $frozen[$key] !== $source[$key]) {
                $this->refuse('frozen_source_identity_conflict');
            }
        }
        $collection = $scope['collection'] ?? $scope['qdrant_collection'] ?? null;
        if (! $this->identity($collection)) {
            $this->refuse('missing_frozen_physical_scope');
        }
        $targets = [];
        foreach ($records as $record) {
            if (array_key_exists('schema_version', $record) || array_key_exists('version', $record)) {
                $this->refuse('unsupported_manifest_version');
            }
            $relative = $record['relative_path'] ?? null;
            $docId = $record['document_id'] ?? null;
            if (! is_string($relative) || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\')
                || array_intersect(explode('/', $relative), ['', '.', '..']) !== []
                || $docId !== 'doc_'.substr(hash('sha256', $source['source_id'].'|'.$relative), 0, 40) || isset($targets[$docId])) {
                $this->refuse('manifest_target_identity_invalid');
            }
            foreach (['source_id' => $source['source_id'], 'dataset_id' => $source['dataset_id'], 'workflow_id' => $source['temporal_workflow_id'],
                'run_id' => $metadata['temporal']['run_id'] ?? $metadata['worker_event']['run_id'] ?? null,
                'generation' => $metadata['managed_dispatch']['input']['generation'] ?? null, 'collection' => $collection,
                'qdrant_collection' => $collection] as $key => $expected) {
                if (array_key_exists($key, $record) && $record[$key] !== $expected) {
                    $this->refuse('manifest_scope_identity_conflict');
                }
            }
            // A namespace alone never proves effective graph ownership.
            if (($scope['graph'] ?? null) !== true && ! is_bool($record['graph'] ?? null)) {
                $this->refuse('manifest_effective_graph_evidence_required');
            }
            $graph = ($scope['graph'] ?? null) === true || ($record['graph'] ?? false);
            if (! $graph && ($scope['graph'] ?? null) !== false) {
                $report['graph_disposition_ref_required'] = true;
            }
            $namespace = $graph ? ($scope['neo4j_namespace'] ?? null) : null;
            if ($graph && ! $this->identity($namespace) || array_key_exists('neo4j_namespace', $record) && $record['neo4j_namespace'] !== $namespace) {
                $this->refuse('manifest_graph_scope_conflict');
            }
            // IngestManifestRecord (artifact_documents.py) requires both fields.
            // No checksum-free historical format has an established producer contract.
            if (! is_string($record['content_hash'] ?? null) || ! is_string($record['markdown_path'] ?? null)) {
                $this->refuse('manifest_required_artifact_evidence_missing');
            }

            $artifact = realpath($record['markdown_path'] ?? '');
            $artifactRoot = realpath($source['markdown_storage_path'] ?? $root.'/sources/'.$source['source_id'].'/markdown');
            if (! is_string($record['content_hash']) || ! preg_match('/\A[a-f0-9]{64}\z/', $record['content_hash'])
                || ! $artifactRoot || ! $artifact || ! str_starts_with($artifact, $artifactRoot.DIRECTORY_SEPARATOR)
                || ! str_starts_with($artifact, $root.DIRECTORY_SEPARATOR) || ! is_file($artifact)
                || realpath($artifactRoot.DIRECTORY_SEPARATOR.$relative) !== $artifact
                || $this->contentHash($artifact, $record['content_hash']) !== $record['content_hash']) {
                $this->refuse('manifest_artifact_checksum_unproven');
            }

            if (array_key_exists('sha256', $record) && (! is_string($record['sha256'])
                || ! preg_match('/\A[a-f0-9]{64}\z/', $record['sha256']) || hash_file('sha256', $artifact) !== $record['sha256'])) {
                $this->refuse('manifest_raw_checksum_conflict');
            }
            $targets[$docId] = ['relative_path' => $relative, 'collection' => $collection, 'neo4j_namespace' => $namespace,
                'job_id' => $job['job_id'], 'task_id' => $job['task_id'], 'graph_scope_resolved' => true];
        }
        ksort($targets);
        $report['target_count'] = count($targets);

        return $targets;
    }

    /** Mirrors _artifact_text and strip_leading_converter_markdown_noise in the producer. */
    private function contentHash(string $path, string $expected): string
    {
        $text = file_get_contents($path);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $this->refuse('manifest_artifact_encoding_invalid');
        }
        // Explicitly hashed original Markdown is preserved by _artifact_text.
        if (hash('sha256', $text) === $expected) {
            return $expected;
        }
        $lines = preg_split('/\r\n|[\n\r\v\f\x{001c}-\x{001e}\x{0085}\x{2028}\x{2029}]/u', $text);
        $index = 0;
        while ($index < count($lines) && $this->strip($lines[$index]) === '') {
            $index++;
        }
        $rows = 0;
        $allowed = ['chunk', 'chunks', 'file', 'files', 'filename', 'id', 'index', 'name', 'next', 'nextchunk', 'no', 'number', 'page', 'pages'];
        while ($index < count($lines)) {
            $line = $this->strip($lines[$index]);
            if ($line === '' || (str_contains($line, '-') && preg_match('/\A[\s|:-]+\z/u', $line))) {
                $index++;

                continue;
            }
            $cells = str_contains($line, '|') ? array_filter(array_map(fn ($cell) => $this->strip($cell), explode('|', trim($line, '|'))), fn ($cell) => $cell !== '') : [$line];
            $metadata = $cells !== [];
            foreach ($cells as $cell) {
                $normalized = \Normalizer::normalize($cell, \Normalizer::FORM_KD);
                $normalized = mb_strtolower(implode('', array_filter(mb_str_split($normalized), fn ($char) => \IntlChar::getCombiningClass(\IntlChar::ord($char)) === 0)));
                $tokens = preg_split('/[^a-z0-9]+/', str_replace('ß', 'ss', $normalized), flags: PREG_SPLIT_NO_EMPTY);
                $metadata = $metadata && $tokens !== [] && array_diff($tokens, $allowed) === [];
            }
            if (! $metadata) {
                break;
            }
            $rows++;
            $index++;
        }

        return hash('sha256', $rows === 0 ? $text : $this->strip(implode("\n", array_slice($lines, $index))));
    }

    private function strip(string $text): string
    {
        return preg_replace('/\A[\s\x{001c}-\x{001f}]+|[\s\x{001c}-\x{001f}]+\z/u', '', $text);
    }

    private function temporal(array $execution): string
    {
        try {
            $status = $this->bridge->workflowStatus($execution['workflow_id'], $execution['run_id']);
        } catch (\Throwable) {
            return 'unavailable';
        }
        if (($status['workflow_id'] ?? null) !== $execution['workflow_id'] || ($status['run_id'] ?? null) !== $execution['run_id']) {
            return 'identity_conflict';
        }

        return match ($status['status'] ?? null) {
            'COMPLETED', 'FAILED', 'CANCELED', 'TERMINATED', 'TIMED_OUT' => 'terminal',
            'NOT_FOUND' => 'not_found_requires_retention_approval',
            'RUNNING' => 'active',
            default => 'unavailable',
        };
    }

    private function identity(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 255 && ! preg_match('/[\x00-\x20\x7f]/', $value);
    }

    private function object(?string $value, bool $allowNull = false): ?array
    {
        if ($value === null && $allowNull) {
            return [];
        }
        if ($value === null || ! str_starts_with(ltrim($value), '{')) {
            return null;
        }

        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    private function refuse(string $reason): never
    {
        throw ManagedDeletionException::conflict($reason);
    }
}
