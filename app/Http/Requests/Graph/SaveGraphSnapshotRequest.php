<?php

declare(strict_types=1);

namespace App\Http\Requests\Graph;

use Illuminate\Foundation\Http\FormRequest;

class SaveGraphSnapshotRequest extends FormRequest
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
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'scene' => ['required', 'array'],
        ];
    }

    /**
     * @return array{name?: string|null, scene: array<mixed>}
     */
    public function snapshot(): array
    {
        return $this->validated();
    }
}
