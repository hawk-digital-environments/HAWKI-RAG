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

## Step 13: Pipeline Upload Policy

Status: completed.

Production code changed:

- Added `app/Services/Pipeline/PipelineUploadPolicy.php`.
- Updated `app/Services/Pipeline/PipelineUploadService.php`.

Test code changed:

- Added `tests/Unit/Pipeline/PipelineUploadPolicyTest.php`.

What changed:

- Supported converter extension rules moved out of `PipelineUploadService`.
- `PipelineUploadPolicy` now owns:
  - supported extension normalization from config
  - case-insensitive and dot-insensitive extension checks
  - unsupported-file response message construction
- `PipelineUploadService` now asks the policy whether the stored extension is supported instead of building and checking the list itself.

Before:

```php
$supported = $this->supportedExtensions();

if (!in_array($extension, $supported, true)) {
    return PipelineUploadResult::fromPayload([
        'success' => false,
        'message' => 'Unsupported converter input. Supported file types: ' . implode(', ', $supported) . '.',
    ], 422);
}
```

After:

```php
if (!$this->policy->supports($extension)) {
    return PipelineUploadResult::fromPayload([
        'success' => false,
        'message' => $this->policy->unsupportedMessage(),
    ], 422);
}
```

Learning note:

This keeps upload rules in one place. The storage collaborator stores files; the policy decides whether an uploaded extension is allowed; the upload service coordinates the use case.

Next recommended slice:

- Extract response payload creation from `PipelineUploadService` into private methods or a small `PipelineUploadResponseFactory`.
- Start with private methods first, because the response payloads are still local to this one service.

## Step 14: Pipeline Upload Result Builders

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineUploadService.php`.

What changed:

- Upload response payload construction moved out of the main `upload()` workflow.
- The service now uses named private methods for each upload result branch:
  - unreadable upload file
  - unsupported file extension
  - storage failure
  - RabbitMQ publish failure
  - successful upload
- Response payload keys, messages, and status codes stayed unchanged.

Before:

```php
if (!$file || !$file->isValid()) {
    return PipelineUploadResult::fromPayload([
        'success' => false,
        'message' => 'Upload a readable document file.',
    ], 422);
}
```

After:

```php
if (!$file || !$file->isValid()) {
    return $this->unreadableFileResult();
}
```

Learning note:

This keeps the use-case method readable without introducing a response factory too early. If more controllers or services need these same response shapes later, these private methods can move into a dedicated factory.

Next recommended slice:

- Extract task/job creation payloads into repository-specific methods.
- That would let `PipelineUploadService` call methods like:
  - `$this->taskRepository->createUploadTask(...)`
  - `$this->jobRepository->createUploadConvertJob(...)`
- This is more opinionated than generic `create(array $attributes)`, but it hides more persistence shape from the service.

## Step 15: Pipeline Upload Repository Creation Methods

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/Repositories/PipelineTaskRepository.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineJobRepository.php`.
- Updated `app/Services/Pipeline/PipelineUploadService.php`.

What changed:

- The generic repository methods `create(array $attributes)` were replaced with upload-specific creation methods.
- `PipelineTaskRepository` now owns the persistence shape for creating an upload task.
- `PipelineJobRepository` now owns the persistence shape for creating an upload convert job.
- `PipelineUploadService` no longer passes raw database create arrays for task/job creation.

Before:

```php
$task = $this->taskRepository->create([
    'task_id' => $taskId,
    'dataset_id' => $dataset->dataset_id,
    'status' => PipelineTask::STATUS_RUNNING,
    'started_at' => $now,
    'counters' => [],
    'metadata' => $this->taskMetadata($dataset, $input, $storedUpload),
]);
```

After:

```php
$task = $this->taskRepository->createUploadTask(
    $taskId,
    $dataset,
    $now,
    $this->taskMetadata($dataset, $input, $storedUpload),
);
```

Learning note:

