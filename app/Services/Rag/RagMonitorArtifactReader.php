<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Models\RagGraphFailure;
use App\Models\RagIngestionArtifact;
use App\Services\Rag\Repositories\RagMonitorArtifactRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class RagMonitorArtifactReader
{
    public function __construct(
        private RagMonitorArtifactRepository $artifacts,
    ) {}

    /** @return array{path:string,updated_at:string,data:array<string,mixed>}|null */
    public function latestSummary(): ?array
    {
        return $this->envelope($this->artifacts->latest(), 'summary');
    }

    /** @return array{path:string,updated_at:string,data:array<string,mixed>}|null */
    public function latestGraphPreview(): ?array
    {
        return $this->envelope($this->artifacts->latestWithGraphPreview(), 'graph_preview');
    }

    /** @return list<array<string, mixed>> */
    public function graphFailures(int $limit): array
    {
        return $this->artifacts->latestFailures($limit)
            ->map(fn (RagGraphFailure $failure): array => $this->failurePayload($failure))
            ->values()
            ->all();
    }

    /**
     * @param  'summary'|'graph_preview'  $column
     * @return array{path:string,updated_at:string,data:array<string,mixed>}|null
     */
    private function envelope(?RagIngestionArtifact $artifact, string $column): ?array
    {
        if ($artifact === null) {
            return null;
        }

        $data = $artifact->getAttribute($column);
        if (! is_array($data)) {
            return null;
        }

        return [
            'path' => sprintf('postgresql://rag_ingestion_artifacts/%s/%s', $artifact->getKey(), $column),
            'updated_at' => $artifact->occurred_at->toAtomString(),
            'data' => $data,
        ];
    }

    /** @return array<string, mixed> */
    private function failurePayload(RagGraphFailure $failure): array
    {
        $context = is_array($failure->context) ? $failure->context : [];

        return array_merge($context, [
            'doc_id' => $failure->document_id,
            'error' => $failure->message,
            'timestamp' => $failure->occurred_at->toAtomString(),
        ]);
    }
}
