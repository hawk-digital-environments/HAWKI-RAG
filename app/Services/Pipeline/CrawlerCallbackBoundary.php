<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Services\Document\ManagedCallbackBoundary;
use App\Services\Pipeline\Clients\PythonUrlIngestionClient;
use App\Services\TextIngestion\Clients\PythonTextIngestionClient;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class CrawlerCallbackBoundary
{
    public function __construct(
        private ConfigRepository $config,
        private PythonUrlIngestionClient $python,
        private ManagedCallbackBoundary $managed,
        private PipelineOwnership $ownership,
        private PythonTextIngestionClient $text,
    ) {}

    public function relay(string $workflowId, string $raw, string $kind): ?array
    {
        $backend = $this->config->get('config.crawler_ingestion_backend', 'laravel');
        if ($backend === 'laravel') {
            $body = json_decode($raw, true);
            if (($body['owner'] ?? null) === 'url' || isset($body['url_ingestion_operation_id'])) {
                return ['status' => 409, 'payload' => ['success' => false, 'message' => 'Python URL callbacks must be drained before rollback.']];
            }

            return $this->managed->relay($workflowId, $raw, $kind);
        }
        if ($backend !== 'python') {
            return ['status' => 503, 'payload' => ['success' => false, 'message' => 'Crawler ingestion backend configuration is invalid.']];
        }
        $ownership = $this->ownership->classify('workflow_id', $workflowId);
        if ($ownership['status'] !== 200) {
            return $ownership;
        }

        $body = json_decode($raw, true);
        $owner = $ownership['payload']['owner'] ?? null;
        if ((isset($body['url_ingestion_operation_id']) || ($body['owner'] ?? null) === 'url') && $owner !== 'url'
            || isset($body['managed_document_id']) && $owner !== 'managed') {
            return ['status' => 409, 'payload' => ['success' => false, 'message' => 'Callback owner conflicts with durable ownership.']];
        }

        return match ($owner) {
            'url' => $this->python->relay($raw, $kind),
            'managed' => $this->config->get('config.managed_document_backend', 'laravel') === 'python'
                ? $this->managed->relay($workflowId, $raw, $kind)
                : ['status' => 409, 'payload' => ['success' => false, 'message' => 'Managed callback ownership requires the Python managed backend.']],
            'text' => $this->config->get('config.text_ingestion_backend', 'laravel') === 'python'
                ? ($kind === 'events' ? $this->text->relayEvent($raw) : $this->text->relayWriter($raw))
                : ['status' => 409, 'payload' => ['success' => false, 'message' => 'Text callback ownership requires the Python text backend.']],
            'laravel' => null,
            default => ['status' => 409, 'payload' => ['success' => false, 'message' => 'Unknown workflow ownership.']],
        };
    }
}
