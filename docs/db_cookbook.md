# DB Cookbook (MariaDB + RabbitMQ + Worker Job Store)

Quick command reference for the RAWKI/HAWKI local stack.

## 1) Quick status checks

```bash
docker compose ps
docker compose ps mariadb rabbitmq hawki-rag-scraper-event-worker hawki-rag-scrape-monitor-event-worker hawki-rag-converter-event-worker hawki-rag-ingestion-event-worker
```

## 2) MariaDB access

Use credentials already injected into the `mariadb` container.

```bash
# Root shell (uses MYSQL_ROOT_PASSWORD from container env)
docker exec -it mariadb sh -lc 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD"'

# App user shell (uses MYSQL_USER / MYSQL_PASSWORD / MYSQL_DATABASE from container env)
docker exec -it mariadb sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
```

Run one query without opening interactive shell:

```bash
docker exec mariadb sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SHOW TABLES;"'
```

## 3) P1 persistence tables (SQL)

```sql
SHOW TABLES LIKE 'documents';
SHOW TABLES LIKE 'document_processing_state';
SHOW TABLES LIKE 'document_chunks';
```

Useful checks:

```sql
-- latest documents
SELECT id, status, source_type, created_at
FROM documents
ORDER BY created_at DESC
LIMIT 20;

-- stage/state distribution
SELECT stage, state, COUNT(*) AS c
FROM document_processing_state
GROUP BY stage, state
ORDER BY stage, state;

-- recent failed processing states
SELECT document_id, stage, attempt_count, error_message, updated_at
FROM document_processing_state
WHERE state = 'failed'
ORDER BY updated_at DESC
LIMIT 20;

-- chunk counts per document
SELECT document_id, COUNT(*) AS chunks
FROM document_chunks
GROUP BY document_id
ORDER BY chunks DESC
LIMIT 20;
```

Cascade-delete sanity check:

```sql
-- should return 0 rows for a deleted document_id
SELECT * FROM document_processing_state WHERE document_id = 'PUT-UUID-HERE';
SELECT * FROM document_chunks WHERE document_id = 'PUT-UUID-HERE';
```

## 4) Laravel migration commands

Important hostname note:

- `mariadb` is a Docker-internal hostname.
- If you run `php artisan` from your host shell, DNS will not resolve `mariadb`.

Use one of these:

```bash
# Recommended: run Artisan inside app container
docker exec -it hawki_rag_app php artisan migrate

# Or run from host shell with host DB address
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan migrate
```

If you run Artisan from host often, set `DB_HOST=127.0.0.1` and `DB_PORT=3306` in your host `.env` profile for local runs.

Additional migration commands:

```bash
docker exec -it hawki_rag_app php artisan migrate:status
docker exec -it hawki_rag_app php artisan migrate
docker exec -it hawki_rag_app php artisan migrate:rollback --step=1
```

## 5) RabbitMQ access

Management UI:

- URL: `http://localhost:15672`
- User: `guest` (or your configured `RABBITMQ_USER`)
- Password: `guest` (or your configured `RABBITMQ_PASSWORD`)

Queue/exchange/binding inspection from container:

```bash
docker exec rabbitmq rabbitmqctl list_exchanges name type durable | rg 'jobs'
docker exec rabbitmq rabbitmqctl list_queues name durable arguments messages_ready messages_unacknowledged | rg 'crawl_jobs|failed_jobs'
docker exec rabbitmq rabbitmqctl list_bindings source_name destination_name destination_kind routing_key | rg 'jobs|crawl'

# MVP pipeline topology
docker exec rabbitmq rabbitmqctl list_exchanges name type durable | rg 'pipeline'
docker exec rabbitmq rabbitmqctl list_queues name durable arguments messages_ready messages_unacknowledged | rg 'pipeline_'
docker exec rabbitmq rabbitmqctl list_bindings source_name destination_name destination_kind routing_key | rg 'scrape.requested|scrape.monitor.requested|page.scraped|file.discovered|file.converted|content.ingested|job.failed'
```

Expected topology:

- Exchanges: `jobs`, `jobs.retry`, `jobs.failed`
- Queues: `crawl_jobs`, `crawl_jobs_retry`, `failed_jobs`
- Routing keys: `crawl`, `crawl.retry`, `crawl.failed`

MVP pipeline worker topology:

- Exchanges: `pipeline.events`, `pipeline.retry`, `pipeline.failures`
- Queues: `pipeline_scraper_events`, `pipeline_scrape_monitor_events`, `pipeline_converter_events`, `pipeline_ingestion_events`, `pipeline_failed_events`
- Scrape monitoring: RabbitMQ owns delayed Crawl4AI status polling through `scrape.monitor.requested`
- Event routing keys: `scrape.requested`, `scrape.monitor.requested`, `page.scraped`, `file.discovered`, `file.converted`, `content.ingested`, `job.failed`

## 6) Re-apply topology declaration

```bash
docker compose restart hawki-rag-scraper-event-worker hawki-rag-scrape-monitor-event-worker hawki-rag-converter-event-worker hawki-rag-ingestion-event-worker
docker compose logs --tail=120 hawki-rag-scraper-event-worker hawki-rag-scrape-monitor-event-worker hawki-rag-converter-event-worker hawki-rag-ingestion-event-worker
```

## 7) Publish a test message (HTTP API)

```bash
curl -u guest:guest -H "content-type:application/json" \
  -X POST http://localhost:15672/api/exchanges/%2F/jobs/publish \
  -d '{
    "properties": {"delivery_mode": 2, "content_type": "application/json"},
    "routing_key": "crawl",
    "payload_encoding": "string",
    "payload": "{\"job_id\":\"manual-test-1\",\"retry_count\":0,\"max_retries\":3,\"docs\":[{\"id\":\"doc-1\",\"text\":\"hello\",\"payload\":{}}],\"graph\":false}"
  }'
```

## 8) Worker logs and consumer health

```bash
docker compose logs -f hawki-rag-scraper-event-worker hawki-rag-scrape-monitor-event-worker hawki-rag-converter-event-worker hawki-rag-ingestion-event-worker
docker compose ps hawki-rag-scraper-event-worker hawki-rag-scrape-monitor-event-worker hawki-rag-converter-event-worker hawki-rag-ingestion-event-worker
```

You should see structured events like:

- `receive`
- `processing-start`
- `success`
- `retry-published`
- `failed-published`
- `skip-duplicate`

## 9) Worker job-tracking tables

```sql
SELECT task_id, job_id, job_type, status, source_url, local_path, updated_at
FROM pipeline_jobs
ORDER BY updated_at DESC
LIMIT 20;

SELECT job_id, stage, status, retry_count, max_retries, updated_at
FROM job_processing_state
ORDER BY updated_at DESC
LIMIT 20;
```

## 10) Failed-job queue triage

```bash
# queue depth
docker exec rabbitmq rabbitmqctl list_queues name messages_ready messages_unacknowledged | rg 'pipeline_failed_events|failed_jobs'
```

```sql
SELECT task_id, job_id, job_type, status, metadata, updated_at
FROM pipeline_jobs
WHERE status = 'failed'
ORDER BY updated_at DESC
LIMIT 50;
```
