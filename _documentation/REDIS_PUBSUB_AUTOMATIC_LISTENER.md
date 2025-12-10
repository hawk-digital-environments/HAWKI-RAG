# Automatic Redis Event Listener Implementation

## Overview

This document describes the updated Redis Pub/Sub implementation where event listeners are **automatically started** when a scrape job is submitted, eliminating the need for a separate subscriber process.

## Key Changes

### Before (Manual Subscriber)
- Required running `php artisan scrape:subscribe` separately
- Single subscriber process handled all jobs
- Had to manually manage the subscriber lifecycle
- Subscriber listened to ALL events from ALL jobs

### After (Automatic Per-Job Listener)
- Event listener **automatically starts** when a job is submitted
- Each job gets its own dedicated listener
- Listener automatically stops when job completes or times out
- Only processes events for the specific job ID

## Architecture

```
┌──────────────────────────────────────────────────────────────┐
│              Laravel Application                              │
│                                                               │
│  ┌──────────────────────────────────────────────────────┐    │
│  │  ScraperPipelineService                              │    │
│  │                                                       │    │
│  │  1. Submit job to Python microservice                │    │
│  │     └──> HTTP POST /crawl                            │    │
│  │                                                       │    │
│  │  2. Receive job_id in response                       │    │
│  │                                                       │    │
│  │  3. Dispatch ProcessScrapeEvents job                 │    │
│  │     └──> ProcessScrapeEvents::dispatch($job_id)      │    │
│  └──────────────────────────────────────────────────────┘    │
│                                                               │
│  ┌──────────────────────────────────────────────────────┐    │
│  │  Queue: ProcessScrapeEvents Job                      │    │
│  │                                                       │    │
│  │  - Subscribe to Redis channel                        │    │
│  │  - Filter events by job_id                           │    │
│  │  - Process matching events                           │    │
│  │  - Store in database                                 │    │
│  │  - Stop when job_completed received or timeout       │    │
│  └──────────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────────┘
                            │
                            │ Redis Pub/Sub
                            │
                            ▼
┌──────────────────────────────────────────────────────────────┐
│           Python Microservice (CustomCrawler)                 │
│                                                               │
│  - Publishes events to Redis                                 │
│  - Each event includes job_id                                │
│  - Events: crawling_started, url_scraped, job_completed, etc.│
└──────────────────────────────────────────────────────────────┘
```

## Implementation Details

### 1. Job Class: `ProcessScrapeEvents`

**Location**: `app/Jobs/ProcessScrapeEvents.php`

**Purpose**: Dedicated job that listens for Redis events for a specific job ID.

**Key Features**:
- Takes `$jobId` as constructor parameter
- Subscribes to Redis Pub/Sub channel
- Filters events to only process those matching the job ID
- Automatically stops when `job_completed` event is received
- Has configurable timeout (default: 1 hour)
- Stores all events in `scrape_metadata` table
- Updates `scrape_jobs` status

**Parameters**:
```php
public function __construct(
    string $jobId,              // Job ID to listen for
    ?string $channel = null,    // Redis channel (default from config)
    int $maxWaitSeconds = 3600  // Timeout in seconds
)
```

### 2. Pipeline Integration

**Location**: `app/Services/ScrapeService/ScraperPipelineService.php`

**Changes**:
```php
private function executeExecution(ScrapeContext $context, ?callable $outputCallback = null): void
{
    // ... existing code ...

    $result = $this->executionService->execute($context->request, $outputCallback);

    if($result->event === 'job_submitted'){
        $context->setStage('process_submitted');

        // NEW: Automatically start event listener for this job
        $this->startEventListener($result->jobId);
    }
}

private function startEventListener(string $jobId): void
{
    $channel = config('scrape.redis_channel', 'scrape-events');
    $maxWaitSeconds = config('scrape.max_job_duration', 3600);

    // Dispatch the event listener job
    ProcessScrapeEvents::dispatch($jobId, $channel, $maxWaitSeconds);

    Log::info("Started Redis event listener for job {$jobId}");
}
```

### 3. Configuration

**Location**: `config/scrape.php`

**New Configuration**:
```php
'max_job_duration' => env('SCRAPE_MAX_JOB_DURATION', 3600),
```

**Environment Variable**:
```env
SCRAPE_MAX_JOB_DURATION=3600  # Maximum time (seconds) to listen for events
```

## Usage

### Simple Usage

```php
use App\Services\ScrapeService\ScraperPipelineService;
use App\Services\ScrapeService\Data\ScrapeJobRequest;

$pipeline = app(ScraperPipelineService::class);

$request = new ScrapeJobRequest(
    url: 'https://example.com',
    label: 'example-scrape',
    maxPages: 10
);

$result = $pipeline->execute($request);

// That's it! Event listener is automatically started
// No need to manually subscribe or manage listeners
```

### Behind the Scenes

When you call `$pipeline->execute()`:

1. **Job Submission** (HTTP):
   ```
   POST http://custom-crawler:8000/crawl
   Response: { "job_id": "abc-123", "event": "job_submitted", ... }
   ```

2. **Automatic Listener Dispatch** (Queue):
   ```php
   ProcessScrapeEvents::dispatch("abc-123", "scrape-events", 3600);
   ```

3. **Event Processing** (Background):
   - Queue worker picks up the `ProcessScrapeEvents` job
   - Job subscribes to Redis and filters for job_id = "abc-123"
   - Events are processed and stored in database
   - Job completes when `job_completed` event is received

## Requirements

### Queue Worker

Since the event listener runs as a Laravel job, you **must** have a queue worker running:

**Development**:
```bash
php artisan queue:work
```

**Production** (with Supervisor):
```ini
[program:laravel-worker]
command=php /path/to/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/storage/logs/worker.log
```

