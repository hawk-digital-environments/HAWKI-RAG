<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Services\Document\Clients\PythonManagedDocumentClient;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class ManagedCallbackBoundary
{
    public function __construct(private ConfigRepository $config, private PythonManagedDocumentClient $python) {}

    /** Null means proven Laravel ownership. Unknown ownership never falls back. */
    public function relay(string $workflowId, string $raw, string $kind): ?array
    {
        $backend = $this->config->get('config.managed_document_backend', 'laravel');
        if ($backend === 'laravel') {
            return null;
        }
        if ($backend !== 'python') {
            return ['status' => 503, 'payload' => ['success' => false, 'message' => 'Managed document backend configuration is invalid.']];
        }
        $ownership = $this->python->relay(json_encode(['workflow_id' => $workflowId], JSON_THROW_ON_ERROR), 'ownership');
        if ($ownership['status'] !== 200) {
            return $ownership;
        }
        if (($ownership['payload']['owner'] ?? null) === 'laravel') {
            return null;
        }

        return $this->python->relay($raw, $kind);
    }
}
