<?php

declare(strict_types=1);

namespace App\Http\Controllers\Document;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\ManagedDocumentWriterRequest;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\ManagedDocumentWriterService;
use Illuminate\Http\JsonResponse;

final class ManagedDocumentWriterController extends Controller
{
    public function __invoke(ManagedDocumentWriterRequest $request, ManagedDocumentWriterService $writers): JsonResponse
    {
        try {
            return response()->json($writers->record($request->validated()));
        } catch (ManagedDeletionException $error) {
            return response()->json(['success' => false, 'message' => $error->getMessage()], 409);
        }
    }
}