Repositories should hide persistence shape, not just wrap Eloquent with a generic `create()` method. A named repository method says what kind of record is being created and keeps model constants/default fields close to the database write.

Next recommended slice:

- Move upload-specific id generation out of `PipelineUploadService`.
- Suggested collaborator:
  - `app/Services/Pipeline/PipelineUploadIdentifierFactory.php`
- It would own:
  - upload task id generation
  - convert job id generation
  - upload source URL generation

## Step 16: Pipeline Upload Identifier Factory

Status: completed.

Production code changed:

- Added `app/Services/Pipeline/PipelineUploadIdentifierFactory.php`.
- Updated `app/Services/Pipeline/PipelineUploadService.php`.

Test code changed:

- Added `tests/Unit/Pipeline/PipelineUploadIdentifierFactoryTest.php`.

What changed:

- Upload-specific identifier creation moved out of `PipelineUploadService`.
- `PipelineUploadIdentifierFactory` now owns:
  - upload task id generation
  - convert job id generation
  - upload source URL generation
- `PipelineUploadService` now receives generated identifiers from the factory and continues coordinating the workflow.
- The `Str` dependency was removed from `PipelineUploadService`.

Before:

```php
$taskId = 'task_controller_upload_' . now()->format('Ymd_His') . '_' . Str::lower(Str::random(6));
$jobId = 'convert_' . substr(hash('sha256', $taskId . '|' . $hash . '|' . $path), 0, 24);
$sourceUrl = 'upload://' . $storedUpload->originalName;
```

After:

```php
$taskId = $this->identifiers->uploadTaskId();
$jobId = $this->identifiers->convertJobId($taskId, $storedUpload);
$sourceUrl = $this->identifiers->sourceUrl($storedUpload);
```

Learning note:

Identifier generation is small, but it has rules worth naming. Pulling it into a factory makes the upload service less dependent on string formats and gives those formats direct unit coverage.

Next recommended slice:

- Add unit tests for the repository-specific creation methods or move broader pipeline task/job repository extraction into `PipelineTaskService`.
- For this upload-focused refactor, repository method tests are the smaller next step.

## Step 17: Pipeline Upload Repository Tests

Status: completed.

Test code changed:

- Added `tests/Feature/PipelineUploadRepositoryTest.php`.

What changed:

- Added focused database-backed tests for upload repository creation methods.
- `PipelineTaskRepository::createUploadTask()` is now covered directly.
- `PipelineJobRepository::createUploadConvertJob()` is now covered directly.

Covered behavior:

- upload tasks are created with:
  - the provided task id
  - the dataset id from the dataset model
  - `running` status
  - empty counters
  - provided metadata
  - provided start time
- upload convert jobs are created with:
  - the provided job id
  - the parent task id
  - `convert` job type
  - `queued` status
  - upload source URL
  - stored upload local path
  - stored upload content hash
  - provided metadata
  - provided start time

Learning note:

These are feature tests rather than pure unit tests because repositories intentionally write to the database. They verify the repository boundary directly without going through the HTTP upload endpoint.

Next recommended slice:

- Start extracting repository reads/writes from `PipelineTaskService`.
- Suggested first target:
  - replace `PipelineTask::query()->where(...)->first()` and `PipelineJob::query()->where(...)->get()` in simple read methods with repository methods.
  - keep behavior unchanged and test with `PipelineTaskOrchestrationTest`.

