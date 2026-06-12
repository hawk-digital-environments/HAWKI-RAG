<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Services\Pipeline\Recovery\PipelineRecoveryService;
use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Pipeline\Status\PipelineStatusService;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use App\Services\Pipeline\Uploads\PipelineUploadService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineService
{
    public function __construct(
        public PipelineStateService $state,
        public PipelineStatusService $status,
        public PipelineTaskService $tasks,
        public PipelineRecoveryService $recovery,
        public PipelineUploadService $uploads,
    ) {}
}
