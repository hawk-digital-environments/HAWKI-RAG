<?php

declare(strict_types=1);

namespace App\Http\Controllers\Document;

use App\Http\Requests\Document\ManagedDeletionWriterProofRequest;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\ManagedDeletionWriterProofService;
use Illuminate\Http\JsonResponse;

readonly class ManagedDeletionWriterProofController
{
    public function __construct(private ManagedDeletionWriterProofService $proofs) {}

    public function __invoke(ManagedDeletionWriterProofRequest $request): JsonResponse
    {
        try {
            return new JsonResponse($this->proofs->prove($request->validated(), $request->getContent()));
        } catch (ManagedDeletionException) {
            return new JsonResponse(['verified' => false], 409);
        } catch (\Throwable) {
            return new JsonResponse(['verified' => false], 503);
        }
    }
}
