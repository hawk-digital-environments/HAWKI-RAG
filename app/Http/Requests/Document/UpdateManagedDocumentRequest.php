<?php

declare(strict_types=1);

namespace App\Http\Requests\Document;

use Illuminate\Validation\Rule;

class UpdateManagedDocumentRequest extends ManagedDocumentRequest
{
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
