<?php
declare(strict_types=1);

namespace App\Http\Requests\SpecV2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReplaceGrantAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'users' => 'sometimes|array|max:250',
            'users.*' => 'string|max:255',
            'groups' => 'sometimes|array|max:250',
            'groups.*' => 'string|max:191',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $users = $this->input('users', []);
            $groups = $this->input('groups', []);

            if ((is_array($users) && $users !== []) || (is_array($groups) && $groups !== [])) {
                return;
            }

            $validator->errors()->add('users', 'Provide at least one granted user or group.');
        });
    }
}
