# HAWKI RAG – HAWKI’s Retrieval Stack

HAWKI RAG is the customised retrieval deployment used in the HAWKI project. It keeps the
Laravel application and FastAPI bridge you already know, but rebrands the end-user
experience and Docker stack, highlighting the combo of **Qdrant** + **Neo4j** + the
HAWKI RAG pipeline.

HAWKI RAG is designed for fast retrieval over crawled HAWKI content. By default it uses
`bge-m3` for embeddings and `llama3:8b` / `llama3.1:8b` for grounded answers.
<img width="2720" height="992" alt="HAWKI RAG Logo green" src="https://github.com/user-attachments/assets/af606f07-185b-4204-bcb8-8db1e8a58766" />

## RabbitMQ Ingestion Worker

Converted-document event consumption is available as an additive worker layer:

- Docs: [docs/rag_ingestion_worker_rabbitmq.md](docs/rag_ingestion_worker_rabbitmq.md)
- DB/ops commands: [docs/db_cookbook.md](docs/db_cookbook.md)
- Worker entrypoint: `python -m workers.rag_ingestion_worker`

## Scheduled Crawl Pipeline (Make-Based)

Scheduler execution is additive and uses Make targets as source-of-truth:

- scraper repo: `make crawl ...`
- HAWKI RAG repo: `make ingest ...`
- Docs: [docs/scheduled_crawl_make_pipeline.md](docs/scheduled_crawl_make_pipeline.md)

Create a daily scheduled crawl job:

```bash
php artisan rag:schedule-crawl \
  --url="https://www.hawk.de" \
  --period=per-day \
  --job-id="job_date_2026_04_28" \
  --crawled-root="/app/shared/crawled-data" \
  --graph=true
```

Run due jobs manually:

```bash
php artisan rag:run-scheduled-crawls
```

Make examples:

```bash
make crawl URL=https://www.hawk.de JOB_ID_FULL=job_date_2026_04_28
make ingest CRAWLED_ROOT=/app/shared/crawled-data GRAPH=true
make ingest CRAWLED_ROOT=/app/shared/crawled-data GRAPH=false
```