## Step 18: Pipeline Task Read Repositories

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineTaskService.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineTaskRepository.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineJobRepository.php`.

Test code changed:

- Added `tests/Feature/PipelineRepositoryReadTest.php`.

What changed:

- `PipelineTaskService::show()` now loads the task through `PipelineTaskRepository::findWithOrderedJobs()`.
- `PipelineTaskService::jobs()` now loads jobs through `PipelineJobRepository::forTaskOrdered()`.
- `PipelineTaskService::failedJobs()` now loads failed jobs through `PipelineJobRepository::failedForTask()`.
- active job counting now uses `PipelineJobRepository::countForTaskWithStatuses()`.
- The service still owns response shaping and task status recalculation.

Covered behavior:

- task repository loads a task with jobs eager-loaded in id order.
- job repository returns all task jobs in id order.
- job repository returns failed jobs newest first.
- job repository counts queued/running jobs for active job totals.

Learning note:

This is the safest way to begin moving `PipelineTaskService` toward the repository rule: first extract read-only queries with exact ordering preserved, then move write paths separately.

Next recommended slice:

- Continue with `PipelineTaskService::list()` by moving the recent-task query into `PipelineTaskRepository`.
- Keep response shaping and status recalculation in the service for now.

## Step 19: Pipeline Task Recent List Repository

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineTaskService.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineTaskRepository.php`.

Test code changed:

- Updated `tests/Feature/PipelineRepositoryReadTest.php`.

What changed:

- `PipelineTaskService::list()` no longer builds the recent-task query directly.
- `PipelineTaskRepository::recent()` now owns:
  - clamping the limit between 1 and 250
  - sorting by `started_at` descending
  - sorting tied rows by `id` descending
  - applying the SQL limit
- `PipelineTaskService` still owns:
  - recalculating task status
  - shaping the API response array
  - computing active job totals through the job repository

Before:

```php
return PipelineTask::query()
    ->orderByDesc('started_at')
    ->orderByDesc('id')
    ->limit($limit)
    ->get()
    ->map(...);
```

After:

```php
return $this->taskRepository->recent($limit)
    ->map(...);
```

Learning note:

This keeps query rules together while avoiding a premature resource/DTO split. The service still decides how task models become response payloads.

Next recommended slice:

- Move `PipelineTaskService::recentEvents()` fallback job query into `PipelineJobRepository`.
- That query is still read-only and low risk, but it has filtering logic in the service that should stay there for now.

## Step 20: Pipeline Recent Event Job Query Repository

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineTaskService.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineJobRepository.php`.

Test code changed:

- Updated `tests/Feature/PipelineRepositoryReadTest.php`.

What changed:

- The fallback job query inside `PipelineTaskService::recentEvents()` moved into `PipelineJobRepository::forTaskByRecentUpdate()`.
- The repository owns loading task jobs ordered by `updated_at` descending.
- `PipelineTaskService` still owns:
  - timeline fallback decision
  - converting jobs into event payload arrays
  - filtering by event type
  - filtering by job id
  - sorting event payloads by event timestamp
  - applying the final event limit

Before:

```php
$events = PipelineJob::query()
    ->where('task_id', $taskId)
    ->orderByDesc('updated_at')
    ->get()
    ->flatMap(fn (PipelineJob $job) => $this->eventsForJob($job));
```

After:

```php
$events = $this->jobRepository
    ->forTaskByRecentUpdate($taskId)
    ->flatMap(fn (PipelineJob $job) => $this->eventsForJob($job));
```

Learning note:

This keeps the query in the repository while leaving event-specific transformation in the service. That separation matters because the repository should not know how pipeline event history is shaped for HTTP.

Next recommended slice:

- Move the simple task lookup in `PipelineTaskService::completeIfIdle()` into `PipelineTaskRepository`.
- This is another low-risk read query before touching write-heavy methods like `upsertJob()` and `retryFailedJobs()`.

## Step 21: Pipeline Complete-If-Idle Task Lookup

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineTaskService.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineTaskRepository.php`.

Test code changed:

- Updated `tests/Feature/PipelineRepositoryReadTest.php`.

What changed:

- `PipelineTaskService::completeIfIdle()` no longer queries `PipelineTask` directly.
- `PipelineTaskRepository::findByTaskId()` now owns the simple task lookup.
- The service still owns the workflow decision:
  - if a task exists, recalculate its status
  - if no task exists, return `null`

Before:

```php
$task = PipelineTask::query()->where('task_id', $taskId)->first();
```

