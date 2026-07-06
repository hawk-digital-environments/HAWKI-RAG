<?php
declare(strict_types=1);

namespace App\Http\Requests\Dataset;

use Illuminate\Foundation\Http\FormRequest;

class CreateDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'heap_id' => 'nullable|string|max:191',
            'heapId' => 'nullable|string|max:191',
            'dataset_id' => 'nullable|string|max:191',
            'datasetId' => 'nullable|string|max:191',
            'tenant_id' => 'nullable|string|max:191',
            'owner_application_id' => 'nullable|string|max:191',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|max:64',
            'visibility' => 'nullable|string|max:32',
            'protected' => 'nullable|boolean',
            'metadata' => 'nullable|array',
            'metadata_json' => 'nullable|array',
            'qdrant_collection' => 'nullable|string|max:191',
            'qdrantCollection' => 'nullable|string|max:191',
            'neo4j_namespace' => 'nullable|string|max:191',
            'neo4jNamespace' => 'nullable|string|max:191',
        ];
    }
}
