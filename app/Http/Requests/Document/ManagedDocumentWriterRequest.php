<?php

declare(strict_types=1);

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

final class ManagedDocumentWriterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Exact-body HMAC middleware authenticates this internal route.
    }

    public function rules(): array
    {
        return [
            'schema_version' => 'required|integer|in:1', 'event_id' => 'required|string|max:255',
            'action' => 'required|in:acquire,release,uncertain',
            'source_id' => 'required|string|max:191', 'job_id' => 'required|string|max:191',
            'workflow_id' => 'required|string|max:191', 'run_id' => 'required|string|max:191',
            'activity_id' => 'required|string|max:191', 'attempt' => 'required|integer|min:1',
            'targets' => 'present|array|max:1000', 'targets.*.doc_id' => 'required|string|max:191',
            'targets.*.relative_path' => 'required|string|max:2048',
            'targets.*.graph' => 'sometimes|boolean',
        ];
    }
}
