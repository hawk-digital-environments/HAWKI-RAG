<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;

#[Singleton]
readonly class PipelineProofArtifactWriter
{
    public function __construct(
        private PipelineProofMarkdownRenderer $markdown,
        private ConfigRepository $config,
        private Filesystem $files,
    ) {
    }

    public function outputDirectory(ConsoleWorkflowIO $io, string $jobId, Carbon $startedAt): string
    {
        $output = trim((string) ($io->option('output') ?? ''));
        if ($output !== '') {
            return $output;
        }

        return rtrim((string) $this->config->get('config.pipeline_proof_root'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $this->safePathSegment($jobId)
            . '-'
            . $startedAt->format('Ymd_His');
    }

    /**
     * @param array<string, mixed> $proof
     * @param array<string, mixed> $finalStatus
     * @param array<string, mixed> $databaseState
     * @param list<array<string, mixed>> $snapshots
     * @param list<array<string, mixed>> $pipelineStageLogs
     * @param list<array<string, mixed>> $relatedLogs
     */
    public function write(
        string $outputDir,
        array $proof,
        array $finalStatus,
        array $databaseState,
        array $snapshots,
        array $pipelineStageLogs,
        array $relatedLogs,
    ): void {
        $this->files->ensureDirectoryExists($outputDir);
        $this->writeJson($outputDir.DIRECTORY_SEPARATOR.'proof.json', $proof);
        $this->writeJson($outputDir.DIRECTORY_SEPARATOR.'final-status.json', $finalStatus);
        $this->writeJson($outputDir.DIRECTORY_SEPARATOR.'database-state.json', $databaseState);
        $this->writeJsonl($outputDir.DIRECTORY_SEPARATOR.'status-snapshots.jsonl', $snapshots);
        $this->writeJsonl($outputDir.DIRECTORY_SEPARATOR.'pipeline-stage-logs.jsonl', $pipelineStageLogs);
        $this->writeJsonl($outputDir.DIRECTORY_SEPARATOR.'related-logs.jsonl', $relatedLogs);
        $this->files->put($outputDir.DIRECTORY_SEPARATOR.'proof.md', $this->markdown->report($proof));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $this->files->put($path, $this->json($data).PHP_EOL);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function writeJsonl(string $path, array $rows): void
    {
        $lines = array_map(fn (array $row) => $this->json($row), $rows);
        $this->files->put($path, implode(PHP_EOL, $lines).($lines === [] ? '' : PHP_EOL));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function safePathSegment(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value) ?: 'pipeline-job';

        return trim($safe, '-') ?: 'pipeline-job';
    }
}
