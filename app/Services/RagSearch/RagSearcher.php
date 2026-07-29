<?php

declare(strict_types=1);

namespace App\Services\RagSearch;

use App\Models\User;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use App\Services\Authorization\Values\AuthorizedDatasetScope;
use App\Services\RagSearch\Exceptions\RagSearcherFailedException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Factory as HttpFactory;

class RagSearcher
{
    private ?string $query = null;

    private int $topK = 15;

    private ?AuthorizedDatasetScope $scope = null;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly RagSearchPayloadFactory $payloads,
        private readonly RagSearchSchemaFactory $schemas,
        private readonly RagSearchResponseFilter $responses,
        private readonly DatasetQueryAuthorizationService $authorization,
        #[Config('config.hawki_rag_bridge_url')]
        private readonly string $baseUrl,
    ) {}

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function withQuery(?string $query): static
    {
        $clone = clone $this;
        $clone->query = $query;

        return $clone;
    }

    public function getQuery(): ?string
    {
        return $this->query;
    }

    public function withTopK(int $topK): static
    {
        if ($topK < 1) {
            throw RagSearcherFailedException::invalidTopK($topK);
        }

        $clone = clone $this;
        $clone->topK = $topK;

        return $clone;
    }

    public function getTopK(): int
    {
        return $this->topK;
    }

    public function forDataset(User $user, string $datasetId): static
    {
        $clone = clone $this;
        $clone->scope = $this->authorization->authorize($user, $datasetId);

        return $clone;
    }

    public function getResponseSchema(JsonSchema $schema): array
    {
        return $this->schemas->make($schema);
    }

    public function execute(): array
    {
        if (empty($this->query)) {
            throw RagSearcherFailedException::missingQuery();
        }

        if (! $this->scope instanceof AuthorizedDatasetScope) {
            throw RagSearcherFailedException::missingAuthorizedDatasetScope();
        }

        $baseUrl = rtrim($this->baseUrl, '/');

        try {
            $response = $this->http->timeout(60)
                ->post($baseUrl.'/query', $this->payloads->make($this->query, $this->topK, $this->scope));

            if (! $response->successful()) {
                throw RagSearcherFailedException::backendRequestFailed($this->query, $baseUrl);
            }

            $body = $response->json();

            return $this->responses->filter(is_array($body) ? $body : []);
        } catch (RagSearcherFailedException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw RagSearcherFailedException::connectionFailed($this->query, $exception);
        }
    }
}
