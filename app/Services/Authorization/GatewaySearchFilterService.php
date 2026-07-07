<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Services\Rag\CanonicalFilterExpressionSerializer;
use App\Services\Rag\FilterLanguageParser;
use App\Services\Rag\Values\FilterExpression;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class GatewaySearchFilterService
{
    public function __construct(
        private ApplicationReadPolicy $policy,
        private FilterLanguageParser $filters,
        private CanonicalFilterExpressionSerializer $serializer,
    ) {}

    /**
     * @param array<string, mixed> $clientFilters
     * @return array<string, mixed>
     */
    public function build(array $clientFilters, ApiActor $actor, ?string $requestedUserIdentifier = null): array
    {
        $scope = $this->policy->documentScope($actor, $requestedUserIdentifier);
        $expression = $this->filters->parse($clientFilters);

        return $this->serializer->serialize($this->merge($scope->searchExpression, $expression));
    }

    private function merge(FilterExpression $scope, FilterExpression $client): FilterExpression
    {
        if ($scope->isEmpty()) {
            return $client;
        }

        if ($client->isEmpty()) {
            return $scope;
        }

        return FilterExpression::group('AND', [$scope, $client]);
    }
}
