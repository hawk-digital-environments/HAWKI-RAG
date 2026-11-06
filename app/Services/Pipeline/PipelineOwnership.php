<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Services\Pipeline\Clients\PythonUrlIngestionClient;
use App\Services\Pipeline\Repositories\PipelineOwnershipRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineOwnership
{
    public function __construct(private PythonUrlIngestionClient $python, private PipelineOwnershipRepository $legacy) {}

    /** A remote absence requires positive local evidence before legacy dispatch. */
    public function classify(string $field, string $identity): array
    {
        $remote = $this->python->relay(json_encode([$field => $identity], JSON_THROW_ON_ERROR), 'ownership');
        if ($remote['status'] !== 200) {
            return $remote;
        }
        $owner = $remote['payload']['owner'] ?? null;
        if (in_array($owner, ['url', 'managed', 'text'], true)) {
            if ($this->legacy->exists($field, $identity)) {
                return $this->failure('Conflicting Python and Laravel ownership.');
            }

            return $remote;
        }
        if (in_array($owner, ['absent', 'laravel'], true) && $this->legacy->owns($field, $identity)) {
            return ['status' => 200, 'payload' => ['owner' => 'laravel']];
        }

        return $this->failure('Pipeline ownership is unknown or conflicting.');
    }

    private function failure(string $message): array
    {
        return ['status' => 409, 'payload' => ['success' => false, 'message' => $message]];
    }
}
