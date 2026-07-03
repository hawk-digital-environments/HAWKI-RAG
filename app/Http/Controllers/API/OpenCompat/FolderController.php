<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\OpenCompat;

use App\Http\Controllers\Controller;
use App\Services\OpenCompat\OpenCompatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    public function __construct(private readonly OpenCompatService $compat) {}

    public function create(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'sometimes|string|max:1000',
            'folder_id' => 'sometimes|string|max:255',
            'folderId' => 'sometimes|string|max:255',
        ]);

        return $this->json($this->compat->createFolder($input));
    }

    public function list(Request $request): JsonResponse
    {
        return $this->json($this->compat->listFolders((int) $request->query('limit', 100)));
    }

    public function details(Request $request): JsonResponse
    {
        $folderId = (string) ($request->input('folder_id') ?? $request->input('folderId') ?? $request->input('name', ''));

        return $this->json($this->compat->showFolder($folderId));
    }

    public function show(string $folderId): JsonResponse
    {
        return $this->json($this->compat->showFolder($folderId));
    }

    public function delete(string $folderId): JsonResponse
    {
        return $this->json($this->compat->deleteFolder($folderId));
    }

    public function listSummaries(): JsonResponse
    {
        return $this->json($this->compat->unsupported(
            'folders/summary',
            'RAWKI maps folders to datasets only for list/create/show; hierarchy, summaries, movement, and membership mutation require a real folder model.',
        ));
    }

    public function summary(string $folderId): JsonResponse
    {
        return $this->unsupported($folderId.'/summary');
    }

    public function attachDocument(string $folderId, string $documentId): JsonResponse
    {
        return $this->unsupported($folderId.'/documents/'.$documentId);
    }

    public function detachDocument(string $folderId, string $documentId): JsonResponse
    {
        return $this->unsupported($folderId.'/documents/'.$documentId);
    }

    public function move(string $folderId): JsonResponse
    {
        return $this->unsupported($folderId.'/move');
    }

    private function unsupported(string $endpoint): JsonResponse
    {
        return $this->json($this->compat->unsupported(
            'folders/'.$endpoint,
            'RAWKI maps folders to datasets only for list/create/show; hierarchy, summaries, movement, and membership mutation require a real folder model.',
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
