<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Authorization\DatasetQueryAuthorizationService;

final readonly class DatasetQueryPolicy
{
    public function __construct(
        private DatasetQueryAuthorizationService $authorization,
    ) {}

    public function query(User $user, string $datasetId): bool
    {
        return $this->authorization->canQuery($user, $datasetId);
    }
}
