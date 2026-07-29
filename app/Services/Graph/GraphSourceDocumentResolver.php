<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Models\Document;
use App\Services\Document\DocumentMarkdownPreviewReader;
use App\Services\Document\DocumentRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class GraphSourceDocumentResolver
{
    public function __construct(
        private DocumentRepository $documents,
        private DocumentMarkdownPreviewReader $previews,
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

        $documents = $this->documents->findByExternalIds($docIds)
            ->keyBy(fn (Document $document): string => (string) $document->external_id);

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
