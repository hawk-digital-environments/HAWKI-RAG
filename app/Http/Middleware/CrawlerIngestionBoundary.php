<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Requests\Pipeline\ListFailedPipelineJobsRequest;
use App\Http\Requests\Pipeline\ListPipelineTasksRequest;
use App\Services\Authorization\ManagedMutationPrincipalService;
use App\Services\Pipeline\Clients\PythonUrlIngestionClient;
use App\Services\Pipeline\PipelineCompatibilityBoundary;
use App\Services\Pipeline\PipelineExecutionBackend;
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
        private PipelineExecutionBackend $execution,
        private ManagedMutationPrincipalService $principals,
        private PythonUrlIngestionClient $python,
        private PipelineOwnership $ownership,
        private PipelineOwnershipRepository $legacy,
        private PipelineCompatibilityBoundary $compatibility,
        private ValidatorFactory $validator,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $backend = $this->config->get('config.crawler_ingestion_backend', 'python');
        if (! $this->execution->valid() || ! in_array($backend, ['laravel', 'python'], true)) {
            return response()->json(['success' => false, 'message' => 'Pipeline or crawler execution backend configuration is invalid.'], 503);
        }
        $path = substr($request->path(), strlen('api/'));
        if ($path === 'scraper/tasks' && $request->isMethod('GET')) {
            return $next($request);
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
        $startOwner = null;
        if ($path === 'pipeline/tasks/start' && $backend === 'python') {
            // A lost BFF response may leave only the caller's idempotency key.
            // Resolve that durable request before treating a rollback replay as new.
            if ($this->execution->value() === 'laravel' && $request->header('Idempotency-Key') !== null) {
                $previous = $this->python->forward($user, 'GET', 'pipeline/start-request', ['key' => $request->header('Idempotency-Key')], null);
                if ($previous['status'] !== 200 || ! in_array($previous['payload']['owner'] ?? null, ['url', 'absent'], true)) {
                    return response()->json($previous['status'] !== 200 ? $previous['payload'] : ['success' => false, 'message' => 'Invalid URL start ownership.'], $previous['status'] !== 200 ? $previous['status'] : 502);
                }
                if ($previous['payload']['owner'] === 'url') {
                    $previousId = $previous['payload']['task_id'] ?? null;
                    if (! is_string($previousId)) {
                        return response()->json(['success' => false, 'message' => 'Invalid URL start identity.'], 502);
                    }
                    $owner = $this->ownership->classify('task_id', $previousId);
                    if ($owner['status'] !== 200 || $owner['payload']['owner'] !== 'url') {
                        return response()->json($owner['payload'], $owner['status'] !== 200 ? $owner['status'] : 409);
                    }
                    $startOwner = 'url';
                }
            }
            $identity = $request->input('task_id', $request->input('taskId'));
            if (is_string($identity) && trim($identity) !== '') {
                $classification = $this->ownership->classify('task_id', trim($identity), allowAbsent: true);
                if ($classification['status'] !== 200) {
                    return response()->json($classification['payload'], $classification['status']);
                }
                $submittedOwner = $classification['payload']['owner'];
                $startOwner = $startOwner === 'url' ? 'url' : $submittedOwner;
                if (! in_array($submittedOwner, ['url', 'absent'], true)) {
                    return response()->json(['success' => false, 'message' => 'Task identity already belongs to existing work; use its recovery API.'], 409);
                }
            }
        }
        if ($path === 'pipeline/tasks/start' && $this->execution->value() === 'laravel' && $startOwner !== 'url') {
            return $next($request);
        }
        if ($path === 'pipeline/tasks/start' && ($this->python->readiness()['ready'] ?? false) !== true) {
            return response()->json(['success' => false, 'message' => 'Python pipeline execution is not ready.'], 503);
        }
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

                if ($request->isMethod('POST') && $path === 'pipeline/tasks/'.$identity.'/jobs') {
                    $jobId = $request->input('job_id', $request->input('jobId'));
                    if (! is_string($jobId) || ! $this->legacy->belongsToTask($jobId, $identity)) {
                        return response()->json(['success' => false, 'message' => 'Legacy job ownership is not proven.'], 409);
                    }
                    $jobOwner = $this->ownership->classify('job_id', $jobId);
                    if ($jobOwner['status'] !== 200 || ($jobOwner['payload']['owner'] ?? null) !== 'laravel') {
                        return response()->json(['success' => false, 'message' => 'Legacy job ownership is unavailable or conflicting.'], 409);
                    }
                }

                return $next($request);
            }
            $urlCommand = $owner === 'url' && ($request->isMethod('DELETE') && $path === 'pipeline/tasks/'.$identity
                || $request->isMethod('POST') && $path === 'pipeline/tasks/'.$identity.'/cancel');
            if (! in_array($owner, ['url', 'managed', 'text'], true) || (! $request->isMethod('GET') && ! $urlCommand)) {
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
