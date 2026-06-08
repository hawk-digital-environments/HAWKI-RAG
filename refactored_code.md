# Laravel Refactor Log

This file tracks the Laravel refactor against the repo `SKILL.md` coding standard.

## Step 1: Architecture Audit

Status: completed as a read-only code audit.

Production code changed in this step: none.

Files changed in this step:

- `refactored_code.md` was added so the audit and learning notes are visible.

The goal of this step was to map the current Laravel structure before moving code. This avoids a risky broad refactor and gives us a clear order for small, testable changes.

## Standards From `SKILL.md`

The repo standard expects:

- Business logic grouped into light DDD domains under `App\Services\{Domain}`.
- Domain namespace names as singular nouns, for example `Pipeline`, `Scrape`, `Dataset`, `Document`.
- Controllers that only handle HTTP: validation, one service call, and response.
- Validation in `FormRequest` classes, not inline in controllers.
- JSON shaping in API Resources where practical.
- DB access inside repositories, not directly in controllers or services.
- Services that are stateless, dependency-injected, and registered as singletons.
- No service locator usage like `app()` inside services or models.
- Value objects and DTOs in `Values/`.
- Domain exceptions in `Exceptions/`, with marker interfaces and static factories.
- `declare(strict_types=1);` in every PHP file.
- PHP native classes fully qualified, for example `\Throwable`.

## Current Domain Map

Current service roots:

- `Datasets`
- `Documents`
- `FileConverter`
- `GraphService`
- `Pipeline`
- `Profile`
- `Rag`
- `RagSearch`
- `ScrapeService`
- `StorageService`
- `WebSearchService`

Suggested target domain roots:

- `Dataset`
- `Document`
- `FileConversion`
- `Graph`
- `Pipeline`
- `Profile`
- `Rag`
- `RagSearch`
- `Scrape`
- `Storage`
- `WebSearch`

Reason: the skill asks for singular domain nouns and discourages suffixing domain names with technical labels like `Service`.

## Main Findings

### 1. Strict Types Are Mostly Missing

There are 95 PHP files under `app/`, but only 3 currently declare strict types.

Target pattern:

```php
<?php
declare(strict_types=1);

namespace App\Services\Pipeline;
```

Refactor approach: add `declare(strict_types=1);` only when touching a file for a focused refactor. Do not mass-edit every file in one commit.

### 2. FormRequests And API Resources Are Missing

I did not find `app/Http/Requests` or `app/Http/Resources`.

Current controllers often call `$request->validate(...)` inline. Examples:

- `DatasetController`
- `DocumentBrowserController`
- `PipelineTaskController`
- `PipelineControlController`
- `API/IngestController`

Target:

- `app/Http/Requests/Dataset/CreateDatasetRequest.php`
- `app/Http/Requests/Document/ListDocumentsRequest.php`
- `app/Http/Requests/Pipeline/StartPipelineTaskRequest.php`
- `app/Http/Resources/Dataset/DatasetResource.php`
- `app/Http/Resources/Document/DocumentResource.php`
- `app/Http/Resources/Pipeline/PipelineTaskResource.php`

This is a good first real refactor because it reduces controller noise without changing business behavior.

### 3. Some Controllers Are Too Heavy

High-priority examples:

- `PipelineControlController::uploadFile()` validates input, prepares storage, moves files, creates task/job DB records, publishes RabbitMQ events, handles failure state, and shapes JSON.
- `PipelineStatusController` is about 546 lines and computes scrape, convert, and ingest state inside the controller.
- `API/IngestController` is about 666 lines and contains path selection, command building, process control, status log writing, and pipeline state updates.

Target:

- Controllers should delegate to one service method.
- File upload orchestration should move to a `PipelineUploadService` or `DocumentUploadService`.
- Pipeline status computation should move to a `PipelineStatusService`.
- Ingest process orchestration should move to a `RagIngestionService` or `IngestProcessService`.

Low-risk controller examples:

- `DatasetController`
- `DocumentBrowserController`
- parts of `PipelineTaskController`

These are already closer to the standard and should be cleaned first with FormRequests.

### 4. Services Still Own Direct DB Access

The skill says DB access belongs in repositories. Current services frequently call Eloquent directly.

Examples:

