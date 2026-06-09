<?php

declare(strict_types=1);

namespace App\Services\Graph;

use Psr\Log\LoggerInterface;

class Neo4jClient
{
    public function __construct(
        private readonly Neo4jQueryClient $queries,
        private readonly GraphResultNormalizer $normalizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function run(string $statement, array $parameters = [], bool $includeGraph = true): array
    {
        $payload = $this->queries->postStatements([[
            'statement' => $statement,
            'parameters' => $parameters,
            'resultDataContents' => $includeGraph ? ['row', 'graph'] : ['row'],
        ]]);

        return $this->normalizer->records($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function clearAll(): array
    {
        try {
            $payload = $this->queries->postStatements([['statement' => 'MATCH (n) DETACH DELETE n']]);

            return [
                'ok' => true,
                'stats' => $payload['results'][0]['stats'] ?? null,
            ];
        } catch (\Throwable $exception) {
            $this->logger->warning('Neo4j clear exception', ['exception' => $exception]);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }
}
