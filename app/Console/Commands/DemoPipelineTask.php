<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pipeline\Commands\PipelineDemoCommandInputParser;
use App\Services\Pipeline\Commands\PipelineDemoCommandRenderer;
use App\Services\Pipeline\Commands\PipelineDemoCommandSupport;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Console\Command;

class DemoPipelineTask extends Command
{
    protected $signature = 'pipeline:demo
        {--dataset=demo : Dataset identifier for the demo run}
        {--limit=5 : Number of demo URLs to queue}
        {--graph=true : Include graph ingestion metadata}
        {--dry-run=false : Print the planned demo without creating jobs}
        {--url=* : Optional demo URL override; can be repeated}
        {--force=false : Allow this development command to run in production}';

    protected $description = 'Start a small development/demo scrape/convert/ingest pipeline task.';

    public function handle(
        PipelineTaskService $tasks,
        PipelineDemoCommandSupport $support,
        PipelineDemoCommandInputParser $inputs,
        PipelineDemoCommandRenderer $renderer,
    ): int {
        $parsed = $inputs->parse($this);
        if ($parsed['error'] !== null) {
            $this->error($parsed['error']);

            return self::FAILURE;
        }

        $input = $parsed['input'];
        if ($input === null) {
            return self::FAILURE;
        }

        if ($support->productionLocked($input->force)) {
            $this->error('pipeline:demo is disabled in production. Start production tasks through the pipeline task API.');

            return self::FAILURE;
        }

        $urls = $support->demoUrls($input->urls, $input->limit);
        if ($urls === []) {
            $this->error('No demo URLs are configured.');

            return self::FAILURE;
        }

        $taskId = $support->taskId();
        $taskInput = [
            'task_id' => $taskId,
            'dataset_id' => $input->dataset,
            'urls' => $urls,
            'metadata' => [
                'source' => 'pipeline-demo-command',
                'catalog_task_label' => 'Demo pipeline task',
                'label' => 'hawki-demo',
                'max_pages' => 1,
                'max_concurrency' => 1,
                'max_rpm' => 30,
                'skip_images' => true,
                'discovery_mode' => false,
            ],
        ];
        $taskInput['metadata']['graph'] = $input->graph;
        $taskInput['metadata']['rag_ingest_graph'] = $input->graph;

        $renderer->planned($this, $taskId, $input->dataset, $urls, $taskInput);

        if ($input->dryRun) {
            $renderer->dryRun($this, $urls, $support->dashboardUrls());

            return self::SUCCESS;
        }

        $task = $tasks->start($taskInput);
        $status = $tasks->show($task->task_id);
        $renderer->created($this, $task, $status, $urls, $support->dashboardUrls());

        return self::SUCCESS;
    }
}
