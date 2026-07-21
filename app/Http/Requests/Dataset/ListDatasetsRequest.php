<?php

declare(strict_types=1);

namespace App\Http\Requests\Dataset;

use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\Http\FormRequest;

class ListDatasetsRequest extends FormRequest
{
    public function authorize(GateContract $gate): bool
    {
        $user = $this->user();

        if ($user !== null && ! $user instanceof User) {
            return false;
        }

        return $user === null
            ? $gate->allows('access-operator')
            : $gate->forUser($user)->allows('access-operator');
    }

    public function rules(): array
    {
        return [
            'limit' => 'nullable|integer|min:1|max:250',
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 50);
    }
}
