# Redis Pub/Sub Implementation Guide

## Overview

This document describes the Redis Pub/Sub implementation for real-time communication between the Python CustomCrawler microservice and the Laravel application. This replaces the previous WebSocket-based communication system.

## Architecture

```
┌─────────────────────────┐         ┌─────────────────────────┐
│   Python Microservice   │         │   Laravel Application   │
│    (CustomCrawler)      │         │      (Orchestrator)     │
│                         │         │                         │
│  ┌──────────────────┐   │         │   ┌──────────────────┐  │
│  │ RedisEventEmitter│   │         │   │RedisEventSub-    │  │
│  │                  │   │         │   │    scriber       │  │
│  └────────┬─────────┘   │         │   └────────▲─────────┘  │
│           │ Publish     │         │            │ Subscribe  │
└───────────┼─────────────┘         └────────────┼────────────┘
            │                                    │
            └────────────┐         ┌─────────────┘
                         │         │
                    ┌────▼─────────▼────┐
                    │                   │
                    │   Redis Server    │
                    │   (Pub/Sub)       │
                    │                   │
                    └───────────────────┘
```

## Why Redis Pub/Sub over WebSockets?

### Advantages:
1. **Scalability**: Supports multiple publishers and subscribers easily
2. **Simplicity**: No persistent connection management required
3. **Reliability**: Automatic reconnection handling
4. **Integration**: Better integration with Laravel ecosystem
5. **Deployment**: Simpler Docker orchestration
6. **Performance**: Lower overhead than WebSocket connections

### Trade-offs:
- Messages are fire-and-forget (no message persistence)
- Subscribers must be running to receive messages
- Best for real-time events, not for guaranteed message delivery

## Message Format

All events follow a consistent JSON structure:

```json
{
  "job_id": "unique-job-id",
  "event": "event_name",
  "data": {
    "key": "value"
  },
  "timestamp": "2025-11-24T15:30:00.000000Z"
}
```

### Event Types

| Event | Description | Data Fields |
|-------|-------------|-------------|
| `sitemap_detected` | Sitemap was found | `sitemap_url`, `total_urls` |
| `urls_discovered` | URLs discovered from sitemap | `urls_count`, `total_to_crawl` |
| `crawling_started` | Crawling process started | `total_pages` |
| `url_fetching` | Starting to fetch a URL | `url`, `page_number`, `total_pages` |
| `url_scraped` | URL was scraped | `url`, `page_number`, `success`, `error` |
| `url_completed` | URL processing complete | `url`, `page_number`, `files_created` |
| `job_completed` | Entire job completed | `pages_crawled`, `output_directory`, `success`, `error` |

## Python Implementation (CustomCrawler)

### Installation

1. **Update requirements.txt**:
   ```bash
   cd CustomCrawler
   pip install -r requirements.txt
   ```

   The `requirements.txt` now includes:
   ```
   redis>=5.0.0
   ```

2. **Configure environment**:
   Copy `.env.example` to `.env` and configure:
   ```env
   REDIS_HOST=redis
   REDIS_PORT=6379
   REDIS_DB=0
   REDIS_PASSWORD=
   REDIS_CHANNEL=scrape-events
   DATA_PATH=/app/data/jobs
   ```

### Code Structure

**`services/redis_event_emitter.py`**:
- Production-ready Redis publisher
- Automatic reconnection with exponential backoff
- Connection health monitoring
- Error handling and logging

**Key Features**:
```python
from services import RedisEventEmitter

# Initialize
emitter = RedisEventEmitter(
    job_id="unique-job-id",
    redis_host="redis",
    redis_port=6379,
    channel="scrape-events"
)

# Connect
await emitter.connect()

# Emit events
await emitter.emit("url_scraped", {
    "url": "https://example.com",
    "page_number": 1,
    "success": True
})

# Close
await emitter.close()
```

### Reconnection Logic

