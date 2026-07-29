<?php

declare(strict_types=1);

namespace App\Http\Requests\Dataset;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class GrantSelfDatasetQueryAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('access-query-principal');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function authenticatedUser(): User
    {
        /** @var User $user */
        $user = $this->user();

        return $user;
    }
}
