<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

use Illuminate\Foundation\Http\FormRequest;

class ListHeapDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:250',
            'q' => 'sometimes|string|max:1000',
            'search' => 'sometimes|string|max:1000',
        ];
    }

    public function page(): int
    {
        return max(1, (int) $this->validated('page', 1));
    }

    public function perPage(): int
    {
        return max(1, min(250, (int) $this->validated('per_page', 25)));
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter([
            'search' => $this->validated('search') ?? $this->validated('q'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
