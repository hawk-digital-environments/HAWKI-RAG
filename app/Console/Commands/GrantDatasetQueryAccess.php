<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Authorization\DatasetQueryAuthorizationService;
use App\Services\Dataset\DatasetRepository;
use App\Services\User\Repositories\UserRepository;
use Illuminate\Console\Command;

class GrantDatasetQueryAccess extends Command
{
    protected $signature = 'dataset:grant-query {dataset_id : Logical dataset identifier} {user_id : Local user ID}';

    protected $description = 'Grant a local user permission to query one dataset';

    public function __construct(
        private readonly DatasetRepository $datasets,
        private readonly UserRepository $users,
        private readonly DatasetQueryAuthorizationService $authorization,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $datasetId = trim((string) $this->argument('dataset_id'));
        $userId = trim((string) $this->argument('user_id'));
        $dataset = $this->datasets->findByDatasetId($datasetId);
        $user = $this->users->findById($userId);

        if ($dataset === null) {
            $this->error('Dataset not found.');

            return self::FAILURE;
        }

        if ($user === null || (bool) $user->isRemoved) {
            $this->error('Active user not found.');

            return self::FAILURE;
        }

        $this->authorization->grantQueryAccess($user, $dataset);
        $this->info(sprintf('Granted user %s query access to dataset %s.', $user->getAuthIdentifier(), $dataset->dataset_id));

        return self::SUCCESS;
    }
}
