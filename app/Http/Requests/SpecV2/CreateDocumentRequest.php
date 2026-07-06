<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class CreateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'sometimes|string|max:255',
            'document_id' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'file' => 'sometimes|file|max:102400',
            'metadata' => 'sometimes|array',
            'source_url' => 'sometimes|string|max:1000',
            'title' => 'sometimes|string|max:255',
            'filename' => 'sometimes|string|max:255',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasContent = array_key_exists('content', $this->all());
            $hasFile = $this->uploadedFile() instanceof UploadedFile;

            if (! $hasContent && ! $hasFile) {
                $validator->errors()->add('content', 'Provide either document content or a file upload.');

                return;
            }

            if ($hasContent && $hasFile) {
                $validator->errors()->add('file', 'Provide either document content or a file upload, not both.');
            }
        });
    }

    public function uploadedFile(): ?UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : null;
    }
}