After:

```php
$task = $this->taskRepository->findByTaskId($taskId);
```

Learning note:

This is intentionally small. It continues the `SKILL.md` repository rule without changing the behavior of task completion or status recalculation.

Next recommended slice:

- Move the task lookup inside `PipelineTaskService::recalculateTaskStatus()` into the repository.
- Use a repository method that throws when missing, for example `findByTaskIdOrFail()`.

## Step 22: Pipeline Recalculate Task Lookup

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineTaskService.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineTaskRepository.php`.

Test code changed:

- Updated `tests/Feature/PipelineRepositoryReadTest.php`.

What changed:

- `PipelineTaskService::recalculateTaskStatus()` no longer directly queries `PipelineTask` when called with a task id string.
- `PipelineTaskRepository::findByTaskIdOrFail()` now owns the throwing task lookup.
- Existing behavior is preserved: missing task ids still throw Laravel's model-not-found exception.

Before:

```php
$task = $task instanceof PipelineTask
    ? $task
    : PipelineTask::query()->where('task_id', $task)->firstOrFail();
```

After:

```php
$task = $task instanceof PipelineTask
    ? $task
    : $this->taskRepository->findByTaskIdOrFail($task);
```

Learning note:

This is another direct application of the repository rule in `SKILL.md`. The service still owns status calculation, but the database lookup lives in the repository.

Next recommended slice:

- Move the job collection query inside `PipelineTaskService::recalculateTaskStatus()` into `PipelineJobRepository`.
- Suggested method:
  - `PipelineJobRepository::forTask(string $taskId): Collection`

## Step 23: Pipeline Recalculate Job Collection Query

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineTaskService.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineJobRepository.php`.

Test code changed:

- Updated `tests/Feature/PipelineRepositoryReadTest.php`.

What changed:

- `PipelineTaskService::recalculateTaskStatus()` no longer queries `PipelineJob` directly for the task job collection.
- `PipelineJobRepository::forTask()` now owns the unordered task-job lookup used for status recalculation.
- Ordered query methods remain separate:
  - `forTaskOrdered()` for response job lists
  - `forTaskByRecentUpdate()` for recent-event fallback
  - `failedForTask()` for failed-job views

Before:

```php
$jobs = PipelineJob::query()->where('task_id', $task->task_id)->get();
```

After:

```php
$jobs = $this->jobRepository->forTask($task->task_id);
```

Learning note:

The repository now owns the query, but the service still owns counter calculation and status transition rules. That keeps persistence and workflow responsibilities separate in the way `SKILL.md` asks for.

Next recommended slice:

- Move write operations inside `PipelineTaskService::recalculateTaskStatus()` into `PipelineTaskRepository`.
- Suggested method:
  - `PipelineTaskRepository::updateStatusCounters(PipelineTask $task, string $status, ?Carbon $finishedAt, array $counters): PipelineTask`

## Step 24: Pipeline Recalculate Status Write Repository

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineTaskService.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineTaskRepository.php`.

Test code changed:

- Updated `tests/Feature/PipelineRepositoryReadTest.php`.

What changed:

- `PipelineTaskService::recalculateTaskStatus()` no longer directly writes task status, `finished_at`, or counters.
- `PipelineTaskRepository::updateStatusCounters()` now owns the persistence write.
- `PipelineTaskService` still owns:
  - counter calculation
  - deciding whether the task is pending/running/failed/completed
  - deciding whether `finished_at` should be null or set

Before:

```php
$task->forceFill([
    'status' => $status,
    'finished_at' => $finishedAt,
    'counters' => $counters,
])->save();

