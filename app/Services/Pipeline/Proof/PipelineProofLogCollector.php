<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;

readonly class PipelineProofLogCollector
{
    public function __construct(
        private ConfigRepository $config,
        private Filesystem $files,
    ) {
    }

    /**
     * @param array<string, mixed> $databaseState
     * @param array<string, mixed> $conversionEvidence
     * @return array<int, string>
     */
    public function tokens(string $jobId, ?string $datasetPath, array $databaseState, array $conversionEvidence): array
    {
        $tokens = [$jobId];
        if ($datasetPath !== null && $datasetPath !== '') {
            $tokens[] = $datasetPath;
        }

        foreach (($databaseState['jobProcessingState'] ?? []) as $row) {
            foreach (['job_id', 'input_path', 'output_path', 'trace_id'] as $key) {
                if (is_array($row) && is_scalar($row[$key] ?? null) && trim((string) $row[$key]) !== '') {
                    $tokens[] = trim((string) $row[$key]);
                }
            }
        }

        foreach (($conversionEvidence['convertedMetadataFiles'] ?? []) as $meta) {
            foreach (['converted_id', 'source_file', 'output_dir'] as $key) {
                if (is_array($meta) && is_scalar($meta[$key] ?? null) && trim((string) $meta[$key]) !== '') {
                    $tokens[] = trim((string) $meta[$key]);
                }
            }
        }

        return array_values(array_unique(array_filter($tokens, fn (string $token) => $token !== '')));
    }

    /**
     * @param array<int, string> $tokens
     * @return array{pipelineStageLogs:array<int,array<string,mixed>>,relatedLogs:array<int,array<string,mixed>>,filesScanned:array<int,string>}
     */
    public function collect(array $tokens, string $jobId, int $maxLines): array
    {
        $pipelineStageLogs = [];
        $relatedLogs = [];
        $filesScanned = [];

        foreach ($this->logFiles() as $path) {
            $filesScanned[] = $path;
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                continue;
            }

            try {
                while (($line = fgets($handle)) !== false) {
                    if (! $this->lineMatchesTokens($line, $tokens)) {
                        continue;
                    }

                    $entry = $this->logEntry($path, $line);
                    if ($this->isPipelineStageLogForJob($entry, $jobId)) {
                        $pipelineStageLogs[] = $entry;
                    }

                    if (count($relatedLogs) < $maxLines) {
                        $relatedLogs[] = $entry;
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        return [
            'pipelineStageLogs' => $pipelineStageLogs,
            'relatedLogs' => $relatedLogs,
            'filesScanned' => $filesScanned,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function logFiles(): array
    {
        $paths = [];
        foreach ($this->configList('config.pipeline_proof_log_files') as $path) {
            if ($this->files->isFile($path)) {
                $paths[] = $path;
            }
        }

        foreach ($this->configList('config.pipeline_proof_log_globs') as $glob) {
            foreach ($this->files->glob($glob) ?: [] as $path) {
                if ($this->files->isFile($path)) {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     */
    private function configList(string $key): array
    {
        $value = $this->config->get($key, []);

        return is_array($value)
            ? array_values(array_filter(array_map('strval', $value)))
            : [];
    }

    /**
     * @param array<int, string> $tokens
     */
    private function lineMatchesTokens(string $line, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token !== '' && str_contains($line, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function logEntry(string $path, string $line): array
    {
        $trimmed = rtrim($line, "\r\n");
        $decoded = json_decode($trimmed, true);

        return [
            'file' => $path,
            'decoded' => is_array($decoded) ? $decoded : null,
            'raw' => $trimmed,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function isPipelineStageLogForJob(array $entry, string $jobId): bool
    {
        $decoded = is_array($entry['decoded'] ?? null) ? $entry['decoded'] : [];
        $context = is_array($decoded['context'] ?? null) ? $decoded['context'] : [];

        $isPipelineStage = ($decoded['message'] ?? null) === 'pipeline.stage'
            || ($context['event'] ?? null) === 'pipeline.stage';

        return $isPipelineStage && (string) ($context['job_id'] ?? '') === $jobId;
    }
}
