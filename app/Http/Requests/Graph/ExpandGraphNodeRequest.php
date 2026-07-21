<?php

declare(strict_types=1);

namespace App\Http\Requests\Graph;

use Illuminate\Foundation\Http\FormRequest;

class ExpandGraphNodeRequest extends FormRequest
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
            'depth' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'limit' => ['sometimes', 'integer', 'min:5', 'max:250'],
        ];
    }

    public function nodeId(): string
    {
        return (string) $this->validated('node_id');
    }

    public function depth(): int
    {
        return (int) ($this->validated('depth') ?? 1);
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 80);
    }
}
