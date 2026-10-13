<?php

declare(strict_types=1);

namespace App\Http\Requests\Document;

class DeleteManagedDocumentRequest extends MutateManagedDocumentRequest
{
    public function rules(): array
    {
        return [];
    }
}
