<?php
declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use Illuminate\Foundation\Http\FormRequest;

class ListPipelineTaskEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => 'nullable|integer|min:1|max:250',
            'event_type' => 'nullable|string',
            'eventType' => 'nullable|string',
            'job_id' => 'nullable|string',
            'jobId' => 'nullable|string',
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 100);
    }

    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'event_type' => $validated['event_type'] ?? $validated['eventType'] ?? null,
            'job_id' => $validated['job_id'] ?? $validated['jobId'] ?? null,
        ];
    }
}
