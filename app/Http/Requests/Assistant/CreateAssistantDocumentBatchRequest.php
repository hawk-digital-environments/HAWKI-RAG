<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistant;

use Illuminate\Http\UploadedFile;

class CreateAssistantDocumentBatchRequest extends AssistantDocumentRequest
{
    public function rules(): array
    {
        return [
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|max:102400',
            'dataset_id' => 'required|string|max:160|regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,159}\z/',
            'graph' => 'nullable',
            'metadata_json' => 'nullable',
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploadedFiles(): array
    {
        $files = $this->file('files', []);
        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile));
    }
}
