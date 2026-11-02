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
            'python' => $this->browserResponse($this->python->query($user, $data)),
            default => $this->invalidBackend(),
        };
    }

    /** @return array{payload: array<string, mixed>, status: int} */
    private function invalidBackend(): array
    {
        $this->logger->error('Query backend configuration is invalid.');

        return ['status' => 503, 'payload' => ['ok' => false,
            'error' => 'query_backend_configuration_invalid',
            'message' => 'Query execution is not available.',
        ]];
    }

    /** Preserve the Laravel browser error envelope without exposing Python's detail contract.
     * @param  array{status: int, payload: mixed}  $result
     * @return array{status: int, payload: mixed}
     */
    private function browserResponse(array $result): array
    {
        if ($result['status'] < 400) {
            return $result;
        }
        $payload = (array) $result['payload'];
        $detail = $payload['detail'] ?? null;
        if (is_array($detail) || is_object($detail)) {
            $detail = (array) $detail;
            $error = $detail['error'] ?? 'query_failed';
            $message = $detail['message'] ?? 'Query execution failed.';
        } else {
            $error = is_string($detail) ? $detail : 'query_failed';
            $message = 'Query execution failed.';
        }
        $result['payload'] = ['ok' => false, 'error' => $error, 'message' => $message];
        if ($result['status'] === 422) {
            $result['payload'] = ['ok' => false, 'error' => 'validation_error',
                'message' => 'The given data was invalid.',
                'errors' => ['query' => ['The query could not be validated.']]];
        }

        return $result;
    }
}
