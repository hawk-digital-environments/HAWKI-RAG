<?php

declare(strict_types=1);

namespace App\Http\Requests\Graph;

use Illuminate\Foundation\Http\FormRequest;

class SearchGraphRequest extends FormRequest
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
            'q' => ['required', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function queryText(): string
    {
        return (string) $this->validated('q');
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 12);
    }
}
