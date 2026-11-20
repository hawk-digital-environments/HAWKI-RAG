<?php

declare(strict_types=1);

namespace App\Http\Requests\Integration;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class DeleteTextIngestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => 'required|string|max:191|regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/',
        ];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }

    public function authenticatedUser(): User
    {
        /** @var User $user */
        $user = $this->user();

        return $user;
    }
}
