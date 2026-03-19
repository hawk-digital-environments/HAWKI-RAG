<?php

declare(strict_types=1);

function benchmark_env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function benchmark_env_int(string $key, int $default): int
{
    return (int) benchmark_env($key, $default);
}

function benchmark_env_bool(string $key, bool $default): bool
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}

function benchmark_env_list(string $key, array $default): array
{
    $value = getenv($key);
    if ($value === false || trim((string) $value) === '') {
        return $default;
    }

    return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
}

$rootDir = dirname(__DIR__);
$provider = benchmark_env('RAG_TEST_EMBEDDING_PROVIDER', 'ollama');
$apiBase = benchmark_env('RAG_TEST_EMBEDDING_API_BASE', 'http://localhost:11434');

return [
    'package' => [
        'name' => 'rag_test',
        'version' => '1.0.0',
        'python' => '3.11+',
    ],
    'qdrant' => [
        'url' => benchmark_env('RAG_TEST_QDRANT_URL', 'http://localhost:6333'),
        'api_key' => benchmark_env('RAG_TEST_QDRANT_API_KEY', ''),
        'timeout_seconds' => benchmark_env_int('RAG_TEST_QDRANT_TIMEOUT', 30),
    ],
    'neo4j' => [
        'uri' => benchmark_env('RAG_TEST_NEO4J_URI', 'bolt://localhost:7687'),
        'user' => benchmark_env('RAG_TEST_NEO4J_USER', 'neo4j'),
        'password' => benchmark_env('RAG_TEST_NEO4J_PASSWORD', ''),
        'database' => benchmark_env('RAG_TEST_NEO4J_DATABASE', 'neo4j'),
    ],
    'collections' => [
        'prefix' => benchmark_env('RAG_TEST_COLLECTION_PREFIX', 'ragtest'),
        'distance' => benchmark_env('RAG_TEST_DISTANCE', 'Cosine'),
        'top_k' => benchmark_env_int('RAG_TEST_TOP_K', 10),
        'recreate' => benchmark_env_bool('RAG_TEST_RECREATE_COLLECTIONS', false),
        'naming_pattern' => '{prefix}_{collection_suffix}',
    ],
    'reranker' => [
        'enabled' => true,
        'provider' => benchmark_env('RAG_TEST_RERANKER_PROVIDER', 'backend'),
        'api_base' => benchmark_env('RAG_TEST_RERANKER_API_BASE', ''),
        'api_key' => benchmark_env('RAG_TEST_RERANKER_API_KEY', ''),
        'mode' => benchmark_env('RAG_TEST_RERANKER_MODE', 'cosine'),
    ],
    'benchmark' => [
        'random_seed' => benchmark_env_int('RAG_TEST_RANDOM_SEED', 42),
        'source_volume_path' => benchmark_env('RAG_TEST_SOURCE_VOLUME_PATH', '/var/lib/docker/volumes/rawki_shared_storage/_data'),
        'random_folder_count' => benchmark_env_int('RAG_TEST_RANDOM_FOLDER_COUNT', 10),
        'chunk_size' => benchmark_env_int('RAG_TEST_CHUNK_SIZE', 1200),
        'chunk_overlap' => benchmark_env_int('RAG_TEST_CHUNK_OVERLAP', 150),
        'embed_batch_size' => benchmark_env_int('RAG_TEST_EMBED_BATCH_SIZE', 16),
        'max_files_per_folder' => benchmark_env_int('RAG_TEST_MAX_FILES_PER_FOLDER', 200),
        'phase_1_query_target_min' => benchmark_env_int('RAG_TEST_PHASE1_MIN_QUERIES', 100),
        'phase_1_query_target_max' => benchmark_env_int('RAG_TEST_PHASE1_MAX_QUERIES', 300),
        'phase_2_entity_cases_min' => benchmark_env_int('RAG_TEST_PHASE2_ENTITY_MIN', 50),
        'phase_2_entity_cases_max' => benchmark_env_int('RAG_TEST_PHASE2_ENTITY_MAX', 100),
        'phase_2_neighbor_cases_target' => benchmark_env_int('RAG_TEST_PHASE2_NEIGHBOR_TARGET', 50),
        'graph_k_values' => benchmark_env_list('RAG_TEST_GRAPH_K_VALUES', ['1', '3', '5']),
    ],
    'models' => [
        'bge-m3' => [
            'internal_key' => 'bge-m3',
            'display_name' => 'BGE-M3',
            'provider' => benchmark_env('RAG_TEST_BGE_M3_PROVIDER', $provider),
            'model_name' => benchmark_env('RAG_TEST_BGE_M3_MODEL', 'bge-m3'),
            'api_base' => benchmark_env('RAG_TEST_BGE_M3_API_BASE', $apiBase),
            'api_key' => benchmark_env('RAG_TEST_BGE_M3_API_KEY', benchmark_env('RAG_TEST_EMBEDDING_API_KEY', '')),
            'collection_suffix' => benchmark_env('RAG_TEST_BGE_M3_COLLECTION_SUFFIX', 'bge_m3'),
            'embedding_mode' => 'aligned',
            'enabled' => benchmark_env_bool('RAG_TEST_BGE_M3_ENABLED', true),
        ],
        'qwen3-embedding' => [
            'internal_key' => 'qwen3-embedding',
            'display_name' => 'Qwen3-Embedding',
            'provider' => benchmark_env('RAG_TEST_QWEN3_EMBEDDING_PROVIDER', $provider),
            'model_name' => benchmark_env('RAG_TEST_QWEN3_EMBEDDING_MODEL', 'qwen3-embedding'),
            'api_base' => benchmark_env('RAG_TEST_QWEN3_EMBEDDING_API_BASE', $apiBase),
            'api_key' => benchmark_env('RAG_TEST_QWEN3_EMBEDDING_API_KEY', benchmark_env('RAG_TEST_EMBEDDING_API_KEY', '')),
            'collection_suffix' => benchmark_env('RAG_TEST_QWEN3_EMBEDDING_COLLECTION_SUFFIX', 'qwen3_embedding'),
            'embedding_mode' => 'aligned',
            'enabled' => benchmark_env_bool('RAG_TEST_QWEN3_EMBEDDING_ENABLED', true),
        ],
        'nomic-embed-text' => [
            'internal_key' => 'nomic-embed-text',
            'display_name' => 'Nomic-Embed-Text',
            'provider' => benchmark_env('RAG_TEST_NOMIC_EMBED_TEXT_PROVIDER', $provider),
            'model_name' => benchmark_env('RAG_TEST_NOMIC_EMBED_TEXT_MODEL', 'nomic-embed-text'),
            'api_base' => benchmark_env('RAG_TEST_NOMIC_EMBED_TEXT_API_BASE', $apiBase),
            'api_key' => benchmark_env('RAG_TEST_NOMIC_EMBED_TEXT_API_KEY', benchmark_env('RAG_TEST_EMBEDDING_API_KEY', '')),
            'collection_suffix' => benchmark_env('RAG_TEST_NOMIC_EMBED_TEXT_COLLECTION_SUFFIX', 'nomic_embed_text'),
            'embedding_mode' => 'aligned',
            'enabled' => benchmark_env_bool('RAG_TEST_NOMIC_EMBED_TEXT_ENABLED', true),
        ],
    ],
    'paths' => [
        'root_dir' => $rootDir,
        'data_test' => $rootDir . '/data_test',
        'results' => $rootDir . '/results',
        'logs' => $rootDir . '/logs',
        'benchmark_queries' => $rootDir . '/benchmark/queries',
        'benchmark_gold' => $rootDir . '/benchmark/gold',
    ],
];
