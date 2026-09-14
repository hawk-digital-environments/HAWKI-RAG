# HAWKI RAG Python services

Six independently packaged roles own the data plane. Use the
[Repository Map](../../_documentation/Reference/8_repo_map.md#python-service-ownership)
for direct workspace dependencies and paths; use
[Architecture](../../_documentation/Getting%20Started/3_introduction_architecture.md)
for the runtime diagrams.

| Member | Responsibility |
|---|---|
| [hawki_bridge](hawki_bridge/README.md) | Read-only data-plane bridge: query/graph reads, health, plus Temporal controls |
| [hawki_workflow_worker](hawki_workflow_worker/README.md) | Deterministic source and direct-text orchestration |
| [hawki_scraper_worker](hawki_scraper_worker/README.md) | External crawling or upload staging into raw artifacts |
| [hawki_converter_worker](hawki_converter_worker/README.md) | Inspection/conversion into Markdown artifacts |
| [hawki_indexer_worker](hawki_indexer_worker/README.md) | Incremental vectors, optional graph enrichment, terminal callbacks |
| [hawki_reranker](hawki_reranker/README.md) | Cohere-compatible reranking and model lifecycle |

Laravel supplies trusted scope and paths. Public authentication policy varies
by route; see [Authorization](../../_documentation/Core%20Concepts/authorization_dataset_scope.md).
Activity workers report state through signed callbacks and do not update
Laravel's database directly.

Ordinary source ingestion runs scrape → convert → index → terminal callback.
Direct text skips scrape/conversion. Within indexing, Qdrant commit precedes
optional graph extraction/commit; these writes are separate failure boundaries.
See [Ingestion](../../_documentation/Operations/6_ingestion_embeddings.md).

## Directory conventions

Each member owns its `pyproject.toml`, `README.md`, deployable entrypoint, and
colocated `tests/`. `src/` is a packaging boundary.

| Directory | Ownership |
|---|---|
| Import package root | Composition, settings, entrypoint, startup checks |
| `domain/` | Pure models, errors, ports |
| `application/` or capability folder | Service-owned use-case policy |
| `adapters/` | Network/store/provider/artifact/callback I/O |
| `http/` | FastAPI schemas, dependencies, routers, middleware, errors |
| `activities/` | Temporal activity side effects, heartbeats, callbacks, results |
| `workflows/` | Deterministic Temporal ordering, retries, history compatibility |

Use [Testing](../../_documentation/Developer/testing.md) for locked commands.
The reranker has a separate environment because its Transformers resolution
conflicts with the indexer. CPU/GPU builds do not create additional service roles.
