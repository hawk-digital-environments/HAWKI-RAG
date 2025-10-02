<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;



class GWDGProvider extends BaseAIModelProvider
{
    /**
     * Create a new GWDGProvider instance
     */
    public function __construct()
    {
        parent::__construct('gwdg');

        // Validate GWDG configuration
        $this->validateConfiguration();
    }

    /**
     * Generate embeddings for a text
     *
     * @param string $text
     * @return array|null
     */
    public function getEmbeddings(string $text)
    {
        // GWDG doesn't support embeddings yet 
        Log::warning('GWDG embeddings not implemented');
        return null;
    }

    /**
     * Generate keywords for given input
     *
     * @param string $input
     * @return string
     */
    public function generateKeywords(string $input)
    {
        // GWDG keywords not implemented yet
        Log::warning('GWDG keywords generation not implemented');
        return 'undefined';
    }

    /**
     * Generate additional context for given input
     *
     * @param string $input
     * @return string
     */
    public function generateAdditionalContext(string $input)
    {
        // GWDG additional context not implemented yet
        Log::warning('GWDG additional context generation not implemented');
        return $input;
    }

    /**
     * Generate context for an image
     *
     * @param string $imagePath
     * @param string $text
     * @return string
     */
    public function generateImageContext(string $imagePath, string $text)
    {
        // GWDG image context not implemented yet
        Log::warning('GWDG image context generation not implemented');
        return $imagePath;
    }

    /**
     * Generate optimized search term from conversation
     *
     * @param array $messages
     * @return string
     */
    public function generateTermFromMessage(array $messages)
    {
        // GWDG term from message not implemented yet
        Log::warning('GWDG term generation not implemented');

        $lastUserMessage = collect($messages)
            ->where('role', 'user')
            ->last();

        return $lastUserMessage['content'] ?? '';
    }

    /**
     * Generate a non-stream chat response using the GWDG API
     *
     * @param array $messages
     * @param mixed $items
     * @return string
     */
    public function generateNonStreamChatResponse(array $messages, $items)
    {
        // Set PHP execution time limit
        set_time_limit(40);

        try {
            $itemsArray = $this->processItemsToArray($items);
            $lastUserMessage = collect($messages)->where('role', 'user')->last();
            $userLang = $this->guessLang($lastUserMessage['content'] ?? '');
            $itemsArray = $this->filterAndDedupeItems($itemsArray, 60);
            $chatRequest = $this->prepareApiRequest($messages, $itemsArray);

            // Retry logic for transient errors
            $maxRetries = 2;
            $retryCount = 0;
            $retryDelay = 1000; // milliseconds

            while ($retryCount <= $maxRetries) {
                try {
                    $response = Http::timeout(40)
                        ->withHeaders([
                            'Authorization' => 'Bearer ' . $this->config['api_key'],
                            'Content-Type' => 'application/json',
                        ])
                        ->post($this->config['api_url'], $chatRequest);

                    // Early return if successful
                    if ($response->successful()) {
                        $responseData = $response->json();

                        // Use null coalescing operator for cleaner content retrieval
                        return $responseData['choices'][0]['message']['content'] ??
                            "I apologize, but I received an unexpected response format.";
                    }

                    // Handle 5xx error (server error)
                    $statusCode = $response->status() ?? 0;
                    if ($statusCode >= 500 && $statusCode < 600 && $retryCount < $maxRetries) {
                        $retryCount++;
                        usleep($retryDelay * 1000);
                        $retryDelay *= 2;
                        continue;
                    }

                    // If we've exhausted retries for a server error, use local fallback
                    if ($this->isServerError($statusCode) && $retryCount >= $maxRetries) {
                        return $this->generateLocalFallbackResponse($messages, $itemsArray);
                    }

                    return "I apologize, but I encountered an error while processing your request.";
                } catch (\Exception $requestException) {
                    // Return fallback response if retries exhausted
                    if ($retryCount >= $maxRetries) {
                        return $this->generateLocalFallbackResponse($messages, $itemsArray);
                    }

                    // Otherwise retry
                    $retryCount++;
                    usleep($retryDelay * 1000);
                    $retryDelay *= 2;
                }
            }

            // If we get here, all retries failed
            return $this->generateLocalFallbackResponse($messages, $itemsArray);
        } catch (\Exception $e) {
            // Try to use local fallback in case of complete failure
            try {
                return $this->generateLocalFallbackResponse($messages, $itemsArray ?? []);
            } catch (\Exception $fallbackError) {
                return "I apologize, but something went wrong while processing your request.";
            }
        }
    }

