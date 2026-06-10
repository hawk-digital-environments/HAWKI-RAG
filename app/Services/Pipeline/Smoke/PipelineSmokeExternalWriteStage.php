<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

use App\Models\Document;
use App\Models\PipelineTask;
use App\Services\Dataset\DatasetService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineSmokeExternalWriteStage
{
    public function __construct(private PipelineSmokeExternalVerifier $externalVerifier)
    {
    }

    public function run(
        PipelineSmokeStageRunner $runner,
        PipelineSmokeRunContext $context,
        DatasetService $datasets,
        PipelineTask $task,
        Document $document,
    ): void {
        $dataset = $datasets->ensure($task->dataset_id);
        $runner->stage('Qdrant write', function () use ($dataset, $document, $task, $context): int {
            return $this->externalVerifier->verifyQdrantPoint(
                (string) $dataset->qdrant_collection,
                (string) $document->external_id,
                (string) $task->task_id,
                $context->timeout,
            );
        }, fn (int $points): string => "Found {$points} Qdrant point(s) for the smoke document.");

        if ($context->graph) {
            $runner->stage('Neo4j write', function () use ($document, $task, $context): array {
                return $this->externalVerifier->verifyNeo4jGraph((string) $document->external_id, (string) $task->task_id, $context->timeout);
            }, fn (array $counts): string => "Found {$counts['nodes']} node(s) and {$counts['relationships']} relationship(s).");

            return;
        }

        $runner->skip('Neo4j write', 'Graph mode is disabled for this smoke run.');
    }
}
