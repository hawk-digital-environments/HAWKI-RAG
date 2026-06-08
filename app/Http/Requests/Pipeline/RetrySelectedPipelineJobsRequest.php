<?php
declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use Illuminate\Foundation\Http\FormRequest;

class RetrySelectedPipelineJobsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'job_ids' => 'nullable|array',
            'job_ids.*' => 'string',
            'jobIds' => 'nullable|array',
            'jobIds.*' => 'string',
        ];
    }

    public function jobIds(): array
    {
        $validated = $this->validated();

        return $validated['job_ids'] ?? $validated['jobIds'] ?? [];
    }
}
