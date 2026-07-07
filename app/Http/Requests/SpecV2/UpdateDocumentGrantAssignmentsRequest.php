<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateDocumentGrantAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'add_users' => 'sometimes|array|max:250',
            'add_users.*' => 'string|max:255',
            'remove_users' => 'sometimes|array|max:250',
            'remove_users.*' => 'string|max:255',
            'groups' => 'prohibited',
            'add' => 'prohibited',
            'remove' => 'prohibited',
            'add_groups' => 'prohibited',
            'remove_groups' => 'prohibited',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['add_users', 'remove_users'] as $field) {
                $value = $this->input($field);
                if (is_array($value) && $value !== []) {
                    return;
                }
            }

            $validator->errors()->add('add_users', 'Provide at least one document grant delta.');
        });
    }
}
