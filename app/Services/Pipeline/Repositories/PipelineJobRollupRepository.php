<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;

#[Singleton]
readonly class PipelineJobRollupRepository
{
    /**
     * @param  array{total:int,processed:int,failed:int,skipped:int}  $counts
     * @param  array<string, mixed>  $attributes
     */
    public function updateStageRollup(
        PipelineJob $job,
        string $currentStage,
        string $status,
        array $counts,
        ?Carbon $completedAt,
        array $attributes,
    ): PipelineJob {
        $job->current_stage = $attributes['current_stage'] ?? $currentStage;
        $job->status = $status;

        if (isset($attributes['dataset_path'])) {
            $job->dataset_path = $attributes['dataset_path'];
        }
        if (isset($attributes['source_url'])) {
            $job->source_url = $attributes['source_url'];
        }
        if (isset($attributes['label'])) {
            $job->label = $attributes['label'];
        }
        if (array_key_exists('index_status', $attributes)) {
            $job->index_status = $attributes['index_status'];
        }
        if (array_key_exists('error_message', $attributes)) {
            $job->error_message = $attributes['error_message'];
        }
        if (array_key_exists('finished_at', $attributes)) {
            $job->finished_at = $attributes['finished_at'];
        }

        $job->total_documents = $counts['total'];
        $job->processed_documents = $counts['processed'];
        $job->failed_documents = $counts['failed'];
        $job->skipped_documents = $counts['skipped'];
        $job->completed_at = $completedAt;
        $job->save();

        return $job->refresh();
    }
}
