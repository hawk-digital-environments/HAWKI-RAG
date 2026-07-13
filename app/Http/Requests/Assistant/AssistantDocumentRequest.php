<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistant;

use App\Http\Requests\Concerns\InteractsWithManagedDocumentInput;
use Illuminate\Foundation\Http\FormRequest;

abstract class AssistantDocumentRequest extends FormRequest
{
    use InteractsWithManagedDocumentInput;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function assistantInput(bool $defaultGraph = false): array
    {
        return $this->managedInput($defaultGraph);
    }
}
