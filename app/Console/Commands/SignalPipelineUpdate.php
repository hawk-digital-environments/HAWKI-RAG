<?php

namespace App\Console\Commands;

use App\Services\Scrape\ScraperPipelineService;
use Illuminate\Console\Command;

class SignalPipelineUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:signal-pipeline-update
                            {job_id : The ID of the job to update}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update pipeline status by reading the generated file from disk';

    /**
     * Execute the console command.
     */
    public function handle(ScraperPipelineService $pipeline): void
    {
        $jobId = (string) $this->argument('job_id');
        $status = $pipeline->readPipelineStatus($jobId);

        $this->info(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