return $task->refresh();
```

After:

```php
return $this->taskRepository->updateStatusCounters($task, $status, $finishedAt, $counters);
```

Learning note:

This completes the repository extraction for `recalculateTaskStatus()`: the service computes the new state, the repositories load/write the models.

Next recommended slice:

- Move the task and existing-job lookups in `PipelineTaskService::upsertJob()` into repositories.
- Suggested repository methods:
  - `PipelineTaskRepository::findByTaskIdOrFail()`
  - already exists
  - `PipelineJobRepository::findByJobId(string $jobId): ?PipelineJob`

## Step 25: Pipeline Upsert Job Repository Boundary

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineTaskService.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineJobRepository.php`.

Test code changed:

- Updated `tests/Feature/PipelineRepositoryReadTest.php`.

What changed:

- `PipelineTaskService::upsertJob()` no longer directly queries `PipelineTask` to load the owning task.
- `PipelineTaskService::upsertJob()` no longer directly queries `PipelineJob` to load the existing job record.
- `PipelineTaskService::upsertJob()` no longer calls `PipelineJob::query()->updateOrCreate()` directly.
- `PipelineJobRepository` now owns:
  - `findByJobId()`
  - `upsertForTask()`
- `PipelineTaskService` still owns:
  - request field normalization
  - default job id generation
  - job status normalization
  - metadata merge rules
  - started/finished timestamp decisions
  - triggering task status recalculation after the job write

Before:

```php
$task = PipelineTask::query()->where('task_id', $taskId)->firstOrFail();
$existing = PipelineJob::query()->where('job_id', $jobId)->first();

$job = PipelineJob::query()->updateOrCreate(
    ['job_id' => $jobId],
    [
        'task_id' => $task->task_id,
        'status' => $status,
        'metadata' => $metadata,
    ],
);
```

After:

```php
$task = $this->taskRepository->findByTaskIdOrFail($taskId);
$existing = $this->jobRepository->findByJobId($jobId);

$job = $this->jobRepository->upsertForTask(
    $jobId,
    $task,
    [
        'status' => $status,
        'metadata' => $metadata,
    ],
);
```

Learning note:

This follows the `SKILL.md` repository standard: the service makes workflow decisions, while the repository owns Eloquent persistence. The important detail is that the service still builds the attributes because those values are business rules, not simple database access.

Next recommended slice:

- Refactor `PipelineTaskService::retryFailedJobs()`.
- Move the failed-job lookup and task metadata/status write into repositories, while keeping retry-count metadata and RabbitMQ retry publishing in the service.

## Step 26: Pipeline Retry Failed Jobs Repository Boundary

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineTaskService.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineJobRepository.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineTaskRepository.php`.

Test code changed:

- Updated `tests/Feature/PipelineRepositoryReadTest.php`.

What changed:

- `PipelineTaskService::retryFailedJobs()` no longer directly queries `PipelineTask` to find the task.
- `PipelineTaskService::retryFailedJobs()` no longer directly queries `PipelineJob` to find failed jobs.
- `PipelineTaskService::retryFailedJobs()` no longer directly writes job retry state with `forceFill()->save()`.
- `PipelineTaskService::retryFailedJobs()` no longer directly writes task running state and retry metadata.
- `PipelineJobRepository` now owns:
  - `failedForRetry()`
  - `markQueuedForRetry()`
- `PipelineTaskRepository` now owns:
  - `markFailedJobsRetried()`
- `PipelineTaskService` still owns:
  - deciding that missing tasks return `null`
  - incrementing `retry_count`
  - setting `retried_at`
  - appending the task metadata event
  - publishing the RabbitMQ retry event for each retried job
  - recalculating the task status after retry writes

Before:

```php
$task = PipelineTask::query()->where('task_id', $taskId)->first();

$jobs = PipelineJob::query()
    ->where('task_id', $task->task_id)
    ->where('status', PipelineJob::STATUS_FAILED)
    ->get();

$job->forceFill([
    'status' => PipelineJob::STATUS_QUEUED,
    'error_message' => null,
    'finished_at' => null,
    'metadata' => $metadata,
])->save();