    /**
     * Generate a stream chat response using the GWDG API
     *
     * @param array $messages
     * @param mixed $items
     * @param callable $callback Function to process each chunk as it's received
     * @return void
     */
    public function generateStreamChatResponse(array $messages, $items, callable $callback)
    {
        set_time_limit(120);

        $itemsArray  = $this->processItemsToArray($items);
        $itemsArray = $this->filterAndDedupeItems($itemsArray, 60);
        $chatRequest = $this->prepareApiRequest($messages, $itemsArray, true);
        Log::info('GWDG Final Chat Prompt', [
            'system' => $chatRequest['messages'][0]['content'],
            'user'   => $chatRequest['messages'][1]['content'] ?? '',
        ]);

        $client = new \GuzzleHttp\Client([
            'http_errors' => false,   // don’t throw on 4xx/5xx so we can fallback
        ]);

        $maxRetries  = 2;
        $retryCount  = 0;
        $retryDelay  = 1000; // ms

        $emitText = function ($txt) use ($callback) {
            if ($txt !== '') $callback($txt, false);
        };

        while ($retryCount <= $maxRetries) {
            try {
                $response = $client->post($this->config['api_url'], [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['api_key'],
                        'Content-Type'  => 'application/json',
                        'Accept'        => 'text/event-stream',
                        'Connection'    => 'keep-alive',
                    ],
                    'json'        => $chatRequest,
                    'stream'      => true,
                    'timeout'     => 60,
                    // NB: Guzzle ignores read_timeout here; timeout covers full transfer
                    'on_headers'  => function (\Psr\Http\Message\ResponseInterface $resp) {
                        $status = $resp->getStatusCode();
                        if ($status !== 200) {
                            throw new \RuntimeException("API returned error status: {$status}");
                        }
                    },
                ]);

                $body   = $response->getBody();
                $buffer = '';
                $chunks = 0;
                $startedAt = microtime(true);

                // read in reasonable chunks
                while (!$body->eof()) {
                    $chunk = $body->read(8192);
                    if ($chunk === '') {
                        // watchdog: if nothing arrived for 10s, break (provider stalled)
                        if ((microtime(true) - $startedAt) > 10 && $chunks === 0) break;
                        usleep(30_000);
                        continue;
                    }

                    if ($chunks === 0) {
                        Log::info('GWDG first bytes', ['preview' => mb_substr($chunk, 0, 120)]);
                    }

                    $buffer .= $chunk;

                    // split on any newline (\n or \r\n)
                    $lines = preg_split("/\r\n|\n|\r/", $buffer);
                    // keep last partial line in buffer
                    $buffer = array_pop($lines) ?? '';

                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '' || $line === ':' || $line === 'data:') {
                            continue;
                        }

                        // Handle SSE "data: ..."
                        if (str_starts_with($line, 'data: ')) {
                            $line = substr($line, 6);
                        }

                        if ($line === '[DONE]' || $line === 'data: [DONE]') {
                            continue;
                        }

                        $data = json_decode($line, true);
                        if (!is_array($data)) {
                            // Some servers send multiple "data: {..}" on one line; try to extract JSONs
                            foreach (preg_split('/\s*data:\s*/', $line) as $maybe) {
                                $maybe = trim($maybe);
                                if ($maybe === '' || $maybe === '[DONE]') continue;
                                $j = json_decode($maybe, true);
                                if (is_array($j)) $data = $j;
                            }
                        }

                        if (is_array($data)) {
                            $delta   = $data['choices'][0]['delta']   ?? null;
                            $message = $data['choices'][0]['message'] ?? null;

                            // OpenAI-style: delta.content
                            $txt = $delta['content'] ?? null;

                            // Some providers send message.content instead during stream
                            if ($txt === null && is_array($message)) {
                                $txt = $message['content'] ?? null;
                            }

                            // Some send content as array of parts: [{type:'text', text:'...'}]
                            if ($txt === null && isset($delta['content'][0]['text'])) {
                                $txt = $delta['content'][0]['text'];
                            }

                            if (is_string($txt) && $txt !== '') {
                                $emitText($txt);
                                $chunks++;
                            }
                        }
                    }
                }

