<?php

namespace App\Services\ScrapeService\Data;

use App\Models\ScrapeProcess;
use App\Models\ScrapeStatistics;
use App\Services\ScrapeService\Pipeline\ScrapeFinalizerService;
use Exception;

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
    public readonly string $jobId;

    public ScrapeProcess $process;
    public ScrapeStatistics $jobStats;

    public int $progress = 0;

    public function __construct(
        ScrapeProcess $process
    ) {
        $this->jobId = $process->job_id;
        $this->process = $process;
        $this->jobStats = $process->stats;
    }

    /**
     * Set the current pipeline stage.
     *
 * @param string $stage Stage name
     * @return void
     */
    public function setStage(string $stage): void
    {
        // Update the process status in the database
        $this->process->update(['stage' => $stage]);
        $this->process->save();
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
     * Add an error to the context.
     *
     * @param string $message Error message
     * @param string|null $stage Stage where error occurred
     * @return void
     */
    public function addError(string $message): void
    {
        $this->jobStats->addError([
            'message' => $message,
            'stage' => $this->process->stage,
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

    /**
     * @throws Exception
     */
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

    public function getStage(): string
    {
        return $this->process->stage;
    }

    public function getRequest(): ScrapeJobRequest{
        return ScrapeJobRequest::fromArray($this->process->request);
    }


    public function toArray(): array{
        return [
            'jobId' => $this->jobId ?? null,
            'stage' => $this->process->stage,
            'errors' => $this->getErrors(),
            'warnings' => $this->getWarnings(),
        ];
    }

}