$task->forceFill([
    'status' => PipelineTask::STATUS_RUNNING,
    'finished_at' => null,
    'metadata' => $this->appendMetadataEvent($task, 'failed_jobs_retried'),
])->save();
```

After:

```php
$task = $this->taskRepository->findByTaskId($taskId);
$jobs = $this->jobRepository->failedForRetry($task);

$job = $this->jobRepository->markQueuedForRetry($job, $metadata);

$task = $this->taskRepository->markFailedJobsRetried(
    $task,
    $this->appendMetadataEvent($task, 'failed_jobs_retried'),
);
```

Learning note:

This follows the same `SKILL.md` split as the previous steps. The repository owns persistence, while the service owns retry workflow rules and external RabbitMQ publishing. That is important because publishing retry events is orchestration behavior, not database access.

Next recommended slice:

- Refactor `PipelineTaskService::createScrapeJob()`.
- Move scrape-job creation into `PipelineJobRepository`, while keeping duplicate-scrape detection and RabbitMQ `scrape.requested` publishing in the service.

## Step 27: Pipeline Scrape Job Creation Repository

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineTaskService.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineJobRepository.php`.

Test code changed:

- Updated `tests/Feature/PipelineRepositoryReadTest.php`.

What changed:

- `PipelineTaskService::createScrapeJob()` no longer calls `PipelineJob::query()->create()`.
- `PipelineJobRepository::createScrapeJob()` now owns the scrape-job insert.
- `PipelineTaskService` still owns:
  - scrape job id generation
  - content hash generation
  - queued/skipped status decision
  - scrape-job metadata and reason text
  - deciding whether to publish `scrape.requested`

Learning note:

This keeps persistence in the repository without moving workflow decisions into the repository. The repository creates the row; the service decides what the row means.

## Step 28: Pipeline Task Start Creation Repository

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineTaskService.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineTaskRepository.php`.

Test code changed:

- Updated `tests/Feature/PipelineRepositoryReadTest.php`.

What changed:

- `PipelineTaskService::start()` no longer calls `PipelineTask::query()->create()`.
- `PipelineTaskRepository::createRunningTask()` now owns running task creation.
- `PipelineTaskRepository::createUploadTask()` now delegates to `createRunningTask()` so upload task creation and orchestrated task creation share one persistence method.
- `PipelineTaskService` still owns:
  - dataset resolution through `DatasetService`
  - task id generation
  - default counters
  - start metadata shape
  - transaction boundary around task and child-job creation

Learning note:

The repository owns the insert. The service still owns the orchestration of the start workflow because that includes dataset setup, child jobs, counters, and RabbitMQ side effects.

## Step 29: Pipeline Scrape History Repository

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineTaskService.php`.
- Added `app/Services/Pipeline/Repositories/PipelineScrapeHistoryRepository.php`.

Test code changed:

- Updated `tests/Feature/PipelineRepositoryReadTest.php`.

What changed:

- `PipelineTaskService` no longer queries `ScrapedElement` directly.
- `PipelineTaskService` no longer queries completed/skipped `PipelineJob` records directly for duplicate-scrape detection.
- `PipelineScrapeHistoryRepository` now owns:
  - checking existing `scraped_elements`
  - checking completed/skipped pipeline jobs for a source URL
  - combining those checks as `hasCompletedScrape()`
- `PipelineTaskService` still owns the consequence of that check:
  - create a skipped scrape job for already-scraped URLs
  - create a queued scrape job and publish `scrape.requested` for new URLs

Learning note:

This repository is intentionally separate from `PipelineJobRepository` because it reads both scrape history and pipeline jobs. The name describes the read model the service needs: "has this URL already been scraped?"

