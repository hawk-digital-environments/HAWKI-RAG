<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Models\Document;
use App\Services\Document\DocumentMarkdownPreviewReader;
use App\Services\Document\DocumentRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class GraphSourceDocumentResolver
{
    public function __construct(
        private DocumentRepository $documents,
        private DocumentMarkdownPreviewReader $previews,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $nodes
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

        try {
            $documents = $this->documents->findByExternalIds($docIds)
                ->keyBy(fn (Document $document): string => (string) $document->external_id);
        } catch (QueryException $e) {
            $this->logger->warning('Graph source document lookup skipped because documents storage is unavailable.', [
                'exception' => $e,
            ]);
            $documents = collect();
        }

        foreach ($nodes as &$node) {
            $label = $this->stringValue($node['label'] ?? null);
            $node['source_documents'] = array_map(
                function (string $docId) use ($documents, $label): array {
                    /** @var Document|null $document */
                    $document = $documents->get($docId);

                    return $document
                        ? $this->sourceDocument($document, $docId, $label)
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
    private function sourceDocument(Document $document, string $docId, ?string $entityLabel): array
    {
        $preview = $this->previews->preview($document->storage_path);

        return [
            'docId' => $docId,
            'documentId' => $document->id,
            'datasetId' => $document->dataset_id,
            'label' => $this->displayLabel($document, $docId),
            'title' => $document->title,
            'sourceType' => $document->source_type,
            'sourceUrl' => $document->source_url,
            'originalFilename' => $document->original_filename,
            'localPath' => $document->storage_path,
            'markdownPreviewPath' => $preview['path'],
            'markdownSnippet' => $this->markdownSnippet($preview['content'], $entityLabel),
        ];
    }

    private function displayLabel(Document $document, string $docId): string
    {
        return $this->stringValue($document->original_filename)
            ?? $this->stringValue($document->title)
            ?? $this->stringValue($document->source_url)
            ?? $this->stringValue($document->storage_path)
            ?? $docId;
    }

    private function markdownSnippet(string $content, ?string $needle): ?string
    {
        $needle = $this->stringValue($needle);
        if ($content === '' || ! $needle) {
            return null;
        }

        $position = stripos($content, $needle);
        if ($position === false) {
            return null;
        }

        $start = max(0, $position - 180);
        $snippet = substr($content, $start, 420);
        $snippet = preg_replace('/\s+/u', ' ', $snippet) ?? $snippet;
        $snippet = trim($snippet);

        if ($start > 0) {
            $snippet = '...'.$snippet;
        }
        if ($start + 420 < strlen($content)) {
            $snippet .= '...';
        }

        return $snippet;
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
