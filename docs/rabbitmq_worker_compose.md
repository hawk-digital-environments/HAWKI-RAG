# RabbitMQ + Worker Compose Setup

This stack now includes:

- `rabbitmq` (`rabbitmq:3-management`) with durable data volume and healthcheck
- `rabbitmq_topology_init` one-shot topology bootstrap
- `hawki_rag_worker` consumer (`python worker.py`)
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
  - `docker compose up -d rabbitmq rabbitmq_topology_init hawki_rag_worker`
- Start with external producer profile:
  - `docker compose --profile crawler-producer up -d crawler_producer`

## Env Examples

- Worker example: `docker/env/hawki-rag-worker.env.example`
- Producer example: `docker/env/crawler-producer.env.example`

