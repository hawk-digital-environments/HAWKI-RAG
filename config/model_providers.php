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

$gatewayEmbeddingModels = $csv('EMBED_MODELS', 'hawki-ollama-embedding,hawki-openai-embedding,hawki-embedding');
$gatewayChatModels = $csv('CHAT_MODELS', 'hawki-ollama-chat,hawki-gpt-chat,hawki-claude-chat,hawki-chat');
$gatewayVisionModels = $csv('VISION_MODELS', 'hawki-ollama-vision,hawki-gpt-vision,hawki-claude-vision,hawki-vision');
$directEmbeddingModels = array_values(array_unique(array_merge(
    [$canonicalEmbeddingModel],
    $csv('EMBED_MODELS', $canonicalEmbeddingModel),
)));
$directChatModels = array_values(array_unique(array_merge(
    [$canonicalChatModel],
    $csv('CHAT_MODELS', $canonicalChatModel),
)));
$directVisionModels = array_values(array_unique(array_merge(
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
    |   Direct Ollama is the local default. LiteLLM is an optional explicit
    |   runtime boundary for Ollama aliases, OpenAI, Anthropic, and other
    |   proxy-managed routes. Provider failures never trigger silent fallback.
    |
    */
    'providers' => [
        'litellm' => [
            'label' => 'LiteLLM Gateway',
            'description' => 'Optional OpenAI-compatible gateway for allowlisted Ollama, GPT, Claude, and other proxy-managed route aliases.',
            'configuration_mode' => 'environment',
            'model_selection_mode' => 'settings',
            'runtime_supported' => true,
            'api_url' => env('LITELLM_API_URL', 'http://litellm:4000/v1'),
            'endpoints' => [
                'embedding' => 'embeddings',
                'chat' => 'chat/completions',
            ],
            'models' => [
                'embedding' => env('EMBED_MODEL', 'hawki-ollama-embedding'),
                'graph' => env('CHAT_MODEL', 'hawki-ollama-chat'),
                'text' => env('CHAT_MODEL', 'hawki-ollama-chat'),
                'multimodal' => env('VISION_MODEL', 'hawki-ollama-vision'),
                'rag' => env('CHAT_MODEL', 'hawki-ollama-chat'),
            ],
            'allowed_models' => [
                'graph' => $gatewayChatModels,
                'embedding' => $gatewayEmbeddingModels,
                'vision' => $gatewayVisionModels,
            ],
            'model_placeholders' => [
                'graph' => 'hawki-ollama-chat, hawki-gpt-chat, or hawki-claude-chat',
                'embedding' => 'hawki-ollama-embedding or hawki-openai-embedding',
                'vision' => 'hawki-ollama-vision, hawki-gpt-vision, or hawki-claude-vision',
            ],
            'model_options' => [
                'graph' => [
                    ['value' => 'hawki-ollama-chat', 'label' => 'Ollama · local chat'],
                    ['value' => 'hawki-gpt-chat', 'label' => 'OpenAI · GPT chat'],
                    ['value' => 'hawki-claude-chat', 'label' => 'Anthropic · Claude chat'],
                    ['value' => 'hawki-chat', 'label' => 'Legacy local chat alias'],
                ],
                'embedding' => [
                    ['value' => 'hawki-ollama-embedding', 'label' => 'Ollama · local embedding'],
                    ['value' => 'hawki-openai-embedding', 'label' => 'OpenAI · embedding'],
                    ['value' => 'hawki-embedding', 'label' => 'Legacy local embedding alias'],
                ],
                'vision' => [
                    ['value' => 'hawki-ollama-vision', 'label' => 'Ollama · local vision'],
                    ['value' => 'hawki-gpt-vision', 'label' => 'OpenAI · GPT vision'],
                    ['value' => 'hawki-claude-vision', 'label' => 'Anthropic · Claude vision'],
                    ['value' => 'hawki-vision', 'label' => 'Legacy local vision alias'],
                ],
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
                    'placeholder' => 'hawki-ollama-embedding=1024,hawki-openai-embedding=1536,hawki-embedding=1024',
                    'description' => 'Trusted alias dimensions used when graph-only ingestion has not observed an embedding response.',
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
                'graph' => $directChatModels,
                'embedding' => $directEmbeddingModels,
                'vision' => $directVisionModels,
            ],
            'model_placeholders' => [
                'graph' => 'ollama_chat/llama3.1:8b',
                'embedding' => 'ollama/bge-m3',
                'vision' => 'ollama_chat/qwen2.5vl:7b',
            ],
            'model_options' => [
                'graph' => $canonicalOptions($directChatModels),
                'embedding' => $canonicalOptions($directEmbeddingModels),
                'vision' => $canonicalOptions($directVisionModels),
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
            'description' => 'GPT chat, embedding, and vision routes are configured on the LiteLLM proxy.',
            'configuration_mode' => 'proxy',
            'model_selection_mode' => 'none',
            'runtime_supported' => false,
            'api_url' => env('OPENAI_API_URL', 'https://api.openai.com/v1'),
            'models' => [
                'embedding' => 'hawki-openai-embedding',
                'graph' => 'hawki-gpt-chat',
                'multimodal' => 'hawki-gpt-vision',
            ],
            'environment_variables' => [
                ['name' => 'OPENAI_API_KEY', 'placeholder' => 'sk-...', 'description' => 'OpenAI key read only by the LiteLLM container.', 'secret' => true, 'configured' => $secretConfigured('OPENAI_API_KEY')],
                ['name' => 'LITELLM_OPENAI_CHAT_MODEL', 'placeholder' => 'openai/gpt-4.1-mini', 'description' => 'Target behind hawki-gpt-chat.', 'secret' => false, 'configured' => true],
                ['name' => 'LITELLM_OPENAI_EMBED_MODEL', 'placeholder' => 'openai/text-embedding-3-small', 'description' => 'Target behind hawki-openai-embedding.', 'secret' => false, 'configured' => true],
                ['name' => 'LITELLM_OPENAI_VISION_MODEL', 'placeholder' => 'openai/gpt-4.1-mini', 'description' => 'Target behind hawki-gpt-vision.', 'secret' => false, 'configured' => true],
            ],
        ],
        'anthropic' => [
            'label' => 'Anthropic Claude (via LiteLLM)',
            'description' => 'Claude chat and vision routes are configured on the LiteLLM proxy. Use Ollama or OpenAI for embeddings.',
            'configuration_mode' => 'proxy',
            'model_selection_mode' => 'none',
            'runtime_supported' => false,
            'api_url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1'),
            'models' => [
                'embedding' => '',
                'graph' => 'hawki-claude-chat',
                'multimodal' => 'hawki-claude-vision',
            ],
            'environment_variables' => [
                ['name' => 'ANTHROPIC_API_KEY', 'placeholder' => 'sk-ant-...', 'description' => 'Anthropic key read only by the LiteLLM container.', 'secret' => true, 'configured' => $secretConfigured('ANTHROPIC_API_KEY')],
                ['name' => 'LITELLM_ANTHROPIC_CHAT_MODEL', 'placeholder' => 'anthropic/claude-sonnet-4-5-20250929', 'description' => 'Target behind hawki-claude-chat.', 'secret' => false, 'configured' => true],
                ['name' => 'LITELLM_ANTHROPIC_VISION_MODEL', 'placeholder' => 'anthropic/claude-sonnet-4-5-20250929', 'description' => 'Target behind hawki-claude-vision.', 'secret' => false, 'configured' => true],
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