- `DatasetService` queries `Dataset`, `Document`, `PipelineTask`, and `PipelineJob` directly.
- `DocumentBrowserService` queries `Document` and `PipelineJob` directly.
- `PipelineTaskService` creates and updates `PipelineTask` and `PipelineJob` directly.
- `PipelineStateService` owns `PipelineStageState` and `PipelineJob` persistence directly.
- `PipelineEventRecorder` owns `PipelineEventRecord` persistence directly.
- `ScrapeService`, `ScrapeContextBuilder`, and `ScrapeFinalizerService` query scrape models directly.

Target repositories:

```text
app/Services/Pipeline/Repositories/PipelineTaskRepository.php
app/Services/Pipeline/Repositories/PipelineJobRepository.php
app/Services/Pipeline/Repositories/PipelineEventRecordRepository.php
app/Services/Pipeline/Repositories/PipelineStageStateRepository.php
app/Services/Dataset/Repositories/DatasetRepository.php
app/Services/Document/Repositories/DocumentRepository.php
app/Services/Scrape/Repositories/ScrapeProcessRepository.php
app/Services/Scrape/Repositories/ScrapedElementRepository.php
```

Migration rule: extract repositories around existing queries first, then move callers. Avoid changing query behavior during the extraction.

### 5. Services Mix Multiple Responsibilities

Largest service files found:

- `ScrapeService`: about 1182 lines.
- `PipelineTaskService`: about 727 lines.
- `PipelineStateService`: about 418 lines.
- `PipelineRecoveryService`: about 382 lines.
- `ScrapeMonitorEventHandler`: about 333 lines.
- `PipelineEventBus`: about 319 lines.
- `IngestionEventHandler`: about 315 lines.

Important examples:

- `ScrapeService` is both a public scrape API, Crawl4AI HTTP client, Task UI client, scrape DB accessor, normalizer, and delete/read service.
- `PipelineTaskService` starts tasks, creates jobs, publishes events, resolves URLs/sitemaps, recalculates task status, and builds response payloads.
- `DatasetService` manages datasets, builds response payloads, queries related pipeline/document data, and calls Qdrant/Neo4j HTTP APIs.

Target pattern from `SKILL.md`:

```php
class PipelineService
{
    public function __construct(
        public readonly PipelineTaskService $tasks,
        public readonly PipelineStatusService $status,
        public readonly PipelineRecoveryService $recovery,
        private readonly PipelineTaskRepository $repository,
    ) {}
}
```

The aggregate service gives callers one injection point while keeping each sub-service focused.

### 6. Service Locator Usage Should Be Removed

The skill requires constructor injection. Current service locator examples:

- `ScrapeService::startPipeline()` resolves `ScraperPipelineService` with `app(...)`.
- `ScrapeService` calls `app(PipelineStateService::class)` in multiple methods.
- `ScrapeFinalizerService` resolves `StorageService` and `PipelineDataValidator` inside the constructor with `app(...)`.
- `ScrapeEventHandler` resolves `ScrapeDatasetCreator` with `app(...)`.
- `PipelineTaskService` publishes through `app(PipelineEventBus::class)` in one path.
- `API/IngestController` calls `app(PipelineStateService::class)` directly.

Target: inject dependencies explicitly through constructors.

Why this matters: constructor injection makes dependencies visible, makes tests easier, and follows the `SKILL.md` rule that services should be stateless and dependency-injected.

### 7. Exceptions Do Not Yet Follow The Domain Pattern

Current examples:

- `WebSearchService/Exception/WebSearchFailedException.php`
- `RagSearch/Exception/RagSearcherFailedException.php`
- many direct `throw new \RuntimeException(...)`
- some direct `throw new \Exception(...)`
- some direct `throw new \InvalidArgumentException(...)`

Gaps against the standard:

- Namespace should be `Exceptions`, not `Exception`.
- Each domain should define a marker interface, for example `PipelineExceptionInterface extends \Throwable`.
- Exceptions should expose static factory methods.
- Application code should not throw built-in PHP exceptions directly.

Target:

```text
app/Services/WebSearch/Exceptions/WebSearchExceptionInterface.php
app/Services/WebSearch/Exceptions/WebSearchFailedException.php
app/Services/Pipeline/Exceptions/PipelineExceptionInterface.php
app/Services/Pipeline/Exceptions/EventPublishFailedException.php
app/Services/Scrape/Exceptions/ScrapeExceptionInterface.php
app/Services/Scrape/Exceptions/CrawlerStatusReadFailedException.php
```

