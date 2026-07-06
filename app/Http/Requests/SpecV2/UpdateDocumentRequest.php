<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => 'sometimes|string',
            'metadata' => 'sometimes|array',
            'source_url' => 'sometimes|string|max:1000',
            'title' => 'sometimes|string|max:255',
            'filename' => 'sometimes|string|max:255',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $validated = $this->all();

            foreach (['content', 'metadata', 'source_url', 'title', 'filename'] as $field) {
                if (array_key_exists($field, $validated)) {
                    return;
                }
            }

            $validator->errors()->add('content', 'Provide at least one document field to update.');
        });
    }
}
