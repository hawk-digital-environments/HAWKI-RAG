<?php

declare(strict_types=1);

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class ManagedDeletionWriterProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Exact-body HMAC middleware authenticates this internal read.
    }

    public function rules(): array
    {
        return array_fill_keys(['operation_id', 'document_id', 'dataset_id', 'deletion_workflow_id', 'deletion_run_id', 'writer_workflow_id', 'writer_run_id', 'targets_sha256'], ['required', 'string', 'max:255']);
    }
}
