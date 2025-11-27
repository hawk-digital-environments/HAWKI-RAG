# Redis Pub/Sub Quick Start Guide

## Quick Setup (5 minutes)

### Prerequisites
- Docker and Docker Compose installed
- PHP with Redis extension
- Python 3.10+
- Laravel queue configured (database or Redis)

### Step 1: Start Redis (1 minute)

```bash
# From the main Laravel directory
docker-compose up -d redis

# Verify it's running
docker exec hawki_redis redis-cli ping
# Expected output: PONG
```

### Step 2: Configure Python Microservice (2 minutes)

```bash
cd CustomCrawler

# Copy environment file
cp .env.example .env

# Edit .env (optional - defaults are fine for local development)
nano .env

# Install dependencies
pip install -r requirements.txt

# Start the service
docker-compose up -d

# Check logs
docker-compose logs -f crawler
```

### Step 3: Configure Laravel (1 minute)

```bash
cd /path/to/laravel-app

# Update .env (add these lines if not present)
cat >> .env << 'EOF'
SCRAPE_REDIS_CHANNEL=scrape-events
CUSTOM_CRAWLER_URL=http://custom-crawler:8000
SCRAPE_MAX_JOB_DURATION=3600
QUEUE_CONNECTION=database
EOF

# Run migrations if needed
php artisan migrate

# Clear config cache
php artisan config:clear
```

### Step 4: Start Laravel Queue Worker (1 minute)

```bash
# Start the queue worker (this processes the event listeners)
php artisan queue:work
```

You should see:
```
INFO  Processing jobs from the [default] queue.
```

**Note**: The event listener is **automatically started** when you submit a scrape job. You don't need to run a separate subscriber command!

## Quick Test

### Terminal 1: Monitor Queue Worker
```bash
# In Laravel directory
php artisan queue:work --verbose
```

### Terminal 2: Monitor Laravel Logs
```bash
# In Laravel directory
tail -f storage/logs/laravel.log
```

### Terminal 3: Send Test Event from Python

Run the test publisher:
```bash
cd CustomCrawler
python test_redis_publisher.py
```

This will simulate a complete scraping job with multiple events.

### Expected Output

**Terminal 1 (Queue Worker)**:
```
INFO  Processing jobs from the [default] queue.
INFO  Processing: App\Jobs\ProcessScrapeEvents
```

**Terminal 2 (Laravel logs)**:
```
[2025-11-24 15:30:00] local.INFO: Started Redis event listener for job test-job-simulation-456
[2025-11-24 15:30:01] local.INFO: Processing event: sitemap_detected for job test-job-simulation-456
[2025-11-24 15:30:02] local.INFO: Processing event: crawling_started for job test-job-simulation-456
[2025-11-24 15:30:03] local.INFO: Processing event: url_scraped for job test-job-simulation-456
[2025-11-24 15:30:05] local.INFO: Job test-job-simulation-456 completed, stopping event listener
```

**Terminal 3 (Python test script)**:
```
✓ All events published successfully!
```

## Common Commands

### Check Redis Status
```bash
docker exec hawki_redis redis-cli ping
```

### Monitor Redis Messages (debugging)
```bash
docker exec -it hawki_redis redis-cli
127.0.0.1:6379> SUBSCRIBE scrape-events
```

### Restart Services
```bash
# Restart Redis
docker-compose restart redis

# Restart Python microservice
cd CustomCrawler && docker-compose restart

# Restart Laravel subscriber
# Stop with Ctrl+C and run again:
php artisan scrape:subscribe
```

### View Logs
```bash
# Redis logs
docker-compose logs -f redis

# Python logs
cd CustomCrawler && docker-compose logs -f crawler

# Laravel logs
tail -f storage/logs/laravel.log
```

## Next Steps

1. **Production Deployment**: See [REDIS_PUBSUB_IMPLEMENTATION.md](REDIS_PUBSUB_IMPLEMENTATION.md#deployment-steps)
2. **Supervisor Setup**: Configure long-running subscriber process
3. **Monitoring**: Add health checks and alerting
4. **Security**: Enable Redis authentication

## Troubleshooting

### Can't connect to Redis
```bash
# Check if Redis is running
docker ps | grep redis

# Check Docker network
docker network inspect hawki-network

# Test connection from Python container
docker exec crawl4ai-service ping redis
```

### No events received in Laravel
```bash
# 1. Check if queue worker is running
ps aux | grep "queue:work"

# 2. Check if job was dispatched
SELECT * FROM jobs ORDER BY id DESC LIMIT 5;

# 3. Check if events are being published by Python
docker exec -it hawki_redis redis-cli
127.0.0.1:6379> SUBSCRIBE scrape-events
# Then run a test from Python

# 4. Verify channel name matches
# Python: Check .env REDIS_CHANNEL
# Laravel: Check config/scraper.php 'redis_channel'
```

### Queue worker not picking up jobs
```bash
# Check queue connection
php artisan queue:failed

# Restart queue worker
# Stop with Ctrl+C and restart:
php artisan queue:work

# Check for failed jobs
php artisan queue:retry all
```

### Import errors in Python
```bash
cd CustomCrawler
pip install -r requirements.txt

# Verify redis is installed
python -c "import redis; print(redis.__version__)"
```

## Support

For detailed documentation, see [REDIS_PUBSUB_IMPLEMENTATION.md](REDIS_PUBSUB_IMPLEMENTATION.md)

For issues, check:
- Python logs: `docker-compose logs crawler`
- Laravel logs: `storage/logs/laravel.log`
- Redis logs: `docker-compose logs redis`
