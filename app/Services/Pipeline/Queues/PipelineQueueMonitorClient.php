<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Queues;

use App\Services\Pipeline\Exceptions\PipelineQueueMonitorException;
use App\Services\Pipeline\Events\PipelineEventConfig;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class PipelineQueueMonitorClient
{
    public function __construct(
        private HttpFactory $http,
        private PipelineEventConfig $config,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function fetchQueues(int $timeout): array
    {
        $url = $this->managementUrl();
        if ($url === '') {
            throw PipelineQueueMonitorException::missingManagementUrl();
        }

        $response = $this->http->timeout($timeout)
            ->connectTimeout($timeout)
            ->withBasicAuth(
                $this->config->rabbitUser(),
                $this->config->rabbitPassword(),
            )
            ->acceptJson()
            ->get($url.'/api/queues/'.rawurlencode($this->config->rabbitVhost()));

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
        return $this->config->rabbitManagementUrl();
    }
}