### 8. Namespace Shape Needs Cleanup

Current namespace issues:

- `WebSearchService/Interface` should become `WebSearch/Contracts`.
- `WebSearchService/Exception` should become `WebSearch/Exceptions`.
- `RagSearch/Exception` should become `RagSearch/Exceptions`.
- `ScrapeService` should become `Scrape`.
- `StorageService` should become `Storage`.
- `GraphService` should become `Graph`.
- `Datasets` and `Documents` should become `Dataset` and `Document`.

This should happen after repository and request/resource extraction, because namespace moves touch many imports.

### 9. Models Are Mostly Acceptable

Models such as `PipelineJob`, `PipelineTask`, and `Document` mostly describe data:

- fillable fields
- casts
- relationships
- simple instance helpers like `isTerminal()`

That aligns reasonably well with the skill.

Future improvement: move status and type constants into enums under `Values/`, for example:

```text
app/Services/Pipeline/Values/PipelineJobStatus.php
app/Services/Pipeline/Values/PipelineJobType.php
app/Services/Document/Values/DocumentStatus.php
app/Services/Document/Values/DocumentSource.php
```

Do this later because constants are used widely and replacing them has a larger blast radius.

## Recommended Refactor Order After This Audit

### Step 2: Add HTTP Boundary Classes

Start with low-risk controllers:

- `DatasetController`
- `DocumentBrowserController`
- `PipelineTaskController`

Add FormRequests and optionally Resources.

Why first: this teaches the Laravel request/resource pattern and reduces controller noise without moving domain logic yet.

### Step 3: Extract Read Repositories

Start with read-heavy services:

- `DocumentBrowserService`
- `DatasetService`

Create repositories and move queries without changing payload output.

Why second: read repositories are easier to test and less risky than write workflows.

### Step 4: Extract Pipeline Repositories

Move task, job, stage, and event-record queries from:

- `PipelineTaskService`
- `PipelineStateService`
- `PipelineEventStateService`
- `PipelineEventRecorder`
- event handlers

into `Pipeline/Repositories`.

Why third: this is foundational for the pipeline, and it reduces repeated Eloquent access.

### Step 5: Split Large Services Into Focused Sub-services

Primary targets:

- `ScrapeService`
- `PipelineTaskService`
- `DatasetService`

Expected split examples:

- `ScrapeCrawlerClient`
- `ScrapeTaskUiClient`
- `ScrapeJobService`
- `PipelineTaskCreator`
- `PipelineTaskStatusService`
- `DatasetStatsService`

Why fourth: after repositories exist, services become easier to split cleanly.

### Step 6: Introduce Values, Enums, And Domain Exceptions

Create typed value objects for common raw arrays:

- pipeline event payloads
- scrape monitor status
- dataset identity
- bridge ingestion responses

Create enums for constrained strings:

- pipeline event type
- pipeline job type
- pipeline job status
- scrape monitor status

Why fifth: this reduces string drift after behavior has already been isolated.

### Step 7: Rename Namespaces To Match The Standard

Perform namespace moves after behavior is already safer:

- `Datasets` to `Dataset`
- `Documents` to `Document`
- `ScrapeService` to `Scrape`
- `StorageService` to `Storage`
- `WebSearchService` to `WebSearch`
- `GraphService` to `Graph`
- `Interface` to `Contracts`
- `Exception` to `Exceptions`

Why last: namespace moves touch many files but should not change behavior.

## Suggested First Code Refactor

The first real code step should be:

1. Create `app/Http/Requests/Dataset/ListDatasetsRequest.php`.
2. Create `app/Http/Requests/Dataset/CreateDatasetRequest.php`.
3. Update `DatasetController` to use those FormRequests.
4. Add `declare(strict_types=1);` to touched files.
5. Run `tests/Feature/DatasetManagementTest.php`.

This is the safest start because `DatasetController` is small and already delegates to `DatasetService`.

## Learning Notes

Why FormRequests matter:

- They move validation away from controllers.
- They make validation reusable and testable.
- They keep controller methods readable.

Why repositories matter:

- Eloquent static calls are hard to mock.
- Repositories create one place for query behavior.
- Services become about business decisions, not SQL construction.

