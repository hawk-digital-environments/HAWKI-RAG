<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Services\Graph\Exceptions\Neo4jClientException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class Neo4jQueryClient
{
    public function __construct(
        private HttpFactory $http,
        private ConfigRepository $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $statements
     * @return array<string, mixed>
     */
    public function postStatements(array $statements, bool $requireValidEnvelope = false): array
    {
        $response = $this->http->timeout(20)
            ->withBasicAuth($this->user(), $this->password())
            ->post($this->endpoint(), ['statements' => $statements]);

        if (! $response->successful()) {
            $this->logger->warning('Neo4j HTTP request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw Neo4jClientException::httpError($response->status(), $response->body());
        }

        if ($requireValidEnvelope) {
            // Preserve JSON object/array distinctions before associative decoding.
            $envelope = json_decode($response->body());
            if (json_last_error() !== JSON_ERROR_NONE || ! $envelope instanceof \stdClass
                || ! is_array($envelope->results ?? null) || ! is_array($envelope->errors ?? null)) {
                throw Neo4jClientException::invalidClearAcknowledgement();
            }
            foreach ($envelope->results as $result) {
                if (! $result instanceof \stdClass || ! is_array($result->columns ?? null)
                    || ! is_array($result->data ?? null)) {
                    throw Neo4jClientException::invalidClearAcknowledgement();
                }
                foreach ($result->data as $data) {
                    if (! $data instanceof \stdClass || ! is_array($data->row ?? null)) {
                        throw Neo4jClientException::invalidClearAcknowledgement();
                    }
                }
            }
        }
        $payload = $response->json() ?: [];
        $errors = $payload['errors'] ?? [];
        if (! empty($errors) && is_array($errors)) {
            throw Neo4jClientException::queryErrors($errors);
        }

        return is_array($payload) ? $payload : [];
    }

    private function endpoint(): string
    {
        $baseUrl = rtrim((string) $this->config->get('config.neo4j_http_url', 'http://hawki_rag_neo4j:7474'), '/');
        $database = trim((string) $this->config->get('config.neo4j_database', 'neo4j')) ?: 'neo4j';

        return $baseUrl.'/db/'.rawurlencode($database).'/tx/commit';
    }

    private function user(): string
    {
        return (string) $this->config->get('config.neo4j_user', 'neo4j');
    }

    private function password(): string
    {
        return (string) $this->config->get('config.neo4j_password', '');
    }
}
