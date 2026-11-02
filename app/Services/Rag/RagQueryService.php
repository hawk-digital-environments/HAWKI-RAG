<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Models\User;
use App\Services\Rag\Clients\PythonQueryClient;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class RagQueryService
{
    public function __construct(
        private ConfigRepository $config,
        private RagProxyService $legacy,
        private PythonQueryClient $python,
        private LoggerInterface $logger,
    ) {}

    /** @param array<string, mixed> $data
     * @return array{payload: mixed, status: int}
     */
    public function query(User $user, array $data): array
    {
        return match ($this->config->get('config.query_backend', 'laravel')) {
            'laravel' => $this->legacy->query($user, $data),
            'python' => $this->python->query($user, $data),
            default => $this->invalidBackend(),
        };
    }

    /** @return array{payload: array<string, mixed>, status: int} */
    private function invalidBackend(): array
    {
        $this->logger->error('Query backend configuration is invalid.');

        return ['status' => 503, 'payload' => ['detail' => [
            'error' => 'query_backend_configuration_invalid',
            'message' => 'Query execution is not available.',
        ]]];
    }
}
