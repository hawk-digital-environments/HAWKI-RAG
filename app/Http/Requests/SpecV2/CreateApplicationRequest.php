<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

use App\Models\SpecV2\Application;
use Illuminate\Foundation\Http\FormRequest;

class CreateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'nullable|string|max:191',
            'tenant_id' => 'nullable|string|max:191',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|in:'.implode(',', Application::allowedPermissions()),
            'metadata' => 'nullable|array',
        ];
    }
}
