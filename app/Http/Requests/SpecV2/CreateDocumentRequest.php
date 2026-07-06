<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

use Illuminate\Foundation\Http\FormRequest;

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
            'content' => 'required|string',
            'metadata' => 'sometimes|array',
            'source_url' => 'sometimes|string|max:1000',
            'title' => 'sometimes|string|max:255',
            'filename' => 'sometimes|string|max:255',
        ];
    }
}
