<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

use App\Models\SpecV2\Heap;
use App\Rules\DisallowReservedMetadataKeys;
use Illuminate\Foundation\Http\FormRequest;

class UpdateHeapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'visibility' => 'sometimes|string|in:'.Heap::VISIBILITY_DISCOVERABLE.','.Heap::VISIBILITY_HIDDEN,
            'metadata' => ['sometimes', 'nullable', 'array', new DisallowReservedMetadataKeys],
        ];
    }
}
