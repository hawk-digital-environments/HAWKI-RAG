<?php
declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use Illuminate\Foundation\Http\FormRequest;

class StartDirectIngestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => 'required|string',
            'collection' => 'sometimes|string',
            'provider' => 'sometimes|string',
            'embedding_model' => 'sometimes|string',
            'graph' => 'sometimes|boolean',
            'graph_engine' => 'sometimes|string',
            'graph_model' => 'sometimes|string',
            'neo4j_database' => 'sometimes|string',
            'graph_only' => 'sometimes|boolean',
            'chunk_chars' => 'sometimes|integer',
            'chunk_overlap' => 'sometimes|integer',
            'batch' => 'sometimes|integer',
            'timeout' => 'sometimes|integer',
            'resume_mode' => 'sometimes|string|in:resume,start',
            'job_id' => 'sometimes|string',
        ];
    }
}
