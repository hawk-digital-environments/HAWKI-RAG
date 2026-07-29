<?php

declare(strict_types=1);

namespace App\Http\Requests\Graph;

use Illuminate\Foundation\Http\FormRequest;

class ShowGraphNodeRequest extends FormRequest
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
            'node_id' => ['required', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:5', 'max:250'],
        ];
    }

    public function nodeId(): string
    {
        return (string) $this->validated('node_id');
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 80);
    }
}
