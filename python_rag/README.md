# RAWKI Python RAG workspace

`python_rag` is one Python 3.13.14 uv workspace with one lockfile. It contains
small reusable packages and six independently built production services. Laravel
is the public control plane and remains the sole owner of authentication,
authorization, and application PostgreSQL metadata.

## Runtime architecture

```text
Laravel --authorized scope--> bridge --read--> Qdrant / Neo4j --optional--> reranker
   |                           |
   +--start/cancel/schedule----+--> Temporal
                                      |
                                      v
                  workflow -> scraper -> converter -> indexer
                                |            |            |
                                +---- artifact store -----+--> Qdrant / Neo4j
                                +-- signed, typed callbacks --> Laravel
```

The bridge exposes health, config, authorized query, scoped graph-read, and
Temporal-control endpoints. It has no ingestion endpoint and no vector or graph
write path. The indexer executes ingestion in-process; it never calls the bridge.
Workers report status and artifact manifests through an HMAC-signed, idempotent
Laravel callback and never connect to Laravel's database.

## Workspace members

Reusable libraries:

- `packages/contracts`: side-effect-free Pydantic wire contracts and stable
  Temporal names.
- `packages/artifact_store`: root-confined local shared-volume operations,
  atomic manifests, and stable content/document identities. Laravel allocates
  the paths; canonical wire models live in `packages/contracts`.
- `packages/worker_runtime`: Temporal bootstrap helpers, retries, heartbeats,
  logging, external jobs, and signed callback delivery.
- `packages/resilience`: retry, optional-import, redaction, and event helpers.
- `packages/text_processing`: Markdown/text normalization, chunking, tags,
  safety, terms, and packaged German stopwords.
- `packages/model_providers`: provider ports plus Ollama and LiteLLM adapters.
- `packages/stores`: typed low-level Qdrant and Neo4j clients.

Production services and image roles:

| Service member | Image | Responsibility |
| --- | --- | --- |
| `services/hawki_bridge` | `hawki-rag-bridge` | Read-only query/graph API and Temporal control |
| `services/hawki_workflow_worker` | `hawki-rag-workflow-worker` | Deterministic ingestion workflows |
| `services/hawki_scraper_worker` | `hawki-rag-scraper-worker` | Scraping and raw artifacts |
| `services/hawki_converter_worker` | `hawki-rag-converter-worker` | Inspection, conversion, and Markdown artifacts |
| `services/hawki_indexer_worker` | `hawki-rag-indexer-worker` | Incremental vector/graph indexing |
| `services/hawki_reranker` | `hawki-rag-reranker` | Cohere-compatible local reranking API |

The indexer and reranker support `cpu` and `gpu` dependency/image variants.
Those are alternative builds of the same two roles, not extra services.
The two roles are intentionally separate uv environments: MinerU uses
Transformers 4 while the reranker uses Transformers 5. uv records both exact
resolutions in the one lockfile and prevents installing the incompatible roles
into the same Python environment.

LightRAG remains pinned to immutable source commit
`c5bf73dbf6139f1b03f738a2fec4e47d5e66f3ab`, which reports package version
`1.5.5`. That commit predates and differs from the published `1.5.5` wheel, so
the indexer builder installs this one dependency from source. All registry
dependencies retain exact versions in `uv.lock`; this intentional source pin is
the only exception to a literal wheel-only install.

## Dependency management

Install uv `0.11.26` and Python `3.13.14`, then run commands from the repository
root:

```bash
make python-lock                 # refresh one python_rag/uv.lock and verify variants
make python-deps USE_OLLAMA_GPU=0
make python-quality
make python-test USE_OLLAMA_GPU=0
```

For CUDA 13.0 use `USE_OLLAMA_GPU=1`. Production Docker builds use `uv sync
--frozen --no-dev --no-editable` against the root lock. Member projects pin all
direct runtime, test, and build dependencies exactly. See
[`DEPENDENCY_DELTA.md`](DEPENDENCY_DELTA.md) for intentional changes from the
legacy requirements exports.

Live tests remain opt-in:

```bash
make python-integration
make provider-test
```

## Local and production containers

Validate the model and build all six roles:

```bash
docker compose --env-file .env config --quiet
docker compose --env-file .env build \
  hawki_rag_bridge \
  hawki_rag_rerank \
  hawki-rag-temporal-workflow-worker \
  hawki-rag-temporal-scraper-worker \
  hawki-rag-temporal-converter-worker \
  hawki-rag-indexer-worker
```

`make up-core` starts the production-style stack. `make up-core-local` adds the
local UI override without bind-mounting the entire Python workspace into every
container. Production images use allowlisted workspace members and exclude
tests, caches, build output, and unrelated service code. 

Worker callbacks require the same non-empty
`HAWKI_RAG_WORKER_CALLBACK_SECRET` in Laravel and each activity worker. The
callback client uses an explicit timeout, signs the exact transmitted body, and
retries only the stable idempotent event identifier. Bridge Qdrant and Neo4j
credentials should be read-only where the backing services support separate
credentials.

## Temporal compatibility

The initial migration preserves `IngestSourceWorkflow`, `scrape_source`,
`inspect_and_convert_files`, `ingest_markdown_files`, and `mark_source_ready`.
The workflow uses Temporal's `workflow.patched` API when selecting the indexer
queue. Pre-patch histories continue emitting the legacy ingestion-queue
command; new executions may use `task_queues.indexer`. The indexer worker polls
both the configured indexer queue and `rag-ingestion-task-queue` while old
executions drain. Tests cover both patched and pre-patch command paths. No
captured production histories were available in this checkout, so history
replay must still be exercised before retiring the legacy queue.

A second patch marker preserves the old early-return command history for
unsuccessful index results. New executions run the cheap `mark_source_ready`
activity for terminal callback delivery, while the expensive indexing activity
remains isolated from callback retries. Tests cover both sides of this patch;
captured production histories are still required for a real Replayer gate.
