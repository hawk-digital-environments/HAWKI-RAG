# HAWKI RAG Python services

This directory contains independently packaged Python services. It has no shared
service entrypoint. The authoritative
[Repository Map](../../_documentation/Reference/8_repo_map.md#python-service-ownership)
owns workspace dependencies and implementation paths; use
[Architecture](../../_documentation/Getting%20Started/3_introduction_architecture.md)
for cross-service behavior and runtime diagrams.

| Member | Responsibility |
|---|---|
| [hawki_bridge](hawki_bridge/README.md) | Query/graph reads and health; Temporal controls; no canonical Qdrant/Neo4j ingestion writes |
| [hawki_workflow_worker](hawki_workflow_worker/README.md) | Deterministic source and direct-text orchestration |
| [hawki_scraper_worker](hawki_scraper_worker/README.md) | External crawling or upload staging into raw artifacts |
| [hawki_converter_worker](hawki_converter_worker/README.md) | Inspection/conversion into Markdown artifacts |
| [hawki_indexer_worker](hawki_indexer_worker/README.md) | Incremental vectors, optional graph enrichment, terminal callbacks |
| [hawki_reranker](hawki_reranker/README.md) | Cohere-compatible reranking and model lifecycle |

These services do not own Laravel's application records or public access policy.
See [Authorization](../../_documentation/Core%20Concepts/authorization_dataset_scope.md)
and [Ingestion](../../_documentation/Operations/6_ingestion_embeddings.md) for
those boundaries and the complete ingestion flow.

## Directory conventions

Each member owns its `pyproject.toml`, `README.md`, deployable entrypoint, and
colocated `tests/`. Find its console command under `[project.scripts]` and its
implementation in `src/<import_package>/main.py`. `src/` is a packaging boundary.

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
