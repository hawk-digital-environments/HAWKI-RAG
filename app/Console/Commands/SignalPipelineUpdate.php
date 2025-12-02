<?php

namespace App\Console\Commands;

use App\Services\ScrapeService\ScraperPipelineService;
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
    public function handle(): void
    {
        $jobId = $this->argument('job_id');
        $pipeline = app(ScraperPipelineService::class);
        $status = $pipeline->readPipelineStatus($jobId);

        $this->info($status);
    }
}
