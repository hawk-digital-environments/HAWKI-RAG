<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Logs;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Models\PipelineTask;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Psr\Clock\ClockInterface;

#[Singleton]
readonly class PipelineStageLogService
{
    private const STAGE_ALIASES = [
        'scrape' => 'scrape',
        'scraper' => 'scrape',
        'convert' => 'convert',
        'converter' => 'convert',
        'ingest' => 'ingest',
        'ingestion' => 'ingest',
    ];

    private const DOWNLOAD_PREFIXES = [
        'scrape' => 'scraper',
        'convert' => 'converter',
        'ingest' => 'ingest',
    ];

    private const STAGE_LABELS = [
        'scrape' => 'Scraper',
        'convert' => 'Converter',
        'ingest' => 'Ingest',
    ];

    public function __construct(
        private PipelineTaskRepository $tasks,
        private Filesystem $files,
        private ConfigRepository $config,
        private ClockInterface $clock,
    ) {
    }

    public function isSupportedStage(string $stage): bool
    {
        return $this->canonicalStage($stage) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forStage(string $taskId, string $stage): ?array
    {
        $canonicalStage = $this->canonicalStage($stage);
        if ($canonicalStage === null) {
            return null;
        }

        $task = $this->tasks->findWithOrderedJobs($taskId);
        if (! $task instanceof PipelineTask) {
            return null;
        }

        $jobs = $this->stageJobs($task, $canonicalStage);
        $text = $this->buildText($task, $canonicalStage, $jobs);

        return [
            'taskId' => $task->task_id,
            'datasetId' => $task->dataset_id,
            'stage' => $canonicalStage,
            'label' => self::STAGE_LABELS[$canonicalStage],
            'filename' => $this->filename($task, $canonicalStage),
            'lineCount' => $this->lineCount($text),
            'text' => $text,
            'updatedAt' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function canonicalStage(string $stage): ?string
    {
        $key = strtolower(trim($stage));

        return self::STAGE_ALIASES[$key] ?? null;
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    private function stageJobs(PipelineTask $task, string $stage): Collection
    {
        $jobs = $task->relationLoaded('jobs') ? $task->jobs : collect();

        return $jobs
            ->filter(fn (PipelineJob $job): bool => $this->jobMatchesStage($task, $job, $stage))
            ->values();
    }

    private function jobMatchesStage(PipelineTask $task, PipelineJob $job, string $stage): bool
    {
        $type = (string) $job->job_type;
        if ($type === $stage || ($stage === 'scrape' && $type === 'scraper')) {
            return true;
        }

        if ($stage === 'ingest' && $type === PipelineJob::TYPE_INGEST) {
            return true;
        }

        if ($stage === 'convert' && $this->isUploadedFileTask($task) && $type === PipelineJob::TYPE_INGEST) {
            return true;
        }

        if ($job->relationLoaded('stages') && $job->stages->contains(fn (PipelineStageState $state): bool => $state->stage === $stage)) {
            return true;
        }

        $currentStage = strtolower((string) $job->current_stage);

        return match ($stage) {
            'scrape' => str_contains($currentStage, 'scrape'),
            'convert' => str_contains($currentStage, 'convert') || str_contains($currentStage, 'inspect'),
            'ingest' => str_contains($currentStage, 'ingest') || str_contains($currentStage, 'ready'),
            default => false,
        };
    }

    private function isUploadedFileTask(PipelineTask $task): bool
    {
        $metadata = is_array($task->metadata) ? $task->metadata : [];

        return ($metadata['request']['mode'] ?? null) === 'uploaded_file_convert_ingest';
    }

    /**
     * @param Collection<int, PipelineJob> $jobs
     */
    private function buildText(PipelineTask $task, string $stage, Collection $jobs): string
    {
        $lines = [
            'HAWKI-RAG '.$this->stageName($stage).' stage log',
            str_repeat('=', 80),
            'Task ID: '.$this->scalar($task->task_id),
            'Dataset ID: '.$this->scalar($task->dataset_id),
            'Task status: '.$this->scalar($task->status),
            'Stage: '.$this->stageName($stage).' ('.$stage.')',
            'Generated at: '.$this->clock->now()->format(\DateTimeInterface::ATOM),
            '',
        ];

        $this->appendTaskSection($lines, $task);
        $this->appendJobSection($lines, $jobs, $stage);
        $this->appendCommunicationLogSection($lines, $task, $jobs, $stage);

        return rtrim(implode("\n", $lines))."\n";
    }

    /**
     * @param list<string> $lines
     */
    private function appendTaskSection(array &$lines, PipelineTask $task): void
    {
        $lines[] = 'Task record';
        $lines[] = str_repeat('-', 80);
        $lines[] = 'Started: '.$this->scalar($this->dateValue($task->started_at));
        $lines[] = 'Finished: '.$this->scalar($this->dateValue($task->finished_at));
        $this->appendJson($lines, 'Counters', $task->counters ?? []);
        $this->appendJson($lines, 'Metadata', $task->metadata ?? []);
        $lines[] = '';
    }

    /**
     * @param list<string> $lines
     * @param Collection<int, PipelineJob> $jobs
     */
    private function appendJobSection(array &$lines, Collection $jobs, string $stage): void
    {
        $lines[] = $this->stageName($stage).' job and stage records';
        $lines[] = str_repeat('-', 80);

        if ($jobs->isEmpty()) {
            $lines[] = 'No job records are currently linked to this stage.';
            $lines[] = '';

            return;
        }

        $jobs->each(function (PipelineJob $job) use (&$lines, $stage): void {
            $lines[] = 'Job: '.$this->scalar($job->job_id);
            $lines[] = '  Type: '.$this->scalar($job->job_type);
            $lines[] = '  Status: '.$this->scalar($job->status);
            $lines[] = '  Current stage: '.$this->scalar($job->current_stage);
            $lines[] = '  Source URL: '.$this->scalar($job->source_url);
            $lines[] = '  Local path: '.$this->scalar($job->local_path);
            $lines[] = '  Started: '.$this->scalar($this->dateValue($job->started_at));
            $lines[] = '  Finished: '.$this->scalar($this->dateValue($job->finished_at));
            $lines[] = '  Error: '.$this->scalar($job->error_message);
            $this->appendJson($lines, '  Job metadata', $job->metadata ?? []);

            $stageStates = $job->relationLoaded('stages')
                ? $job->stages->filter(fn (PipelineStageState $state): bool => $state->stage === $stage)->values()
                : collect();

            if ($stageStates->isEmpty()) {
                $lines[] = '  Stage state: none recorded yet.';
            } else {
                $stageStates->each(function (PipelineStageState $state) use (&$lines): void {
                    $lines[] = '  Stage state: '.$this->scalar($state->status);
                    $lines[] = '    Started: '.$this->scalar($this->dateValue($state->started_at));
                    $lines[] = '    Completed: '.$this->scalar($this->dateValue($state->completed_at));
                    $lines[] = '    Failed: '.$this->scalar($this->dateValue($state->failed_at));
                    $lines[] = '    Updated: '.$this->scalar($this->dateValue($state->updated_at));
                    $this->appendJson($lines, '    Counts', $state->counts ?? []);
                    $this->appendJson($lines, '    Metadata', $state->metadata ?? []);
                    $this->appendJson($lines, '    Warnings', $state->warnings ?? []);
                    $this->appendJson($lines, '    Errors', $state->errors ?? []);
                });
            }

            $lines[] = '';
        });
    }

    /**
     * @param list<string> $lines
     * @param Collection<int, PipelineJob> $jobs
     */
    private function appendCommunicationLogSection(array &$lines, PipelineTask $task, Collection $jobs, string $stage): void
    {
        $entries = $this->communicationEntries($task, $jobs, $stage);

        $lines[] = 'Pipeline communication log entries';
        $lines[] = str_repeat('-', 80);

        if ($entries === []) {
            $lines[] = 'No matching communication log entries were found for this task and stage.';
            $lines[] = '';

            return;
        }

        foreach ($entries as $entry) {
            $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
            $lines[] = sprintf(
                '[%s] %s stage=%s status=%s job=%s doc=%s',
                $this->scalar($entry['datetime'] ?? null),
                $this->scalar($entry['level_name'] ?? null),
                $this->scalar($context['stage'] ?? null),
                $this->scalar($context['status'] ?? null),
                $this->scalar($context['job_id'] ?? null),
                $this->scalar($context['doc_id'] ?? null),
            );
            $this->appendJson($lines, '  Raw entry', $entry);
            $lines[] = '';
        }
    }

    /**
     * @param Collection<int, PipelineJob> $jobs
     * @return list<array<string, mixed>>
     */
    private function communicationEntries(PipelineTask $task, Collection $jobs, string $stage): array
    {
        $path = (string) $this->config->get('logging.channels.communication.path', storage_path('logs/comm_logs.json'));
        if ($path === '' || ! $this->files->isFile($path)) {
            return [];
        }

        $needles = $this->matchingNeedles($task, $jobs);
        $entries = [];
        $file = new \SplFileObject($path, 'r');

        while (! $file->eof()) {
            $line = trim((string) $file->fgets());
            if ($line === '') {
                continue;
            }

            $entry = json_decode($line, true);
            if (! is_array($entry)) {
                continue;
            }

            $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
            if (($context['event'] ?? null) !== 'pipeline.stage') {
                continue;
            }

            if (! $this->entryMatchesStage($context, $stage)) {
                continue;
            }

            if ($needles !== [] && ! $this->entryMatchesNeedles($entry, $needles)) {
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param Collection<int, PipelineJob> $jobs
     * @return list<string>
     */
    private function matchingNeedles(PipelineTask $task, Collection $jobs): array
    {
        return collect([
            $task->task_id,
            $task->dataset_id,
            ...$jobs->pluck('job_id')->all(),
            ...$jobs->pluck('source_id')->all(),
        ])
            ->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => (string) $value)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function entryMatchesStage(array $context, string $stage): bool
    {
        $values = [$stage, self::DOWNLOAD_PREFIXES[$stage], self::STAGE_LABELS[$stage]];
        $contextStage = strtolower((string) ($context['stage'] ?? ''));
        foreach ($values as $value) {
            if ($contextStage === strtolower($value)) {
                return true;
            }
        }

        $pipelineStage = strtolower((string) ($context['pipeline_stage'] ?? ''));

        return match ($stage) {
            'scrape' => str_contains($pipelineStage, 'scrape') || str_contains($pipelineStage, 'crawl'),
            'convert' => str_contains($pipelineStage, 'convert') || str_contains($pipelineStage, 'conversion'),
            'ingest' => str_contains($pipelineStage, 'ingest') || str_contains($pipelineStage, 'index'),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string> $needles
     */
    private function entryMatchesNeedles(array $entry, array $needles): bool
    {
        $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded)) {
            return false;
        }

        foreach ($needles as $needle) {
            if (str_contains($encoded, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $lines
     * @param array<mixed> $payload
     */
    private function appendJson(array &$lines, string $label, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded)) {
            return;
        }

        $lines[] = $label.':';
        foreach (explode("\n", $encoded) as $line) {
            $lines[] = '  '.$line;
        }
    }

    private function filename(PipelineTask $task, string $stage): string
    {
        $dataset = $this->filenameSegment($task->dataset_id ?: $task->task_id ?: 'dataset');

        return self::DOWNLOAD_PREFIXES[$stage].'_log_'.$dataset.'.txt';
    }

    private function filenameSegment(string $value): string
    {
        $segment = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value);
        $segment = trim((string) $segment, '._-');

        return $segment !== '' ? $segment : 'dataset';
    }

    private function stageName(string $stage): string
    {
        return self::STAGE_LABELS[$stage];
    }

    private function scalar(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '-';
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function lineCount(string $text): int
    {
        $trimmed = rtrim($text, "\n");

        return $trimmed === '' ? 0 : substr_count($trimmed, "\n") + 1;
    }
}
