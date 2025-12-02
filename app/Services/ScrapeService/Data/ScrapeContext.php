<?php

namespace App\Services\ScrapeService\Data;

use App\Models\ScrapeMetadata;
use App\Models\ScrapeProcess;
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
 * @property array $metadata Additional metadata accumulated during processing
 * @property string $stage Current pipeline stage
 * @property array $errors Errors encountered during processing
 * @property array $warnings Warnings generated during processing
 */
class ScrapeContext
{
    public readonly ScrapeProcess $process;
    public readonly string $jobId;
    public string $stage;

    public ScrapeJobRequest $config;

    public array $metadata;

    public array $errors = [];
    public array $warnings = [];

    public int $progress = 0;

    public function __construct(
        ScrapeProcess $process,
    ) {
        $this->process = $process;

        $this->jobId = $process->job_id;
        $this->stage = $process->status;
        $this->config = ScrapeJobRequest::fromArray($process->config);

        $metaCollection = $process->metadata()->get();
        $this->metadata = $metaCollection->toArray();
        $this->errors   = $metaCollection->where('event', 'error')->values()->toArray();
        $this->warnings = $metaCollection->where('event', 'warning')->values()->toArray();
    }

    /**
     * Add metadata to the context.
     *
     * @param string $key Metadata key
     * @param mixed $value Metadata value
     * @return void
     */
    public function addMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
        ScrapeMetadata::create([
            'scrape_job_id' => $this->process->id,
            'event' => $key,
            'data' => $value,
        ]);
    }

    /**
     * Get metadata from the context.
     *
     * @param string $key Metadata key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
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
        $this->errors[] = [
            'message' => $message,
            'stage' => $stage ?? $this->stage,
            'timestamp' => now()->toIso8601String(),
        ];
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
        $this->warnings[] = [
            'message' => $message,
            'stage' => $stage ?? $this->stage,
            'timestamp' => now()->toIso8601String(),
        ];
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
        $this->process->update(['status' => $stage]);
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
     * Set number of scraped URLs
     * @param int $progress
     *
     * **/
    public function setProgress(int $progress): void
    {
        $this->progress = $progress;
    }

    /**
     *  get number of scraped URLs.
     */
    public function getProgress(): int
    {
        return $this->progress;
    }

    public function setEndProcess(): void{
        $this->setStage('finalization');
        $this->process->update(['ended_at' => now()]);
        $this->addMetadata('endTime', now());
        // Calculate duration
        if ($this->getMetadata('startTime')) {
            $duration = $this->getMetadata('endTime')->diffInSeconds($this->getMetadata('startTime'));
            $this->addMetadata('durationSeconds', $duration);
        }
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
        return count($this->errors) > 0;
    }

    /**
     * Check if the context has any warnings.
     *
     * @return bool
     */
    public function hasWarnings(): bool
    {
        return count($this->warnings) > 0;
    }

    /**
     * Get all errors.
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get all warnings.
     *
     * @return array
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }


    public function toArray(): array{
        return [
            'jobId' => $this->jobId ?? null,
            'stage' => $this->stage,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'metadata' => $this->metadata,
        ];
    }





}
