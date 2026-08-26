# Shared Python packages

`python_rag/packages/` contains focused libraries shared by independently
deployed RAWKI Python services. Packages provide stable contracts and reusable
infrastructure; they do not orchestrate services, interpret authorization, or
own workflow order.

## Package rules

- Packages never import service code.
- Service-specific policy remains in the owning service.
- Domain transformations are deterministic and independent from I/O.
- Network, database, and filesystem behavior lives in named adapters.
- Retry classification stays beside the client whose errors it understands.
- Required dependencies use direct imports and fail during startup/import when
  the deployment is incomplete.

## Package map

### `artifact_store/`

Root-confined shared-volume operations, atomic manifests, and stable artifact
identity. Laravel allocates paths and owns artifact lifecycle.

### `contracts/`

Side-effect-free Pydantic wire contracts for artifacts, authorization scope,
ingestion, queries, reranking, callbacks, and stable Temporal identifiers.

### `external_jobs/`

Generic HTTP start-or-resume and status-polling behavior used by scraper and
converter adapters. Service payload interpretation remains outside the package.

### `graph_store/`

Neo4j contracts, ports, scoped Cypher requests, response parsing, driver
lifecycle, and managed-transaction behavior. Graph cleanup, retrieval ranking,
and RAG-hit projection are application-owned.

### `model_providers/`

Model capability ports plus Ollama and LiteLLM adapters. Provider configuration
accepts explicit model aliases; request, authorization, dataset, and workflow
interpretation remain service-owned.

### `observability/`

Stable event names, correlation-ID selection, and bounded secret-safe logging.
It contains no retry, HTTP client, database, or framework behavior.

### `pipeline_callbacks/`

Exact-body HMAC signing and retry-classified HTTP delivery for immutable worker
events sent to Laravel.

### `text_processing/`

Pure Markdown cleanup, chunking, term extraction, tag normalization, packaged
language data, and deterministic prompt/output safety rules. It performs no
model, network, database, filesystem, or environment I/O.

### `vector_store/`

Qdrant contracts, ports, request/response mapping, scoped collection behavior,
transport, and Qdrant-specific retry policy. Retrieval ranking and fallback
strategies are application-owned.

### `worker_runtime/`

Temporal activity-executor construction, heartbeat delivery, retry-delay value
objects, and worker logging/settings. Laravel callbacks and external jobs live
in their own packages.
