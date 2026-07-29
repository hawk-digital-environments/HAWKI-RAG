<?php

declare(strict_types=1);

namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class HealthCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'timeout' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }

    public function timeout(): ?int
    {
        $timeout = $this->validated('timeout');

        return $timeout === null ? null : (int) $timeout;
    }
}
