<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Services\Pipeline\Health\PipelineHealthService;
use App\Services\Rag\RagHealthService;
use App\Services\Rag\RagStatsService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class HawkiRagSystemGateService
{
    public function __construct(
        private ConfigRepository $config,
        private PipelineHealthService $pipeline,
        private RagHealthService $rag,
        private RagStatsService $stats,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function report(?int $timeout = null): array
    {
        $enabled = $this->enabled();
        $timeout = max(1, $timeout ?? (int) $this->config->get('config.health_gate.timeout', 3));
        $required = $this->requiredChecks();

        $checks = [
            $this->retrievalCheck(in_array('retrieval', $required, true)),
            $this->graphCheck(in_array('graph', $required, true)),
            $this->pipelineCheck(in_array('pipeline', $required, true), $timeout),
        ];

        $blocking = array_values(array_filter(
            $checks,
            static fn (array $check): bool => ($check['required'] ?? false) && ($check['status'] ?? 'fail') !== 'ok',
        ));

        return [
            'success' => true,
            'enforce' => $enabled,
            'status' => $enabled ? ($blocking === [] ? 'ready' : 'blocked') : 'disabled',
            'checkedAt' => $this->clock->now()->format(DATE_ATOM),
            'required' => $required,
            'checks' => $checks,
            'blocking' => $blocking,
            'repairActions' => $this->repairActions(),
        ];
    }

    private function enabled(): bool
    {
        return filter_var($this->config->get('config.health_gate.enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return list<string>
     */
    private function requiredChecks(): array
    {
        $value = $this->config->get('config.health_gate.required', []);
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => strtolower(trim((string) $item)),
            is_array($value) ? $value : [],
        ))));
    }

    /**
     * @return array<string, mixed>
     */
    private function retrievalCheck(bool $required): array
    {
        try {
            $result = $this->rag->show();
            $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
            $ok = (int) ($result['status'] ?? 0) === 200 && (bool) ($payload['ok'] ?? false);

            return $this->check(
                'retrieval',
                'HAWKI-RAG Retrieval',
                $ok ? 'ok' : 'fail',
                $ok
                    ? 'RAG bridge is reachable for Qdrant-backed retrieval.'
                    : (string) ($payload['message'] ?? $payload['body'] ?? 'RAG bridge is not ready.'),
                $ok ? '' : 'Open health checks and verify HAWKI_RAG_BRIDGE_URL, RAG bridge, and Qdrant.',
                $required,
                ['payload' => $payload],
            );
        } catch (\Throwable $exception) {
            return $this->exceptionCheck('retrieval', 'HAWKI-RAG Retrieval', $exception, $required);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function graphCheck(bool $required): array
    {
        try {
            $stats = $this->stats->show();
            $qdrantOk = (bool) ($stats['qdrant']['ok'] ?? false);
            $neo4jOk = (bool) ($stats['neo4j']['ok'] ?? false);
            $ok = $qdrantOk && $neo4jOk;

            return $this->check(
                'graph',
                'HAWKI-RAG Graph Retrieval',
                $ok ? 'ok' : 'fail',
                $ok
                    ? sprintf(
                        'Qdrant and Neo4j are ready. Neo4j has %d entities and %d triplets.',
                        (int) ($stats['neo4j']['entities'] ?? 0),
                        (int) ($stats['neo4j']['triplets'] ?? 0),
                    )
                    : 'Graph retrieval is not smooth yet: Qdrant or Neo4j stats are unavailable.',
                $ok ? '' : 'Check Qdrant, Neo4j credentials, graph extraction, and RAG stats.',
                $required,
                ['stats' => $stats],
            );
        } catch (\Throwable $exception) {
            return $this->exceptionCheck('graph', 'HAWKI-RAG Graph Retrieval', $exception, $required);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pipelineCheck(bool $required, int $timeout): array
    {
        try {
            $checks = $this->pipeline->check($timeout);
            $failures = array_values(array_filter(
                $checks,
                static fn (array $check): bool => ($check['status'] ?? 'fail') !== 'ok',
            ));

            return $this->check(
                'pipeline',
                'HAWKI-RAG Pipeline',
                $failures === [] ? 'ok' : 'fail',
                $failures === []
                    ? sprintf('Pipeline is smooth. %d checks passed.', count($checks))
                    : sprintf('Pipeline needs repair. %d of %d checks failed.', count($failures), count($checks)),
                $failures[0]['fix'] ?? 'Open Pipeline Health and repair the failing worker/service.',
                $required,
                ['checks' => $checks],
            );
        } catch (\Throwable $exception) {
            return $this->exceptionCheck('pipeline', 'HAWKI-RAG Pipeline', $exception, $required);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function exceptionCheck(string $key, string $title, \Throwable $exception, bool $required): array
    {
        return $this->check(
            $key,
            $title,
            'fail',
            $exception->getMessage(),
            'Inspect logs, repair the service, then refresh the system gate.',
            $required,
        );
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function check(
        string $key,
        string $title,
        string $status,
        string $detail,
        string $fix,
        bool $required,
        array $meta = [],
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'fix' => $fix,
            'required' => $required,
            'meta' => $meta,
        ];
    }

    /**
     * @return list<array{label:string,href:string,kind:string}>
     */
    private function repairActions(): array
    {
        return [
            ['label' => 'Open Pipeline Health', 'href' => '/pipeline-health', 'kind' => 'health'],
        ];
    }
}
