<?php
declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use Illuminate\Foundation\Http\FormRequest;

class StopDirectIngestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pid' => 'sometimes|integer|min:1',
            'pids' => 'sometimes|array',
            'pids.*' => 'integer|min:1',
            'mode' => 'sometimes|string|in:default,neo4j',
        ];
    }
}
