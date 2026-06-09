<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Services\DirectIngest\CrawledDataFolderService;
use App\Services\DirectIngest\DirectIngestLaunchService;
use App\Services\DirectIngest\DirectIngestStatusService;
use App\Services\DirectIngest\DirectIngestStatusStore;
use App\Services\DirectIngest\DirectIngestStopService;
use App\Services\Pipeline\Queues\PipelineQueueMonitorService;
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
        public PipelineQueueMonitorService $queues,
        public CrawledDataFolderService $crawledFolders,
        public DirectIngestLaunchService $directIngestLaunches,
        public DirectIngestStatusService $directIngestStatuses,
        public DirectIngestStatusStore $directIngestStatusStore,
        public DirectIngestStopService $directIngestStops,
    ) {}
}
