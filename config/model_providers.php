<?php

$csv = static function (string $name, string $default): array {
    $value = env($name, $default);

    return array_values(array_unique(array_filter(array_map(
        static fn (string $item): string => trim($item),
        explode(',', is_string($value) ? $value : $default),
    ))));
};

$secretConfigured = static fn (string $name): bool => trim((string) env($name, '')) !== '';

// Canonical, provider-prefixed model configuration: the prefix routes the
// request in the Python runtime (see hawki-model-providers), so one set of
// keys serves direct Ollama, a LiteLLM gateway, and hosted backends.
$canonicalEmbeddingModel = trim((string) env('EMBED_MODEL', 'ollama/bge-m3'));
$canonicalChatModel = trim((string) env('CHAT_MODEL', 'ollama_chat/llama3.1:8b'));
$canonicalVisionModel = trim((string) env('VISION_MODEL', 'ollama_chat/qwen2.5vl:7b'));
$canonicalEmbeddingModel = $canonicalEmbeddingModel !== '' ? $canonicalEmbeddingModel : 'ollama/bge-m3';
$canonicalChatModel = $canonicalChatModel !== '' ? $canonicalChatModel : 'ollama_chat/llama3.1:8b';
$canonicalVisionModel = $canonicalVisionModel !== '' ? $canonicalVisionModel : 'ollama_chat/qwen2.5vl:7b';

$displayModel = static fn (string $model): string => str_contains($model, '/')
    ? explode('/', $model, 2)[1]
    : $model;
$canonicalOptions = static fn (array $models): array => array_map(
    static fn (string $model): array => ['value' => $model, 'label' => $displayModel($model)],
    $models,
);

// One canonical allowlist per capability feeds both provider surfaces: the
// Settings UI menus and the gateway's rendered route list.
$embeddingModels = array_values(array_unique(array_merge(
    [$canonicalEmbeddingModel],
    $csv('EMBED_MODELS', $canonicalEmbeddingModel),
)));
$chatModels = array_values(array_unique(array_merge(
    [$canonicalChatModel],
    $csv('CHAT_MODELS', $canonicalChatModel),
)));
$visionModels = array_values(array_unique(array_merge(
    [$canonicalVisionModel],
    $csv('VISION_MODELS', $canonicalVisionModel),
)));

