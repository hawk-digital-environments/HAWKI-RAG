<?php
declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use Illuminate\Foundation\Http\FormRequest;

class ShowDirectIngestStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => 'nullable|string',
        ];
    }

    public function mode(): string
    {
        $mode = (string) ($this->validated('mode') ?? $this->query('mode', 'default'));

        return in_array($mode, ['default', 'neo4j'], true) ? $mode : 'default';
    }
}