                // flush any residual json left in buffer
                $line = trim($buffer);
                if ($line !== '' && $line !== ':' && $line !== 'data:' && $line !== 'data: [DONE]' && $line !== '[DONE]') {
                    if (str_starts_with($line, 'data: ')) $line = substr($line, 6);
                    $data = json_decode($line, true);
                    if (is_array($data)) {
                        $txt = $data['choices'][0]['delta']['content']
                            ?? ($data['choices'][0]['message']['content'] ?? null)
                            ?? ($data['choices'][0]['delta']['content'][0]['text'] ?? null);
                        if (is_string($txt) && $txt !== '') {
                            $emitText($txt);
                            $chunks++;
                        }
                    }
                }

                // if nothing streamed, send local fallback once
                if ($chunks === 0) {
                    Log::warning('GWDG stream produced zero deltas; using local fallback');
                    $this->streamLocalFallbackResponse($messages, $itemsArray, $callback);
                }

                $callback('', true);
                return;
            } catch (\Throwable $e) {
                // retry on known transient errors
                if ($retryCount < $maxRetries && $this->isRetryableError($e->getMessage() ?? '')) {
                    $retryCount++;
                    $callback("…retrying the chat server ({$retryCount})…", false);
                    usleep($retryDelay * 1000);
                    $retryDelay *= 2;
                    continue;
                }

                // server-side error? stream fallback
                if ($this->isServerErrorMessage($e->getMessage() ?? '')) {
                    $this->streamLocalFallbackResponse($messages, $itemsArray, $callback);
                    $callback('', true);
                    return;
                }

                Log::error('GWDG stream fatal', ['err' => $e->getMessage()]);
                $callback("I apologize, but the chat server failed: {$e->getMessage()}", false);
                $callback('', true);
                return;
            }
        }

        // end while
    }


    /**
     * Validate GWDG provider configuration
     * 
     * @throws \Exception
     */
    private function validateConfiguration(): void
    {
        if (empty($this->config)) {
            throw new \Exception("GWDG provider configuration not found. Please check your model_providers.php config file.");
        }

        if (
            empty($this->config['api_key']) || !preg_match('/^[A-Za-z0-9._-]+$/', $this->config['api_key'])
            || strlen($this->config['api_key']) < 10
        ) {
            throw new \Exception("GWDG_API_KEY must be a valid API key (alphanumeric, minimum 10 characters). Please check your .env file.");
        }

        if (empty($this->config['api_url']) || !filter_var($this->config['api_url'], FILTER_VALIDATE_URL) || !str_starts_with($this->config['api_url'], 'http')) {
            throw new \Exception("GWDG_API_URL must be a valid HTTP/HTTPS URL. Please check your .env file.");
        }
    }

    /**
     * Check if a status code represents a server error (5xx)
     * 
     * @param int $statusCode
     * @return bool
     */
    private function isServerError(int $statusCode): bool
    {
        return $statusCode === 502 || $statusCode === 503 || $statusCode === 504;
    }

    /**
     * Check if an error message indicates a server error
     * 
     * @param string $errorMessage
     * @return bool
     */
    private function isServerErrorMessage(string $errorMessage): bool
    {
        return strpos($errorMessage, "API returned error status: 502") !== false ||
            strpos($errorMessage, "API returned error status: 503") !== false ||
            strpos($errorMessage, "API returned error status: 504") !== false;
    }

    /**
     * Check if an error message indicates a retryable error
     * 
     * @param string $errorMessage
     * @return bool
     */
    private function isRetryableError(string $errorMessage): bool
    {
        return $this->isServerErrorMessage($errorMessage) ||
            strpos($errorMessage, "cURL error") !== false ||
            strpos($errorMessage, "timed out") !== false;
    }

    /**
     * Prepare common system message and chat request for API calls
     *
     * @param array $messages
     * @param array $itemsArray
     * @param bool $isStreaming
     * @return array
     */
    private function prepareApiRequest(array $messages, array $itemsArray, bool $isStreaming = false): array
    {
        $basicPagesList = $this->formatBasicPagesList($itemsArray);
        $extendedPagesList = $this->formatExtendedPagesList($itemsArray);

        // Format the lists as strings for template replacement
        $basicPagesString = implode("\n\n", $basicPagesList);
        $extendedPagesString = implode("\n\n", $extendedPagesList);

        if (count($itemsArray) > 0) {
            $systemContent = str_replace(
                ['{basicPagesList}', '{extendedPagesList}'],
                [$basicPagesString, $extendedPagesString],
                $this->prompts['chat']
            );

            // Add URL preservation instruction to ensure digits are preserved
            $systemContent .= "\n\nIMPORTANT: Page URLs must be displayed exactly as provided with all digits preserved. Example: https://projekte.g.hawk.de/projekt/5df0c5b794683";
        } else {
            $systemContent = $this->prompts['chat_empty'];
        }

        $systemMessage = [
            'role' => 'system',
            'content' => $systemContent
        ];

        $chatRequest = [
            'model' => $this->config['models']['multilingual'],
            'messages' => array_merge([$systemMessage], $messages),
            'temperature' => 0.7,
            'max_tokens' => 1024,
            'top_p' => 0.9
        ];

        // Add streaming parameter if needed
        if ($isStreaming) {
            $chatRequest['stream'] = true;
        }

        return $chatRequest;
    }

    /**
     * Generate a local fallback response when the API is unavailable
     * 
     * @param array $messages
     * @param array $items
     * @return string
     */
    protected function generateLocalFallbackResponse(array $messages, array $items)
    {
        // Get the last user message
        $lastUserMessage = collect($messages)
            ->where('role', 'user')
            ->last();

        $userQuery = $lastUserMessage['content'] ?? '';

        // Extract titles for the top 3 pages
        $pageTitles = array_slice(array_column($items, 'title'), 0, 3);

        // Build a simple response using sprintf for better performance
        $response = sprintf("I found some pages that might be relevant to your interest in %s.\n\n", $userQuery);

        if (count($pageTitles) > 0) {
            $response .= "Here are some pages you might want to explore:\n";

            // Use iterator for potentially large arrays
            $pageList = '';
            $iterator = new \ArrayIterator($pageTitles);
            for ($iterator->rewind(); $iterator->valid(); $iterator->next()) {
                $index = $iterator->key();
                $title = $iterator->current();
                $pageList .= sprintf("%d. %s\n", $index + 1, $title);
            }

            // Use string interpolation instead of concatenation
            $response .= "{$pageList}\nYou can see more details by clicking on these pages.";
        } else {
            $response .= "Unfortunately, I couldn't find specific pages matching your query. Please try a different search term or browse our site content.";
        }

        return $response;
    }

    /**
     * Stream a local fallback response when the API is unavailable
     * 
     * @param array $messages
     * @param array $items
     * @param callable $callback
     * @return void
     */
    protected function streamLocalFallbackResponse(array $messages, array $items, callable $callback)
    {
        // Generate the fallback response
        $response = $this->generateLocalFallbackResponse($messages, $items);

        // Split the response into chunks to simulate stream
        $chunks = str_split($response, 20); // Split into smaller chunks

        // Simulate network delay for the first chunk
        usleep(500000); // 500ms delay

        // Use iterator for potentially large arrays instead of foreach
        $iterator = new \ArrayIterator($chunks);
        for ($iterator->rewind(); $iterator->valid(); $iterator->next()) {
            $index = $iterator->key();
            $chunk = $iterator->current();

            // Only add delay after the first chunk
            if ($index > 0) {
                usleep(50000); // 50ms delay between chunks
            }

            $callback($chunk, false);
        }

        // Final empty chunk to signal completion
        $callback("", true);
    }
}