The `RedisEventEmitter` includes robust reconnection logic:
- Automatic reconnection on connection loss
- Configurable max reconnection attempts (default: 5)
- Configurable reconnection delay (default: 2 seconds)
- Connection health checks via `ping()`
- Graceful degradation (logs events even if Redis is unavailable)

## Laravel Implementation

### Installation

1. **Ensure Redis PHP extension is installed**:
   ```bash
   # Check if phpredis is installed
   php -m | grep redis

   # If not installed:
   # For Ubuntu/Debian:
   sudo apt-get install php-redis

   # For macOS with Homebrew:
   pecl install redis
   ```

2. **Configure Laravel environment**:
   Update `.env`:
   ```env
   # Redis Configuration
   REDIS_CLIENT=phpredis
   REDIS_HOST=127.0.0.1
   REDIS_PASSWORD=null
   REDIS_PORT=6379

   # Scrape Service Configuration
   SCRAPE_REDIS_CHANNEL=scrape-events
   CUSTOM_CRAWLER_URL=http://custom-crawler:8000
   SCRAPE_STORAGE_PATH=
   SCRAPE_DEFAULT_MAX_PAGES=100
   SCRAPE_DEFAULT_CONCURRENCY=4
   SCRAPE_DEFAULT_MAX_RPM=60
   SCRAPE_DEFAULT_SKIP_IMAGES=false
   SCRAPE_DEFAULT_DISCOVERY_MODE=false
   SCRAPE_MAX_JOB_DURATION=3600

   # Queue Configuration (required for event processing)
   QUEUE_CONNECTION=database
   ```

3. **Run migrations** (if not already run):
   ```bash
   php artisan migrate
   ```

4. **Clear config cache**:
   ```bash
   php artisan config:clear
   php artisan config:cache
   ```

### Code Structure

**`app/Jobs/ProcessScrapeEvents.php`**:
- Job-specific Redis subscriber
- Automatically dispatched when a scrape job is submitted
- Listens only for events from the specific job ID
- Stops automatically when job completes or times out
- Event validation and processing
- Database persistence (ScrapeMetadata)

**`app/Services/ScrapeService/ScraperPipelineService.php`**:
- Orchestrates the scrape pipeline
- Submits jobs to Python microservice
- Automatically starts event listener for each job

**`config/scrape.php`**:
- Configuration file for scrape service
- Redis channel configuration
- Default job settings
- Max job duration (timeout for event listener)

### How It Works

When you submit a scrape job through the pipeline:

1. **Job Submission**: The pipeline submits the job to the Python microservice via HTTP
2. **Automatic Listener**: Upon successful submission, Laravel automatically dispatches a background job (`ProcessScrapeEvents`)
3. **Job-Specific Listening**: The listener subscribes to Redis and processes only events for that specific job ID
4. **Automatic Termination**: The listener stops when:
   - A `job_completed` event is received
   - The timeout is reached (default: 1 hour)
5. **Event Processing**: All events are stored in the database and trigger appropriate handlers

### No Manual Subscriber Required

Unlike the old WebSocket implementation or traditional pub/sub patterns, **you don't need to run a separate subscriber process**. The event listener is automatically managed for each job.

### Queue Worker Requirement

Since the event listener runs as a Laravel job, you need a queue worker running:

**Development**:
```bash
php artisan queue:work
```

**Production (using Supervisor)**:

Create `/etc/supervisor/conf.d/laravel-worker.conf`:
```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/storage/logs/worker.log
stopwaitsecs=3600
```

Start with:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker:*
```

## Docker Configuration

### Main Application (`docker-compose.yml`)

Redis service added:
```yaml
services:
  redis:
    image: redis:7-alpine
    container_name: hawki_redis
    ports:
      - "6379:6379"
    volumes:
      - redis_data:/data
    networks:
      - hawki-network
    restart: unless-stopped
    command: redis-server --appendonly yes
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 3s
      retries: 5

volumes:
  redis_data:
