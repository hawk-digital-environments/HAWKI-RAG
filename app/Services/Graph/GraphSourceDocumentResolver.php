<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Models\ManagedDocument;
use App\Models\ManagedDocumentOutput;
use App\Services\Document\ManagedDocumentPayloadBuilder;
use App\Services\Document\Repositories\ManagedDocumentRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class GraphSourceDocumentResolver
{
    public function __construct(
        private ManagedDocumentRepository $documents,
        private ManagedDocumentPayloadBuilder $payloads,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    public function attachToNodes(array $nodes): array
    {
        $docIds = [];
        foreach ($nodes as $node) {
            foreach ($this->stringList($node['source_document_ids'] ?? []) as $docId) {
                $docIds[] = $docId;
            }
        }

        $documents = $this->documentsByBridgeId($docIds);

        foreach ($nodes as &$node) {
            $label = $this->stringValue($node['label'] ?? null);
            $node['source_documents'] = array_map(
                function (string $docId) use ($documents, $label): array {
                    $document = $documents->get($docId);

                    return is_array($document)
                        ? $this->sourceDocument($document['model'], $document['payload'], $docId, $label)
                        : [
                            'docId' => $docId,
                            'label' => $docId,
                            'missing' => true,
                        ];
                },
                $this->stringList($node['source_document_ids'] ?? []),
            );
        }
        unset($node);

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceDocument(ManagedDocument $document, array $payload, string $docId, ?string $entityLabel): array
    {
        $preview = $this->stringValue($payload['markdown_preview'] ?? null) ?? '';

        return [
            'docId' => $docId,
            'documentId' => $document->documentId()->value,
            'datasetId' => $document->dataset_id,
            'label' => $this->displayLabel($document, $payload, $docId),
            'title' => $this->stringValue($payload['title'] ?? null),
            'sourceType' => $document->source_type,
            'sourceUrl' => $document->source_url,
            'originalFilename' => $this->stringValue($payload['original_filename'] ?? null),
            'localPath' => $this->stringValue($payload['local_path'] ?? null),
            'markdownPreviewPath' => $this->stringValue($payload['markdown_preview_path'] ?? null),
            'markdownSnippet' => $this->markdownSnippet($preview, $entityLabel),
        ];
    }

    /**
     * @param  list<string>  $docIds
     * @return Collection<string, array{model:ManagedDocument,payload:array<string,mixed>}>
     */
    private function documentsByBridgeId(array $docIds): Collection
    {
        $wanted = array_fill_keys($docIds, true);
        $resolved = collect();

        foreach ($this->documents->forActiveBridgeDocumentIds($docIds) as $document) {
            $payload = $this->payloads->build($document, includeDetails: true);

            foreach ($document->outputs as $output) {
                if (! $output instanceof ManagedDocumentOutput || ! $output->active) {
                    continue;
                }

                $bridgeDocumentId = $this->stringValue($output->bridge_document_id);
                if ($bridgeDocumentId === null || ! isset($wanted[$bridgeDocumentId])) {
                    continue;
                }

                $resolved->put($bridgeDocumentId, [
                    'model' => $document,
                    'payload' => $payload,
                ]);
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function displayLabel(ManagedDocument $document, array $payload, string $docId): string
    {
        return $this->stringValue($payload['original_filename'] ?? null)
            ?? $this->stringValue($payload['title'] ?? null)
            ?? $this->stringValue($document->display_name)
            ?? $this->stringValue($document->source_url)
            ?? $this->stringValue($payload['local_path'] ?? null)
            ?? $docId;
    }

    private function markdownSnippet(string $content, ?string $needle): ?string
    {
        $content = $this->validUtf8($content);
        $needle = $this->stringValue($needle);
        if ($content === '' || ! $needle) {
            return null;
        }

        $position = mb_stripos($content, $needle, 0, 'UTF-8');
        if ($position === false) {
            return null;
        }

        $start = max(0, $position - 180);
        $contentLength = mb_strlen($content, 'UTF-8');
        $snippet = mb_substr($content, $start, 420, 'UTF-8');
        $snippet = preg_replace('/\s+/u', ' ', $snippet) ?? $snippet;
        $snippet = trim($snippet);

        if ($start > 0) {
            $snippet = '...'.$snippet;
        }
        if ($start + 420 < $contentLength) {
            $snippet .= '...';
        }

        return $snippet;
    }

    private function validUtf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }
        if ($value === null || $value === '') {
            return [];
        }

        return [(string) $value];
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