Why values/enums matter:

- Raw arrays and strings are easy to misspell.
- Typed objects make payload shape explicit.
- Enums document allowed states in code.

Why not refactor everything at once:

- This app has active pipeline behavior, RabbitMQ workers, Crawl4AI integration, Qdrant, Neo4j, and bridge calls.
- Broad namespace moves and service splits can break many imports at once.
- Small refactors with focused tests are safer and easier to learn from.

## Step 2: Dataset Controller FormRequests

Status: completed.

Production code changed:

- Added `app/Http/Requests/Dataset/ListDatasetsRequest.php`.
- Added `app/Http/Requests/Dataset/CreateDatasetRequest.php`.
- Updated `app/Http/Controllers/DatasetController.php`.

What changed:

- `DatasetController::index()` no longer validates `limit` inline.
- `DatasetController::store()` no longer validates dataset creation fields inline.
- Validation now lives in FormRequest classes, which matches the `SKILL.md` rule that controllers should not own validation.
- `declare(strict_types=1);` was added to the touched PHP files.

Before:

```php
public function index(Request $request): JsonResponse
{
    $validated = $request->validate([
        'limit' => 'nullable|integer|min:1|max:250',
    ]);

    return response()->json([
        'success' => true,
        'datasets' => $this->datasets->list((int) ($validated['limit'] ?? 50)),
    ]);
}
```

After:

```php
public function index(ListDatasetsRequest $request): JsonResponse
{
    return response()->json([
        'success' => true,
        'datasets' => $this->datasets->list($request->limit()),
    ]);
}
```

Learning note:

FormRequests are HTTP boundary classes. They answer two questions before the controller logic runs:

- Is the user allowed to make this request?
- Is the request payload valid?

This keeps the controller focused on routing the request to the domain service and returning a response.

Next recommended slice:

- Repeat the same pattern for `DocumentBrowserController`.
- Create `ListDocumentsRequest`.
- Keep behavior unchanged.
- Run `tests/Feature/DocumentBrowserTest.php`.

## Step 3: Document Browser FormRequest

Status: completed.

Production code changed:

- Added `app/Http/Requests/Document/ListDocumentsRequest.php`.
- Updated `app/Http/Controllers/DocumentBrowserController.php`.

What changed:

- `DocumentBrowserController::index()` no longer validates request query parameters inline.
- Validation now lives in `ListDocumentsRequest`.
- The request class exposes:
  - `limit()` for the normalized list limit.
  - `filters()` for validated document filters without the `limit` key.
- `declare(strict_types=1);` was added to the touched PHP files.

Before:

```php
public function index(Request $request): JsonResponse
{
    $validated = $request->validate([
        'dataset_id' => 'nullable|string|max:191',
        'datasetId' => 'nullable|string|max:191',
        'q' => 'nullable|string|max:255',
        'search' => 'nullable|string|max:255',
        'limit' => 'nullable|integer|min:1|max:250',
    ]);

    return response()->json([
        'success' => true,
        'documents' => $this->documents->list((int) ($validated['limit'] ?? 100), $validated),
    ]);
}
```

After:

```php
public function index(ListDocumentsRequest $request): JsonResponse
{
    return response()->json([
        'success' => true,
        'documents' => $this->documents->list($request->limit(), $request->filters()),
    ]);
}
```

Learning note:

This keeps validation at the HTTP boundary and leaves `DocumentBrowserService` focused on document lookup and response payload construction. The controller now has one dependency call for the index action.

Next recommended slice:

- Apply the same FormRequest pattern to `PipelineTaskController`.
- Suggested requests:
  - `ListPipelineTasksRequest`
  - `StartPipelineTaskRequest`
  - `ListPipelineTaskEventsRequest`
  - `UpsertPipelineJobRequest`
- Keep this step limited to validation movement only.

## Step 4: Pipeline Task Controller FormRequests

Status: completed.

Production code changed:

- Added `app/Http/Requests/Pipeline/ListPipelineTasksRequest.php`.
- Added `app/Http/Requests/Pipeline/StartPipelineTaskRequest.php`.
- Added `app/Http/Requests/Pipeline/ListPipelineTaskEventsRequest.php`.
- Added `app/Http/Requests/Pipeline/UpsertPipelineJobRequest.php`.
- Updated `app/Http/Controllers/PipelineTaskController.php`.

