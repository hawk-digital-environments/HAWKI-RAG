<?php
declare(strict_types=1);

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class ListDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'heap_id' => 'nullable|string|max:191',
            'heapId' => 'nullable|string|max:191',
            'user_identifier' => 'nullable|string|max:255',
            'q' => 'nullable|string|max:255',
            'search' => 'nullable|string|max:255',
            'limit' => 'nullable|integer|min:1|max:250',
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 100);
    }

    public function filters(): array
    {
        $validated = $this->validated();
        unset($validated['limit'], $validated['user_identifier']);

        return $validated;
    }

    public function userIdentifier(): ?string
    {
        $value = $this->validated('user_identifier');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
