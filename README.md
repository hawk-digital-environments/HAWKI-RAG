# HAWKI RAG – HAWKI’s Retrieval Stack

HAWKI RAG is the customised retrieval deployment used in the HAWKI project. It keeps the
Laravel application and FastAPI bridge you already know, but rebrands the end-user
experience and Docker stack, highlighting the combo of **Qdrant** + **Neo4j** + the
HAWKI RAG pipeline.

HAWKI RAG is designed for fast retrieval over crawled HAWKI content. By default it uses
`bge-m3` for embeddings and `llama3:8b` / `llama3.1:8b` for grounded answers.
<img width="2720" height="992" alt="HAWKI RAG Logo green" src="https://github.com/user-attachments/assets/af606f07-185b-4204-bcb8-8db1e8a58766" />

## Temporal RAG Ingestion

RAG source ingestion is orchestrated by Temporal. Laravel remains the API/admin/control
layer: it creates source records, starts `IngestSourceWorkflow`, stores workflow and
schedule IDs, cancels/retries workflows, and displays status from app PostgreSQL
metadata. Temporal owns durable workflow history, retries, timers, and schedules.

`IngestSourceWorkflow` runs four phases in order on independent task queues:

| Phase | Task queue | Worker |
|---|---|---|
| `scrape_source` | `rag-scraper-task-queue` | `python -m temporal_rag.worker_scraper` |
| `inspect_and_convert_files` | `rag-converter-task-queue` | `python -m temporal_rag.worker_converter` |
| `ingest_markdown_files` | `rag-ingestion-task-queue` | `python -m temporal_rag.worker_ingestion` |
| `mark_source_ready` | `rag-ingestion-task-queue` | `python -m temporal_rag.worker_ingestion` |

The workflow definition is registered by `python -m temporal_rag.worker_workflow` on
`rag-workflow-task-queue`. Temporal payloads contain only small references and status
objects. Raw files, Markdown bodies, chunks, embeddings, and graph data are never passed
through Temporal.

Local Docker handoff uses shared storage:

- `/shared/sources/{source_id}/raw/`
- `/shared/sources/{source_id}/markdown/`
- `/shared/sources/{source_id}/ingest/manifest.json`

Object-storage deployments can use prefixes such as
`s3://hawki-rag/sources/{source_id}/raw/`; the local worker implementation currently
expects shared-volume paths unless an object-storage adapter is added.

PostgreSQL stores both Laravel metadata and Temporal persistence, separated by database
ownership. Laravel tables store sources, documents, workflow IDs, schedule IDs,
freshness metadata, and ingestion status. Temporal PostgreSQL tables store workflow
history/state/retries/timers and are not written by Laravel/RAG code. Qdrant stores chunk
embeddings and vector payload metadata. Neo4j stores entities, relationships, document
graph records, URL/source links, and extracted knowledge graph data. Elasticsearch is
not needed because this local stack uses Temporal SQL/PostgreSQL persistence and
visibility.

RabbitMQ has been removed from Docker/runtime orchestration and the legacy PHP
event-bus/worker layer for RAG ingestion. Source ingestion start/retry/cancel paths and
Docker services now use Temporal instead.

### Run Temporal ingestion locally

```bash
make up-core
```

Enable Temporal UI only when you want low-level workflow diagnostics:

```bash
make up-core-ui
```

Configure external services with `EXTERNAL_SCRAPER_URL`,
`EXTERNAL_SCRAPER_START_PATH`, `EXTERNAL_SCRAPER_STATUS_PATH`,
`EXTERNAL_CONVERTER_URL`, `EXTERNAL_CONVERTER_START_PATH`, and
`EXTERNAL_CONVERTER_STATUS_PATH`. The scraper and converter remain external services;
the workers are only adapters around their start/status APIs.

Start one ingestion workflow from Laravel:

```bash
docker compose exec hawki_rag_app php artisan pipeline:start-task --source-url=https://example.edu --refresh-cadence=daily
```

Daily, weekly, and monthly cadences create/update Temporal schedules for
`IngestSourceWorkflow`. When started with the devtools profile, Temporal UI is
available at `http://localhost:8081` by default.

To validate retries/resume behavior, start a workflow, stop one worker container during
its phase, then start it again. Temporal should keep the workflow open and resume/retry
the activity once the worker returns.

Validation checklist:

1. Start PostgreSQL, Temporal, Laravel, Qdrant, Neo4j, and the FastAPI bridge.
2. Start `make up-core-ui` as needed if you want Temporal UI during debugging.
3. Start all four Temporal workers.
4. Start one workflow from Laravel CLI/API.
5. If Temporal UI is enabled, confirm it shows scrape, converter, ingestion, and readiness activities.
6. Confirm the external scraper and converter services were called.
7. Confirm raw and Markdown files exist under `/shared/sources/{source_id}/`.
8. Confirm Qdrant receives vectors and Neo4j receives graph updates.
9. Confirm Laravel PostgreSQL metadata is updated with workflow/status fields.
10. Restart a worker mid-run and confirm Temporal retries/resumes.
11. Confirm RabbitMQ and Elasticsearch are not required for RAG ingestion.

## Neo4j Graph Explorer Indexes

The HAWKI playground graph explorer uses Neo4j directly for entity search and graph expansion.
Create this fulltext index for fast entity lookup:

```cypher
CREATE FULLTEXT INDEX entity_name_fulltext IF NOT EXISTS
FOR (n:Entity)
ON EACH [n.name, n.entity_id];
```

Semantic graph search uses the existing RAG semantic retrieval as a fallback. If graph nodes
also carry embedding vectors in Neo4j, add a vector index matching the embedding dimensions,
for example for `bge-m3`:

```cypher
CREATE VECTOR INDEX entity_embedding_vector IF NOT EXISTS
FOR (n:Entity)
ON (n.embedding)
OPTIONS {
  indexConfig: {
    `vector.dimensions`: 1024,
    `vector.similarity_function`: 'cosine'
  }
};
```
