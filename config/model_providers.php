<?php

return [

    /*
    |--------------------------------------------------------------------------
    |   Model Providers
    |--------------------------------------------------------------------------
    |
    |   List of model providers suggested for the project.
    |   The RAG stack in this project is configured to use local Ollama by
    |   default. To include other providers, extend this list and the Python
    |   provider adapters together.
    |
    */
    'providers' => [
        // Ollama (local)
        'ollama' => [
            'label' => 'Ollama',
            'runtime_supported' => true,

            'api_url' => env('OLLAMA_API_URL', 'http://127.0.0.1:11434/api'),

            'endpoints' => [
                'embedding'  => 'embeddings',
                'completion' => 'generate',
                'chat'       => 'chat',
            ],

            'models' => [
                'embedding'  => env('OLLAMA_EMBED_MODEL', 'bge-m3'),
                'graph'      => env('GRAPH_OLLAMA_RAG_MODEL', env('OLLAMA_RAG_MODEL', 'llama3:8b')),
                // swap to llama3:8b (larger) by setting OLLAMA_TEXT_MODEL in .env
                'text'       => env('OLLAMA_TEXT_MODEL', 'llama3:8b'),
                'multimodal' => env('OLLAMA_VISION_MODEL', 'llava:13b'),
                // default RAG chat model (uses llama3:8b unless overridden)
                'rag'        => env('OLLAMA_RAG_MODEL', 'llama3:8b'),
            ],
        ],
        'openai' => [
            'label' => 'ChatGPT / OpenAI',
            'runtime_supported' => false,
            'api_url' => env('OPENAI_API_URL', 'https://api.openai.com/v1'),
            'endpoints' => [
                'embedding' => 'embeddings',
                'chat' => 'chat/completions',
            ],
            'models' => [
                'embedding' => env('OPENAI_EMBED_MODEL', ''),
                'graph' => env('OPENAI_GRAPH_MODEL', env('OPENAI_CHAT_MODEL', '')),
            ],
        ],
        'anthropic' => [
            'label' => 'Claude / Anthropic',
            'runtime_supported' => false,
            'api_url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1'),
            'endpoints' => [
                'chat' => 'messages',
            ],
            'models' => [
                'embedding' => '',
                'graph' => env('ANTHROPIC_GRAPH_MODEL', env('CLAUDE_GRAPH_MODEL', '')),
            ],
        ],
    ],


    'vector_stores' => [
        'qdrant' => [
            // base connection
            'scheme'     => env('QDRANT_SCHEME', 'http'),
            'host'       => env('QDRANT_HOST', '127.0.0.1'),
            'port'       => (int) env('QDRANT_PORT', 6333),

            // default collection + distance
            'collection' => env('QDRANT_COLLECTION', 'embeddings_hawk'),
            'distance'   => env('QDRANT_DISTANCE', 'Cosine'), // Cosine | Dot | Euclid

            'api_key'    => env('QDRANT_API_KEY'),            // null if not secured
            'timeout'    => (int) env('QDRANT_TIMEOUT', 30),   // seconds
        ],
    ],
];
