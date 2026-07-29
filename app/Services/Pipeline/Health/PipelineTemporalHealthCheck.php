<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Health;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class PipelineTemporalHealthCheck
{
    public function __construct(
        private ConfigRepository $config,
        private PipelineHealthResultFactory $results,
    ) {
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function check(): array
    {
        $address = (string) $this->config->get('temporal.address', 'temporal:7233');
        [$host, $port] = $this->hostAndPort($address);

        try {
            $socket = @fsockopen($host, $port, $errno, $error, 3.0);
            if (! is_resource($socket)) {
                return $this->results->failure(
                    'Temporal',
                    trim($error) !== '' ? $error : "Could not connect to {$host}:{$port}.",
                    'Start the temporal service and verify TEMPORAL_ADDRESS.',
                );
            }

            fclose($socket);

            return $this->results->ok(
                'Temporal',
                sprintf(
                    'Reachable at %s. Namespace: %s. Workflow task queue: %s.',
                    $address,
                    $this->config->get('temporal.namespace', 'default'),
                    $this->config->get('temporal.task_queues.workflow', 'rag-workflow-task-queue'),
                ),
            );
        } catch (\Throwable $exception) {
            return $this->results->failure(
                'Temporal',
                $exception->getMessage(),
                'Start the temporal service and verify TEMPORAL_ADDRESS.',
            );
        }
    }

    /**
     * @return array{string,int}
     */
    private function hostAndPort(string $address): array
    {
        if (str_starts_with($address, 'dns:///')) {
            $address = substr($address, strlen('dns:///'));
        }

        $parts = explode(':', $address, 2);

        return [
            $parts[0] !== '' ? $parts[0] : 'temporal',
            isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 7233,
        ];
    }
}
