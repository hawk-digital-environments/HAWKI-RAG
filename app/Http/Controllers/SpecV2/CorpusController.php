<?php
declare(strict_types=1);

namespace App\Http\Controllers\SpecV2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpecV2\PaginatedSpecRequest;
use App\Services\SpecV2\Exceptions\CorpusNotFoundException;
use App\Services\SpecV2\SpecV2Service;
use Illuminate\Http\JsonResponse;

class CorpusController extends Controller
{
    public function __construct(
        private readonly SpecV2Service $spec,
    ) {}

    public function index(PaginatedSpecRequest $request): JsonResponse
    {
        return response()->json($this->spec->corpora->list($request->page(), $request->perPage()));
    }

    public function show(string $corpusId): JsonResponse
    {
        try {
            return response()->json($this->spec->corpora->show($corpusId));
        } catch (CorpusNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }
}
