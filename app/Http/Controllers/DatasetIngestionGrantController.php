<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Dataset\GrantSelfDatasetIngestionAccessRequest;
use App\Services\Dataset\DatasetAuthority;
use Illuminate\Http\JsonResponse;

final class DatasetIngestionGrantController extends Controller
{
    public function store(
        GrantSelfDatasetIngestionAccessRequest $request,
        DatasetAuthority $datasets,
        string $datasetId,
    ): JsonResponse {
        $result = $datasets->grantIngestSelf(
            $request->authenticatedUser(),
            $datasetId,
            $request->grantToken(),
        );

        return response()->json($result['payload'], $result['status']);
    }
}
