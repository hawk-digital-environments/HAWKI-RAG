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

    private const RAGANYTHING_LOG_HINTS = [
        'api:ingest',
        'application.workflows.stage',
        'ingest:start',
        'ingest:doc',
        'ingest:prepared',
        'ingest:qdrant',
        'ingest:points',
        'index_vector',
        'graph:extract',
        'graph:neo4j',
        'graph-viz',
        'graph:extract_triplets',
        'perf:graph',
        'RAG-Anything',
        'raganything',
    ];

    private const STAGE_RUNTIME_LOG_HINTS = [
        'scrape' => [
            'scrape_source',
            'rag-scraper-task-queue',
            'crawler',
            'scraper',
        ],
        'convert' => [
            'inspect_and_convert_files',
            'rag-converter-task-queue',
            'converter',
            'convert_files',
            'extract_api',
        ],
        'ingest' => [
            'ingest_markdown_files',
            'mark_source_ready',
            'rag-ingestion-task-queue',
            'ingestion',
            'ingest',
        ],
    ];

    private const RUNTIME_LOG_LINE_LIMIT = 5000;
    private const RUNTIME_TASK_START_GRACE_SECONDS = 120;

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
        return $this->stagePayload($taskId, $stage, false);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reportForStage(string $taskId, string $stage): ?array
    {
        return $this->stagePayload($taskId, $stage, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stagePayload(string $taskId, string $stage, bool $includeDiagnosticReport): ?array
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
        $text = $includeDiagnosticReport
            ? $this->buildReportText($task, $canonicalStage, $jobs)
            : $this->buildLiveText($task, $canonicalStage, $jobs);

        return [
            'task_id' => $task->task_id,
            'dataset_id' => $task->dataset_id,
            'stage' => $canonicalStage,
            'label' => self::STAGE_LABELS[$canonicalStage],
            'filename' => $this->filename($task, $canonicalStage),
            'line_count' => $this->lineCount($text),
            'text' => $text,
            'updated_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
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
    private function buildLiveText(PipelineTask $task, string $stage, Collection $jobs): string
    {
        $lines = [];
        $this->appendDirectStageLogSection($lines, $task, $jobs, $stage);

        return rtrim(implode("\n", $lines))."\n";
    }

    /**
     * @param Collection<int, PipelineJob> $jobs
     */
    private function buildReportText(PipelineTask $task, string $stage, Collection $jobs): string
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

        $this->appendDirectStageLogSection($lines, $task, $jobs, $stage);
        $this->appendTaskSection($lines, $task);
        $this->appendJobSection($lines, $jobs, $stage);
        $this->appendCommunicationLogSection($lines, $task, $jobs, $stage);

        return rtrim(implode("\n", $lines))."\n";
    }

    /**
     * @param list<string> $lines
     * @param Collection<int, PipelineJob> $jobs
     */
    private function appendDirectStageLogSection(array &$lines, PipelineTask $task, Collection $jobs, string $stage): void
    {
        if ($stage === 'scrape') {
            $this->appendLogEntriesSection(
                $lines,
                'Scraper crawler.log entries',
                $this->scraperCrawlerLogEntries($task, $jobs),
                'No matching scraper crawler.log entries were found yet.'
            );

            return;
        }

        if ($stage === 'convert') {
            $this->appendStageRuntimeLogSection($lines, $task, $jobs, $stage, 'Converter worker log entries');

            return;
        }

        $this->appendRagAnythingRuntimeLogSection($lines, $task, $jobs, $stage);
        $this->appendStageRuntimeLogSection($lines, $task, $jobs, $stage, 'Temporal ingestion worker log entries');
    }

    /**
     * @param list<string> $lines
     * @param Collection<int, PipelineJob> $jobs
     */
    private function appendStageRuntimeLogSection(array &$lines, PipelineTask $task, Collection $jobs, string $stage, string $title): void
    {
        $this->appendLogEntriesSection(
            $lines,
            $title,
            $this->stageRuntimeEntries($task, $jobs, $stage),
            'No matching '.$this->stageName($stage).' worker log entries were found yet.'
        );
    }

    /**
     * @param list<string> $lines
     * @param list<string> $entries
     */
    private function appendLogEntriesSection(array &$lines, string $title, array $entries, string $emptyMessage): void
    {
        $lines[] = $title;
        $lines[] = str_repeat('-', 80);

        if ($entries === []) {
            $lines[] = $emptyMessage;
            $lines[] = '';

            return;
        }

        foreach ($entries as $entry) {
            $lines[] = $entry;
        }

        $lines[] = '';
    }

    /**
     * @param list<string> $lines
     * @param Collection<int, PipelineJob> $jobs
     */
    private function appendRagAnythingRuntimeLogSection(array &$lines, PipelineTask $task, Collection $jobs, string $stage): void
    {
        if ($stage !== 'ingest') {
            return;
        }

        $entries = $this->ragAnythingRuntimeEntries($task, $jobs);

        $this->appendLogEntriesSection(
            $lines,
            'RAG-Anything runtime log entries',
            $entries,
            'No matching RAG-Anything runtime log entries were found yet.'
        );
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
     * @return list<string>
     */
    private function ragAnythingRuntimeEntries(PipelineTask $task, Collection $jobs): array
    {
        $paths = $this->ragAnythingRuntimeLogPaths();
        if ($paths === []) {
            return [];
        }

        $baseNeedles = $this->ragAnythingRuntimeNeedles($task, $jobs);
        $documentNeedles = $this->documentNeedles($jobs);
        $selected = [];
        $entries = [];

        foreach ($paths as $path) {
            if (! $this->files->isFile($path)) {
                continue;
            }

            $logLines = $this->runtimeLogLines($path);

            foreach ($logLines as $line) {
                $text = $line['text'];
                if (! $this->runtimeLineLooksRelevant($text)) {
                    continue;
                }

                if ($this->lineMatchesNeedles($text, $baseNeedles)) {
                    $this->appendRuntimeEntry($entries, $selected, $path, $line['number'], $text);
                    $documentNeedles = $this->mergeNeedles($documentNeedles, $this->documentNeedlesFromLine($text));
                }
            }

            if ($documentNeedles === []) {
                continue;
            }

            foreach ($logLines as $line) {
                $text = $line['text'];
                if (! $this->runtimeLineLooksRelevant($text)) {
                    continue;
                }

                if ($this->lineMatchesNeedles($text, $documentNeedles)) {
                    $this->appendRuntimeEntry($entries, $selected, $path, $line['number'], $text);
                }
            }
        }

        return $this->sortRuntimeEntries($entries);
    }

    /**
     * @param Collection<int, PipelineJob> $jobs
     * @return list<string>
     */
    private function stageRuntimeEntries(PipelineTask $task, Collection $jobs, string $stage): array
    {
        $paths = $this->stageRuntimeLogPaths($stage);
        if ($paths === []) {
            return [];
        }

        $needles = $this->runtimeNeedles($task, $jobs);
        $selected = [];
        $entries = [];

        foreach ($paths as $path) {
            if (! $this->files->isFile($path)) {
                continue;
            }

            foreach ($this->runtimeLogLines($path) as $line) {
                $text = $line['text'];
                if (! $this->stageRuntimeLineLooksRelevant($stage, $text)) {
                    continue;
                }

                if (! $this->runtimeLineIsInsideTaskWindow($text, $task)) {
                    continue;
                }

                if ($needles !== [] && ! $this->lineMatchesNeedles($text, $needles)) {
                    continue;
                }

                $this->appendRuntimeEntry($entries, $selected, $path, $line['number'], $text);
            }
        }

        return $this->sortRuntimeEntries($entries);
    }

    /**
     * @param Collection<int, PipelineJob> $jobs
     * @return list<string>
     */
    private function scraperCrawlerLogEntries(PipelineTask $task, Collection $jobs): array
    {
        $paths = $this->scraperCrawlerLogPaths($task, $jobs);
        if ($paths === []) {
            return [];
        }

        $selected = [];
        $entries = [];

        foreach ($paths as $path) {
            if (! $this->files->isFile($path)) {
                continue;
            }

            foreach ($this->runtimeLogLines($path) as $line) {
                $this->appendRuntimeEntry($entries, $selected, $path, $line['number'], $line['text']);
            }
        }

        return $this->sortRuntimeEntries($entries);
    }

    /**
     * @param list<string> $entries
     * @return list<string>
     */
    private function sortRuntimeEntries(array $entries): array
    {
        usort($entries, function (string $left, string $right): int {
            return $this->runtimeEntryPosition($left) <=> $this->runtimeEntryPosition($right);
        });

        return $entries;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function runtimeEntryPosition(string $entry): array
    {
        if (preg_match('/^\[([^:]+):(\d+)]/', $entry, $matches)) {
            return [(string) $matches[1], (int) $matches[2]];
        }

        return [$entry, 0];
    }

    /**
     * @param Collection<int, PipelineJob> $jobs
     * @return list<string>
     */
    private function scraperCrawlerLogPaths(PipelineTask $task, Collection $jobs): array
    {
        $sharedRoot = rtrim((string) $this->config->get('temporal.storage.shared_root', '/shared'), '/');
        $candidates = [];

        if (is_scalar($task->task_id) && trim((string) $task->task_id) !== '') {
            $this->appendPathCandidate($candidates, $sharedRoot.'/'.$task->task_id);
        }

        if (is_scalar($task->dataset_id) && trim((string) $task->dataset_id) !== '') {
            $this->appendPathCandidate($candidates, $sharedRoot.'/'.$task->dataset_id);
        }

        $jobs->each(function (PipelineJob $job) use (&$candidates, $sharedRoot): void {
            $this->appendPathCandidate($candidates, $job->local_path);
            if (is_scalar($job->job_id) && trim((string) $job->job_id) !== '') {
                $this->appendPathCandidate($candidates, $sharedRoot.'/'.$job->job_id);
            }

            if (is_scalar($job->source_id) && trim((string) $job->source_id) !== '') {
                $this->appendPathCandidate($candidates, $sharedRoot.'/sources/'.$job->source_id.'/raw');
            }

            $metadata = is_array($job->metadata) ? $job->metadata : [];
            $this->appendLogPathCandidatesFromPayload($candidates, $metadata);
        });

        return $this->resolveLogPaths($candidates, 'crawler.log');
    }

    /**
     * @return list<string>
     */
    private function ragAnythingRuntimeLogPaths(): array
    {
        $configured = $this->config->get('config.raganything_runtime_log_paths', []);
        $paths = is_array($configured) ? $configured : [$configured];

        $candidates = collect($paths)
            ->filter(fn (mixed $path): bool => is_string($path) && trim($path) !== '')
            ->map(fn (string $path): string => trim($path))
            ->unique()
            ->values()
            ->all();

        return $this->uniqueReadableLogPaths($candidates);
    }

    /**
     * @return list<string>
     */
    private function stageRuntimeLogPaths(string $stage): array
    {
        $configured = $this->config->get('config.pipeline_stage_runtime_log_paths.'.$stage, []);
        $paths = is_array($configured) ? $configured : [$configured];

        $candidates = collect($paths)
            ->filter(fn (mixed $path): bool => is_string($path) && trim($path) !== '')
            ->map(fn (string $path): string => trim($path))
            ->unique()
            ->values()
            ->all();

        return $this->uniqueReadableLogPaths($candidates);
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function uniqueReadableLogPaths(array $paths): array
    {
        $unique = [];
        $seen = [];

        foreach ($paths as $path) {
            if (! $this->files->isFile($path)) {
                continue;
            }

            $key = $this->fileIdentity($path);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $path;
        }

        return $unique;
    }

    private function fileIdentity(string $path): string
    {
        $stat = @stat($path);
        if (is_array($stat) && isset($stat['dev'], $stat['ino'])) {
            return 'inode:'.$stat['dev'].':'.$stat['ino'];
        }

        $realPath = realpath($path);
        if (is_string($realPath) && $realPath !== '') {
            return 'path:'.$realPath;
        }

        return 'path:'.$path;
    }

    /**
     * @return list<array{number: int, text: string}>
     */
    private function runtimeLogLines(string $path): array
    {
        $lines = [];
        $file = new \SplFileObject($path, 'r');
        $lineNumber = 0;

        while (! $file->eof()) {
            $lineNumber++;
            $line = rtrim((string) $file->fgets(), "\r\n");
            if ($line === '') {
                continue;
            }

            $lines[] = [
                'number' => $lineNumber,
                'text' => $line,
            ];

            if (count($lines) > self::RUNTIME_LOG_LINE_LIMIT) {
                array_shift($lines);
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $entries
     * @param array<string, true> $selected
     */
    private function appendRuntimeEntry(array &$entries, array &$selected, string $path, int $lineNumber, string $line): void
    {
        $key = $path.':'.$lineNumber;
        if (isset($selected[$key])) {
            return;
        }

        $selected[$key] = true;
        $entries[] = '['.basename($path).':'.$lineNumber.'] '.$line;
    }

    /**
     * @param Collection<int, PipelineJob> $jobs
     * @return list<string>
     */
    private function runtimeNeedles(PipelineTask $task, Collection $jobs): array
    {
        $needles = $this->matchingNeedles($task, $jobs);

        $jobs->each(function (PipelineJob $job) use (&$needles): void {
            $metadata = is_array($job->metadata) ? $job->metadata : [];
            $this->appendNeedle($needles, $metadata['source_id'] ?? null);
            $this->appendNeedle($needles, $metadata['task_id'] ?? null);
            $this->appendNeedle($needles, $metadata['job_id'] ?? null);
            $this->appendNeedle($needles, $metadata['managed_document_id'] ?? null);
            $this->appendNeedle($needles, $metadata['document_id'] ?? null);
            $this->appendNeedle($needles, $metadata['doc_id'] ?? null);
            $this->appendNeedle($needles, $job->local_path);
            $this->appendNeedle($needles, $job->content_hash);
        });

        return $this->normalizeNeedles($needles);
    }

    /**
     * @param Collection<int, PipelineJob> $jobs
     * @return list<string>
     */
    private function ragAnythingRuntimeNeedles(PipelineTask $task, Collection $jobs): array
    {
        $needles = [];
        $this->appendNeedle($needles, $task->task_id);

        $jobs->each(function (PipelineJob $job) use (&$needles): void {
            $metadata = is_array($job->metadata) ? $job->metadata : [];
            $this->appendNeedle($needles, $job->job_id);
            $this->appendNeedle($needles, $metadata['task_id'] ?? null);
            $this->appendNeedle($needles, $metadata['job_id'] ?? null);
            $this->appendNeedle($needles, $metadata['managed_document_id'] ?? null);
            $this->appendNeedle($needles, $metadata['document_id'] ?? null);
            $this->appendNeedle($needles, $metadata['doc_id'] ?? null);
        });

        return $this->normalizeNeedles($needles);
    }

    /**
     * @param Collection<int, PipelineJob> $jobs
     * @return list<string>
     */
    private function documentNeedles(Collection $jobs): array
    {
        $needles = [];

        $jobs->each(function (PipelineJob $job) use (&$needles): void {
            $metadata = is_array($job->metadata) ? $job->metadata : [];
            $this->appendNeedle($needles, $metadata['managed_document_id'] ?? null);
            $this->appendNeedle($needles, $metadata['document_id'] ?? null);
            $this->appendNeedle($needles, $metadata['doc_id'] ?? null);

            foreach ($this->manifestPathsForJob($job) as $path) {
                foreach ($this->documentNeedlesFromManifest($path) as $documentId) {
                    $this->appendNeedle($needles, $documentId);
                }
            }
        });

        return $this->normalizeNeedles($needles);
    }

    /**
     * @return list<string>
     */
    private function manifestPathsForJob(PipelineJob $job): array
    {
        $sourceId = is_string($job->source_id) ? trim($job->source_id) : '';
        if ($sourceId === '') {
            return [];
        }

        $sharedRoot = rtrim((string) $this->config->get('temporal.storage.shared_root', '/shared'), '/');

        return [$sharedRoot.'/sources/'.$sourceId.'/ingest/manifest.json'];
    }

    /**
     * @return list<string>
     */
    private function documentNeedlesFromManifest(string $path): array
    {
        if (! $this->files->isFile($path)) {
            return [];
        }

        $decoded = json_decode($this->files->get($path), true);
        if (! is_array($decoded)) {
            return [];
        }

        $needles = [];
        foreach ($decoded as $record) {
            if (! is_array($record)) {
                continue;
            }

            $this->appendNeedle($needles, $record['document_id'] ?? null);
            $this->appendNeedle($needles, $record['doc_id'] ?? null);
        }

        return $this->normalizeNeedles($needles);
    }

    /**
     * @return list<string>
     */
    private function documentNeedlesFromLine(string $line): array
    {
        $needles = [];
        $patterns = [
            '/\bdoc_id=([A-Za-z0-9._:-]+)/',
            '/\bdoc=([A-Za-z0-9._:-]+)/',
            '/"doc_id"\s*:\s*"([^"]+)"/',
            '/"document_id"\s*:\s*"([^"]+)"/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $line, $matches)) {
                foreach ($matches[1] as $match) {
                    $this->appendNeedle($needles, $match);
                }
            }
        }

        foreach (['idempotency_key', 'request_id'] as $field) {
            if (! preg_match_all('/\b'.$field.'=([A-Za-z0-9._:-]+)/', $line, $matches)) {
                continue;
            }

            foreach ($matches[1] as $operationId) {
                $documentId = $this->documentIdFromOperationId($operationId);
                if ($documentId !== null) {
                    $this->appendNeedle($needles, $documentId);
                }
            }
        }

        return $this->normalizeNeedles($needles);
    }

    private function documentIdFromOperationId(string $operationId): ?string
    {
        $parts = explode(':', trim($operationId));
        if (count($parts) < 4 || end($parts) !== 'ingest') {
            return null;
        }

        $documentId = $parts[count($parts) - 2] ?? '';

        return $documentId !== '' ? $documentId : null;
    }

    private function runtimeLineLooksRelevant(string $line): bool
    {
        if ($this->runtimeLineShouldBeHidden($line)) {
            return false;
        }

        $lower = strtolower($line);

        foreach (self::RAGANYTHING_LOG_HINTS as $hint) {
            if (str_contains($lower, strtolower($hint))) {
                return true;
            }
        }

        return false;
    }

    private function runtimeLineShouldBeHidden(string $line): bool
    {
        $lower = strtolower($line);

        return str_contains($lower, 'api.request_start')
            || str_contains($lower, 'body={"docs"')
            || str_contains($lower, 'body={\\"docs\\"');
    }

    private function stageRuntimeLineLooksRelevant(string $stage, string $line): bool
    {
        $lower = strtolower($line);

        foreach (self::STAGE_RUNTIME_LOG_HINTS[$stage] ?? [] as $hint) {
            if (str_contains($lower, strtolower($hint))) {
                return true;
            }
        }

        return false;
    }

    private function runtimeLineIsInsideTaskWindow(string $line, PipelineTask $task): bool
    {
        $lineTime = $this->runtimeLineTime($line);
        if ($lineTime === null || ! ($task->started_at instanceof \DateTimeInterface)) {
            return true;
        }

        $taskStartedAt = \DateTimeImmutable::createFromInterface($task->started_at)
            ->setTimezone(new \DateTimeZone('UTC'));

        return $lineTime->getTimestamp() >= $taskStartedAt->getTimestamp() - self::RUNTIME_TASK_START_GRACE_SECONDS;
    }

    private function runtimeLineTime(string $line): ?\DateTimeImmutable
    {
        if (! preg_match('/\b(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:[,.](\d{1,6}))?/', $line, $matches)) {
            return null;
        }

        $fraction = str_pad(substr((string) ($matches[3] ?? '0'), 0, 6), 6, '0');
        $time = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $matches[1].' '.$matches[2].'.'.$fraction,
            new \DateTimeZone('UTC'),
        );

        return $time instanceof \DateTimeImmutable ? $time : null;
    }

    /**
     * @param list<string> $needles
     */
    private function lineMatchesNeedles(string $line, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $needles
     */
    private function appendNeedle(array &$needles, mixed $value): void
    {
        if (! is_scalar($value)) {
            return;
        }

        $needle = trim((string) $value);
        if ($needle !== '') {
            $needles[] = $needle;
        }
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     * @return list<string>
     */
    private function mergeNeedles(array $left, array $right): array
    {
        return $this->normalizeNeedles([...$left, ...$right]);
    }

    /**
     * @param list<string> $needles
     * @return list<string>
     */
    private function normalizeNeedles(array $needles): array
    {
        return collect($needles)
            ->filter(fn (string $needle): bool => strlen(trim($needle)) >= 6)
            ->map(fn (string $needle): string => trim($needle))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param list<string> $candidates
     * @param array<string, mixed> $payload
     */
    private function appendLogPathCandidatesFromPayload(array &$candidates, array $payload, int $depth = 0): void
    {
        if ($depth > 3) {
            return;
        }

        $pathKeys = [
            'crawler_log',
            'crawler_log_path',
            'dataset_path',
            'local_path',
            'log_path',
            'markdown_dir',
            'markdown_output_path',
            'output_path',
            'raw_dir',
            'raw_output_path',
        ];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, $pathKeys, true)) {
                $this->appendPathCandidate($candidates, $value);
            }

            if (is_array($value)) {
                $this->appendLogPathCandidatesFromPayload($candidates, $value, $depth + 1);
            }
        }
    }

    /**
     * @param list<string> $candidates
     */
    private function appendPathCandidate(array &$candidates, mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->appendPathCandidate($candidates, $item);
            }

            return;
        }

        if (! is_scalar($value)) {
            return;
        }

        $path = trim((string) $value);
        if ($path === '') {
            return;
        }

        if (str_starts_with($path, 'file://')) {
            $path = substr($path, 7);
        }

        if ($path !== '') {
            $candidates[] = $path;
        }
    }

    /**
     * @param list<string> $candidates
     * @return list<string>
     */
    private function resolveLogPaths(array $candidates, string $fileName): array
    {
        $paths = [];

        foreach ($candidates as $candidate) {
            if ($this->files->isFile($candidate)) {
                $paths[] = $candidate;
                continue;
            }

            if ($this->files->isDirectory($candidate)) {
                $paths[] = rtrim($candidate, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$fileName;
            }
        }

        return collect($paths)
            ->filter(fn (string $path): bool => $this->files->isFile($path))
            ->unique()
            ->values()
            ->all();
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
