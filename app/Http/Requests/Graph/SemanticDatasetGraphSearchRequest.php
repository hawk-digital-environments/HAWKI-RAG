<?php

declare(strict_types=1);

namespace App\Http\Requests\Graph;

use Illuminate\Foundation\Http\FormRequest;

class SemanticDatasetGraphSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'dataset_id' => 'required|string|max:191',
            'q' => 'required|string|max:500',
            'limit' => 'sometimes|integer|min:1|max:25',
        ];
    }

    public function datasetId(): string
    {
        return trim((string) $this->validated('dataset_id'));
    }

    public function queryText(): string
    {
        return trim((string) $this->validated('q'));
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 8);
    }
}
