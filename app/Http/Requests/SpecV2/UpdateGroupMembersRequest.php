<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateGroupMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'add' => 'nullable|array',
            'add.*' => 'string|max:255',
            'remove' => 'nullable|array',
            'remove.*' => 'string|max:255',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $add = $this->input('add', []);
                $remove = $this->input('remove', []);

                if (($add === null || $add === []) && ($remove === null || $remove === [])) {
                    $validator->errors()->add('add', 'At least one user must be added or removed.');
                }
            },
        ];
    }
}
