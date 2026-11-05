<?php

declare(strict_types=1);

namespace App\Http\Controllers\Document;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\ManagedDocumentDeletionEventRequest;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\ManagedDocumentOutputDeletionService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;

final class ManagedDocumentDeletionEventController extends Controller
{
    public function __invoke(ManagedDocumentDeletionEventRequest $request, ManagedDocumentOutputDeletionService $deletions, ConfigRepository $config): JsonResponse
    {
        $backend = $config->get('config.managed_document_backend', 'laravel');
        if ($backend !== 'laravel') {
            // Python performs its own durable sink cleanup. A callback from an
            // old Laravel deletion workflow must be reconciled before cutover.
            return response()->json(['success' => false, 'message' => 'Legacy managed deletion ownership requires reconciliation.'], $backend === 'python' ? 409 : 503);
        }
        $receipt = $request->validated();
        try {
            $operation = $deletions->applyReceipt($receipt);
        } catch (ManagedDeletionException $error) {
            return response()->json(['success' => false, 'message' => $error->getMessage()], 409);
        }

        return response()->json(['success' => true, 'status' => $operation->status]);
    }
}
