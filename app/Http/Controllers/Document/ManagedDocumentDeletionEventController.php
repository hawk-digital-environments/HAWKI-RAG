<?php

declare(strict_types=1);

namespace App\Http\Controllers\Document;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\ManagedDocumentDeletionEventRequest;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\ManagedDocumentOutputDeletionService;
use Illuminate\Http\JsonResponse;

final class ManagedDocumentDeletionEventController extends Controller
{
    public function __invoke(ManagedDocumentDeletionEventRequest $request, ManagedDocumentOutputDeletionService $deletions): JsonResponse
    {
        $receipt = $request->validated();
        try {
            $operation = $deletions->applyReceipt($receipt);
        } catch (ManagedDeletionException $error) {
            return response()->json(['success' => false, 'message' => $error->getMessage()], 409);
        }

        return response()->json(['success' => true, 'status' => $operation->status]);
    }
}
