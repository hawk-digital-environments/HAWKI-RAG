<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pipeline\Proof\PipelineProofService;
use App\Services\Pipeline\Repositories\PipelineProofRepository;
use App\Services\Pipeline\Status\PipelineStatusService;
use Illuminate\Console\Command;

class CapturePipelineProof extends Command
{
    protected $signature = 'pipeline:capture-proof
        {job_id : Pipeline/crawler job ID to capture}
        {--watch : Poll the pipeline status endpoint and save snapshots until completion, failure, or timeout}
        {--interval=2 : Seconds between status polls when --watch is used}
        {--timeout=900 : Maximum seconds to watch}
        {--source-url= : Source URL to record when it is not available from persisted state}
        {--requested-output-dir= : Requested scrape output directory to record when it is not available from persisted state}
        {--output= : Output directory; defaults to storage/logs/pipeline-proofs/<job-id>-<timestamp>}
        {--max-log-lines=3000 : Maximum related log lines to copy into the proof artifact}';

    protected $description = 'Capture detailed evidence for an end-to-end scrape/convert/ingest pipeline run.';

    public function handle(
        PipelineProofService $proof,
        PipelineProofRepository $proofs,
        PipelineStatusService $statuses,
    ): int {
        return $proof->run($this, $proofs, $statuses);
    }
}
