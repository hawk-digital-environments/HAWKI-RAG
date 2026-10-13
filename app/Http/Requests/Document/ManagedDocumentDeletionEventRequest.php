<?php

declare(strict_types=1);

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

final class ManagedDocumentDeletionEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        // This route requires exact-body worker HMAC middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'schema_version' => 'required|integer|in:1', 'event_id' => 'required|string|max:255',
            'operation_id' => ['required', 'string', 'regex:/^delete_[0-9a-f]{64}$/'],
            'workflow_id' => 'required|string|max:191', 'run_id' => 'required|string|max:255',
            'status' => 'required|string|in:completed,failed', 'results' => 'present|array|max:1000',
            'results.*' => 'required|array', 'results.*.output_id' => 'required|integer|min:1',
            'results.*.qdrant' => 'sometimes|array', 'results.*.neo4j' => 'sometimes|array',
        ];
    }
}
