<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Services\Document\Clients\PythonManagedDocumentClient;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

#[Singleton]
readonly class ManagedDocumentBoundary
{
    public function __construct(private ConfigRepository $config, private PythonManagedDocumentClient $python) {}

    /** Null selects the legacy authority. All other results are final. */
    public function forward(Request $request, string $purpose, array $input = [], array $files = [], ?string $documentId = null): ?array
    {
        $backend = $this->config->get('config.managed_document_backend', 'laravel');
        if ($backend === 'laravel') {
            return null;
        }
        if ($backend !== 'python') {
            return ['status' => 503, 'payload' => ['success' => false, 'message' => 'Managed document backend configuration is invalid.']];
        }
        $key = $request->header('Idempotency-Key');

        return $this->python->mutate($request->user(), $purpose, $input, $files, is_string($key) ? $key : null, $documentId);
    }

    public function managedInput(Request $request, bool $batch = false): array
    {
        $normalized = $request->managedInput(false);
        $input = [];
        foreach (['dataset_id', 'display_name', 'source_url', 'source_updated_at', 'source_checksum_sha256', 'metadata_json', 'force'] as $field) {
            if ($request->exists($field)) {
                $value = $normalized[$field];
                $input[$field] = $value instanceof \DateTimeInterface ? $value->format(\DateTimeInterface::ATOM) : $value;
            }
        }
        if ($request->exists('graph')) {
            $input['graph'] = $normalized['graph_enabled'];
        }

        return $input;
    }
}
