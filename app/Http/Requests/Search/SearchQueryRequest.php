<?php

declare(strict_types=1);

namespace App\Http\Requests\Search;

use Illuminate\Foundation\Http\FormRequest;

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
            'top_k' => 'prohibited',
            'k' => 'prohibited',
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
        $data['limit'] = (int) ($data['limit'] ?? 5);

        return $data;
    }
}