What changed:

- `PipelineTaskController::index()` no longer validates `limit` inline.
- `PipelineTaskController::start()` no longer validates task creation input inline.
- `PipelineTaskController::events()` no longer validates timeline filters inline.
- `PipelineTaskController::upsertJob()` no longer validates job update input inline.
- `declare(strict_types=1);` was added to the touched PHP files.

Before:

```php
public function events(Request $request, string $taskId): JsonResponse
{
    $validated = $request->validate([
        'limit' => 'nullable|integer|min:1|max:250',
        'event_type' => 'nullable|string',
        'eventType' => 'nullable|string',
        'job_id' => 'nullable|string',
        'jobId' => 'nullable|string',
    ]);
    $filters = [
        'event_type' => $validated['event_type'] ?? $validated['eventType'] ?? null,
        'job_id' => $validated['job_id'] ?? $validated['jobId'] ?? null,
    ];

    return response()->json([
        'events' => $this->tasks->recentEvents($taskId, (int) ($validated['limit'] ?? 100), $filters),
    ]);
}
```

After:

```php
public function events(ListPipelineTaskEventsRequest $request, string $taskId): JsonResponse
{
    return response()->json([
        'events' => $this->tasks->recentEvents($taskId, $request->limit(), $request->filters()),
    ]);
}
```

Learning note:

This step keeps the same behavior but makes the request contract explicit. The controller still returns JSON directly for now; API Resources can come later. The important part is that validation is now outside the controller, so each controller action is closer to the skill rule: validate through a FormRequest, call one service method, and return a response.

Next recommended slice:

- Add FormRequests for `PipelineRecoveryController`.
- It is small and still part of HTTP-boundary cleanup.
- After that, move to heavier controllers like `PipelineControlController` only after we have enough request coverage.

## Step 5: Pipeline Recovery Controller FormRequests

Status: completed.

Production code changed:

- Added `app/Http/Requests/Pipeline/ListFailedPipelineJobsRequest.php`.
- Added `app/Http/Requests/Pipeline/RetrySelectedPipelineJobsRequest.php`.
- Updated `app/Http/Controllers/PipelineRecoveryController.php`.

What changed:

- `PipelineRecoveryController::failedJobs()` no longer validates failed-job filters inline.
- `PipelineRecoveryController::retrySelected()` no longer validates selected job IDs inline.
- Validation was split into two request classes because the two endpoints accept different payload shapes:
  - listing failed jobs uses filters like `limit`, `task_id`, and `dataset_id`.
  - retrying selected jobs uses arrays like `job_ids` or `jobIds`.
- `declare(strict_types=1);` was added to the touched PHP files.

Before:

```php
public function retrySelected(Request $request): JsonResponse
{
    $validated = $request->validate([
        'job_ids' => 'nullable|array',
        'job_ids.*' => 'string',
        'jobIds' => 'nullable|array',
        'jobIds.*' => 'string',
    ]);

    return response()->json([
        'recovery' => $this->recovery->retrySelected($validated['job_ids'] ?? $validated['jobIds'] ?? []),
    ]);
}
```

After:

```php
public function retrySelected(RetrySelectedPipelineJobsRequest $request): JsonResponse
{
    return response()->json([
        'recovery' => $this->recovery->retrySelected($request->jobIds()),
    ]);
}
```

Learning note:

One FormRequest per request shape keeps validation readable. It is better to have two small request classes than one generic recovery request with unrelated rules.

Next recommended slice:

- Add a FormRequest for `PipelineControlController::uploadFile()`.
- That is the last small HTTP-boundary validation extraction before we start moving orchestration logic out of heavier controllers.

## Step 6: Pipeline File Upload FormRequest

Status: completed.

Production code changed:

- Added `app/Http/Requests/Pipeline/UploadPipelineFileRequest.php`.
- Updated `app/Http/Controllers/PipelineControlController.php`.

What changed:

- `PipelineControlController::uploadFile()` no longer validates the basic upload fields inline.
- The Laravel validation rules for `file`, `dataset_id`, `datasetId`, and `graph` now live in `UploadPipelineFileRequest`.
- The custom checks for readable upload files, supported converter extensions, storage preparation, DB record creation, and RabbitMQ event publishing stayed unchanged.
- `declare(strict_types=1);` was added to the touched PHP files.

