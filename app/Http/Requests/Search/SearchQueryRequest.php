<?php

declare(strict_types=1);

namespace App\Http\Requests\Search;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class SearchQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'query' => 'required|string|max:4000',
            'limit' => 'sometimes|integer|min:1|max:100',
            'top_k' => 'sometimes|integer|min:1|max:100',
            'k' => 'sometimes|integer|min:1|max:100',
            'filters' => 'sometimes|array',
            'user_identifier' => 'sometimes|string|max:255',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();
        $limits = array_filter([
            'limit' => $data['limit'] ?? null,
            'top_k' => $data['top_k'] ?? null,
            'k' => $data['k'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        if (count(array_unique(array_map('intval', $limits))) > 1) {
            throw ValidationException::withMessages([
                'limit' => ['Provide only one search limit value. limit, top_k, and k must match when multiple aliases are sent.'],
            ]);
        }

        $data['limit'] = (int) ($data['limit'] ?? $data['top_k'] ?? $data['k'] ?? 5);
        unset($data['top_k'], $data['k']);

        return $data;
    }
}
