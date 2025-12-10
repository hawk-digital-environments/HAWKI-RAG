<?php

namespace App\Services\ScrapeService\Data;

use App\Models\ScrapeProcess;
use App\Models\ScrapeStatistics;
use App\Services\ScrapeService\Pipeline\ScrapeFinalizerService;
use Illuminate\Support\Str;

/**
 * Context object that travels through the crawler pipeline stages.
 *
 * This mutable object maintains the state as it passes through each stage
 * of the crawler pipeline. Each stage can read from and write to the context,
 * allowing data to flow through the pipeline while maintaining clear visibility
 * of what data is available at each stage.
 *
 * @property ScrapeJobRequest $request Original job request
 * @property ScrapeEventPacket|null $result Execution result
 * @property string $stage Current pipeline stage
 * @property array $errors Errors encountered during processing
 * @property array $warnings Warnings generated during processing
 */
class ScrapeContext
{
    public ScrapeProcess $process;
    public readonly string $jobId;
    public string $stage;

    public ScrapeJobRequest $request;
    public ScrapeStatistics $jobStats;

    public int $progress = 0;

    public function __construct(
        ScrapeProcess $process,
    ) {
        $this->process = $process;

        $this->jobId = $process->job_id;
        $this->stage = $process->stage;
        // Merge the job_id into the request params before creating the request object
        $requestParams = array_merge($process->request, ['job_id' => $process->job_id]);
        $this->request = ScrapeJobRequest::fromArray($requestParams);
        $this->jobStats = ScrapeStatistics::updateOrCreate(
            ['job_id' => $process->job_id], // The fields to match on (likely your unique constraint)
            [
                'started_at'=> now(),
                'completed_at'=> null,
                'target_urls' => $this->request->maxPages,
                'errors'=> [],
                'warnings'=> [],
            ] // The attributes to update/create
        );
    }


    /**
     * Set the current pipeline stage.
     *
     * @param string $stage Stage name
     * @return void
     */
    public function setStage(string $stage): void
    {
        $this->stage = $stage;
        // Update the process status in the database
        $this->process->update(['stage' => $stage]);
    }

    /**
     * Set the current stage.
     *
     * **/
    public function getStage(): string
    {
        return $this->stage;
    }

    /**
     * Add metadata to the context.
     *
     * @param string $key Metadata key
     * @param mixed $value Metadata value
     * @return void
     */
    public function setStats(string $key, mixed $value): void
    {
        $this->jobStats->{$key} = $value;
        $this->jobStats->save();
    }


    /**
     * Get metadata from the context.
     *
     * @param string $key Metadata key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed
     */
    public function getStats(): array
    {
        return $this->jobStats->toArray();
    }

    /**
     * Add an error to the context.
     *
     * @param string $message Error message
     * @param string|null $stage Stage where error occurred
     * @return void
     */
    public function addError(string $message, ?string $stage = null): void
    {
        $this->jobStats->addError([
            'message' => $message,
            'stage' => $stage ?? $this->stage,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Add a warning to the context.
     *
     * @param string $message Warning message
     * @param string|null $stage Stage where warning occurred
     * @return void
     */
    public function addWarning(string $message, ?string $stage = null): void
    {
        $this->jobStats->addWarning([
            'message' => $message,
            'stage' => $stage ?? $this->stage,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function setEndProcess($success): void{
        if(!$success){
            $this->setStage('failed');
            return;
        }
        $this->setStage('finalization');
        $finalizer = new ScrapeFinalizerService();
        $finalizer->executeFinalization($this);
    }



    /**
     * Check if the context has any errors.
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return count($this->jobStats->errors ?? []) > 0;
    }

    /**
     * Check if the context has any warnings.
     *
     * @return bool
     */
    public function hasWarnings(): bool
    {
        return count($this->jobStats->warnings ?? []) > 0;
    }

    /**
     * Get all errors.
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->jobStats->getErrors();
    }

    /**
     * Get all warnings.
     *
     * @return array
     */
    public function getWarnings(): array
    {
        return $this->jobStats->getWarnings();
    }


    public function toArray(): array{
        return [
            'jobId' => $this->jobId ?? null,
            'stage' => $this->stage,
            'errors' => $this->getErrors(),
            'warnings' => $this->getWarnings(),
        ];
    }

}
