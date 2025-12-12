# Web Crawler Documentation

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Workflow](#workflow)
4. [Key Components](#key-components)
5. [Usage Guide](#usage-guide)
6. [API Integration](#api-integration)
7. [Configuration](#configuration)
8. [Events](#events)
9. [Best Practices](#best-practices)
10. [Troubleshooting](#troubleshooting)

---

## Overview

The RAWKI Web Crawler is a sophisticated, pipeline-based web scraping system built on top of Crawlee (Node.js) and integrated into Laravel. It provides a robust, scalable solution for crawling websites, extracting content, downloading assets, and storing results in multiple formats.

### Key Features

- **Pipeline Architecture**: Seven-stage pipeline for clear separation of concerns
- **Event-Driven**: Decoupled progress reporting and monitoring
- **API-Ready**: Can be used from console commands, API endpoints, and queue jobs
- **Resumable**: Automatically detects and continues interrupted crawls
- **Host Filtering**: Built-in forbidden host management
- **Database Integration**: Optional storage in database for easy querying
- **PDF Conversion**: Integrated PDF to Markdown conversion
- **Retry Logic**: Automatic retry for transient failures

---

## Architecture

### High-Level Architecture

```
┌─────────────────────────────────────────────────────┐
│              Entry Points                           │
│   (Console Commands / API / Queue Jobs)             │
└────────────────────┬────────────────────────────────┘
                     │
┌────────────────────▼────────────────────────────────┐
│         CrawlerPipelineService                      │
│         (Main Gateway/Orchestrator)                 │
└────────────────────┬────────────────────────────────┘
                     │
┌────────────────────▼────────────────────────────────┐
│              Pipeline Stages                        │
│  1. Validation  → 2. Configuration                  │
│  3. Pre-Exec    → 4. Execution                      │
│  5. Post-Proc   → 6. Storage → 7. Finalization      │
└────────────────────┬────────────────────────────────┘
                     │
┌────────────────────▼────────────────────────────────┐
│            Specialized Services                     │
│  • HostFilterService     • ValidationService        │
│  • ConfigurationService  • ExecutionService         │
│  • StorageService        • EventService             │
└─────────────────────────────────────────────────────┘
```

### Directory Structure

```
app/Services/Crawler/
├── CrawlerPipelineService.php      # Main gateway
│
├── Data/                            # DTOs
│   ├── CrawlerJobRequest.php       # Input DTO
│   ├── CrawlerContext.php          # Pipeline state
│   ├── CrawlerJobResult.php        # Output DTO
│   ├── CrawlerConfig.php           # Execution config
│   ├── CrawlerResult.php           # Execution result
│   ├── DirectoryAnalysis.php       # Directory analysis
│   └── UrlProcessingOptions.php    # URL processing
│
├── Pipeline/                        # Pipeline components
│   ├── CrawlerConfigurationService.php
│   ├── CrawlerExecutionService.php
│   ├── CrawlerDirectoryService.php
│   ├── CrawlerProgressService.php
│   └── CrawlerUrlService.php
│
├── Validation/                      # Validation services
│   ├── CrawlerValidationService.php
│   └── HostFilterService.php
│
├── Storage/                         # Storage services
│   ├── CrawlerStorageService.php
│   └── PdfConversionService.php
│
└── Events/                          # Event system
    └── CrawlerEventService.php
```

**Note on Public Interface**:

When integrating the crawler with other parts of the application (controllers, API endpoints, queue jobs, other services), you should **only import and use `CrawlerPipelineService`** from the main namespace:

```php
use App\Services\ScrapeService\ScraperPipelineService;
use App\Services\ScrapeService\Data\ScrapeJobRequest;
use App\Services\ScrapeService\Data\ScrapeRequestResult;
```

All services in subdirectories (`Pipeline/`, `Validation/`, `Storage/`, `Events/`) are internal implementation details and should not be directly referenced outside the Crawler namespace. This ensures clean separation of concerns and makes future refactoring easier.

---

## Workflow

### Pipeline Stages

The crawler follows a seven-stage pipeline architecture:

#### Stage 1: Validation
- Validates input parameters (URL, label, maxPages, etc.)
- Checks forbidden hosts
- Validates business rules
- **Output**: Validated request or error messages

#### Stage 2: Configuration
- Processes URL to determine type (local file, sitemap, or direct URL)
- Filters forbidden hosts from URL lists
- Extracts base URL and sitemap URLs
- **Output**: URL processing options

#### Stage 3: Pre-Execution
- Sets up output directory
- Analyzes existing crawl data
- Determines continuation strategy (continue/restart/cancel)
- Builds crawler configuration
- **Output**: Complete crawler configuration

#### Stage 4: Execution
- Executes Node.js crawler with configuration
- Streams output in real-time (optional)
- Monitors progress
- **Output**: Crawled data in file system

#### Stage 5: Post-Processing
- Collects final statistics
- Performs cleanup operations
- Prepares artifacts
- **Output**: Processed results

#### Stage 6: Storage (Optional)
- Stores results in database (ScrapedPage model)
- Archives results to ZIP (optional)
- Exports to JSON (optional)
- **Output**: Persisted data

#### Stage 7: Finalization
- Calculates final statistics
- Compiles result object
- Triggers completion events
- **Output**: CrawlerJobResult

### Data Flow

```
User Input
    ↓
CrawlerJobRequest (DTO)
    ↓
CrawlerPipelineService.execute()
    ↓
CrawlerContext (travels through stages)
    ↓
CrawlerJobResult (DTO)
    ↓
Response to User
```

---

## Key Components

### 1. CrawlerPipelineService

**Purpose**: Main gateway for all crawler operations

**Responsibilities**:
- Orchestrates the complete pipeline
- Manages stage transitions
- Handles errors and exceptions
- Coordinates service interactions

**Key Methods**:
```php
public function execute(
    CrawlerJobRequest $request,
    string $existingDataStrategy = self::STRATEGY_CONTINUE,
    bool $storeInDatabase = false,
    ?callable $outputCallback = null
): CrawlerJobResult
```

**Strategies**:
- `STRATEGY_CONTINUE`: Continue from where crawler left off
- `STRATEGY_RESTART`: Delete existing data and start fresh
- `STRATEGY_CANCEL`: Cancel if existing data found

### 2. CrawlerJobRequest (DTO)

**Purpose**: Input data transfer object

**Properties**:
```php
string $url                 // Target URL or file path
string $label               // Crawl session identifier
int $maxPages              // Maximum pages to crawl (0 = unlimited)
string $outputDir          // Output directory path
bool $skipImages           // Skip image downloads
array|null $imageExceptions // CSS selectors to exclude
string|null $dateSelector   // CSS selector for dates
int $maxConcurrency        // Parallel requests limit
int $maxRpm                // Requests per minute limit
int|null $requestDelay     // Delay between requests (ms)
string|null $jobId         // Unique job identifier
```

**Factory Methods**:
```php
CrawlerJobRequest::fromArray(array $params)
```

### 3. CrawlerJobResult (DTO)

**Purpose**: Output data transfer object

**Properties**:
```php
bool $success              // Success status
string $jobId              // Job identifier
array $statistics          // Crawl statistics
array $errors              // Error messages
array $warnings            // Warning messages
array $artifacts           // Generated file paths
array $metadata            // Additional metadata
```

**Methods**:
```php
$result->isSuccessful(): bool
$result->getSummary(): string
$result->toArray(): array
$result->toJson(): string
```

### 4. CrawlerEventService

**Purpose**: Event dispatching and listening

**Key Events**:
- `validation.started`, `validation.completed`
- `configuration.started`, `configuration.completed`
- `existing_data.found`
- `execution.started`, `execution.progress`, `execution.completed`
- `storage.started`, `storage.completed`
- `pipeline.completed`
- `error`, `warning`, `info`

**Usage**:
```php
$events = $pipeline->getEventService();
$events->on('execution.progress', function($context, $output) {
    // Handle progress update
});
```

### 5. HostFilterService

**Purpose**: Manage forbidden host filtering

**Key Methods**:
```php
isHostForbidden(string $url): bool
filterForbiddenHosts(array $urls): array
loadForbiddenHosts(): void
getFilterStatistics(array $original, array $filtered): array
```

**Configuration**: `storage/forbidden-hosts.txt`
```
# Comments start with #
*.example.com
forbidden-site.com
```

### 6. CrawlerStorageService

**Purpose**: Database and file persistence

**Key Methods**:
```php
storeResults(CrawlerContext $context): int
archiveResults(CrawlerContext $context, ?string $destination): ?string
exportToJson(CrawlerContext $context, ?string $destination): ?string
cleanupOldResults(int $days = 30, ?string $label = null): int
```

### 7. PdfConversionService

**Purpose**: PDF to Markdown conversion with retry logic

**Key Methods**:
```php
convertDirectory(
    string $directory,
    bool $forceReprocess = false,
    int $maxRetries = 3,
    int $retryDelayMs = 1500,
    ?callable $progressCallback = null
): array

convertPdf(
    string $pdfPath,
    bool $forceReprocess = false,
    int $maxRetries = 3,
    int $retryDelayMs = 1500
): array
```

---

## Usage Guide

### Console Command

#### Basic Usage
```bash
php artisan crawlee:scrape "https://example.com"
```

#### With Options
```bash
php artisan crawlee:scrape "https://example.com" \
    --label=my-project \
    --max-pages=100 \
    --skip-images \
    --date=".publication-date" \
    --store-db
```

#### Using Local Sitemap File
```bash
php artisan crawlee:scrape "/path/to/sitemap.txt" \
    --label=bulk-import \
    --max-pages=1000
```

#### Available Options
- `--label`: Unique identifier for this crawl session
- `--max-pages`: Maximum number of pages to crawl (default: 100)
- `--output-dir`: Custom output directory
- `--skip-images`: Skip downloading images
- `--image-exceptions`: CSS selectors for images to exclude
- `--date`: CSS selector for date extraction
- `--max-concurrency`: Parallel requests (default: 4)
- `--max-rpm`: Requests per minute (default: 60)
- `--request-delay`: Delay between requests in ms
- `--store-db`: Store results in database

### Programmatic Usage (PHP)

#### Basic Example

```php
use App\Services\ScrapeService\ScraperPipelineService;
use App\Services\ScrapeService\Data\ScrapeJobRequest;

class CrawlerController extends Controller
{
    public function crawl(ScraperPipelineService $pipeline)
    {
        $request = new ScrapeJobRequest(
            url: 'https://example.com',
            label: 'my-crawl',
            maxPages: 100
        );

        $result = $pipeline->execute($request);

        if ($result->isSuccessful()) {
            return response()->json([
                'message' => $result->getSummary(),
                'statistics' => $result->statistics,
                'artifacts' => $result->artifacts,
            ]);
        }

        return response()->json([
            'message' => 'Crawl failed',
            'errors' => $result->errors,
        ], 500);
    }
}
```

#### With Event Listeners
```php
$pipeline = app(CrawlerPipelineService::class);
$events = $pipeline->getEventService();

// Listen for progress
$events->on('execution.progress', function($context, $output) {
    Log::info('Crawler progress: ' . $output);
});

// Listen for stage changes
$events->on('stage.changed', function($context, $stage) {
    broadcast(new CrawlerStageChanged($context->request->getJobId(), $stage));
});

// Execute
$result = $pipeline->execute($request, storeInDatabase: true);
```

#### With Output Streaming
```php
$result = $pipeline->execute(
    request: $request,
    storeInDatabase: true,
    outputCallback: function(string $type, string $buffer) {
        if ($type === 'out') {
            echo $buffer;
            flush();
        }
    }
);
```

### Queue Job Integration

```php
namespace App\Jobs;

use App\Services\ScrapeService\ScraperPipelineService;
use App\Services\ScrapeService\Data\ScrapeJobRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CrawlWebsiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $url,
        public string $label,
        public int $maxPages = 100
    ) {}

    public function handle(ScraperPipelineService $pipeline)
    {
        $request = new ScrapeJobRequest(
            url: $this->url,
            label: $this->label,
            maxPages: $this->maxPages
        );

        // Add event listeners for job progress
        $events = $pipeline->getEventService();
        $events->on('stage.changed', function($context, $stage) {
            $this->updateJobProgress($stage);
        });

        $result = $pipeline->execute(
            request: $request,
            storeInDatabase: true
        );

        if ($result->isFailed()) {
            $this->fail(new \Exception($result->getSummary()));
        }
    }

    private function updateJobProgress(string $stage)
    {
        // Update job progress in cache/database
        Cache::put("job:{$this->job->uuid()}:stage", $stage, 3600);
    }
}
```

**Dispatching**:
```php
CrawlWebsiteJob::dispatch('https://example.com', 'my-label', 100);
```

---

## API Integration

### RESTful API Endpoints

#### Start Crawl
```php
// routes/api.php
Route::post('/crawler/start', [CrawlerApiController::class, 'start']);

// app/Http/Controllers/Api/CrawlerApiController.php
public function start(Request $request, CrawlerPipelineService $pipeline)
{
    $validated = $request->validate([
        'url' => 'required|url',
        'label' => 'required|alpha_dash',
        'max_pages' => 'integer|min:1|max:10000',
        'skip_images' => 'boolean',
        'store_in_database' => 'boolean',
    ]);

    $jobRequest = CrawlerJobRequest::fromArray($validated);

    // For async processing, dispatch to queue
    CrawlWebsiteJob::dispatch(
        $jobRequest->url,
        $jobRequest->label,
        $jobRequest->maxPages
    );

    return response()->json([
        'message' => 'Crawl job started',
        'job_id' => $jobRequest->getJobId(),
    ], 202);
}
```

#### Get Crawl Status
```php
Route::get('/crawler/status/{jobId}', [CrawlerApiController::class, 'status']);

public function status(string $jobId)
{
    $stage = Cache::get("job:{$jobId}:stage");
    $result = Cache::get("job:{$jobId}:result");

    return response()->json([
        'job_id' => $jobId,
        'status' => $result ? 'completed' : 'in_progress',
        'current_stage' => $stage,
        'result' => $result,
    ]);
}
```

#### Get Crawl Results
```php
Route::get('/crawler/results/{label}', [CrawlerApiController::class, 'results']);

public function results(string $label)
{
    $pages = ScrapedPage::where('path', 'like', "%/{$label}/%")
        ->paginate(20);

    return response()->json($pages);
}
```

---

## Configuration

### Environment Variables

```env
# File Converter (for PDF conversion)
FILE_CONVERTER_RETRIES=3
FILE_CONVERTER_RETRY_DELAY_MS=1500
```

### Forbidden Hosts Configuration

Create `storage/forbidden-hosts.txt`:
```txt
# Forbidden hosts configuration
# Lines starting with # are comments
# Supports wildcard patterns

# Block entire domain
example-forbidden.com

# Block subdomains with wildcard
*.blocked-domain.com

# Block specific subdomain
subdomain.example.com
```

### Crawler Configuration Defaults

Edit `app/Services/Crawler/Data/CrawlerJobRequest.php` to change defaults:
```php
public function __construct(
    public readonly string $url,
    public readonly string $label,
    public readonly int $maxPages = 100,        // Default: 100 pages
    public readonly int $maxConcurrency = 4,     // Default: 4 parallel
    public readonly int $maxRpm = 60,            // Default: 60 req/min
    // ...
)
```

---

## Events

### Available Events

| Event | Parameters | Description |
|-------|------------|-------------|
| `validation.started` | `$context` | Validation stage begins |
| `validation.completed` | `$context, $success` | Validation stage ends |
| `configuration.started` | `$context` | Configuration stage begins |
| `configuration.completed` | `$context` | Configuration stage ends |
| `existing_data.found` | `$context` | Existing crawl data detected |
| `execution.started` | `$context` | Crawler execution begins |
| `execution.progress` | `$context, $output` | Crawler progress update |
| `execution.completed` | `$context, $success` | Crawler execution ends |
| `storage.started` | `$context` | Database storage begins |
| `storage.completed` | `$context` | Database storage ends |
| `pipeline.completed` | `$context` | Entire pipeline complete |
| `error` | `$context, $message, $exception` | Error occurred |
| `warning` | `$context, $message` | Warning generated |
| `info` | `$context, $message` | Informational message |

### Event Listener Example

```php
$pipeline = app(CrawlerPipelineService::class);
$events = $pipeline->getEventService();

// Listen to specific event
$events->on('execution.progress', function($context, $output) {
    // Send to WebSocket
    broadcast(new CrawlerProgressUpdate(
        $context->request->getJobId(),
        $output
    ));
});

// Listen to errors
$events->on('error', function($context, $message, $exception) {
    // Send notification
    Notification::route('slack', env('SLACK_WEBHOOK'))
        ->notify(new CrawlerErrorNotification($message));
});

// Execute crawler
$result = $pipeline->execute($request);
```

### Broadcasting Events

```php
// Create Laravel event
namespace App\Events;

class CrawlerProgressUpdate implements ShouldBroadcast
{
    public function __construct(
        public string $jobId,
        public string $output
    ) {}

    public function broadcastOn()
    {
        return new PrivateChannel("crawler.{$this->jobId}");
    }
}

// Broadcast via event listener
$events->on('execution.progress', function($context, $output) {
    broadcast(new CrawlerProgressUpdate(
        $context->request->getJobId(),
        $output
    ));
});
```

---

## Best Practices

### 1. URL Selection
- **DO**: Use sitemaps when available
- **DO**: Filter hosts using `forbidden-hosts.txt`
- **DON'T**: Crawl sites without permission
- **DON'T**: Set `maxPages` too high initially

### 2. Performance Optimization
- Adjust `maxConcurrency` based on target server capacity
- Use `maxRpm` to respect rate limits
- Enable `skipImages` for text-only content
- Use `requestDelay` for sensitive servers

### 3. Error Handling
- Always check `$result->isSuccessful()`
- Log errors from `$result->errors`
- Monitor warnings from `$result->warnings`
- Set up event listeners for real-time monitoring

### 4. Database Storage
- Only use `storeInDatabase: true` when needed
- Index `page_url` column for faster lookups
- Regularly clean up old crawl data
- Use `CrawlerStorageService::cleanupOldResults()`

### 5. Resource Management
- Clean up old crawl directories periodically
- Archive important crawls to ZIP
- Use queue jobs for large crawls
- Monitor disk space usage

### 6. Continuation Strategy
- Use `STRATEGY_CONTINUE` for resuming interrupted crawls
- Use `STRATEGY_RESTART` only when needed (destructive)
- Use `STRATEGY_CANCEL` for safety checks

---

## Troubleshooting

### Common Issues

#### 1. "Node.js crawler directory not found"
**Solution**: Run the setup command first
```bash
php artisan crawlee:setup
```

#### 2. "URL is from a forbidden host"
**Solution**: Check `storage/forbidden-hosts.txt` and remove if needed

#### 3. "No valid URLs remaining after filtering"
**Solution**: Your sitemap contains only forbidden hosts. Review filter rules.

#### 4. Crawl is very slow
**Solutions**:
- Increase `maxConcurrency` (default: 4)
- Increase `maxRpm` (default: 60)
- Enable `skipImages` if images aren't needed
- Check target server response time

#### 5. PDF conversion failing
**Solutions**:
- Check DocumentConverter service configuration
- Verify external API credentials
- Check network connectivity
- Review retry settings in config

#### 6. Database storage failing
**Solutions**:
- Run migrations: `php artisan migrate`
- Check database connection
- Verify `ScrapedPage` model exists
- Check disk space for database

### Debugging

#### Enable Event History
```php
$events = $pipeline->getEventService();
$events->enableHistory();

$result = $pipeline->execute($request);

// Review event history
$history = $events->getHistory();
foreach ($history as $event) {
    Log::debug($event['event'], $event['args']);
}
```

#### Inspect Context
```php
// Add custom event listener
$events->on('stage.changed', function($context, $stage) {
    Log::debug("Stage: {$stage}", [
        'metadata' => $context->metadata,
        'errors' => $context->errors,
        'warnings' => $context->warnings,
    ]);
});
```

#### Check Crawler Output
```php
$result = $pipeline->execute(
    request: $request,
    outputCallback: function(string $type, string $buffer) {
        Log::info("Crawler output ({$type}): {$buffer}");
    }
);
```

---

## Appendix

### Data Models

#### ScrapedPage Model
```php
// Database fields
$table->string('title')->nullable();
$table->text('page_url');
$table->text('meta_img_url')->nullable();
$table->json('images')->nullable();
$table->string('date')->nullable();
$table->json('pdfs')->nullable();
$table->text('path');
$table->timestamps();
```

### File Structure

#### Crawl Output Directory
```
storage/app/private/crawled-data/
└── {label}/
    ├── 00001/
    │   ├── site_00001.txt           # HTML content
    │   ├── data_00001.json          # Metadata
    │   └── files/                   # Downloaded assets
    │       ├── images/
    │       └── pdfs/
    ├── 00002/
    │   └── ...
    └── crawler-progress-{label}.json
```

#### data_XXXXX.json Format
```json
{
  "title": "Page Title",
  "page_url": ["https://example.com/page"],
  "meta_img_url": "https://example.com/image.jpg",
  "images": ["image1.jpg", "image2.jpg"],
  "date": "2024-01-01",
  "pdfs": ["document.pdf"]
}
```

---

## Support

For issues or questions:
1. Check this documentation
2. Review code comments in service files
3. Check Laravel logs: `storage/logs/laravel.log`
4. Enable debug mode and review event history

## Version History

- **v2.0.0** (Current): Pipeline architecture, event-driven, API-ready
- **v1.0.0**: Initial implementation with callback-based orchestrator