```

### CustomCrawler (`CustomCrawler/docker-compose.yml`)

Updated to use Redis and hawki-network:
```yaml
services:
  crawler:
    environment:
      - REDIS_HOST=${REDIS_HOST:-redis}
      - REDIS_PORT=${REDIS_PORT:-6379}
      - REDIS_DB=${REDIS_DB:-0}
      - REDIS_PASSWORD=${REDIS_PASSWORD:-}
      - REDIS_CHANNEL=${REDIS_CHANNEL:-scrape-events}

networks:
  default:
    name: hawki-network
    external: true
```

## Deployment Steps

### 1. Start Redis

From the main application directory:
```bash
docker-compose up -d redis
```

Verify Redis is running:
```bash
docker-compose ps redis
docker exec hawki_redis redis-cli ping
# Should respond: PONG
```

### 2. Update CustomCrawler

```bash
cd CustomCrawler
cp .env.example .env
# Edit .env with your Redis configuration
docker-compose build
docker-compose up -d
```

### 3. Start Laravel Queue Worker

The event listener runs as a Laravel job, so you need a queue worker:

```bash
cd /path/to/laravel-app
php artisan queue:work
```

For production, use Supervisor (see Queue Worker Requirement section above).

### 4. Submit a Scrape Job

The event listener is automatically started when you submit a job:

```php
use App\Services\ScrapeService\ScraperPipelineService;
use App\Services\ScrapeService\Data\ScrapeJobRequest;

$pipeline = app(ScraperPipelineService::class);

$request = new ScrapeJobRequest(
    url: 'https://example.com',
    label: 'test-job',
    // ... other config
);

$result = $pipeline->execute($request);
// Event listener is automatically started for this job
```

### 5. Test the Integration

**Test Python publisher**:
```bash
cd CustomCrawler

# Create a test script
cat > test_redis.py << 'EOF'
import asyncio
from services import RedisEventEmitter

async def test():
    emitter = RedisEventEmitter("test-job-123", redis_host="localhost")
    await emitter.connect()

    await emitter.emit("crawling_started", {
        "total_pages": 10
    })

    await emitter.close()
    print("Event published!")

asyncio.run(test())
EOF

python test_redis.py
```

**Monitor Laravel logs**:
```bash
tail -f storage/logs/laravel.log
```

You should see the event being received and processed.

## Monitoring and Debugging

### Check Redis Connection

**From Python**:
```bash
docker exec crawl4ai-service python -c "
import redis
r = redis.Redis(host='redis', port=6379, decode_responses=True)
print('Redis connected:', r.ping())
"
```

**From Laravel**:
```bash
php artisan tinker
>>> Redis::ping()
=> "PONG"
```

### Monitor Redis Pub/Sub

Open a Redis CLI session to monitor messages:
```bash
docker exec -it hawki_redis redis-cli
127.0.0.1:6379> SUBSCRIBE scrape-events
```

Or monitor all channels:
```bash
docker exec -it hawki_redis redis-cli
127.0.0.1:6379> PSUBSCRIBE *
```

### Debug Event Flow

**Python side**:
- Check logs: `docker-compose logs crawler`
- Verify Redis connection in logs
- Check event emission logs

**Laravel side**:
- Check logs: `tail -f storage/logs/laravel.log`
- Verify subscriber is running: `ps aux | grep "scrape:subscribe"`
- Check database: `SELECT * FROM scrape_metadata ORDER BY created_at DESC LIMIT 10;`

### Common Issues

**1. "Connection refused" errors**:
- Verify Redis is running: `docker-compose ps redis`
- Check network connectivity: `docker network inspect hawki-network`
- Verify hostname resolution: `docker exec crawl4ai-service ping redis`

**2. No events received in Laravel**:
- Verify subscriber is running: `php artisan scrape:subscribe`
- Check channel name matches in both services
- Monitor Redis directly (see above)

**3. Messages not persisted**:
- Check `ScrapeProcess` exists for the job_id
- Verify database connection
- Check `scrape_metadata` table exists

**4. Docker network issues**:
- Ensure both services use `hawki-network`
- Recreate network: `docker network create hawki-network`
- Restart services: `docker-compose restart`

## Performance Considerations

### Python Side
- Connection pooling is handled automatically by redis-py
- Health checks run every 30 seconds
- Failed publishes are logged but don't block execution

### Laravel Side
- Subscriber runs as a long-lived process
- Events are processed synchronously
- Database writes are batched where possible
- Consider using queues for heavy processing

### Redis Configuration

For production, consider:
```redis
# /etc/redis/redis.conf
maxmemory 256mb
maxmemory-policy allkeys-lru
save ""  # Disable RDB snapshots for Pub/Sub
appendonly no  # Disable AOF for Pub/Sub
```

## Migration from WebSockets

### Backward Compatibility

The old `EventEmitter` class is still available:
```python
from services import EventEmitter  # Old WebSocket version
from services import RedisEventEmitter  # New Redis version
```

### Migration Steps

1. Deploy Redis infrastructure
2. Update Python service to use `RedisEventEmitter`
3. Deploy Laravel subscriber
4. Test thoroughly
5. Remove WebSocket infrastructure

### Rollback Plan

If issues occur:
1. Stop Redis subscriber
2. Revert Python code to use `EventEmitter`
3. Restart WebSocket server
4. Update environment variables

## Security Considerations

### Redis Authentication

For production, enable Redis authentication:

**Python**:
```python
emitter = RedisEventEmitter(
    job_id="job-123",
    redis_password="your-secure-password"
)
```

**Laravel** (`.env`):
```env
REDIS_PASSWORD=your-secure-password
```

**Docker**:
```yaml
redis:
  command: redis-server --requirepass your-secure-password
