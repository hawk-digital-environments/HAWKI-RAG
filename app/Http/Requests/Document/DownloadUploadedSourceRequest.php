<?php

declare(strict_types=1);

namespace App\Http\Requests\Document;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class DownloadUploadedSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'source_url' => ['required', 'string', 'max:2048', 'starts_with:upload://'],
            'content_hash' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function sourceUrl(): string
    {
        return (string) $this->query('source_url', '');
    }

    public function contentHash(): ?string
    {
        $value = $this->query('content_hash');

        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'A valid upload:// source URL is required.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
