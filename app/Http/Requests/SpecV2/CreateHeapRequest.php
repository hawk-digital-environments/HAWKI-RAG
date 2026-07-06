<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

use App\Models\SpecV2\Heap;
use App\Rules\DisallowReservedMetadataKeys;
use Illuminate\Foundation\Http\FormRequest;

class CreateHeapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'nullable|string|max:191',
            'heap_id' => 'nullable|string|max:191',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'owner_application_id' => 'nullable|string|max:191',
            'visibility' => 'nullable|string|in:'.Heap::VISIBILITY_DISCOVERABLE.','.Heap::VISIBILITY_HIDDEN,
            'protected' => 'nullable|boolean',
            'metadata' => ['nullable', 'array', new DisallowReservedMetadataKeys],
        ];
    }
}
