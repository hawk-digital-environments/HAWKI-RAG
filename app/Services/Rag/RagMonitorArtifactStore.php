<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Models\PipelineJob;
use App\Models\PipelineWorkerEventRecord;
use App\Services\Pipeline\Values\PipelineWorkerEvent;
use App\Services\Rag\Repositories\RagMonitorArtifactRepository;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class RagMonitorArtifactStore
{
    public function __construct(
        private RagMonitorArtifactRepository $artifacts,
        #[Config('config.rag_monitor_retention_days')]
        private int $retentionDays,
        private ClockInterface $clock = new Clock,
    ) {}

    public function record(
        PipelineWorkerEventRecord $record,
        PipelineWorkerEvent $event,
        PipelineJob $job,
        string $datasetId,
    ): void {
        if ($event->monitorArtifacts === null) {
            return;
        }

        $this->artifacts->store($record, $event, $job, $datasetId, $event->monitorArtifacts);
        if ($this->retentionDays < 1) {
            return;
        }

        $now = Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->artifacts->pruneBefore($now->subDays($this->retentionDays));
    }
}
