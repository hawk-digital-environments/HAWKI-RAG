<?php

declare(strict_types=1);

namespace App\Http\Controllers\Document;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\ManagedDocumentWriterRequest;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\ManagedDocumentWriterService;
use App\Services\Pipeline\CrawlerCallbackBoundary;
use App\Services\TextIngestion\Clients\PythonTextIngestionClient;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;

final class ManagedDocumentWriterController extends Controller
{
    public function __invoke(ManagedDocumentWriterRequest $request, ManagedDocumentWriterService $writers, PythonTextIngestionClient $python, ConfigRepository $config, CrawlerCallbackBoundary $managed): JsonResponse
    {
        if ($config->get('config.crawler_ingestion_backend', 'laravel') !== 'laravel') {
            $result = $managed->relay((string) $request->validated('workflow_id'), (string) $request->getContent(), 'writers');
            if ($result !== null) {
                return response()->json($result['payload'], $result['status']);
            }
            try {
                return response()->json($writers->record($request->validated()));
            } catch (ManagedDeletionException $error) {
                return response()->json(['success' => false, 'message' => $error->getMessage()], 409);
            }
        }
        // Signature middleware already verified these exact bytes. The Python
        // owner alone records migrated text writer/fence state.
        if (str_starts_with((string) $request->validated('workflow_id'), 'ingest-text-')) {
            $backend = $config->get('config.text_ingestion_backend', 'laravel');
            if ($backend === 'python') {
                $result = $python->relayWriter((string) $request->getContent());

                return response()->json($result['payload'], $result['status']);
            }
            if ($backend !== 'laravel') {
                return response()->json(['success' => false, 'message' => 'Text ingestion is not available.'], 503);
            }
        }
        if (! str_starts_with((string) $request->validated('workflow_id'), 'ingest-text-')) {
            $result = $managed->relay((string) $request->validated('workflow_id'), (string) $request->getContent(), 'writers');
            if ($result !== null) {
                return response()->json($result['payload'], $result['status']);
            }
        }
        try {
            return response()->json($writers->record($request->validated()));
        } catch (ManagedDeletionException $error) {
            return response()->json(['success' => false, 'message' => $error->getMessage()], 409);
        }
    }
}
