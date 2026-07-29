<?php

declare(strict_types=1);

namespace App\Http\Requests\Document;

class CreateManagedDocumentRequest extends ManagedDocumentRequest
{
    public function rules(): array
    {
        return [
            'file' => 'required|file|max:102400',
            'dataset_id' => 'required|string|max:160|regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,159}\z/',
            'source_updated_at' => 'nullable|date',
            'source_checksum_sha256' => 'nullable|string|size:64|regex:/\A[a-fA-F0-9]{64}\z/',
            'graph' => 'nullable',
            'display_name' => 'nullable|string|max:255',
            'source_url' => 'nullable|url|max:2048',
            'metadata_json' => 'nullable',
        ];
    }
}
