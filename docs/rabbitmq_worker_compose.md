# RabbitMQ + Worker Compose Setup

This stack now includes:

- `rabbitmq` (`rabbitmq:3-management`) with durable data volume and healthcheck
- `hawki_rag_worker` consumer (`python worker.py`) that declares topology on startup
- `hawki-rag-ingestion-worker` consumer (`python -m workers.rag_ingestion_worker`) for `convert.document.completed`
- `crawler_producer` profile service for an external crawler publisher image

## Topology

- Exchanges (durable direct):
  - `jobs`
  - `jobs.retry`
  - `jobs.failed`
- Queues:
  - `crawl_jobs`
  - `crawl_jobs_retry` (`x-message-ttl` + DLX back to `jobs` with `crawl`)
  - `failed_jobs`
- Routing keys:
  - `crawl`
  - `crawl.retry`
  - `crawl.failed`

## Run

- Start core worker stack:
  - `docker compose up -d rabbitmq hawki_rag_worker`
- Start with external producer profile:
  - `docker compose --profile crawler-producer up -d crawler_producer`
- Start converted-document ingestion worker profile:
  - `docker compose --profile rag-ingestion-worker up -d hawki-rag-ingestion-worker`

## Env Examples

- Single source of truth: root `.env` and `.env.example`
- Worker and crawler producer RabbitMQ variables are both defined there
