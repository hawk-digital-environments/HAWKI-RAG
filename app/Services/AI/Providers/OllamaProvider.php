<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaProvider extends BaseAIModelProvider
{
    public function __construct()
    {
        parent::__construct('ollama'); // expects config('model_providers.providers.ollama')
    }

    /** Build full URL for an endpoint key defined in config */
    protected function getEndpointUrl(string $endpointKey): string
    {
        $base = rtrim($this->config['api_url'] ?? 'http://127.0.0.1:11434/api', '/');
        $path = ltrim($this->config['endpoints'][$endpointKey] ?? '', '/');
        return $base . '/' . $path;
    }

    /** -------------------- Embeddings -------------------- */
    public function getEmbeddings(string $text)
    {
        try {
            $model = $this->config['models']['embedding'] ?? 'bge-m3';
            $resp = Http::timeout(180)->post($this->getEndpointUrl('embedding'), [
                // Ollama embeddings API uses 'model' + 'prompt'
                'model'  => $model,
                'prompt' => $text,
            ]);

            if ($resp->successful()) {
                // Ollama returns: { "embedding": [ ... ] }
                $vec = $resp->json('embedding');
                if (is_array($vec)) return $vec;
                Log::warning('Ollama embeddings: unexpected shape', ['body' => $resp->json()]);
                return null;
            }

            Log::error('Ollama embeddings error', [
                'status' => $resp->status(),
                'body'   => $resp->json(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('Ollama embeddings exception', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /** -------------------- Keywords -------------------- */
    public function generateKeywords(string $input)
    {
        try {
            $prompt = str_replace('{input}', $input, $this->prompts['keywords'] ?? 'List 10 keywords for: {input}');
            $model  = $this->config['models']['text'] ?? ($this->config['models']['rag'] ?? 'llama3:8b');

            $resp = Http::timeout(180)->post($this->getEndpointUrl('completion'), [
                'model'   => $model,
                'prompt'  => $prompt,
                'stream'  => false,
                'options' => [
                    'temperature' => 0.2,
                    'top_p'       => 0.8,
                ],
            ]);

            if ($resp->successful()) {
                // Ollama /generate returns { "response": "..." }
                return (string)($resp->json('response') ?? 'undefined');
            }

            Log::error('Ollama keywords error', ['status' => $resp->status(), 'body' => $resp->json()]);
            return 'undefined';
        } catch (\Throwable $e) {
            Log::error('Ollama keywords exception', ['err' => $e->getMessage()]);
            return 'undefined';
        }
    }

    /** -------------------- Additional context -------------------- */
    public function generateAdditionalContext(string $input)
    {
        try {
            $prompt = str_replace('{input}', $input, $this->prompts['additional_context'] ?? 'Describe: {input}');
            $model  = $this->config['models']['text'] ?? ($this->config['models']['rag'] ?? 'llama3:8b');

            $resp = Http::timeout(180)->post($this->getEndpointUrl('completion'), [
                'model'   => $model,
                'prompt'  => $prompt,
                'stream'  => false,
                'options' => [
                    'temperature' => 0.3,
                    'top_p'       => 0.8,
                ],
            ]);

            if ($resp->successful()) {
                return (string)($resp->json('response') ?? $input);
            }

            Log::error('Ollama addl context error', ['status' => $resp->status(), 'body' => $resp->json()]);
            return $input;
        } catch (\Throwable $e) {
            Log::error('Ollama addl context exception', ['err' => $e->getMessage()]);
            return $input;
        }
    }

    /** -------------------- Image context -------------------- */
    public function generateImageContext(string $imagePath, string $text)
    {
        try {
            if (!is_file($imagePath)) {
                Log::error('Image file not found', ['path' => $imagePath]);
                return $imagePath;
            }
            $b64    = base64_encode(file_get_contents($imagePath));
            $prompt = str_replace('{text}', $text, $this->prompts['image_context'] ?? 'Describe the image and answer Q&A.');

            $model = $this->config['models']['multimodal'] ?? 'llava:13b';
            $resp = Http::timeout(180)->post($this->getEndpointUrl('completion'), [
                'model'  => $model,
                'prompt' => $prompt,
                'images' => [$b64],
                'stream' => false,
            ]);

            if ($resp->successful()) {
                return (string)($resp->json('response') ?? $imagePath);
            }

            Log::error('Ollama image context error', ['status' => $resp->status(), 'body' => $resp->json()]);
            return $imagePath;
        } catch (\Throwable $e) {
            Log::error('Ollama image context exception', ['err' => $e->getMessage()]);
            return $imagePath;
        }
    }

    /** -------------------- Term generation (RAG query) -------------------- */
    public function generateTermFromMessage(array $messages)
    {
        try {
            // Prepare conversation strings
            $formatted = array_map(function ($m) {
                $role = strtoupper($m['role'] ?? '');
                return $role . ': ' . ($m['content'] ?? '');
            }, $messages);
            $conversationContext = implode("\n", $formatted);
            $lastUser = collect($messages)->where('role', 'user')->last();
            $lastUserContent = $lastUser['content'] ?? '';

            $prompt = str_replace(
                ['{lastUserContent}', '{conversationContext}'],
                [$lastUserContent, $conversationContext],
                $this->prompts['rag'] ?? 'Return a short search query.'
            );

            $model = env('OLLAMA_TERM_MODEL', $this->config['models']['rag'] ?? 'llama3:8b');

            $resp = Http::timeout(30)->post($this->getEndpointUrl('completion'), [
                'model'   => $model,
                'prompt'  => $prompt,
                'stream'  => false,
                'options' => [
                    'temperature' => 0.2,
                    'top_p'       => 0.8,
                ],
            ]);

            if ($resp->successful()) {
                $out = trim((string)$resp->json('response', ''));
                // Guardrail: collapse to one short line
                $out = preg_replace('/\s+/u', ' ', $out);
                if ($out !== '') return $out;
            }

            Log::error('Ollama term error', [
                'status'   => $resp->status() ?? null,
                'response' => $resp->json(),
                'messages' => $messages,
            ]);

            // Fallback: last user content
            return $lastUserContent;
        } catch (\Throwable $e) {
            Log::error('Ollama term exception', ['err' => $e->getMessage(), 'messages' => $messages]);
            $lastUser = collect($messages)->where('role', 'user')->last();
            return $lastUser['content'] ?? '';
        }
    }
    public function generatePageSummary(string $input): string
    {
        try {
            $prompt = str_replace('{input}', $input, $this->prompts['page_summary_university'] ?? 'Summarize as one paragraph: {input}');
            $model  = $this->config['models']['text'] ?? ($this->config['models']['rag'] ?? 'llama3:8b');

            $resp = \Illuminate\Support\Facades\Http::timeout(180)->post($this->getEndpointUrl('completion'), [
                'model'   => $model,
                'prompt'  => $prompt,
                'stream'  => false,
                'options' => ['temperature' => 0.3, 'top_p' => 0.8],
            ]);

            if ($resp->successful()) {
                $out = (string)($resp->json('response') ?? '');
                $out = trim(preg_replace('/\s+/u', ' ', $out));
                return $out !== '' ? $out : $input;
            }
            Log::error('Ollama page_summary error', ['status' => $resp->status(), 'body' => $resp->json()]);
            return $input;
        } catch (\Throwable $e) {
            Log::error('Ollama page_summary exception', ['err' => $e->getMessage()]);
            return $input;
        }
    }

    public function generatePdfSummary(string $markdown): string
    {
        try {
            $prompt = str_replace('{input}', $markdown, $this->prompts['pdf_content_university'] ?? 'Summarize as one paragraph: {input}');
            $model  = $this->config['models']['text'] ?? ($this->config['models']['rag'] ?? 'llama3:8b');

            $resp = \Illuminate\Support\Facades\Http::timeout(180)->post($this->getEndpointUrl('completion'), [
                'model'   => $model,
                'prompt'  => $prompt,
                'stream'  => false,
                'options' => ['temperature' => 0.3, 'top_p' => 0.8],
            ]);

            if ($resp->successful()) {
                $out = (string)($resp->json('response') ?? '');
                $out = trim(preg_replace('/\s+/u', ' ', $out));
                return $out !== '' ? $out : mb_substr($markdown, 0, 800);
            }
            Log::error('Ollama pdf_summary error', ['status' => $resp->status(), 'body' => $resp->json()]);
            return mb_substr($markdown, 0, 800);
        } catch (\Throwable $e) {
            Log::error('Ollama pdf_summary exception', ['err' => $e->getMessage()]);
            return mb_substr($markdown, 0, 800);
        }
    }
    public function generateUniversityKeywords(string $input): array
    {
        try {
            $prompt = str_replace('{input}', $input, $this->prompts['keywords_university'] ?? 'List 10 keywords: {input}');
            $model  = $this->config['models']['text'] ?? ($this->config['models']['rag'] ?? 'llama3:8b');

            $resp = \Illuminate\Support\Facades\Http::timeout(180)->post($this->getEndpointUrl('completion'), [
                'model'   => $model,
                'prompt'  => $prompt,
                'stream'  => false,
                'options' => ['temperature' => 0.2, 'top_p' => 0.8],
            ]);

            if ($resp->successful()) {
                $csv = (string)($resp->json('response') ?? '');
                $parts = preg_split('/\s*,\s*/u', trim($csv)) ?: [];
                return array_values(array_filter(array_map('trim', $parts)));
            }
            Log::error('Ollama keywords_university error', ['status' => $resp->status(), 'body' => $resp->json()]);
            return [];
        } catch (\Throwable $e) {
            Log::error('Ollama keywords_university exception', ['err' => $e->getMessage()]);
            return [];
        }
    }


    /** -------------------- Non-stream chat (grounded) -------------------- */
    public function generateNonStreamChatResponse(array $messages, $items)
    {
        set_time_limit(40);

        try {
            // 1) Normalize + filter + dedupe (prefer user language)
            $itemsArray = $this->processItemsToArray($items);
            $userLang   = $this->guessLang(collect($messages)->where('role', 'user')->last()['content'] ?? '');
            $itemsArray = $this->filterAndDedupeItems($itemsArray, 60, $userLang);

            // 2) Build system prompt like GWDG
            $basicPagesList     = $this->formatBasicPagesList($itemsArray);
            $extendedPagesList  = $this->formatExtendedPagesList($itemsArray);
            $basicPagesString   = implode("\n\n", $basicPagesList);
            $extendedPagesString = implode("\n\n", $extendedPagesList);
            $hasContext         = count($itemsArray) > 0 && $basicPagesString !== '' && $extendedPagesString !== '';

            $systemContent = $hasContext
                ? str_replace(['{basicPagesList}', '{extendedPagesList}'], [$basicPagesString, $extendedPagesString], $this->prompts['chat'])
                : ($this->prompts['chat_empty'] ?? 'No sources found.');

            if ($hasContext) {
                $systemContent .= "\n\nIMPORTANT: Page URLs must be displayed exactly as provided with all digits preserved.";
            }

            $systemMessage = ['role' => 'system', 'content' => $systemContent];

            // 3) Call Ollama chat (non-stream)
            $model = $this->config['models']['rag'] ?? 'llama3:8b';
            $resp = Http::timeout(45)->post($this->getEndpointUrl('chat'), [
                'model'    => $model,
                'messages' => array_merge([$systemMessage], $messages),
                'stream'   => false,
                'options'  => [
                    'temperature' => 0.3,
                    'top_p'       => 0.9,
                    'num_predict' => 900,
                ],
            ]);

            if ($resp->successful()) {
                $json = $resp->json();
                // /chat returns { message: {role, content}, ... }
                if (isset($json['message']['content'])) return (string)$json['message']['content'];
                // /generate-like fallbacks
                if (isset($json['response'])) return (string)$json['response'];
                Log::warning('Ollama chat non-stream: unexpected shape', ['json' => $json]);
                return "I apologize, but I received an unexpected response format.";
            }

            Log::error('Ollama chat non-stream error', ['status' => $resp->status(), 'body' => $resp->json()]);
            return "I apologize, but I encountered an error while processing your request.";
        } catch (\Throwable $e) {
            Log::error('Ollama chat non-stream exception', ['err' => $e->getMessage()]);
            return "I apologize, but something went wrong while processing your request.";
        }
    }
    private function streamLocalFallbackResponse(array $messages, array $itemsArray, callable $callback): void
    {   // I added this function so if Ollama Fails responding, it should return the fallback summary!

        $userMessage = collect($messages)->where('role', 'user')->last()['content'] ?? '';

        $callback("Ollama did not return any tokens. Fallback summary of the retrieved pages:", false);

        foreach ($itemsArray as $item) {
            $title = $item['title'] ?? 'Untitled';
            $url   = $item['page_url'] ?? '';
            $snippet = mb_substr($item['content'] ?? '', 0, 200);
            $callback("\n\n**{$title}**\n{$snippet}\n{$url}", false);
        }
    }
    /**
     * If /chat produced zero deltas, try /generate (NDJSON) with a composed prompt.
     */
    private function streamViaGenerateFallback(array $messages, string $systemContent, string $model, callable $callback): void
    {
        // Build a single prompt: [SYSTEM]\n\n[USER...]
        $userLast = collect($messages)->where('role', 'user')->last();
        $userText = $userLast['content'] ?? '';
        $prompt = $systemContent . "\n\nUser request:\n" . $userText;

        $resp = \Illuminate\Support\Facades\Http::withOptions([
            'stream'  => true,
            'timeout' => 60,
        ])->post($this->getEndpointUrl('completion'), [
            'model'   => $model,
            'prompt'  => $prompt,
            'stream'  => true,
            'options' => [
                'temperature' => 0.3,
                'top_p'       => 0.9,
                'num_predict' => 900,
            ],
        ]);

        if (!$resp->ok()) {
            Log::error('Ollama /generate stream not OK', ['status' => $resp->status(), 'body' => $resp->body()]);
            return;
        }

        foreach ($resp->toPsrResponse()->getBody() as $chunk) {
            $text = (string)$chunk;
            if ($text === '') continue;

            foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
                $line = trim($line);
                if ($line === '') continue;

                // Some proxies prepend "data: " even for NDJSON; strip if present
                if (str_starts_with($line, 'data: ')) $line = substr($line, 6);

                $json = json_decode($line, true);
                if (!is_array($json)) continue;

                // /generate stream: { "response": "...", "done": false }
                $delta = $json['response'] ?? null;
                if (is_string($delta) && $delta !== '') {
                    $callback($delta, false);
                }

                if (!empty($json['done'])) {
                    return;
                }
            }
        }
    }

    /** -------------------- Stream chat (grounded) -------------------- */
    public function generateStreamChatResponse(array $messages, $items, callable $callback)
    {
        set_time_limit(120);

        try {
            // 1) Normalize + filter + dedupe with user language preference
            $itemsArray = $this->processItemsToArray($items);
            $userLang   = $this->guessLang(collect($messages)->where('role', 'user')->last()['content'] ?? '');
            $itemsArray = $this->filterAndDedupeItems($itemsArray, 60, $userLang);

            // 2) Build grounded system prompt (same rules as GWDG)
            $basicPagesList      = $this->formatBasicPagesList($itemsArray);
            $extendedPagesList   = $this->formatExtendedPagesList($itemsArray);
            $basicPagesString    = implode("\n\n", $basicPagesList);
            $extendedPagesString = implode("\n\n", $extendedPagesList);
            $hasContext          = count($itemsArray) > 0 && $basicPagesString !== '' && $extendedPagesString !== '';

            $systemContent = $hasContext
                ? str_replace(['{basicPagesList}', '{extendedPagesList}'], [$basicPagesString, $extendedPagesString], $this->prompts['chat'])
                : ($this->prompts['chat_empty'] ?? 'No sources found.');

            if ($hasContext) {
                $systemContent .= "\n\nIMPORTANT: Page URLs must be displayed exactly as provided with all digits preserved.";
            }

            $systemMessage = ['role' => 'system', 'content' => $systemContent];

            // Log final prompt for debugging (like GWDG)
            Log::info('OLLAMA Final Chat Prompt', [
                'system' => mb_substr($systemContent, 0, 1500),
                'user'   => collect($messages)->where('role', 'user')->last()['content'] ?? '',
            ]);

            // 3) Stream from Ollama /chat (NDJSON)
            $model = $this->config['models']['rag'] ?? 'llama3:8b';

            $resp = \Illuminate\Support\Facades\Http::withOptions([
                'stream'  => true,
                'timeout' => 60,
            ])->withHeaders([
                'Accept' => 'application/x-ndjson, application/json' // <— add this
            ])->post($this->getEndpointUrl('chat'), [
                'model'    => $model,
                'messages' => array_merge([$systemMessage], $messages),
                'stream'   => true,
                'options'  => [
                    'temperature' => 0.3,
                    'top_p'       => 0.9,
                    'num_predict' => 900,
                ],
            ]);


            if (!$resp->ok()) {
                Log::error('Ollama chat stream: HTTP not OK', ['status' => $resp->status(), 'body' => $resp->body()]);
                $this->streamLocalFallbackResponse($messages, $itemsArray, $callback);
                $callback('', true);
                return;
            }

            $chunks = 0;
            $firstLogged = false;

            foreach ($resp->toPsrResponse()->getBody() as $chunk) {
                $text = (string)$chunk;
                if ($text === '') continue;

                if (!$firstLogged) {
                    Log::info('OLLAMA first bytes', ['preview' => mb_substr($text, 0, 120)]);
                    $firstLogged = true;
                }

                // Ollama streams NDJSON; sometimes multiple lines per chunk
                foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
                    $line = trim($line);
                    if ($line === '') continue;

                    $json = json_decode($line, true);
                    if (!is_array($json)) continue;

                    // Common stream shapes:
                    // { "message": { "role":"assistant", "content":"..." }, "done": false }
                    // { "response": "...", "done": false }
                    $delta = $json['message']['content'] ?? ($json['response'] ?? null);
                    if (is_string($delta) && $delta !== '') {
                        $callback($delta, false);
                        $chunks++;
                    }

                    if (!empty($json['done'])) {
                        $callback('', true);
                        return;
                    }
                }
            }

            // If stream finished without explicit "done", still close
            if ($chunks === 0) {
                Log::warning('Ollama chat stream: zero deltas; attempting body parse and /generate fallback');

                // Try to parse a non-streaming body (some Ollama setups return one JSON blob)
                try {
                    $raw = $resp->body();
                    if (is_string($raw) && trim($raw) !== '') {
                        // Might be a single JSON or NDJSON; handle both
                        $lines = preg_split("/\r\n|\n|\r/", $raw);
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if ($line === '') continue;
                            if (str_starts_with($line, 'data: ')) $line = substr($line, 6);
                            $j = json_decode($line, true);
                            if (!is_array($j)) continue;
                            $delta = $j['message']['content'] ?? ($j['response'] ?? null);
                            if (is_string($delta) && $delta !== '') {
                                $callback($delta, false);
                                $chunks++;
                            }
                        }
                        if ($chunks > 0) {
                            $callback('', true);
                            return;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::info('Ollama chat body-parse fallback failed', ['err' => $e->getMessage()]);
                }

                // Try /generate streaming fallback (NDJSON) using same grounded system content
                try {
                    $this->streamViaGenerateFallback($messages, $systemContent, $model, $callback);
                    // If streamViaGenerateFallback yielded anything, we’re good; finish.
                    $callback('', true);
                    return;
                } catch (\Throwable $e) {
                    Log::error('Ollama /generate fallback failed', ['err' => $e->getMessage()]);
                }

                // Last resort: local summary so user sees something
                $this->streamLocalFallbackResponse($messages, $itemsArray, $callback);
                $callback('', true);
                return;
            }

            $callback('', true);
        } catch (\Throwable $e) {
            Log::error('Ollama chat stream exception', ['err' => $e->getMessage()]);
            // graceful fallback so user still sees something
            try {
                $itemsArray = $itemsArray ?? $this->processItemsToArray($items);
                $this->streamLocalFallbackResponse($messages, $itemsArray, $callback);
            } catch (\Throwable $e2) {
                $callback("I apologize, but the chat server failed.", false);
            }
            $callback('', true);
        }
    }
}
