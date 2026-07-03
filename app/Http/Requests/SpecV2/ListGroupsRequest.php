<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

class ListGroupsRequest extends PaginatedSpecRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'tenant_id' => 'nullable|string|max:191',
            'owner_application_id' => 'nullable|string|max:191',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $filters = [];

        foreach (['tenant_id', 'owner_application_id'] as $field) {
            if (isset($validated[$field]) && is_string($validated[$field]) && trim($validated[$field]) !== '') {
                $filters[$field] = trim($validated[$field]);
            }
        }

        return $filters;
    }
}
