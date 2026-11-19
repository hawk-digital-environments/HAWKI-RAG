<?php

declare(strict_types=1);

namespace Tests\Unit\Health;

use App\Services\Health\HawkiRagSystemGateService;
use App\Services\Pipeline\Health\PipelineHealthService;
use App\Services\Rag\RagHealthService;
use App\Services\Rag\RagStatsService;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Clock\MockClock;
use Tests\TestCase;

class HawkiRagSystemGateServiceTest extends TestCase
{
    public function test_it_ignores_removed_pro_and_analytics_checks(): void
    {
        config()->set('config.health_gate.enabled', true);
        config()->set('config.health_gate.required', ['retrieval', 'graph', 'pipeline', 'pro', 'analytics']);

        $service = new HawkiRagSystemGateService(
            config(),
            $this->passingPipeline(),
            $this->passingRag(),
            $this->passingStats(),
            new MockClock('2026-06-18T12:00:00+00:00'),
        );

        $report = $service->report(1);

        $this->assertSame('ready', $report['status']);
        $this->assertFalse($report['enforce']);
        $this->assertTrue($report['advisory']);
        $this->assertSame(['retrieval', 'graph', 'pipeline'], array_column($report['checks'], 'key'));
        $this->assertSame([], $report['blocking']);
        $this->assertSame('2026-06-18T12:00:00+00:00', $report['checkedAt']);
    }

    public function test_it_is_ready_when_all_required_checks_pass(): void
    {
        config()->set('config.health_gate.enabled', true);
        config()->set('config.health_gate.required', ['retrieval', 'graph', 'pipeline']);

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
        $this->assertSame(['retrieval', 'graph', 'pipeline'], array_column($report['checks'], 'key'));
        $this->assertSame(['Open Pipeline Health'], array_column($report['repairActions'], 'label'));
    }

    #[TestWith([200])]
    #[TestWith([503])]
    public function test_python_retrieval_reports_its_actual_authority_without_bridge_instructions(int $status): void
    {
        config()->set('config.health_gate.required', ['retrieval']);
        $rag = new readonly class($status) extends RagHealthService
        {
            public function __construct(private int $status) {}

            public function show(): array
            {
                return ['status' => $this->status, 'payload' => ['ok' => $this->status === 200, 'authority' => 'python']];
            }
        };
        $report = (new HawkiRagSystemGateService(config(), $this->passingPipeline(), $rag,
            $this->passingStats(), new MockClock('2026-11-19T12:00:00Z')))->report();
        $this->assertSame($status === 200 ? 'ready' : 'blocked', $report['status']);
        $this->assertStringContainsString('Python query', $report['checks'][0]['detail']);
        $this->assertStringNotContainsString('bridge', strtolower(json_encode($report['checks'][0], JSON_THROW_ON_ERROR)));
        $this->assertTrue($report['advisory']);
        $this->assertFalse($report['enforce']);
    }

    #[TestWith(['qdrant'])]
    #[TestWith(['neo4j'])]
    #[TestWith(['worker'])]
    public function test_missing_store_or_failed_worker_state_cannot_report_readiness(string $failure): void
    {
        config()->set('config.health_gate.required', ['retrieval', 'graph', 'pipeline']);
        $stats = new readonly class($failure) extends RagStatsService
        {
            public function __construct(private string $failure) {}

            public function show(): array
            {
                return ['ok' => false, 'qdrant' => $this->failure === 'qdrant' ? [] : ['ok' => true],
                    'neo4j' => $this->failure === 'neo4j' ? [] : ['ok' => true]];
            }
        };
        $pipeline = new readonly class($failure) extends PipelineHealthService
        {
            public function __construct(private string $failure) {}

            public function check(int $timeout): array
            {
                return [['name' => 'Worker', 'status' => $this->failure === 'worker' ? 'fail' : 'ok', 'fix' => 'Inspect worker readiness.']];
            }
        };
        $report = (new HawkiRagSystemGateService(config(), $pipeline, $this->passingRag(), $stats,
            new MockClock('2026-11-19T12:00:00Z')))->report();
        $this->assertSame('blocked', $report['status']);
        $this->assertSame($failure === 'worker' ? 'pipeline' : 'graph', $report['blocking'][0]['key']);
        $this->assertFalse($report['enforce']);
    }

    private function passingPipeline(): PipelineHealthService
    {
        return new readonly class extends PipelineHealthService
        {
            public function __construct() {}

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
        return new readonly class extends RagHealthService
        {
            public function __construct() {}

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
        return new readonly class extends RagStatsService
        {
            public function __construct() {}

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
