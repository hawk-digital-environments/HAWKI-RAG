<?php

declare(strict_types=1);

namespace App\Http\Requests\Graph;

use Illuminate\Foundation\Http\FormRequest;

class ShowGraphOverviewRequest extends FormRequest
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
            'limit' => ['sometimes', 'integer', 'min:5', 'max:300'],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 80);
    }
}
