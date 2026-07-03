<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

use App\Models\SpecV2\Heap;

class ListHeapsRequest extends PaginatedSpecRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'tenant_id' => 'nullable|string|max:191',
            'owner_application_id' => 'nullable|string|max:191',
            'visibility' => 'nullable|string|in:'.Heap::VISIBILITY_DISCOVERABLE.','.Heap::VISIBILITY_HIDDEN,
            'protected' => 'nullable|boolean',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $filters = [];

        foreach (['tenant_id', 'owner_application_id', 'visibility'] as $field) {
            if (isset($validated[$field]) && is_string($validated[$field]) && trim($validated[$field]) !== '') {
                $filters[$field] = trim($validated[$field]);
            }
        }

        if (array_key_exists('protected', $validated)) {
            $filters['protected'] = (bool) $validated['protected'];
        }

        return $filters;
    }
}
