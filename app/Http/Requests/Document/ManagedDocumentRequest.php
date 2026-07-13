<?php

declare(strict_types=1);

namespace App\Http\Requests\Document;

use App\Http\Requests\Concerns\InteractsWithManagedDocumentInput;
use Illuminate\Foundation\Http\FormRequest;

abstract class ManagedDocumentRequest extends FormRequest
{
    use InteractsWithManagedDocumentInput;

    public function authorize(): bool
    {
        return true;
    }
}
