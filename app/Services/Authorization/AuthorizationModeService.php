<?php
declare(strict_types=1);

namespace App\Services\Authorization;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class AuthorizationModeService
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('authz.enabled', false);
    }

    public function documentApiEnforced(): bool
    {
        return $this->enabled()
            && (bool) $this->config->get('authz.document_api_enforced', false);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function sanitizeHeapFilters(array $filters): array
    {
        if ($this->enabled()) {
            return $filters;
        }

        unset($filters['protected']);

        return $filters;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sanitizeHeapInput(array $input): array
    {
        if ($this->enabled()) {
            return $input;
        }

        unset($input['protected']);

        return $input;
    }
}
