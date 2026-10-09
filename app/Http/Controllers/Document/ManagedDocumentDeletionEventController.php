<?php

declare(strict_types=1);

namespace App\Http\Controllers\Document;

use App\Http\Controllers\Controller;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\ManagedDocumentOutputDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagedDocumentDeletionEventController extends Controller
{
    public function __invoke(Request $request, ManagedDocumentOutputDeletionService $deletions): JsonResponse
    {
        $receipt = $request->validate([
            'schema_version' => 'required|integer|in:1', 'event_id' => 'required|string|max:255',
            'operation_id' => ['required', 'string', 'regex:/^delete_[0-9a-f]{64}$/'],
            'workflow_id' => 'required|string|max:191', 'run_id' => 'required|string|max:255',
            'status' => 'required|string|in:completed,failed', 'results' => 'present|array|max:1000',
        ]);
        try {
            $operation = $deletions->applyReceipt($receipt);
        } catch (ManagedDeletionException $error) {
            return response()->json(['success' => false, 'message' => $error->getMessage()], 409);
        }
        return response()->json(['success' => true, 'status' => $operation->status]);
    }
}
