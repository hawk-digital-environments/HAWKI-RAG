<?php

declare(strict_types=1);

namespace App\Services\DirectIngest;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class DirectIngestStatusEntryFactory
{
    public function __construct(private ClockInterface $clock = new Clock())
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function pipelineJobId(array $data): string
    {
        $pipelineJobId = trim((string) ($data['job_id'] ?? ''));

        return $pipelineJobId !== '' ? $pipelineJobId : (string) Str::uuid();
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $cmd
     * @return array<string, mixed>
     */
    public function running(
        array $data,
        array $cmd,
        string $path,
        string $collection,
        bool $collectionExists,
        string $pipelineJobId,
        string $summaryPath,
        string $statusMode,
    ): array {
        $now = $this->timestamp();

        return [
            'id' => (string) Str::uuid(),
            'pipeline_job_id' => $pipelineJobId,
            'started_at' => $now,
            'updated_at' => $now,
            'status' => 'running',
            'progress' => null,
            'last_line' => null,
            'summary_path' => $summaryPath,
            'command' => $cmd,
            'path' => $path,
            'collection' => $collection,
            'collection_exists' => $collectionExists,
            'source' => 'api',
            'resume_mode' => $data['resume_mode'] ?? 'resume',
            'graph' => ! empty($data['graph']),
            'graph_only' => ! empty($data['graph_only']),
            'neo4j_database' => isset($data['neo4j_database']) ? trim((string) $data['neo4j_database']) : null,
            'status_mode' => $statusMode,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public function withPid(array $entry, int $pid): array
    {
        $entry['pid'] = $pid;
        $entry['updated_at'] = $this->timestamp();

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public function failed(array $entry): array
    {
        $entry['status'] = 'failed';
        $entry['updated_at'] = $this->timestamp();

        return $entry;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }
}