Before:

```php
public function uploadFile(Request $request): JsonResponse
{
    $validated = $request->validate([
        'file' => 'required|file|max:102400',
        'dataset_id' => 'nullable|string|max:160',
        'datasetId' => 'nullable|string|max:160',
        'graph' => 'nullable',
    ]);

    // upload workflow...
}
```

After:

```php
public function uploadFile(UploadPipelineFileRequest $request): JsonResponse
{
    $validated = $request->validated();

    // upload workflow unchanged...
}
```

Learning note:

This is still a boundary-only refactor. The controller is not fully compliant yet because it still performs storage, persistence, and RabbitMQ orchestration. The next meaningful refactor is to extract that workflow into a dedicated service.

Next recommended slice:

- Extract the upload workflow from `PipelineControlController::uploadFile()` into a service.
- Suggested service name: `PipelineUploadService`.
- Keep the controller responsible only for calling the service and returning the service result.

## Step 7: Pipeline Upload Service Extraction

Status: completed.

Production code changed:

- Added `app/Services/Pipeline/PipelineUploadService.php`.
- Added `app/Services/Pipeline/Values/PipelineUploadResult.php`.
- Updated `app/Http/Controllers/PipelineControlController.php`.
- Updated `app/Http/Requests/Pipeline/UploadPipelineFileRequest.php`.

What changed:

- The bulky upload workflow moved out of `PipelineControlController::uploadFile()`.
- The controller now receives the validated request, calls one service method, and returns the service result as JSON.
- `PipelineUploadService` now owns the upload orchestration:
  - checks the uploaded file is readable
  - checks configured converter extensions
  - prepares the task storage directory
  - moves the uploaded file
  - ensures the dataset exists
  - creates the `PipelineTask`
  - creates the `PipelineJob`
  - publishes the `file.discovered` RabbitMQ event
  - marks task/job failed if publishing fails
- `PipelineUploadResult` is a small readonly value object that carries the response payload and HTTP status.
- `UploadPipelineFileRequest` now has `uploadedFile()` so the controller does not need to inspect the raw request file value.

Before:

```php
public function uploadFile(UploadPipelineFileRequest $request): JsonResponse
{
    $validated = $request->validated();

    // validate upload state
    // check supported extensions
    // create storage directory
    // move file
    // create dataset/task/job
    // publish RabbitMQ event
    // handle failure states
    // return JSON
}
```

After:

```php
public function uploadFile(UploadPipelineFileRequest $request): JsonResponse
{
    $result = $this->uploads->upload($request->validated(), $request->uploadedFile());

    return response()->json($result->payload, $result->status);
}
```

Learning note:

This step makes the controller much closer to the `SKILL.md` controller rule: validate, call one service method, return a response. It does not finish the domain refactor because `PipelineUploadService` still uses Eloquent models directly. That is intentional for this slice: moving the workflow first makes the next repository extraction smaller and easier to test.

Next recommended slice:

- Extract repository methods for the upload path:
  - create pipeline task
  - create pipeline job
  - mark upload publish failure
- Suggested files:
  - `app/Services/Pipeline/Repositories/PipelineTaskRepository.php`
  - `app/Services/Pipeline/Repositories/PipelineJobRepository.php`

## Step 8: Pipeline Upload Repositories

Status: completed.

Production code changed:

- Added `app/Services/Pipeline/Repositories/PipelineTaskRepository.php`.
- Added `app/Services/Pipeline/Repositories/PipelineJobRepository.php`.
- Updated `app/Services/Pipeline/PipelineUploadService.php`.

What changed:

- The upload service no longer creates or updates `PipelineTask` and `PipelineJob` records through Eloquent query builders directly.
- `PipelineTaskRepository` now owns:
  - creating pipeline task records
  - marking a task failed
- `PipelineJobRepository` now owns:
  - creating pipeline job records
  - marking a job failed with an error message
- `PipelineUploadService` still owns the workflow decisions:
  - when to create task/job records
  - what metadata to store
  - when a publish failure should mark task/job failed
  - what HTTP result should be returned

Before:

```php
$task = PipelineTask::query()->create([...]);
$job = PipelineJob::query()->create([...]);

$job->forceFill([
    'status' => PipelineJob::STATUS_FAILED,
    'error_message' => $message,
    'finished_at' => $failedAt,
])->save();
```

