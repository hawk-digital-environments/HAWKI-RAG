<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\Document;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class DocumentGraphStatsService
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function stats(Document $document, array $metadata): array
    {
        $documentIds = $this->documentIds($document, $metadata);
        if ($documentIds === []) {
            return [
                'ok' => false,
                'nodes' => null,
                'relationships' => null,
                'error' => 'Document has no graph document id.',
            ];
        }

        $baseUrl = rtrim((string) $this->config->get('config.neo4j_http_url', 'http://hawki_rag_neo4j:7474'), '/');
        $database = trim((string) $this->config->get('config.neo4j_database', 'neo4j')) ?: 'neo4j';
        $endpoint = $baseUrl.'/db/'.rawurlencode($database).'/tx/commit';

        try {
            $response = $this->http->timeout(4)
                ->withBasicAuth((string) $this->config->get('config.neo4j_user', 'neo4j'), (string) $this->config->get('config.neo4j_password', ''))
                ->post($endpoint, [
                    'statements' => [[
                        'statement' => <<<'CYPHER'
MATCH (n)
WHERE n.doc_id IN $document_ids
   OR any(doc_id IN $document_ids WHERE doc_id IN coalesce(n.doc_ids, []))
WITH count(DISTINCT n) AS nodes
MATCH ()-[r]->()
WHERE r.doc_id IN $document_ids
   OR any(doc_id IN $document_ids WHERE doc_id IN coalesce(r.doc_ids, []))
RETURN nodes, count(DISTINCT r) AS relationships
CYPHER,
                        'parameters' => [
                            'document_ids' => $documentIds,
                        ],
                    ]],
                ]);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'nodes' => null,
                    'relationships' => null,
                    'error' => 'Neo4j HTTP '.$response->status(),
                ];
            }

            $errors = $response->json('errors') ?? [];
            if ($errors !== []) {
                return [
                    'ok' => false,
                    'nodes' => null,
                    'relationships' => null,
                    'error' => $errors[0]['message'] ?? 'Neo4j query failed.',
                ];
            }

            $row = $response->json('results.0.data.0.row') ?? [0, 0];

            return [
                'ok' => true,
                'nodes' => (int) ($row[0] ?? 0),
                'relationships' => (int) ($row[1] ?? 0),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'nodes' => null,
                'relationships' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @return list<string>
     */
    private function documentIds(Document $document, array $metadata): array
    {
        $documentIds = [
            $this->stringValue($document->external_id),
            $this->stringValue($metadata['document_id'] ?? null),
            $this->stringValue($metadata['doc_id'] ?? null),
        ];

        $summaryDocIds = $metadata['bridge_response']['summary']['documents']['doc_ids'] ?? [];
        if (is_array($summaryDocIds)) {
            foreach ($summaryDocIds as $docId) {
                $documentIds[] = $this->stringValue($docId);
            }
        }

        $perDoc = $metadata['bridge_response']['summary']['graph_preview']['per_doc'] ?? [];
        if (is_array($perDoc)) {
            foreach (array_keys($perDoc) as $docId) {
                $documentIds[] = $this->stringValue($docId);
            }
        }

        return array_values(array_unique(array_filter($documentIds)));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
