# 8. Developer Repository Map

<div className="hero">

Use this page when you know what behavior must change but not where its code
lives. It maps HTTP boundaries, domain logic, Python workflows, adapters,
build files, and the tests that protect each area.

[Understand the runtime architecture](../Getting%20Started/3_introduction_architecture.md)
· [Run the stack](../Getting%20Started/2_setup.md)

</div>

:::info What this reference does not repeat

Runtime responsibilities belong in
[Introduction & Architecture](../Getting%20Started/3_introduction_architecture.md),
environment variables in
[Environment, Database & Temporal](../Operations/5_environment_db_queue.md),
and operational commands in
[Run HAWKI RAG](../Getting%20Started/2_setup.md). This page answers one
question: **where should a developer begin a code change?**

:::

## Find the change you need

| I need to change… | Start here | Follow the behavior into… | Primary tests |
|---|---|---|---|
| Query authorization or dataset scope | `routes/api.php`, `app/Http/Requests/Rag/`, `HawkiRagProxyController.php` | `app/Services/Authorization/` and `app/Services/Rag/` | `tests/Feature/Authorization/`, `tests/Feature/Query/` |
| Retrieval, score merging, or reranking | `python_rag/services/hawki_bridge/src/hawki_bridge/http/routers/query.py` | `hawki_bridge/application/query/`, then `packages/stores/` or the reranker client | `python_rag/tests/bridge/`, `python_rag/tests/characterization/query/` |
| Pipeline creation, retry, or cancellation | `PipelineTaskController.php` and `PipelineControlController.php` | `app/Services/Pipeline/`, then `services/hawki_workflow_worker/` | `tests/Feature/Pipeline/`, `python_rag/tests/workflow/` |
| Chunking, incremental ingestion, or Qdrant commits | `services/hawki_indexer_worker/activities/index.py` | `hawki_indexer_worker/indexing/` and its narrow store adapters | `python_rag/tests/indexer/`, `python_rag/tests/characterization/indexer/` |
| Graph extraction or Neo4j behavior | `app/Http/Controllers/Graph/` or the indexer graph phase | `app/Services/Graph/`, `hawki_indexer_worker/adapters/raganything/`, and `packages/stores/.../neo4j/` | `tests/Feature/Graph/`, `python_rag/tests/graph/` |
| Model providers or model allowlists | `config/model_providers.php` and the owning service settings | `app/Services/Settings/`, `packages/model_providers/`, and indexer provider composition | `tests/Feature/Settings/`, `python_rag/tests/providers/` |
| Browser UI or frontend assets | `routes/web.php` and `resources/js/svelte/` | `resources/views/`, `vite.config.js`, and `svelte.config.js` | `tests/Feature/Ui/` |
| Containers, startup, or health wiring | `Makefile` and `docker-compose*.yml` | `python_rag/services/*/Dockerfile`, `docker/laravel.Dockerfile`, and `docker/` | `tests/System/` plus `make health` and `make test-services` |

## The dependency direction

The typical request path moves inward from framework boundaries to application
logic, then outward through an adapter. Keeping this direction makes business
behavior testable without an HTTP server or live database.

```mermaid
flowchart LR
    subgraph LaravelCode["Laravel control plane"]
        Route["routes"]
        Request["Form Requests"]
        Controller["Controllers"]
        Service["Domain services"]
        Repository["Repositories and clients"]

        Route --> Request --> Controller --> Service --> Repository
    end

    Repository --> Bridge["FastAPI route"]

    subgraph PythonCode["Python data plane"]
        Bridge --> Workflow["Application workflows"]
        Workflow --> Port["Domain contracts"]
        Port --> Adapter["Infrastructure adapters"]
        TemporalWorker["Temporal workers"] --> Workflow
    end
```

This is a navigation model, not a claim that every call crosses every box. For
example, a Laravel service may call the bridge directly, while a pure Python
helper may need no infrastructure adapter.