After:

```php
$task = $this->taskRepository->create([...]);
$job = $this->jobRepository->create([...]);

$job = $this->jobRepository->markFailed($job, $message, $failedAt);
$task = $this->taskRepository->markFailed($task, $failedAt);
```

Learning note:

Repositories are not workflow classes. They should hide persistence details, not decide business behavior. This is why the upload service still builds the task/job payloads and decides when failure handling runs, while the repositories only perform the database writes.

Next recommended slice:

- Add a small value object for upload input or upload metadata so `PipelineUploadService::upload()` no longer passes a loose validated array around.
- Suggested file:
  - `app/Services/Pipeline/Values/PipelineUploadInput.php`

## Step 9: Pipeline Upload Input Value Object

Status: completed.

Production code changed:

- Added `app/Services/Pipeline/Values/PipelineUploadInput.php`.
- Updated `app/Http/Requests/Pipeline/UploadPipelineFileRequest.php`.
- Updated `app/Http/Controllers/PipelineControlController.php`.
- Updated `app/Services/Pipeline/PipelineUploadService.php`.

What changed:

- `PipelineUploadService::upload()` no longer accepts a loose validated array.
- `PipelineUploadInput` now owns upload input normalization:
  - `dataset_id` and `datasetId` are normalized into one `datasetId` property
  - blank dataset values fall back to `controller-uploads`
  - `graph` is normalized into a boolean and defaults to `true`
- `UploadPipelineFileRequest` now exposes `uploadInput()` to convert validated HTTP data into the domain value object.
- `PipelineControlController` now passes a typed input object to the service.

Before:

```php
public function upload(array $validated, ?UploadedFile $file): PipelineUploadResult
{
    $datasetId = $this->stringValue($validated['dataset_id'] ?? $validated['datasetId'] ?? null)
        ?? 'controller-uploads';
    $graph = filter_var($validated['graph'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
}
```

After:

```php
public function upload(PipelineUploadInput $input, ?UploadedFile $file): PipelineUploadResult
{
    $dataset = $this->datasets->ensure($input->datasetId);
    $metadata = [
        'graph' => $input->graph,
    ];
}
```

Learning note:

Value objects are useful when a service receives more than one primitive request value. The service can now read `$input->datasetId` and `$input->graph` without knowing the HTTP field names or fallback rules.

Next recommended slice:

- Extract upload storage operations into a focused collaborator so `PipelineUploadService` no longer manages directories and file moves directly.
- Suggested file:
  - `app/Services/Pipeline/PipelineUploadStorage.php`

## Step 10: Pipeline Upload Storage Extraction

Status: completed.

Production code changed:

- Added `app/Services/Pipeline/PipelineUploadStorage.php`.
- Added `app/Services/Pipeline/Values/PipelineStoredUpload.php`.
- Added `app/Services/Pipeline/Exceptions/PipelineExceptionInterface.php`.
- Added `app/Services/Pipeline/Exceptions/PipelineUploadStorageException.php`.
- Updated `app/Services/Pipeline/PipelineUploadService.php`.

What changed:

- Upload filesystem work moved out of `PipelineUploadService`.
- `PipelineUploadStorage` now owns:
  - extracting the upload extension
  - building the task storage path
  - creating the task directory
  - checking the directory is writable
  - generating the stored filename
  - moving the uploaded file
  - deleting the task directory if the move fails
  - calculating the stored file hash
- `PipelineStoredUpload` now carries the stored file data:
  - original filename
  - target filename
  - local path
  - content hash
  - extension
- `PipelineUploadStorageException` is a domain exception with static factories for storage failures.
- `PipelineUploadService` still decides how storage failures become HTTP responses and still owns the pipeline workflow.

Before:

```php
File::ensureDirectoryExists($taskRoot);
$targetName = $baseName . '-' . Str::lower(Str::random(8)) . '.' . $extension;
$file->move($taskRoot, $targetName);
$localPath = $taskRoot . DIRECTORY_SEPARATOR . $targetName;
$contentHash = hash_file('sha256', $localPath);
```

After:

```php
$extension = $this->storage->extensionFor($file);
$storedUpload = $this->storage->store($taskId, $file, $extension);

$jobId = 'convert_' . substr(hash(
    'sha256',
    $taskId . '|' . $storedUpload->contentHash . '|' . $storedUpload->localPath,
), 0, 24);
```

