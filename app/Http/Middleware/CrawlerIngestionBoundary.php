<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Requests\Pipeline\ListFailedPipelineJobsRequest;
use App\Http\Requests\Pipeline\ListPipelineTasksRequest;
use App\Services\Authorization\ManagedMutationPrincipalService;
use App\Services\Pipeline\Clients\PythonUrlIngestionClient;
use App\Services\Pipeline\PipelineCompatibilityBoundary;
use App\Services\Pipeline\PipelineOwnership;
use App\Services\Pipeline\Repositories\PipelineOwnershipRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class CrawlerIngestionBoundary
{
    public function __construct(
        private ConfigRepository $config,
        private ManagedMutationPrincipalService $principals,
        private PythonUrlIngestionClient $python,
        private PipelineOwnership $ownership,
        private PipelineOwnershipRepository $legacy,
        private PipelineCompatibilityBoundary $compatibility,
        private ValidatorFactory $validator,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $backend = $this->config->get('config.crawler_ingestion_backend', 'laravel');
        if (! in_array($backend, ['laravel', 'python'], true)) {
            return response()->json(['success' => false, 'message' => 'Crawler ingestion backend configuration is invalid.'], 503);
        }
        if ($backend === 'laravel' && $request->isMethod('GET')) {
            return $next($request);
        }
        if (strlen($request->getContent()) > 1024 * 1024) {
            return response()->json(['success' => false, 'message' => 'The URL ingestion request is too large.'], 413);
        }
        $user = $this->principals->resolve($request);
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
        if ($backend === 'laravel') {
            return $next($request);
        }
        $path = substr($request->path(), strlen('api/'));
        if (str_starts_with($path, 'scraper/')) {
            return response()->json(['success' => false, 'message' => 'Use Python-owned pipeline URL operations.'], 409);
        }
        if ($request->isMethod('GET') && in_array($path, ['pipeline/tasks', 'pipeline/recovery/failed-jobs'], true)) {
            $rules = $path === 'pipeline/tasks' ? (new ListPipelineTasksRequest)->rules() : (new ListFailedPipelineJobsRequest)->rules();
            $filters = $this->validator->make($request->query(), $rules)->validate();
            $result = $this->compatibility->inventory($user, $path === 'pipeline/tasks' ? 'tasks' : 'jobs', $filters);

            return response()->json($result['payload'], $result['status']);
        }
        if (! $request->isMethod('GET') && str_starts_with($path, 'pipeline/recovery/')) {
            $result = $this->compatibility->recover($user, $request);

            return response()->json($result['payload'], $result['status']);
        }
        $field = is_string($request->route('jobId')) ? 'job_id' : (is_string($request->route('taskId')) ? 'task_id' : null);
        if ($field !== null) {
            $identity = $request->route($field === 'job_id' ? 'jobId' : 'taskId');
            $ownership = $this->ownership->classify($field, $identity);
            if ($ownership['status'] !== 200) {
                return response()->json($ownership['payload'], $ownership['status']);
            }
            $owner = $ownership['payload']['owner'];
            if ($owner === 'laravel') {
                $candidates = $field === 'job_id' ? $this->legacy->jobs() : $this->legacy->tasks();
                $candidates = array_values(array_filter($candidates, static fn ($row): bool => $row[$field] === $identity));
                $access = $this->compatibility->access($user, $candidates, $field);
                if ($access['status'] !== 200 || ! in_array($identity, $access['payload']['identities'] ?? [], true)) {
                    return response()->json($access['status'] !== 200 ? $access['payload'] : ['success' => false, 'message' => 'Pipeline operation was not found.'], $access['status'] !== 200 ? $access['status'] : 404);
                }

                return $next($request);
            }
            if (! in_array($owner, ['url', 'managed', 'text'], true) || ! $request->isMethod('GET')) {
                return response()->json(['success' => false, 'message' => 'Use the owning source, document, or text API for this operation.'], 409);
            }
        }
        // Log downloads use the same authorized JSON projection as the viewer.
        $download = str_ends_with($path, '/logs/download');
        $remotePath = $download ? substr($path, 0, -strlen('/download')) : $path;
        $result = $this->python->forward($user, $request->method(), $remotePath, $request->all(), $request->header('Idempotency-Key'));
        if ($download && $result['status'] === 200 && is_string($result['payload']['log']['text'] ?? null)) {
            return response($result['payload']['log']['text'], 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="pipeline-log.txt"']);
        }

        return response()->json($result['payload'], $result['status']);
    }
}
