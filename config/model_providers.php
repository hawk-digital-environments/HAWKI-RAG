<?php

$csv = static function (string $name, string $default): array {
    $value = env($name, $default);

    return array_values(array_unique(array_filter(array_map(
        static fn (string $item): string => trim($item),
        explode(',', is_string($value) ? $value : $default),
    ))));
};

$secretConfigured = static fn (string $name): bool => trim((string) env($name, '')) !== '';

$litellmChatAliases = $csv(
    'LITELLM_CHAT_ALIASES',
    'hawki-ollama-chat,hawki-gpt-chat,hawki-claude-chat,hawki-chat',
);
$litellmEmbeddingAliases = $csv(
    'LITELLM_EMBED_ALIASES',
    'hawki-ollama-embedding,hawki-openai-embedding,hawki-embedding',
);
$litellmVisionAliases = $csv(
    'LITELLM_VISION_ALIASES',
    'hawki-ollama-vision,hawki-gpt-vision,hawki-claude-vision,hawki-vision',
);

return [

    /*
    |--------------------------------------------------------------------------
    |   Model Providers
    |--------------------------------------------------------------------------
    |
    |   List of model providers suggested for the project.
    |   HAWKI-RAG uses LiteLLM as its runtime boundary. Ollama, OpenAI, and
    |   Anthropic are upstream routes owned by the proxy rather than direct
    |   Laravel or Python providers.
    |
    */
    'providers' => [
        'litellm' => [
            'label' => 'LiteLLM Gateway',
            'description' => 'The single OpenAI-compatible runtime used by HAWKI-RAG. Select an allowlisted Ollama, GPT, or Claude route alias below.',
            'configuration_mode' => 'environment',
            'model_selection_mode' => 'settings',
            'runtime_supported' => true,
            'api_url' => env('LITELLM_API_URL', 'http://litellm:4000/v1'),
            'endpoints' => [
                'embedding' => 'embeddings',
                'chat' => 'chat/completions',
            ],
            'models' => [
                'embedding' => env('LITELLM_EMBED_MODEL', 'hawki-ollama-embedding'),
                'graph' => env('LITELLM_CHAT_MODEL', 'hawki-ollama-chat'),
                'text' => env('LITELLM_CHAT_MODEL', 'hawki-ollama-chat'),
                'multimodal' => env('LITELLM_VISION_MODEL', 'hawki-ollama-vision'),
                'rag' => env('LITELLM_CHAT_MODEL', 'hawki-ollama-chat'),
            ],
            'allowed_models' => [
                'graph' => $litellmChatAliases,
                'embedding' => $litellmEmbeddingAliases,
                'vision' => $litellmVisionAliases,
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
            'label' => 'Ollama (via LiteLLM)',
            'description' => 'Local chat, embedding, and vision models are exposed only as LiteLLM aliases.',
            'configuration_mode' => 'proxy',
            'model_selection_mode' => 'none',
            'runtime_supported' => false,
            'api_url' => env('LITELLM_OLLAMA_API_BASE', 'http://hawki_ollama:11434'),
            'models' => [
                'embedding' => 'hawki-ollama-embedding',
                'graph' => 'hawki-ollama-chat',
                'multimodal' => 'hawki-ollama-vision',
            ],
            'environment_variables' => [
                ['name' => 'LITELLM_OLLAMA_API_BASE', 'placeholder' => 'http://hawki_ollama:11434', 'description' => 'Ollama API root visible from LiteLLM.', 'secret' => false, 'configured' => true],
                ['name' => 'LITELLM_OLLAMA_CHAT_MODEL', 'placeholder' => 'ollama_chat/llama3.1:8b', 'description' => 'Local chat target behind hawki-ollama-chat.', 'secret' => false, 'configured' => true],
                ['name' => 'LITELLM_OLLAMA_EMBED_MODEL', 'placeholder' => 'ollama/bge-m3', 'description' => 'Local embedding target behind hawki-ollama-embedding.', 'secret' => false, 'configured' => true],
                ['name' => 'LITELLM_OLLAMA_VISION_MODEL', 'placeholder' => 'ollama_chat/qwen2.5vl:7b', 'description' => 'Local vision target behind hawki-ollama-vision.', 'secret' => false, 'configured' => true],
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