Learning note:

This is a collaborator extraction, not a service split for its own sake. `PipelineUploadService` still coordinates the use case, while `PipelineUploadStorage` owns one technical concern: storing an uploaded file in the shared pipeline storage.

Next recommended slice:

- Extract the repeated task/job metadata arrays into value objects or small private builder methods.
- Suggested first target:
  - move upload task metadata construction into a named method on `PipelineUploadService`
  - move converter job metadata construction into a named method on `PipelineUploadService`

## Step 11: Pipeline Upload Metadata Builders

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineUploadService.php`.

What changed:

- The upload service no longer keeps the full task metadata, job metadata, and RabbitMQ payload arrays inline inside `upload()`.
- The public workflow now reads in clearer steps:
  - validate the file
  - store the upload
  - ensure the dataset
  - create the task
  - create the job
  - publish the event
  - return the result
- The repeated dataset metadata shape now lives in one helper method.
- The convert job id and source URL creation now have names instead of inline expressions.

New private methods:

```php
private function convertJobId(string $taskId, PipelineStoredUpload $storedUpload): string
private function sourceUrl(PipelineStoredUpload $storedUpload): string
private function taskMetadata(Dataset $dataset, PipelineUploadInput $input, PipelineStoredUpload $storedUpload): array
private function jobMetadata(Dataset $dataset, PipelineUploadInput $input, PipelineStoredUpload $storedUpload): array
private function fileDiscoveredPayload(PipelineTask $task, PipelineJob $job, string $sourceUrl, PipelineStoredUpload $storedUpload, array $metadata): array
private function datasetMetadata(Dataset $dataset): array
```

Before:

```php
$task = $this->taskRepository->create([
    'metadata' => [
        'request' => [...],
        'dataset' => [...],
        'upload' => [...],
    ],
]);

$metadata = [
    'source' => 'pipeline-controller',
    'dataset' => [...],
];

$payload = [
    'task_id' => $task->task_id,
    'metadata' => $metadata,
];
```

After:

```php
$task = $this->taskRepository->create([
    'metadata' => $this->taskMetadata($dataset, $input, $storedUpload),
]);

$metadata = $this->jobMetadata($dataset, $input, $storedUpload);
$payload = $this->fileDiscoveredPayload($task, $job, $sourceUrl, $storedUpload, $metadata);
```

Learning note:

Private builder methods are a good middle step before creating more value objects. They reduce visual noise and duplication without creating too many classes too early.

Next recommended slice:

- Add focused unit tests around the new value objects and storage collaborator, especially:
  - `PipelineUploadInput::fromValidated()`
  - `PipelineUploadStorage::store()`
  - `PipelineUploadStorageException` failure mapping

## Step 12: Pipeline Upload Value And Storage Tests

Status: completed.

Test code changed:

- Added `tests/Unit/Pipeline/PipelineUploadInputTest.php`.
- Added `tests/Unit/Pipeline/PipelineUploadStorageTest.php`.

What changed:

- Added direct coverage for `PipelineUploadInput::fromValidated()`.
- Added direct coverage for `PipelineUploadStorage::store()`.
- Added direct coverage for storage failure mapping through `PipelineUploadStorageException`.

Covered behavior:

- `dataset_id` is preferred over `datasetId`.
- `datasetId` is accepted when `dataset_id` is absent.
- blank dataset values default to `controller-uploads`.
- `graph=false` normalizes to `false`.
- missing or invalid graph values default to `true`.
- stored uploads return original filename, generated target filename, extension, local path, and SHA-256 hash.
- blocked task storage paths throw a domain exception with the response/log messages the upload service expects.

Learning note:

These tests protect the new class boundaries before the next production refactor. The feature test still verifies the end-to-end HTTP upload behavior, while these unit tests verify the smaller pieces directly.

Next recommended slice:

- Move supported-extension normalization out of `PipelineUploadService`.
- Good options:
  - put it into `PipelineUploadInput` only if it becomes request-specific
  - put it into `PipelineUploadStorage` if we treat extension handling as part of storage
  - create a small `PipelineUploadRules`/`PipelineUploadPolicy` collaborator if more upload rules are coming