return [

    /*
    |--------------------------------------------------------------------------
    |   Model Providers
    |--------------------------------------------------------------------------
    |
    |   List of model providers suggested for the project.
    |   Direct Ollama and the LiteLLM gateway are the two runtimes, selected
    |   through the required RAG_PROVIDER key. Provider failures never
    |   trigger silent fallback.
    |
    */
    'providers' => [
        'litellm' => [
            'label' => 'LiteLLM Gateway',
            'description' => 'Optional OpenAI-compatible gateway serving the canonical provider-prefixed models from the allowlists.',
            'configuration_mode' => 'environment',
            'model_selection_mode' => 'settings',
            'runtime_supported' => true,
            'api_url' => env('LITELLM_API_URL', 'http://litellm:4000/v1'),
            'endpoints' => [
                'embedding' => 'embeddings',
                'chat' => 'chat/completions',
            ],
            'models' => [
                'embedding' => env('EMBED_MODEL', 'ollama/bge-m3'),
                'graph' => env('CHAT_MODEL', 'ollama_chat/llama3.1:8b'),
                'text' => env('CHAT_MODEL', 'ollama_chat/llama3.1:8b'),
                'multimodal' => env('VISION_MODEL', 'ollama_chat/qwen2.5vl:7b'),
                'rag' => env('CHAT_MODEL', 'ollama_chat/llama3.1:8b'),
            ],
            'allowed_models' => [
                'graph' => $chatModels,
                'embedding' => $embeddingModels,
                'vision' => $visionModels,
            ],
            'model_placeholders' => [
                'graph' => 'ollama_chat/llama3.1:8b, openai/gpt-4.1-mini, or anthropic/claude-sonnet-4-5-20250929',
                'embedding' => 'ollama/bge-m3 or openai/text-embedding-3-small',
                'vision' => 'ollama_chat/qwen2.5vl:7b or openai/gpt-4.1-mini',
            ],
            'model_options' => [
                'graph' => $canonicalOptions($chatModels),
                'embedding' => $canonicalOptions($embeddingModels),
                'vision' => $canonicalOptions($visionModels),
            ],
            'environment_variables' => [
                [
                    'name' => 'LITELLM_API_URL',
                    'placeholder' => 'http://litellm:4000/v1',
                    'description' => 'Gateway URL used by the Python RAG runtime.',
                    'secret' => false,
                    'configured' => true,
                ],
                [
                    'name' => 'LITELLM_API_KEY',
                    'placeholder' => 'sk-hawki-proxy-key',
                    'description' => 'Optional bearer key when LiteLLM proxy authentication is enabled.',
                    'secret' => true,
                    'configured' => $secretConfigured('LITELLM_API_KEY'),
                ],
                [
                    'name' => 'GRAPH_EMBEDDING_DIMENSIONS',
                    'placeholder' => 'ollama/bge-m3=1024,ollama/nomic-embed-text=768',
                    'description' => 'Trusted model dimensions (provider-prefixed names) used when graph-only ingestion has not observed an embedding response.',
                    'secret' => false,
                    'configured' => true,
                ],
                [
                    'name' => 'CHAT_MODELS',
                    'placeholder' => 'ollama_chat/llama3.1:8b,openai/gpt-4.1-mini',
                    'description' => 'Gateway routes render at container startup from the canonical keys and these allowlists.',
                    'secret' => false,
                    'configured' => true,
                ],
            ],
        ],
        'ollama' => [
            'label' => 'Ollama (Direct)',
            'description' => 'Default local runtime. Python connects directly to Ollama without requiring the LiteLLM gateway.',
            'configuration_mode' => 'environment',
            'model_selection_mode' => 'settings',
            'runtime_supported' => true,
            'api_url' => env('OLLAMA_API_URL', 'http://hawki_ollama:11434/api'),
            'models' => [
                'embedding' => $canonicalEmbeddingModel,
                'graph' => $canonicalChatModel,
                'text' => $canonicalChatModel,
                'multimodal' => $canonicalVisionModel,
                'rag' => $canonicalChatModel,
            ],
            'allowed_models' => [
                'graph' => $chatModels,
                'embedding' => $embeddingModels,
                'vision' => $visionModels,
            ],
            'model_placeholders' => [
                'graph' => 'ollama_chat/llama3.1:8b',
                'embedding' => 'ollama/bge-m3',
                'vision' => 'ollama_chat/qwen2.5vl:7b',
            ],
            'model_options' => [
                'graph' => $canonicalOptions($chatModels),
                'embedding' => $canonicalOptions($embeddingModels),
                'vision' => $canonicalOptions($visionModels),
            ],
            'environment_variables' => [
                ['name' => 'OLLAMA_API_URL', 'placeholder' => 'http://hawki_ollama:11434/api', 'description' => 'Direct Ollama API root visible from Python services.', 'secret' => false, 'configured' => true],
                ['name' => 'EMBED_MODEL', 'placeholder' => 'ollama/bge-m3', 'description' => 'Canonical embedding model; the prefix routes the request.', 'secret' => false, 'configured' => true],
                ['name' => 'CHAT_MODEL', 'placeholder' => 'ollama_chat/llama3.1:8b', 'description' => 'Canonical chat and graph model.', 'secret' => false, 'configured' => true],
                ['name' => 'VISION_MODEL', 'placeholder' => 'ollama_chat/qwen2.5vl:7b', 'description' => 'Canonical vision model.', 'secret' => false, 'configured' => true],
                ['name' => 'EMBED_MODELS', 'placeholder' => 'ollama/bge-m3', 'description' => 'Allowlisted embedding models shown in Settings.', 'secret' => false, 'configured' => true],
                ['name' => 'CHAT_MODELS', 'placeholder' => 'ollama_chat/llama3.1:8b', 'description' => 'Allowlisted chat and graph models shown in Settings.', 'secret' => false, 'configured' => true],
                ['name' => 'VISION_MODELS', 'placeholder' => 'ollama_chat/qwen2.5vl:7b', 'description' => 'Allowlisted vision models shown in Settings.', 'secret' => false, 'configured' => true],
            ],
        ],
        'openai' => [
            'label' => 'OpenAI GPT (via LiteLLM)',
            'description' => 'Add openai/ entries to the model allowlists; they render as gateway routes and Settings options.',
            'configuration_mode' => 'proxy',
            'model_selection_mode' => 'none',
            'runtime_supported' => false,
            'api_url' => env('OPENAI_API_URL', 'https://api.openai.com/v1'),
            'models' => [
                'embedding' => 'openai/text-embedding-3-small',
                'graph' => 'openai/gpt-4.1-mini',
                'multimodal' => 'openai/gpt-4.1-mini',
            ],
            'environment_variables' => [
                ['name' => 'OPENAI_API_KEY', 'placeholder' => 'sk-...', 'description' => 'OpenAI key read only by the LiteLLM container.', 'secret' => true, 'configured' => $secretConfigured('OPENAI_API_KEY')],
                ['name' => 'CHAT_MODELS', 'placeholder' => 'ollama_chat/llama3.1:8b,openai/gpt-4.1-mini', 'description' => 'openai/ entries serve GPT chat through the gateway.', 'secret' => false, 'configured' => true],
                ['name' => 'EMBED_MODELS', 'placeholder' => 'ollama/bge-m3,openai/text-embedding-3-small', 'description' => 'openai/ entries serve GPT embeddings through the gateway.', 'secret' => false, 'configured' => true],
                ['name' => 'VISION_MODELS', 'placeholder' => 'ollama_chat/qwen2.5vl:7b,openai/gpt-4.1-mini', 'description' => 'openai/ entries serve GPT vision through the gateway.', 'secret' => false, 'configured' => true],
                ['name' => 'OPENAI_API_BASE', 'placeholder' => 'https://api.openai.com/v1', 'description' => 'Optional standard endpoint override for corporate proxies.', 'secret' => false, 'configured' => $secretConfigured('OPENAI_API_BASE')],
            ],
        ],
        'anthropic' => [
            'label' => 'Anthropic Claude (via LiteLLM)',
            'description' => 'Add anthropic/ entries to the model allowlists; they render as gateway routes and Settings options. Use Ollama or OpenAI for embeddings.',
            'configuration_mode' => 'proxy',
            'model_selection_mode' => 'none',
            'runtime_supported' => false,
            'api_url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1'),
            'models' => [
                'embedding' => '',
                'graph' => 'anthropic/claude-sonnet-4-5-20250929',
                'multimodal' => 'anthropic/claude-sonnet-4-5-20250929',
            ],
            'environment_variables' => [
                ['name' => 'ANTHROPIC_API_KEY', 'placeholder' => 'sk-ant-...', 'description' => 'Anthropic key read only by the LiteLLM container.', 'secret' => true, 'configured' => $secretConfigured('ANTHROPIC_API_KEY')],
                ['name' => 'CHAT_MODELS', 'placeholder' => 'ollama_chat/llama3.1:8b,anthropic/claude-sonnet-4-5-20250929', 'description' => 'anthropic/ entries serve Claude chat through the gateway.', 'secret' => false, 'configured' => true],
                ['name' => 'VISION_MODELS', 'placeholder' => 'ollama_chat/qwen2.5vl:7b,anthropic/claude-sonnet-4-5-20250929', 'description' => 'anthropic/ entries serve Claude vision through the gateway.', 'secret' => false, 'configured' => true],
                ['name' => 'ANTHROPIC_API_BASE', 'placeholder' => 'https://api.anthropic.com', 'description' => 'Optional standard endpoint override for corporate proxies.', 'secret' => false, 'configured' => $secretConfigured('ANTHROPIC_API_BASE')],
            ],
        ],
    ],

    'vector_stores' => [
        'qdrant' => [
            // base connection
            'scheme' => env('QDRANT_SCHEME', 'http'),
            'host' => env('QDRANT_HOST', '127.0.0.1'),
            'port' => (int) env('QDRANT_PORT', 6333),

            // default collection + distance
            'collection' => env('QDRANT_COLLECTION', 'embeddings_hawk'),
            'distance' => env('QDRANT_DISTANCE', 'Cosine'), // Cosine | Dot | Euclid

            'api_key' => env('QDRANT_API_KEY'),            // null if not secured
            'timeout' => (int) env('QDRANT_TIMEOUT', 30),   // seconds
        ],
    ],
];
