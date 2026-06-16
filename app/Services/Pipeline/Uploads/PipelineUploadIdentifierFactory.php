<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Uploads;

use App\Services\Pipeline\Values\PipelineStoredUpload;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
class PipelineUploadIdentifierFactory
{
    public function __construct(private readonly ClockInterface $clock = new Clock())
    {
    }

    public function uploadTaskId(): string
    {
        return 'task_controller_upload_'.$this->clock->now()->format('Ymd_His').'_'.Str::lower(Str::random(6));
    }

    public function convertJobId(string $taskId, PipelineStoredUpload $storedUpload): string
    {
        return 'convert_'.substr(
            hash('sha256', $taskId.'|'.$storedUpload->contentHash.'|'.$storedUpload->localPath),
            0,
            24,
        );
    }

    public function ingestJobId(string $taskId, string $sourceId): string
    {
        return 'ingest_'.substr(hash('sha256', $taskId.'|'.$sourceId), 0, 24);
    }

    public function sourceUrl(PipelineStoredUpload $storedUpload): string
    {
        return 'upload://'.$storedUpload->originalName;
    }
}