## Repository at a glance

```text
RAWKI/
├── app/                    Laravel HTTP and domain code
├── routes/                 Browser, API, health, and MCP entrypoints
├── resources/              Svelte, JavaScript, CSS, and Blade source
├── config/                 Laravel runtime configuration mapping
├── database/               Laravel database migrations
├── python_rag/
│   ├── packages/           Seven narrow reusable uv packages
│   ├── services/           Six independently built Python service roles
│   ├── pyproject.toml      Root uv workspace configuration
│   ├── uv.lock             Single Python dependency source of truth
│   └── tests/              Python behavior and integration tests
├── tests/                  Laravel feature, unit, and system tests
├── docker/                 Laravel and service-specific container assets
├── docker-compose*.yml     Base and mode-specific Compose layers
├── python_rag/services/*/Dockerfile  Six service-owned Python role images
└── Makefile                Supported build, startup, test, and lifecycle entrypoints
```

## Laravel control plane

Laravel owns the public HTTP boundary, authorization, dataset configuration,
pipeline control, and operator-facing state.

### Framework boundaries

| Path | Responsibility |
|---|---|
| `routes/web.php` | Browser page shells, Swagger redirect, and crawler UI proxy |
| `routes/api.php` | JSON endpoints for datasets, documents, queries, pipelines, graph operations, settings, and detailed health |
| `routes/health.php` | Lightweight liveness and pipeline-health browser routes |
| `routes/ai.php` | MCP protocol transport |
| `app/Http/Requests/` | Request validation and authorization at the HTTP boundary |
| `app/Http/Controllers/` | Small HTTP adapters that delegate work to services |
| `app/Http/Middleware/` | Browser-query principal enforcement and shared security headers |

### Domain code

Business behavior is grouped by domain under `app/Services/`. Database access
belongs in the domain's repository classes; Eloquent models in `app/Models/`
describe persisted records and relationships.

| Domain | Main concern |
|---|---|
| `Authorization/` | Query principals, dataset grants, and authorized dataset scope |
| `Dataset/` | Dataset lifecycle, collection identity, graph/vector statistics, and cleanup |
| `Document/` | Uploads, browsing, synchronization, previews, and document state |
| `Pipeline/` | Tasks, uploads, Temporal bridge calls, recovery, logs, and projected status |
| `Rag/` and `RagSearch/` | Bridge requests, response filtering, health, monitoring, and MCP search schema |
| `Graph/` | Neo4j administration, exploration, snapshots, search, and result normalization |
| `Settings/` | Persisted operator settings and model runtime selection |
| `Scrape/` and `Storage/` | Crawler integration and shared-file handling |
| `WebSearch/` | Replaceable external search-provider implementations |

Configuration is intentionally split by concern: application values in
`config/app.php`, database settings in `config/database.php`, Temporal in
`config/temporal.php`, providers in `config/model_providers.php`, and
HAWKI-RAG-specific paths and limits in `config/config.php`.

## Python data plane

Python owns ingestion preparation, vector/graph persistence, retrieval,
reranking, model-provider adapters, and Temporal execution.

| Layer | Path | Put code here when… |
|---|---|---|
| **Read-only HTTP boundary** | `python_rag/services/hawki_bridge/src/hawki_bridge/http/` | Validating query/control payloads, handling request context, or exposing a bridge route |
| **Bridge application** | `python_rag/services/hawki_bridge/src/hawki_bridge/application/` | Coordinating scoped retrieval, ranking, generation, and graph reads |
| **Workflow worker** | `python_rag/services/hawki_workflow_worker/` | Changing deterministic Temporal workflow order, names, queues, or retries |
| **Activity workers** | `python_rag/services/hawki_{scraper,converter,indexer}_worker/` | Owning scrape, conversion, or indexing business behavior |
| **Graph extraction** | `python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/adapters/raganything/` | Integrating RAG-Anything/LightRAG extraction, caches, parsing, or fallbacks |
| **Vector/graph stores** | `python_rag/packages/stores/src/hawki_rag_stores/` | Building typed Qdrant or Neo4j transport, request, response, and normalization behavior |
| **Model providers** | `python_rag/packages/model_providers/` | Implementing Ollama or LiteLLM chat/embedding behavior |
| **Reranker** | `python_rag/services/hawki_reranker/` | Hosting the standalone Cohere-compatible reranking API |
| **Shared contracts/runtime** | `python_rag/packages/{contracts,artifact_store,worker_runtime,resilience,text_processing}/` | Reusing a narrow cross-service contract or framework-independent primitive |

