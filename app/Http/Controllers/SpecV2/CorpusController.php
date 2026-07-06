<?php
declare(strict_types=1);

namespace App\Http\Controllers\SpecV2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpecV2\PaginatedSpecRequest;
use App\Http\Resources\SpecV2\CorpusCollection;
use App\Http\Resources\SpecV2\CorpusResource;
use App\Services\SpecV2\SpecV2Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorpusController extends Controller
{
    public function __construct(
        private readonly SpecV2Service $spec,
    ) {}

    public function index(PaginatedSpecRequest $request): JsonResponse
    {
        return response()->json(
            (new CorpusCollection($this->spec->corpora->list($request->page(), $request->perPage())))
                ->resolve($request)
        );
    }

    public function show(Request $request, string $corpusId): JsonResponse
    {
        return response()->json((new CorpusResource($this->spec->corpora->show($corpusId)))->resolve($request));
    }
}
