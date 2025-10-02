<?php

namespace App\Models;

use GuzzleHttp\Client;
use RuntimeException;

class QdrantEmbedding
{
    /** @var array<string,mixed> */
    protected array $cfg;

    protected Client $http;
    protected string $collection;
    protected ?string $defaultDistance;

    public function __construct(?string $collectionOverride = null)
    {
        $this->cfg = config('model_provider.vector_stores.qdrant', []);

        $scheme  = $this->cfg['scheme']     ?? 'http';
        $host    = $this->cfg['host']       ?? '127.0.0.1';
        $port    = (int)($this->cfg['port'] ?? 6333);
        $timeout = (int)($this->cfg['timeout'] ?? 30);

        $this->collection     = $collectionOverride ?: ($this->cfg['collection'] ?? 'embeddings_hawk');
        $this->defaultDistance = $this->cfg['distance'] ?? 'Cosine';

        $baseUri = "{$scheme}://{$host}:{$port}";
        $headers = [];

        // Optional API key
        if (!empty($this->cfg['api_key'])) {
            $headers['api-key'] = $this->cfg['api_key'];
        }

        $this->http = new Client([
            'base_uri' => $baseUri,
            'timeout'  => $timeout,
            'headers'  => $headers,
        ]);
    }

    /* ===================== Collection lifecycle ===================== */

    public function ensureCollection(int $vectorSize, ?string $distance = null): void
    {
        $distance = $distance ?: $this->defaultDistance ?: 'Cosine';

        // Try GET collection
        try {
            $res = $this->http->get("/collections/{$this->collection}");
            if ($res->getStatusCode() === 200) {
                return;
            }
        } catch (\Throwable $e) {
            // proceed to create
        }

        // Create if not exists
        $res = $this->http->put("/collections/{$this->collection}", [
            'json' => [
                'vectors' => [
                    'size'     => $vectorSize,
                    'distance' => $distance, // Cosine | Dot | Euclid
                ],
            ],
        ]);

        if ($res->getStatusCode() >= 300) {
            throw new RuntimeException("Failed to create collection {$this->collection}");
        }
    }

    /* ===================== Upsert / Delete ===================== */

    /**
     * @param array<int,array{id:string|int,vector:float[],payload:array}> $points
     */
    public function upsert(array $points): void
    {
        if (empty($points)) return;

        $res = $this->http->put("/collections/{$this->collection}/points", [
            'json' => ['points' => $points],
        ]);

        if ($res->getStatusCode() >= 300) {
            throw new RuntimeException('Qdrant upsert failed');
        }
    }

    /**
     * @param array<int,string|int> $ids
     */
    public function deleteByIds(array $ids): void
    {
        if (empty($ids)) return;

        $res = $this->http->post("/collections/{$this->collection}/points/delete", [
            'json' => ['points' => array_values($ids)],
        ]);

        if ($res->getStatusCode() >= 300) {
            throw new RuntimeException('Qdrant delete failed');
        }
    }

    /* ===================== Search (optional helper) ===================== */

    /**
     * @param float[] $vector
     */
    public function search(array $vector, int $topK = 5, ?array $filter = null): array
    {
        $body = [
            'vector'       => array_values($vector),
            'limit'        => $topK,
            'with_payload' => true,
            'with_vector'  => false,
        ];
        if ($filter) $body['filter'] = $filter;

        $res = $this->http->post("/collections/{$this->collection}/points/search", [
            'json' => $body,
        ]);

        if ($res->getStatusCode() >= 300) {
            throw new RuntimeException('Qdrant search failed');
        }

        return json_decode((string)$res->getBody(), true);
    }

    /* ===================== Mappers (if you still use them) ===================== */

    /**
     * Build a Qdrant point from crawled page data (no SQL), mirroring your SQL-ish payload fields.
     *
     * @param array $meta      Decoded page.json
     * @param string $text     Markdown/text content for embedding
     * @param array $vector    Embedding vector
     * @param string|int $id   Qdrant point id (e.g., '00042')
     * @param array $extra     Optional extra payload fields to merge
     * @return array{id:string|int, vector:float[], payload:array}
     */
    public static function pointFromCrawl(array $meta, string $text, array $vector, string|int $id, array $extra = []): array
    {
        $payload = array_merge([
            // SQL-like names
            'title'                   => $meta['title'] ?? null,
            'content_chars'           => mb_strlen($text),
            'meta_img_url'            => $meta['metaImageUrl'] ?? null,
            'page_url'                => $meta['url'] ?? null,
            'source_url'              => $meta['url'] ?? null,
            'source_format'           => 'markdown',
            'date'                    => $meta['date'] ?? null,
            'tags'                    => null,
            'intermediate_formatting' => null,

            // crawl extras
            'images'     => array_values($meta['images'] ?? []),
            'pdfs'       => array_values($meta['pdfs'] ?? []),

            // common extras
            'hash'        => sha1($text),
            'chunk_index' => 0,
            'parent_id'   => (string) $id,
        ], $extra);

        return [
            'id'      => $id,
            'vector'  => array_map('floatval', $vector),
            'payload' => $payload,
        ];
    }
}
