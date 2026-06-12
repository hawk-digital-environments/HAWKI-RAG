<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

use App\Models\Document;
use App\Models\PipelineTask;
use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Routing\UrlGenerator;

#[Singleton]
readonly class PipelineSmokeResultReporter
{
    public function __construct(
        private UrlGenerator $urls,
    ) {
    }

    public function printSuccess(
        ConsoleWorkflowIO $io,
        PipelineSmokeStageRunner $runner,
        PipelineTask $task,
        Document $document,
        array $status,
    ): void {
        $io->line('Task Manager URL: '.$this->urls->to('/tasks/'.rawurlencode($task->task_id)));
        $io->line('Documents URL: '.$this->urls->to('/documents?document_id='.rawurlencode((string) $document->id)));
        $io->line('Final task status: '.($status['status'] ?? 'unknown'));
        $io->newLine();
        $runner->printSummary();
        $io->info('Smoke test PASS.');
    }
}
