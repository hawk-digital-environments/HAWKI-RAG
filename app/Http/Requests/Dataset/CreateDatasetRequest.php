<?php

declare(strict_types=1);

namespace App\Http\Requests\Dataset;

use App\Services\Dataset\DatasetBackend;
use Illuminate\Foundation\Http\FormRequest;

class CreateDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'dataset_id' => 'nullable|string|max:191',
            'datasetId' => 'nullable|string|max:191',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|max:64',
            'qdrant_collection' => 'nullable|string|max:191',
            'qdrantCollection' => 'nullable|string|max:191',
            'neo4j_namespace' => 'nullable|string|max:191',
            'neo4jNamespace' => 'nullable|string|max:191',
        ];

        // The Python authority derives one tenant-aware physical scope from the
        // authenticated tenant and the logical dataset id. In Python mode a
        // caller may not select or reserve either physical target. Laravel mode
        // keeps the historical validated-input contract.
        if (app(DatasetBackend::class)->usesPython()) {
            foreach (['qdrant_collection', 'qdrantCollection', 'neo4j_namespace', 'neo4jNamespace'] as $field) {
                $rules[$field] = ['prohibited'];
            }
        }

        return $rules;
    }
}
