<?php

declare(strict_types=1);

namespace App\Http\Requests\Document;

use App\Http\Requests\Concerns\InteractsWithManagedDocumentInput;
use App\Services\Authorization\DatasetIngestionAuthorizationService;
use App\Services\Document\Repositories\ManagedDocumentRepository;
use Illuminate\Foundation\Http\FormRequest;

abstract class MutateManagedDocumentRequest extends FormRequest
{
    use InteractsWithManagedDocumentInput;

    public function authorize(ManagedDocumentRepository $documents, DatasetIngestionAuthorizationService $authorization): bool
    {
        $document = $documents->find((string) $this->route('documentId'));
        abort_if($document === null, 404, 'Document was not found.');
        $authorization->authorize($this->user(), $document->dataset_id);

        return true;
    }
}