## Step 30: Pipeline Task Service Persistence Cleanup

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineTaskService.php`.

Test code changed:

- Updated `tests/Feature/PipelineRepositoryReadTest.php`.
- Existing orchestration tests still cover the HTTP workflow.

What changed:

- Final scan shows no direct model queries or writes remain in `PipelineTaskService`.
- The service now depends on repositories for persistence:
  - `PipelineTaskRepository`
  - `PipelineJobRepository`
  - `PipelineScrapeHistoryRepository`
- The service still owns orchestration and business rules:
  - URL resolution
  - task/job status decisions
  - counters
  - retry metadata
  - event payloads
  - RabbitMQ publishing

Learning note:

This completes the focused `PipelineTaskService` repository refactor requested in the four remaining steps. The class is still not tiny, but its responsibilities are clearer: it coordinates the pipeline workflow instead of owning Eloquent persistence.

Next recommended slice:

- Move to the next service with direct persistence and high operational impact.
- Good candidates:
  - `PipelineRecoveryService`
  - `PipelineEventStateService`
  - `PipelineStateService`

## Step 31: Pipeline Recovery Repository Boundary

Status: completed.

Production code changed:

- Updated `app/Services/Pipeline/PipelineRecoveryService.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineJobRepository.php`.
- Updated `app/Services/Pipeline/Repositories/PipelineTaskRepository.php`.

Test code changed:

- Added `tests/Feature/PipelineRecoveryRepositoryTest.php`.

What changed:

- `PipelineRecoveryService` no longer directly queries `PipelineJob` for failed recovery jobs.
- `PipelineRecoveryService` no longer directly queries `PipelineJob` for selected job ids or single job retry.
- `PipelineRecoveryService` no longer directly locks a `PipelineJob` row.
- `PipelineRecoveryService` no longer directly queries `PipelineTask` for the parent task.
- `PipelineRecoveryService` no longer directly writes recovery-queued job state.
- `PipelineRecoveryService` no longer directly writes recovery-running task state.
- `PipelineRecoveryService` no longer directly writes recovery publish-failure state.
- `PipelineJobRepository` now owns:
  - `findByJobIds()`
  - `failedForRecoveryList()`
  - `failedForRecovery()`
  - `lockForRecovery()`
  - `markRecoveryQueued()`
  - `markRecoveryPublishFailed()`
- `PipelineTaskRepository` now owns:
  - `markRecoveryRunning()`
- `PipelineRecoveryService` still owns:
  - retry scope decisions
  - recovery metadata shape
  - recovery event payload shape
  - idempotency key generation
  - deciding whether a job type can be retried
  - calling RabbitMQ recovery publishing
  - logging recovery publish results
  - recalculating task status after recovery writes

Before:

```php
$jobs = PipelineJob::query()
    ->whereIn('job_id', $jobIds)
    ->get();

$locked = PipelineJob::query()
    ->whereKey($job->getKey())
    ->lockForUpdate()
    ->first();

$task = PipelineTask::query()
    ->where('task_id', $locked->task_id)
    ->first();

$locked->forceFill([
    'status' => PipelineJob::STATUS_QUEUED,
    'error_message' => null,
    'finished_at' => null,
    'completed_at' => null,
    'metadata' => $metadata,
])->save();

$task->forceFill([
    'status' => PipelineTask::STATUS_RUNNING,
    'finished_at' => null,
    'metadata' => $this->taskRecoveryMetadata($task, $recoveryEvent),
])->save();
```

After:

```php
$jobs = $this->jobs->findByJobIds($jobIds);
$locked = $this->jobs->lockForRecovery($job);
$task = $this->taskRepository->findByTaskId((string) $locked->task_id);

$locked = $this->jobs->markRecoveryQueued($locked, $metadata);
$task = $this->taskRepository->markRecoveryRunning(
    $task,
    $this->taskRecoveryMetadata($task, $recoveryEvent),
);
```

Learning note:

This follows the same `SKILL.md` boundary: repositories own persistence and row locking, while the recovery service owns the operator recovery workflow. The transaction stays in the service because it coordinates multiple repository writes and the recovery event payload for one workflow.

Next recommended slice:

- Refactor `PipelineEventStateService`.
- It is the next high-impact pipeline service with direct job persistence, and the methods overlap with event handler behavior.
