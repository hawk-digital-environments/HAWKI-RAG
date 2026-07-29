<?php
declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use Illuminate\Foundation\Http\FormRequest;

class UpsertPipelineJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'job_id' => 'nullable|string',
            'jobId' => 'nullable|string',
            'parent_job_id' => 'nullable|string',
            'parentJobId' => 'nullable|string',
            'job_type' => 'nullable|string',
            'jobType' => 'nullable|string',
            'source_url' => 'nullable|string',
            'sourceUrl' => 'nullable|string',
            'local_path' => 'nullable|string',
            'localPath' => 'nullable|string',
            'content_hash' => 'nullable|string',
            'contentHash' => 'nullable|string',
            'status' => 'nullable|string',
            'error_message' => 'nullable|string',
            'errorMessage' => 'nullable|string',
            'started_at' => 'nullable|string',
            'startedAt' => 'nullable|string',
            'metadata' => 'nullable|array',
        ];
    }
}
