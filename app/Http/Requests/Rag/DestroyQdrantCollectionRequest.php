<?php

declare(strict_types=1);

namespace App\Http\Requests\Rag;

use Illuminate\Foundation\Http\FormRequest;

class DestroyQdrantCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return [
            ...parent::validationData(),
            'collection' => $this->route('collection'),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'collection' => [
                'required',
                'string',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/',
            ],
        ];
    }

    public function collection(): string
    {
        return (string) $this->validated('collection');
    }
}
