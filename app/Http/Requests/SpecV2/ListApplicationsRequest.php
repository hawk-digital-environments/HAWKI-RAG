<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

class ListApplicationsRequest extends PaginatedSpecRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'tenant_id' => 'nullable|string|max:191',
        ];
    }

    public function tenantId(): ?string
    {
        $tenantId = $this->validated('tenant_id');

        return is_string($tenantId) && trim($tenantId) !== '' ? trim($tenantId) : null;
    }
}
