<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

class PipelineProofMarkdownRenderer
{
    /**
     * @param array<string, mixed> $proof
     */
    public function report(array $proof): string
    {
        $metadata = $proof['metadata'];
        $final = $proof['finalProof'];
        $convert = $proof['convert'];
        $publish = $proof['publish'];
        $worker = $proof['rabbitmqWorker'];

        $lines = [
            '# Pipeline Proof',
            '',
            '## 1. Job metadata',
            '',
            $this->table([
                ['Field', 'Value'],
                ['job_id', $metadata['job_id'] ?? ''],
                ['source_url', $metadata['source_url'] ?? ''],
                ['requested_output_dir', $metadata['requested_output_dir'] ?? ''],
                ['actual_dataset_path', $metadata['actual_dataset_path'] ?? ''],
                ['pipeline_status_endpoint', $metadata['pipeline_status_endpoint'] ?? ''],
                ['captured_at', $metadata['captured_at'] ?? ''],
            ]),
            '',
            '## 2. Pipeline status endpoint snapshots',
            '',
            $this->snapshotTable($proof['statusSnapshots']),
            '',
            '## 3. Laravel pipeline.stage logs',
            '',
            'Matching pipeline.stage log lines for this job: ' . count($proof['pipelineStageLogs']),
            '',
            'Full lines are saved in `pipeline-stage-logs.jsonl`.',
            '',
            '## 4. Convert logs and evidence',
            '',
            $this->table([
                ['Field', 'Value'],
                ['dataset path', $convert['datasetPath'] ?? ''],
                ['status', $convert['status'] ?? ''],
                ['sourceFiles', $convert['counts']['sourceFiles'] ?? $convert['counts']['total'] ?? ''],
                ['convertedFiles', $convert['counts']['convertedFiles'] ?? $convert['counts']['processed'] ?? ''],
                ['failedFiles', $convert['counts']['failedFiles'] ?? $convert['counts']['failed'] ?? ''],
                ['exit code', $convert['exitCode'] ?? ''],
                ['exit code source', $convert['exitCodeSource'] ?? ''],
                ['conversion metadata files', $convert['convertedMetadataCount'] ?? 0],
            ]),
            '',
            '## 5. Publish logs and evidence',
            '',
            $this->table([
                ['Field', 'Value'],
                ['publisher', $publish['publisher'] ?? ''],
                ['converted folder', $publish['folder'] ?? ''],
                ['documents published', $publish['documentsPublished'] ?? ''],
                ['events exchange', $publish['eventsExchange'] ?? ''],
                ['routing key', $publish['routingKey'] ?? ''],
                ['exit code', $publish['exitCode'] ?? ''],
                ['ingest stage status', $publish['status'] ?? ''],
            ]),
            '',
            '## 6. RabbitMQ pipeline worker logs/evidence',
            '',
            $this->table([
                ['Field', 'Value'],
                ['job_processing_state rows', $worker['rowsFound'] ?? 0],
                ['completed rows', $worker['completedRows'] ?? 0],
                ['failed rows', $worker['failedRows'] ?? 0],
                ['status counts', $this->inlineJson($worker['statusCounts'] ?? [])],
            ]),
            '',
            'Worker and related logs are saved in `related-logs.jsonl`. Exact database rows are saved in `database-state.json`.',
            '',
            '## 7. Database state',
            '',
            $this->table([
                ['Table', 'Rows/evidence'],
                ['pipeline_jobs', ($proof['databaseState']['pipelineJob'] ?? null) ? 1 : 0],
                ['pipeline_stage_states', count($proof['databaseState']['pipelineStageStates'] ?? [])],
                ['job_processing_state', count($proof['databaseState']['jobProcessingState'] ?? [])],
                ['scrape_jobs', ($proof['databaseState']['scrapeProcess'] ?? null) ? 1 : 0],
            ]),
            '',
            '## 8. Final proof',
            '',
            $this->table([
                ['Field', 'Value'],
                ['overall status', $final['overallStatus'] ?? ''],
                ['current stage', $final['currentStage'] ?? ''],
                ['scrape.status', $final['scrapeStatus'] ?? ''],
                ['convert.status', $final['convertStatus'] ?? ''],
                ['ingest.status', $final['ingestStatus'] ?? ''],
                ['all completed', $final['allCompleted'] ? 'yes' : 'no'],
                ['document counts', $this->inlineJson($final['documentCounts'] ?? [])],
            ]),
            '',
        ];

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function table(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $header = array_map(fn ($value) => $this->cell($value), $rows[0]);
        $lines = [
            '| ' . implode(' | ', $header) . ' |',
            '| ' . implode(' | ', array_fill(0, count($header), '---')) . ' |',
        ];

        foreach (array_slice($rows, 1) as $row) {
            $lines[] = '| ' . implode(' | ', array_map(fn ($value) => $this->cell($value), $row)) . ' |';
        }

        return implode(PHP_EOL, $lines);
    }

    private function cell(mixed $value): string
    {
        if (is_array($value)) {
            $value = $this->inlineJson($value);
        }

        return str_replace('|', '\\|', (string) $value);
    }

    private function inlineJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @param array<int, array<string, mixed>> $snapshots
     */
    private function snapshotTable(array $snapshots): string
    {
        $rows = [[
            'capturedAt',
            'reason',
            'overall',
            'currentStage',
            'scrape',
            'convert',
            'ingest',
        ]];

        foreach ($snapshots as $snapshot) {
            $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
            $stages = is_array($data['stages'] ?? null) ? $data['stages'] : [];
            $rows[] = [
                $snapshot['capturedAt'] ?? '',
                $snapshot['reason'] ?? '',
                $data['status'] ?? 'unknown',
                $data['currentStage'] ?? 'unknown',
                $stages['scrape']['status'] ?? 'unknown',
                $stages['convert']['status'] ?? 'unknown',
                $stages['ingest']['status'] ?? 'unknown',
            ];
        }

        return $this->table($rows);
    }
}
