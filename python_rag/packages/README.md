# Shared Python packages

`python_rag/packages/` contains libraries used by the backend and workers. Packages
provide shared contracts and adapters. The consuming services decide what work
to authorize and in what order to run it.

## Package rules

- Packages never import service code.
- Service-specific policy remains in the owning service.
- Domain transformations are deterministic and independent from I/O.
- Network, database, and filesystem behavior lives in named adapters.
- Retry classification stays beside the client whose errors it understands.
- Required dependencies use direct imports and fail during startup/import when
  the deployment is incomplete.

Each package keeps its tests beside its source in `packages/<member>/tests/`.
The `unit`, `integration`, `contract`, and `characterization` subdirectories make
the kind and owner of a test visible from its path. Run one package from
`python_rag` with `uv run --group test --package <distribution-name> pytest
packages/<member>/tests`; uv installs the package instead of relying on the
working directory or `PYTHONPATH`.

## Package map

### `artifact_store/`

Root-confined shared-volume operations, atomic manifests, and stable artifact
identity. FastAPI allocates paths and owns artifact lifecycle.

### `contracts/`

Side-effect-free Pydantic wire contracts for artifacts, authorization scope,
ingestion, queries, reranking, callbacks, and stable Temporal identifiers.

### `external_jobs/`

HTTP job submission and status polling for scraper and converter adapters.
The CustomCrawler client also verifies `crawler-api` job receipts and sends
cancellation requests.

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
events sent to FastAPI.

### `text_processing/`

Pure Markdown cleanup, chunking, term extraction, tag normalization, packaged
language data, and deterministic prompt/output safety rules. It performs no
model, network, database, filesystem, or environment I/O.

### `vector_store/`

Qdrant contracts, ports, request/response mapping, scoped collection behavior,
transport, and Qdrant-specific retry policy. Retrieval ranking and fallback
strategies are application-owned.

### `worker_runtime/`

Temporal executor construction, heartbeat delivery, retry-delay values, and
worker logging and settings. FastAPI callbacks and external jobs live
in their own packages.

### `retrieval/`

Query execution, semantic/lexical fusion, graph retrieval, reranking, context
assembly, and answer generation. Concrete Qdrant, Neo4j, and reranker adapters.
Retrieval core contains no HTTP dependency; it exports one typed query use case
and ports for model, vector, graph, and reranker capabilities.