### Follow one query

```text
routes/api.php
→ HawkiRagProxyController
→ DatasetQueryAuthorizationService + RagProxyService
→ POST /query
→ hawki_bridge/http/routers/query.py
→ hawki_bridge/application/query/orchestration.py
→ query stages, ranking, and context assembly
→ read-only store, reranker, and provider adapters
```

### Follow one ingestion

```text
Laravel Pipeline controllers
→ app/Services/Pipeline/
→ bridge Temporal-control route
→ workflow worker → scraper worker → converter worker → indexer worker
→ direct in-process hawki_indexer_worker/indexing/orchestration.py
→ chunking + incremental plan + vector commit + optional graph commit
→ Qdrant and Neo4j store adapters + typed signed Laravel callback
```

The exact ingestion commit semantics are documented in
[Ingestion & Embeddings](../Operations/6_ingestion_embeddings.md).

## Build and dependency ownership

| File | Owns |
|---|---|
| `docker/laravel.Dockerfile` | Node/Vite asset build and the PHP/Nginx Laravel runtime image |
| `python_rag/services/*/Dockerfile` | Six digest-pinned, independently built Python role images |
| `docker-compose.yml` | Base services, volumes, internal environment, and optional profiles |
| `docker-compose.ui.yml` | Published local Laravel UI port |
| `docker-compose.local.yml` | Source-mounted development overrides |
| `docker-compose-gpu-override.yml` | NVIDIA-specific Ollama and reranker configuration |
| `composer.json` / `composer.lock` | Laravel/PHP dependencies |
| `package.json` / `package-lock.json` | Frontend and root JavaScript build dependencies |
| `python_rag/pyproject.toml` | uv workspace membership and cross-member resolution constraints |
| `python_rag/packages/*/pyproject.toml` | Exact reusable-package dependencies and build backends |
| `python_rag/services/*/pyproject.toml` | Exact service dependencies, entrypoints, and CPU/CUDA variants |
| `python_rag/uv.lock` | The single locked dependency graph for all packages and services |

## Test map

| Test area | Use it for |
|---|---|
| `tests/Feature/` | Laravel HTTP contracts and database-backed behavior |
| `tests/Unit/` | Laravel services and value behavior without full HTTP flows |
| `tests/System/` | Container and cross-service expectations |
| `python_rag/tests/api/` | FastAPI request/response contracts |
| `python_rag/tests/query/` | Retrieval, ranking, scope, context, and generation behavior |
| `python_rag/tests/indexer/` | Chunking, identity, incremental decisions, and commit behavior |
| `python_rag/tests/graph/` | Graph extraction and dataset-scoped Neo4j writes |
| `python_rag/tests/workflow/`, `scraper/`, `converter/` | Workflow, activity, retry, and external-client behavior |
| `python_rag/tests/providers/` | Provider and RAG-Anything runtime contracts |
| `python_rag/tests/reliability/` | Idempotency, fallback, and partial-failure guarantees |
| `python_rag/tests/integration/` | Tests that deliberately cross adapter boundaries |

:::warning Generated and runtime files are not implementation sources

Do not hand-edit `public/build/`, Laravel files under `storage/`, Python cache
directories, or local `rag_storage` data. Change their source or generating
configuration, then rebuild or rerun the owning process.

:::
