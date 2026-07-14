<?php

declare(strict_types=1);

namespace App\Http\Requests\Rag;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ListAuthorizedQueryDatasetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && ! (bool) $user->isRemoved;
    }

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
