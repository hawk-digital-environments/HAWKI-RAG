<?php

declare(strict_types=1);

namespace App\Http\Requests\Dataset;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class GrantSelfDatasetIngestionAccessRequest extends FormRequest
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
        return [
            'grant_token' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function grantToken(): string|null
    {
        $token = $this->input('grant_token');

        return \is_string($token) && $token !== '' ? $token : null;
    }

    public function authenticatedUser(): User
    {
        /** @var User $user */
        $user = $this->user();

        return $user;
    }
}
