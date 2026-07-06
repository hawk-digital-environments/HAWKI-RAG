<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGrantAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'add' => 'sometimes|array|max:250',
            'add.*' => 'string|max:191',
            'remove' => 'sometimes|array|max:250',
            'remove.*' => 'string|max:191',
        ];
    }
}
