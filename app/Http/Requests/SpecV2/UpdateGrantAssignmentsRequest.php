<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'add_users' => 'sometimes|array|max:250',
            'add_users.*' => 'string|max:255',
            'remove_users' => 'sometimes|array|max:250',
            'remove_users.*' => 'string|max:255',
            'add_groups' => 'sometimes|array|max:250',
            'add_groups.*' => 'string|max:191',
            'remove_groups' => 'sometimes|array|max:250',
            'remove_groups.*' => 'string|max:191',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['add', 'remove', 'add_users', 'remove_users', 'add_groups', 'remove_groups'] as $field) {
                $value = $this->input($field);
                if (is_array($value) && $value !== []) {
                    return;
                }
            }

            $validator->errors()->add('add_users', 'Provide at least one grant delta.');
        });
    }
}