### Queue Configuration

Set in `.env`:
```env
QUEUE_CONNECTION=database  # or 'redis', 'sync', etc.
```

For database queues, ensure migrations are run:
```bash
php artisan migrate
```

## Benefits

### 1. Automatic Lifecycle Management
- ✅ No manual subscriber process to manage
- ✅ Listeners start automatically with each job
- ✅ Listeners stop automatically when job completes
- ✅ Timeout protection prevents hung listeners

### 2. Job Isolation
- ✅ Each job has its own listener
- ✅ Events are filtered by job_id
- ✅ No cross-contamination between jobs
- ✅ Multiple concurrent jobs work seamlessly

### 3. Scalability
- ✅ Listeners scale with your queue workers
- ✅ No single point of failure
- ✅ Easy to add more workers for high volume

### 4. Simplicity
- ✅ One less process to monitor
- ✅ Automatic error handling via queue retry mechanism
- ✅ Standard Laravel queue monitoring tools work
- ✅ Failed jobs can be retried

## Monitoring

### Check Running Jobs

```bash
# View queued jobs
SELECT * FROM jobs WHERE queue = 'default' ORDER BY id DESC;

# View failed jobs
php artisan queue:failed

# Monitor queue worker
php artisan queue:work --verbose
```

### Check Event Processing

```bash
# View events for a specific job
SELECT * FROM scrape_metadata WHERE scrape_job_id = 123 ORDER BY created_at;

# View latest events
SELECT * FROM scrape_metadata ORDER BY created_at DESC LIMIT 20;

# Check job status
SELECT * FROM scrape_jobs WHERE job_id = 'abc-123';
```

### Logs

```bash
# Laravel logs
tail -f storage/logs/laravel.log | grep "ProcessScrapeEvents"

# Queue worker output
tail -f storage/logs/worker.log
```

## Troubleshooting

### Events Not Being Processed

**Problem**: Events are being published by Python but not processed by Laravel.

**Solutions**:
1. Check if queue worker is running:
   ```bash
   ps aux | grep "queue:work"
   ```

2. Check if job was dispatched:
   ```sql
   SELECT * FROM jobs ORDER BY id DESC LIMIT 5;
   ```

3. Check failed jobs:
   ```bash
   php artisan queue:failed
   php artisan queue:retry all
   ```

4. Verify Redis connection:
   ```bash
   php artisan tinker
   >>> Redis::ping()
   ```

### Listener Timeout

**Problem**: Listener stops before job completes.

**Solution**: Increase timeout in `.env`:
```env
SCRAPE_MAX_JOB_DURATION=7200  # 2 hours
```

### Multiple Listeners for Same Job

**Problem**: Accidentally dispatched multiple listeners for the same job.

**Solution**: This is safe! Multiple listeners will all process events for that job. However, to avoid duplicate database entries, you may want to add unique constraints or check before dispatching.

## Migration from Old System

### Old System (Manual Subscriber)
```bash
# Terminal 1: Run subscriber
php artisan scrape:subscribe

# Terminal 2: Submit job
php artisan scrape:submit https://example.com
```

### New System (Automatic Listener)
```bash
# Terminal 1: Run queue worker
php artisan queue:work

# Terminal 2: Submit job (listener starts automatically)
php artisan scrape:submit https://example.com
```

### Deprecation Notice

The `php artisan scrape:subscribe` command is now **deprecated**. Running it will show a deprecation warning:

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                    DEPRECATION WARNING
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

This command is deprecated. Event listeners now start automatically
when you submit a scrape job through the ScraperPipelineService.

The new approach:
  1. Submit a scrape job via the pipeline
  2. Event listener is automatically dispatched as a background job
  3. Events are processed only for that specific job
  4. Listener stops automatically when job completes

To use the new system:
  - Ensure queue worker is running: php artisan queue:work
  - Submit jobs through ScraperPipelineService

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

## Testing

### Test the Automatic Listener

1. Start Redis:
   ```bash
   docker-compose up -d redis
   ```

2. Start queue worker:
   ```bash
   php artisan queue:work --verbose
   ```

3. Run Python test script:
   ```bash
   cd CustomCrawler
   python test_redis_publisher.py
   ```

4. Observe logs:
   ```bash
   # Terminal 3
   tail -f storage/logs/laravel.log
   ```

You should see:
```
[INFO] Started Redis event listener for job test-job-simulation-456
[INFO] Processing event: sitemap_detected for job test-job-simulation-456
[INFO] Processing event: crawling_started for job test-job-simulation-456
...
[INFO] Job test-job-simulation-456 completed, stopping event listener
```

## Performance Considerations

### Queue Worker Count

For high-volume scraping, increase queue workers:

```ini
# Supervisor config
numprocs=3  # Run 3 queue workers
```

### Timeout Configuration

Balance timeout with expected job duration:
- Short jobs (< 5 min): `SCRAPE_MAX_JOB_DURATION=600`
- Medium jobs (< 30 min): `SCRAPE_MAX_JOB_DURATION=1800`
- Long jobs (< 2 hours): `SCRAPE_MAX_JOB_DURATION=7200`

### Database Indexes

Ensure proper indexes for performance:
```sql
-- Index on job_id for quick lookups
CREATE INDEX idx_scrape_metadata_job_id ON scrape_metadata(job_id);
CREATE INDEX job_id ON scrape_processes(job_id);
```

## Conclusion

The automatic listener approach provides a more robust, scalable, and maintainable solution for processing scrape events. By leveraging Laravel's queue system, we get:

- Automatic lifecycle management
- Built-in error handling and retry logic
- Standard monitoring and debugging tools
- Better resource utilization
- Simplified deployment

No more managing separate subscriber processes – just ensure your queue workers are running, and everything works automatically!
