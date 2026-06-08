<?php
declare(strict_types=1);

namespace App\Http\Requests\Dataset;

use Illuminate\Foundation\Http\FormRequest;

class ListDatasetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
