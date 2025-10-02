<?php

namespace App\Services;

use GuzzleHttp\Client;
use RuntimeException;

class EmbeddingService
{
    protected Client $http;

    /** full model_provider config */
    protected array $cfg = [];

    /** providers sub-config */
    protected array $providers = [];

    protected string $provider;
    protected string $model;

    public function __construct()
    {
        $this->http = new Client(['timeout' => 180, 'connect_timeout' => 30]);

        // Load config safely (with defaults if file/keys are missing)
        $this->cfg       = config('model_provider', []) ?: [];
        $this->providers = $this->cfg['providers'] ?? [];

        // Pick provider from env (default ollama); allow missing keys gracefully
        $this->provider  = env('EMBED_PROVIDER', 'ollama');

        // Model from config providers, else a sensible default
        $this->model     = $this->providers[$this->provider]['models']['embedding']
            ?? 'bge-m3';
    }

    /** @return float[] */
    public function embed(string $text): array
    {
        $text = trim($text);
        if ($text === '') return [];

        switch ($this->provider) {
            case 'ollama':
                return $this->embedOllama($text);

            case 'gwdg':
                return $this->embedGwdg($text);

            default:
                throw new RuntimeException("Unsupported embedding provider: {$this->provider}");
        }
    }

    /** Ollama local embeddings (driven by model_provider.php; env fallback) */
    protected function embedOllama(string $text): array
    {
        // Read from config with safe fallbacks
        $apiBase   = rtrim($this->providers['ollama']['api_url'] ?? env('OLLAMA_API_URL', 'http://127.0.0.1:11434/api'), '/');
        $endpoint  = $this->providers['ollama']['endpoints']['embedding'] ?? 'embeddings';
        $model     = $this->model;

        $res  = $this->http->post("{$apiBase}/{$endpoint}", [
            'json' => ['model' => $model, 'prompt' => $text],
        ]);
        $data = json_decode((string) $res->getBody(), true);

        if (!isset($data['embedding']) || !is_array($data['embedding'])) {
            throw new RuntimeException('Ollama embeddings: invalid response');
        }

        return array_map('floatval', $data['embedding']);
    }

    /** GWDG (OpenAI-compatible) embeddings */
    protected function embedGwdg(string $text): array
    {
        $apiUrl = rtrim($this->providers['gwdg']['api_url'] ?? '', '/');
        $apiKey = $this->providers['gwdg']['api_key'] ?? null;
        $model  = $this->model;

        if ($apiUrl === '' || !$apiKey) {
            throw new RuntimeException('GWDG provider requires GWDG_API_URL and GWDG_API_KEY.');
        }

        // Most OpenAI-compatible servers accept the OpenAI embeddings route
        $res = $this->http->post("{$apiUrl}/embeddings", [
            'headers' => [
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'input' => $text,
            ],
        ]);

        $data = json_decode((string) $res->getBody(), true);
        $vec  = $data['data'][0]['embedding'] ?? null;

        if (!is_array($vec)) {
            throw new RuntimeException('GWDG embeddings: invalid response');
        }

        return array_map('floatval', $vec);
    }
}
