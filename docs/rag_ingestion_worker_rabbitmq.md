# RabbitMQ RAG Ingestion Worker

This extension adds a dedicated RabbitMQ consumer for converted-document events without changing
existing manual/API ingestion behavior.

## Event Flow

- scraper repo publishes `scrape.file.discovered`
- file-converter repo consumes and publishes `convert.document.completed`
- HAWKI RAG `workers.rag_ingestion_worker` consumes converted events and ingests into the existing pipeline

## Required Env Vars

```env
COMMUNICATION_ENABLED=true
COMMUNICATION_METHOD=rabbitmq

RABBITMQ_HOST=rabbitmq
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_HEARTBEAT=30
RABBITMQ_CONNECTION_TIMEOUT=30

RABBITMQ_EVENTS_EXCHANGE=pipeline.events
RABBITMQ_EVENTS_EXCHANGE_TYPE=direct

RABBITMQ_RETRY_EXCHANGE=pipeline.retry
RABBITMQ_RETRY_EXCHANGE_TYPE=direct

RABBITMQ_FAILED_EXCHANGE=pipeline.failed
RABBITMQ_FAILED_EXCHANGE_TYPE=direct

RABBITMQ_RAG_INGESTION_QUEUE=rag_ingestion_jobs
RABBITMQ_DOCUMENT_CONVERTED_ROUTING_KEY=convert.document.completed

RABBITMQ_RAG_INGESTION_RETRY_QUEUE=rag_ingestion_jobs_retry
RABBITMQ_RAG_INGESTION_RETRY_ROUTING_KEY=convert.document.completed.retry

RABBITMQ_FAILED_QUEUE=failed_jobs
RABBITMQ_FAILED_ROUTING_KEY=pipeline.failed

RABBITMQ_RETRY_DELAY_MS=5000
RABBITMQ_PREFETCH_COUNT=1
RABBITMQ_MAX_RETRIES=3
RABBITMQ_QUEUE_TYPE=quorum

RABBITMQ_PUBLISHER_CONFIRMS=true
RABBITMQ_PERSISTENT_MESSAGES=true

JOB_SCHEMA_VERSION=1
SERVICE_NAME=hawki-rag

SHARED_STORAGE_ROOT=/app/shared
```

## Run Worker

Docker Compose profile:

```bash
docker compose --profile rag-ingestion-worker up -d hawki-rag-ingestion-worker
docker compose logs -f hawki_rag_ingestion_worker
```

Local process:

```bash
cd python_rag
python -m workers.rag_ingestion_worker
```

## Expected `convert.document.completed` Payload

```json
{
  "event_id": "UUID",
  "job_id": "UUID",
  "parent_event_id": "UUID",
  "schema_version": "1",
  "event_type": "convert.document.completed",
  "source": "file-converter",
  "original_url": "https://example.org/file.pdf",
  "original_path": "/app/shared/crawled/file.pdf",
  "original_relative_path": "crawled/file.pdf",
  "converted_path": "/app/shared/converted/file.md",
  "converted_relative_path": "converted/file.md",
  "output_format": "markdown",
  "converter_name": "mineru",
  "converter_version": "3.0.0",
  "input_checksum_sha256": "optional",
  "output_checksum_sha256": "optional",
  "converted_at": "2026-04-27T12:00:00Z",
  "trace_id": "optional",
  "payload": {}
}
```

## Retry and Failed Queue Behavior

- Manual ACK only.
- On success: persist `job_processing_state` status as `completed`, then ACK.
- On transient failure:
  - increment retry counter
  - republish message to `pipeline.retry` with routing key `convert.document.completed.retry`
  - ACK original only after retry publish succeeds
- On permanent failure or retry limit exceeded:
  - publish `pipeline.failed` event
  - mark job as `failed`
  - ACK original only after failed publish succeeds

No `basic_nack(..., requeue=True)` retry loop is used.

