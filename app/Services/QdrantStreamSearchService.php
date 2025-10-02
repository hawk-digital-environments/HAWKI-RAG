<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\AI\Providers\OllamaProvider;
use App\Services\AI\Providers\GWDGProvider;

class QdrantStreamSearchService
{
    private const STATUS_TYPE   = 'ragStatus';
    private const RESPONSE_TYPE = 'ragResponse';
    private const METADATA_TYPE = 'ragMetadata';
    private const ERROR_TYPE    = 'ragError';

    private const SEARCH_TIMEOUT = 30;
    private const CHAT_GENERATION_TIMEOUT = 60; // a bit more headroom for LLM

    public function __construct(
        protected SemanticQdrantSearch $searcher,
        protected OllamaProvider $ollamaProvider,
        protected GWDGProvider $gwdgProvider,
    ) {}

    public function handle(Request $request, float $requestStartTime = null): StreamedResponse
    {
        return new StreamedResponse(function () use ($request, $requestStartTime) {

            if (ob_get_level()) {
                @ob_end_clean();
            }
            $requestStartTime = $requestStartTime ?? microtime(true);
            $perf = [];

            $sendChunk = function (string $content, bool $isDone = false, ?string $type = null, ?array $metadata = null) use (&$perf) {
                if (connection_aborted()) return;
                $chunk = [
                    'choices' => [[
                        'delta' => ['content' => $content],
                        'finish_reason' => $isDone ? 'stop' : null
                    ]],
                    'type' => $type
                ];
                if ($metadata) {
                    if ($isDone) $metadata['performance'] = $perf;
                    $chunk['metadata'] = $metadata;
                }
                try {
                    echo json_encode($chunk, JSON_UNESCAPED_UNICODE) . "\n";
                    @ob_flush();
                    flush();
                } catch (\Throwable $e) {
                    Log::warning('Stream write failed (client disconnected?)', ['error' => $e->getMessage()]);
                }
            };

            // optional keep-alive ping to avoid proxy idle timeouts
            $ping = function () {
                if (connection_aborted()) return;
                echo ":\n"; // comment line (SSE-style) safe for NDJSON parsers to ignore
                @ob_flush();
                flush();
            };

            $sendError = function (string $message, int $status = 500, ?array $errors = null) use ($sendChunk, &$perf, $requestStartTime) {
                $perf['error'] = true;
                $perf['total_duration_ms'] = round((microtime(true) - $requestStartTime) * 1000);
                $sendChunk($message, true, self::ERROR_TYPE, [
                    'status' => 'error',
                    'code'   => $status,
                    'errors' => $errors,
                ]);
            };

            $setTimeLimit = function (int $seconds) {
                @set_time_limit($seconds);
            };

            $time = function (callable $fn, string $key) use (&$perf) {
                $t0 = microtime(true);
                $res = $fn();
                $perf[$key] = round((microtime(true) - $t0) * 1000);
                return $res;
            };

            $extractLastUser = function (array $messages): ?array {
                $last = null;
                foreach ($messages as $m) if (($m['role'] ?? null) === 'user') $last = $m;
                return $last;
            };

            $resultsCount = fn($results) => (is_array($results) && isset($results['items']) && is_array($results['items'])) ? count($results['items']) : 0;

            try {
                // 1) Validate inputs
                $validated = $request->validate([
                    'query'    => 'nullable|string',
                    'messages' => 'nullable|array',
                    'messages.*.role' => 'required_with:messages|string|in:user,assistant',
                    'messages.*.content' => 'required_with:messages|string',
                    'top_k'    => 'nullable|integer|min:1|max:50',
                    'filters'  => 'nullable|array',
                ]);
                $perf['validation_ms'] = 0;

                $topK    = $validated['top_k'] ?? 5;
                $filters = $validated['filters'] ?? [];
                $term    = $validated['query'] ?? '';

                $sendChunk("Validating sent data...", false, self::STATUS_TYPE);

                // 2) If messages present, generate term from them
                if (!empty($validated['messages'])) {
                    $sendChunk("Generating term from message...", false, self::STATUS_TYPE);

                    $term = $time(function () use ($validated, $extractLastUser) {
                        try {
                            return $this->ollamaProvider->generateTermFromMessage($validated['messages']);
                        } catch (\Throwable $e) {
                            Log::warning('Term generation failed, falling back to last user message', ['err' => $e->getMessage()]);
                            $lastUser = $extractLastUser($validated['messages']);
                            return $lastUser['content'] ?? '';
                        }
                    }, 'term_generation_ms');

                    if ($term === '') {
                        $sendError("No usable term found in messages.", 422);
                        return;
                    }
                }

                if ($term === '') {
                    $sendError("Query parameter is required.", 422);
                    return;
                }

                // 3) Retrieve from Qdrant
                $sendChunk("Searching Qdrant for: {$term}", false, self::STATUS_TYPE);
                $setTimeLimit(self::SEARCH_TIMEOUT);

                $hits = $time(function () use ($term, $topK, $filters) {
                    return $this->searcher->search($term, $topK, $filters);
                }, 'search_ms');

                $results = ['items' => is_array($hits) ? $hits : []];
                $count   = $resultsCount($results);

                if ($count === 0) {
                    $perf['total_duration_ms'] = round((microtime(true) - $requestStartTime) * 1000);
                    $sendChunk("No results found for the given search term.", true, self::RESPONSE_TYPE, [
                        'status' => 'success',
                        'results_count' => 0,
                        'term' => $term,
                    ]);
                    return;
                }

                // 4) Stream LLM answer over retrieved context
                $sendChunk("Generating a chat response...", false, self::STATUS_TYPE);
                $setTimeLimit(self::CHAT_GENERATION_TIMEOUT);

                $chatStart = microtime(true);

                // ********** provider selection (added) **********
                $which = $request->input('provider', 'gwdg'); // 'gwdg' | 'ollama'
                $provider = ($which === 'ollama') ? $this->ollamaProvider : $this->gwdgProvider;
                // ***********************************************

                $provider->generateStreamChatResponse(
                    $validated['messages'] ?? [['role' => 'user', 'content' => $term]],
                    $results,
                    function (string $content, bool $isDone) use ($sendChunk, $count, $term, $chatStart, &$perf, $requestStartTime, $ping) {
                        if ($isDone) {
                            $perf['chat_generation_ms'] = round((microtime(true) - $chatStart) * 1000);
                            $perf['total_duration_ms']  = round((microtime(true) - $requestStartTime) * 1000);
                            $sendChunk("", true, self::METADATA_TYPE, [
                                'status' => 'success',
                                'results_count' => $count,
                                'term' => $term,
                            ]);
                        } else {
                            $sendChunk($content, false, self::RESPONSE_TYPE);
                            $ping(); // keep-alive
                        }
                    }
                );
            } catch (\Illuminate\Validation\ValidationException $e) {
                Log::error('Qdrant stream validation failed', ['errors' => $e->errors()]);
                $sendError('Validation failed', 422, $e->errors());
            } catch (\Throwable $e) {
                Log::error('Qdrant stream failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $sendError('Search processing failed: ' . $e->getMessage(), 500);
            }
        }, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // Nginx
        ]);
    }
}
