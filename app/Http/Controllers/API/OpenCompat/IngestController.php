<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\OpenCompat;

use App\Http\Controllers\Controller;
use App\Services\OpenCompat\OpenCompatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngestController extends Controller
{
    public function __construct(private readonly OpenCompatService $compat) {}

    public function text(Request $request): JsonResponse
    {
        $input = $request->validate([
            'text' => 'required|string',
            'id' => 'sometimes|string|max:255',
            'document_id' => 'sometimes|string|max:255',
            'documentId' => 'sometimes|string|max:255',
            'name' => 'sometimes|string|max:255',
            'filename' => 'sometimes|string|max:255',
            'metadata' => 'sometimes|array',
            'collection' => 'sometimes|string|max:255',
            'dataset_id' => 'sometimes|string|max:255',
            'datasetId' => 'sometimes|string|max:255',
            'folder_name' => 'sometimes|string|max:255',
            'provider' => 'sometimes|string|max:80',
            'graph' => 'sometimes|boolean',
            'chunk_chars' => 'sometimes|integer|min:100|max:20000',
            'chunk_size' => 'sometimes|integer|min:100|max:20000',
            'chunk_overlap' => 'sometimes|integer|min:0|max:10000',
            'embedding_model' => 'sometimes|string|max:255',
            'distance' => 'sometimes|string|max:40',
            'batch_size' => 'sometimes|integer|min:1|max:1000',
            'graph_engine' => 'sometimes|string|max:80',
            'graph_model' => 'sometimes|string|max:255',
            'graph_only' => 'sometimes|boolean',
            'dry_run' => 'sometimes|boolean',
        ]);

        return $this->json($this->compat->ingestText($input, $request->header('Idempotency-Key')));
    }

    public function file(Request $request): JsonResponse
    {
        $input = $request->validate([
            'file' => 'required|file',
            'collection' => 'sometimes|string|max:255',
            'dataset_id' => 'sometimes|string|max:255',
            'datasetId' => 'sometimes|string|max:255',
            'folder_name' => 'sometimes|string|max:255',
            'graph' => 'sometimes|boolean',
            'converter_mode' => 'sometimes|string|max:40',
            'converterMode' => 'sometimes|string|max:40',
            'converter_url' => 'sometimes|url|max:500',
            'converterUrl' => 'sometimes|url|max:500',
            'converter_token' => 'sometimes|string|max:1000',
            'converterToken' => 'sometimes|string|max:1000',
            'converter_start_path' => 'sometimes|string|max:255',
            'converterStartPath' => 'sometimes|string|max:255',
        ]);

        return $this->json($this->compat->ingestFile($input, $request->file('file')));
    }

    public function files(Request $request): JsonResponse
    {
        $input = $request->validate([
            'files' => 'required|array|min:1|max:20',
            'files.*' => 'file',
            'collection' => 'sometimes|string|max:255',
            'dataset_id' => 'sometimes|string|max:255',
            'datasetId' => 'sometimes|string|max:255',
            'folder_name' => 'sometimes|string|max:255',
            'graph' => 'sometimes|boolean',
        ]);

        return $this->json($this->compat->ingestFiles($request->file('files', []), $input));
    }

    public function requeue(Request $request): JsonResponse
    {
        return $this->json($this->compat->requeue($request->all()));
    }

    public function documentQuery(): JsonResponse
    {
        return $this->json($this->compat->unsupported(
            'ingest/document/query',
            'RAWKI does not currently expose a temporary document query workflow without persistence.',
        ));
    }

    /**
     * @param array{payload: array<string, mixed>, status: int} $result
     */
    private function json(array $result): JsonResponse
    {
        return response()->json($result['payload'], $result['status']);
    }
}
