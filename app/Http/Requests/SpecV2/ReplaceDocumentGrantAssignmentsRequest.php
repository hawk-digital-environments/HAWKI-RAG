<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

use Illuminate\Foundation\Http\FormRequest;

class ReplaceDocumentGrantAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'users' => 'required|array|min:1|max:250',
            'users.*' => 'string|max:255',
            'groups' => 'prohibited',
        ];
    }
}
