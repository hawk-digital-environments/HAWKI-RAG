<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Queues;

use App\Services\Pipeline\Exceptions\PipelineQueueMonitorException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Http;

#[Singleton]
readonly class PipelineQueueMonitorClient
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function fetchQueues(int $timeout): array
    {
        $url = $this->managementUrl();
        if ($url === '') {
            throw PipelineQueueMonitorException::missingManagementUrl();
        }

        $response = Http::timeout($timeout)
            ->connectTimeout($timeout)
            ->withBasicAuth(
                (string) config('communication.rabbitmq.user', 'guest'),
                (string) config('communication.rabbitmq.password', 'guest'),
            )
            ->acceptJson()
            ->get($url.'/api/queues/'.rawurlencode((string) config('communication.rabbitmq.vhost', '/')));

        if (! $response->successful()) {
            throw PipelineQueueMonitorException::unsuccessfulResponse($url, $response->status());
        }

        $queues = $response->json();
        if (! is_array($queues)) {
            throw PipelineQueueMonitorException::invalidQueuePayload();
        }

        $byName = [];
        foreach ($queues as $queue) {
            if (is_array($queue) && is_string($queue['name'] ?? null)) {
                $byName[$queue['name']] = $queue;
            }
        }

        return $byName;
    }

    public function managementUrl(): string
    {
        return rtrim((string) config('communication.rabbitmq.management_url', ''), '/');
    }
}
