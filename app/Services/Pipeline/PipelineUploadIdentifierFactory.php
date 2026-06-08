<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Services\Pipeline\Values\PipelineStoredUpload;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;

#[Singleton]
class PipelineUploadIdentifierFactory
{
    public function uploadTaskId(): string
    {
        return 'task_controller_upload_' . now()->format('Ymd_His') . '_' . Str::lower(Str::random(6));
    }

    public function convertJobId(string $taskId, PipelineStoredUpload $storedUpload): string
    {
        return 'convert_' . substr(
            hash('sha256', $taskId . '|' . $storedUpload->contentHash . '|' . $storedUpload->localPath),
            0,
            24,
        );
    }

    public function sourceUrl(PipelineStoredUpload $storedUpload): string
    {
        return 'upload://' . $storedUpload->originalName;
    }
}