```

### Network Security

- Use Docker networks to isolate Redis
- Don't expose Redis port publicly
- Use TLS/SSL for Redis connections in production

### Data Validation

- All events are validated before processing
- JSON schema validation in Laravel
- Type checking in Python

## Maintenance

### Log Rotation

Configure log rotation for long-running subscribers:
```bash
# /etc/logrotate.d/scrape-subscriber
/path/to/storage/logs/scrape-subscriber.log {
    daily
    rotate 7
    compress
    delaycompress
    missingok
    notifempty
}
```

### Health Checks

Add monitoring for:
- Redis service health
- Subscriber process status
- Event processing latency
- Database write success rate

### Backup Considerations

Redis data for Pub/Sub is ephemeral - no backup needed. However:
- Backup `scrape_metadata` table regularly
- Backup `scrape_jobs` table
- Monitor for data loss

## Support and Troubleshooting

### Logs Location

**Python**:
- Container logs: `docker-compose logs crawler`
- Application logs: Check FastAPI output

**Laravel**:
- Application logs: `storage/logs/laravel.log`
- Subscriber logs: Check Supervisor logs if using it

**Redis**:
- Redis logs: `docker-compose logs redis`

### Testing Tools

**Python test script** (`CustomCrawler/test_redis_publisher.py`):
```python
import asyncio
from services import RedisEventEmitter

async def main():
    emitter = RedisEventEmitter("test-job", redis_host="redis")
    await emitter.connect()

    for i in range(5):
        await emitter.emit("url_scraped", {
            "url": f"https://example.com/page-{i}",
            "page_number": i,
            "success": True
        })
        await asyncio.sleep(1)

    await emitter.close()

asyncio.run(main())
```

**Laravel test command**:
```bash
php artisan tinker
>>> Redis::subscribe(['scrape-events'], function($message) { dump($message); });
```

## Conclusion

The Redis Pub/Sub implementation provides a robust, scalable solution for real-time communication between the Python microservice and Laravel application. It offers better performance, simpler deployment, and easier maintenance compared to WebSockets.

For questions or issues, refer to:
- Redis documentation: https://redis.io/docs/manual/pubsub/
- Laravel Redis: https://laravel.com/docs/redis
- redis-py: https://redis-py.readthedocs.io/
