<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Services\Authorization\BrowserQueryPrincipalService;
use App\Services\Document\Clients\PythonManagedDocumentClient;
use App\Services\Document\Repositories\ManagedDocumentRepository;
use App\Services\Document\Values\ManagedDocumentId;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

#[Singleton]
readonly class ManagedDocumentReadBoundary
{
    public function __construct(
        private ConfigRepository $config,
        private BrowserQueryPrincipalService $principals,
        private PythonManagedDocumentClient $python,
        private ManagedDocumentRepository $documents,
        private ManagedDocumentSyncService $sync,
        private ManagedDocumentPayloadBuilder $payloads,
    ) {}

    /** Null retains the existing Laravel read contract. */
    public function read(Request $request, ?string $documentId = null, array $filters = [], int $limit = 100): ?array
    {
        $backend = $this->config->get('config.managed_document_backend', 'laravel');
        if ($backend === 'laravel') {
            return null;
        }
        if ($backend !== 'python') {
            return $this->failure(503, 'Managed document backend configuration is invalid.');
        }
        $user = $this->principals->resolve($request);
        if ($user === null) {
            return $this->failure(401, 'Unauthenticated.');
        }
        $result = $this->python->read($user, $documentId, [...$filters, 'limit' => $limit]);
        if ($documentId !== null) {
            if ($result['status'] !== 404) {
                return $result;
            }
            if (! ManagedDocumentId::looksLike($documentId)) {
                return $this->notFound($documentId);
            }
            $legacy = $this->documents->find($documentId);
            if ($legacy === null) {
                return $this->notFound($documentId);
            }
            $access = $this->python->legacyReadAccess($user, [['document_id' => $documentId, 'dataset_id' => $legacy->dataset_id]]);
            if ($access['status'] !== 200) {
                return $access;
            }
            if (! in_array($documentId, $access['payload']['legacy_document_ids'], true)) {
                return $this->notFound($documentId);
            }

            return ['status' => 200, 'payload' => ['success' => true, 'document' => $this->payloads->build($this->sync->sync($legacy))]];
        }
        if ($result['status'] !== 200) {
            return $result;
        }
        $rows = $result['payload']['documents'];
        // Python already returns its authoritative top N. Every matching
        // legacy candidate must be authorized and synchronized before sorting:
        // raw legacy timestamps cannot bound an unseen candidate's final rank.
        foreach ($this->documents->readCandidates($filters)->chunk(250) as $candidates) {
            $access = $this->python->legacyReadAccess($user, $candidates->map(static fn ($doc): array => ['document_id' => $doc->document_id, 'dataset_id' => $doc->dataset_id])->values()->all());
            if ($access['status'] !== 200) {
                return $access;
            }
            $allowed = array_fill_keys($access['payload']['legacy_document_ids'], true);
            foreach ($candidates as $legacy) {
                if (isset($allowed[$legacy->document_id])) {
                    $rows[] = $this->payloads->build($this->sync->sync($legacy), includeDetails: false);
                }
            }
        }
        // One lexicographic ordering for both owners: each timestamp DESC,
        // missing timestamps last at each comparison, then document ID ASC.
        usort($rows, static function (array $left, array $right): int {
            foreach (['indexed_at', 'updated_at', 'created_at'] as $field) {
                $a = empty($left[$field]) ? -INF : (float) CarbonImmutable::parse($left[$field])->format('U.u');
                $b = empty($right[$field]) ? -INF : (float) CarbonImmutable::parse($right[$field])->format('U.u');
                if ($a !== $b) {
                    return $b <=> $a;
                }
            }

            return strcmp($left['document_id'], $right['document_id']);
        });

        return ['status' => 200, 'payload' => ['success' => true, 'documents' => array_slice($rows, 0, $limit)]];
    }

    private function notFound(string $documentId): array
    {
        return $this->failure(404, "Document {$documentId} was not found.");
    }

    private function failure(int $status, string $message): array
    {
        return ['status' => $status, 'payload' => ['success' => false, 'message' => $message]];
    }
}
