<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

use Illuminate\Foundation\Http\FormRequest;

class CreateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'nullable|string|max:191|regex:/^[a-z0-9_-]+$/',
            'name' => 'required|string|max:255',
            'owner_application_id' => 'nullable|string|max:191',
            'description' => 'nullable|string',
            'metadata' => 'nullable|array',
        ];
    }
}
