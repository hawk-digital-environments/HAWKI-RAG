<?php

declare(strict_types=1);

namespace Tests\Unit\Health;

use App\Services\Health\HawkiRagSystemGateService;
use App\Services\Pipeline\Health\PipelineHealthService;
use App\Services\Rag\RagHealthService;
use App\Services\Rag\RagStatsService;
use Symfony\Component\Clock\MockClock;
use Tests\TestCase;

class HawkiRagSystemGateServiceTest extends TestCase
{
    public function test_it_blocks_when_required_pro_and_analytics_are_not_ready(): void
    {
        config()->set('config.health_gate.enabled', true);
        config()->set('config.health_gate.required', ['retrieval', 'graph', 'pipeline', 'pro', 'analytics']);
        config()->set('config.health_gate.pro_ready', false);
        config()->set('config.health_gate.analytics_ready', false);

        $service = new HawkiRagSystemGateService(
            config(),
            $this->passingPipeline(),
            $this->passingRag(),
            $this->passingStats(),
            new MockClock('2026-06-18T12:00:00+00:00'),
        );

        $report = $service->report(1);

        $this->assertSame('blocked', $report['status']);
        $this->assertSame(['pro', 'analytics'], array_column($report['blocking'], 'key'));
        $this->assertSame('2026-06-18T12:00:00+00:00', $report['checkedAt']);
    }

    public function test_it_is_ready_when_all_required_checks_pass(): void
    {
        config()->set('config.health_gate.enabled', true);
        config()->set('config.health_gate.required', ['retrieval', 'graph', 'pipeline', 'pro', 'analytics']);
        config()->set('config.health_gate.pro_ready', true);
        config()->set('config.health_gate.analytics_ready', true);

        $service = new HawkiRagSystemGateService(
            config(),
            $this->passingPipeline(),
            $this->passingRag(),
            $this->passingStats(),
            new MockClock('2026-06-18T12:00:00+00:00'),
        );

        $report = $service->report(1);

        $this->assertSame('ready', $report['status']);
        $this->assertSame([], $report['blocking']);
        $this->assertSame(['Open Pipeline Health'], array_column($report['repairActions'], 'label'));
    }

    private function passingPipeline(): PipelineHealthService
    {
        return new readonly class extends PipelineHealthService {
            public function __construct()
            {
            }

            public function check(int $timeout): array
            {
                return [
                    ['name' => 'Database', 'status' => 'ok', 'detail' => 'ready', 'fix' => ''],
                    ['name' => 'Qdrant', 'status' => 'ok', 'detail' => 'ready', 'fix' => ''],
                    ['name' => 'Neo4j', 'status' => 'ok', 'detail' => 'ready', 'fix' => ''],
                ];
            }
        };
    }

    private function passingRag(): RagHealthService
    {
        return new readonly class extends RagHealthService {
            public function __construct()
            {
            }

            public function show(): array
            {
                return [
                    'status' => 200,
                    'payload' => ['ok' => true],
                ];
            }
        };
    }

    private function passingStats(): RagStatsService
    {
        return new readonly class extends RagStatsService {
            public function __construct()
            {
            }

            public function show(): array
            {
                return [
                    'ok' => true,
                    'qdrant' => ['ok' => true],
                    'neo4j' => ['ok' => true, 'entities' => 3, 'triplets' => 2],
                ];
            }
        };
    }
}
