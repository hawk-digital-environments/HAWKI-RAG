<?php

declare(strict_types=1);

namespace App\Http\Requests\Document;

use App\Http\Requests\Concerns\InteractsWithManagedDocumentInput;
use App\Services\Document\Repositories\ManagedDocumentRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateManagedDocumentRequest extends FormRequest
{
    use InteractsWithManagedDocumentInput;

    public function authorize(ManagedDocumentRepository $documents): bool
    {
        abort_if($documents->find((string) $this->route('documentId')) === null, 404, 'Document was not found.');

        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|max:102400',
            'source_updated_at' => [
                Rule::requiredIf(! $this->forceUpdate()),
                'date',
            ],
            'source_checksum_sha256' => 'nullable|string|size:64|regex:/\A[a-fA-F0-9]{64}\z/',
            'graph' => 'nullable',
            'display_name' => 'nullable|string|max:255',
            'source_url' => 'nullable|url|max:2048',
            'metadata_json' => 'nullable',
            'force' => 'nullable',
        ];
    }
}
