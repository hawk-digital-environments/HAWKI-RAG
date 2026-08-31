# HAWKI RAG Python services

This directory contains the six independently packaged production roles in the
HAWKI RAG Python data plane. Together they turn an authorized Laravel ingestion
request into durable raw, Markdown, vector, and optional graph data, then serve
dataset-scoped retrieval.

## Result these services must achieve

```text
Laravel --authorized scope--> bridge --read--> Qdrant / Neo4j --optional--> reranker
   |                           |
   +--start/cancel/schedule----+--> Temporal
                                      |
                                      v
                  workflow -> scraper -> converter -> indexer
                                |            |            |
                                +---- artifact store -----+--> Qdrant / Neo4j
                                +-- signed callbacks ----------> Laravel
```

The complete service outcome is:

1. Laravel authenticates and authorizes the request, supplies trusted dataset
   scope and safe storage paths, and starts or controls the Temporal workflow.
2. The workflow worker durably orders scraping, conversion, indexing, and the
   terminal callback without performing nondeterministic I/O.
3. The scraper produces validated raw artifacts.
4. The converter produces canonical Markdown artifacts and deterministic
   artifact metadata.
5. The indexer produces dataset-scoped Qdrant records and, when enabled,
   dataset- and namespace-scoped Neo4j facts.
6. The bridge reads only the authorized data and may use the reranker to return
   better ordered evidence.
7. Activity workers report progress through typed, idempotent, HMAC-signed
   callbacks. They never update Laravel's database directly.

CPU and GPU builds of the indexer and reranker are variants of the same two
roles, not additional services. They remain isolated because their model stacks
resolve incompatible Transformers generations.



## Directory conventions

Every member root owns a `pyproject.toml`, a member-focused `README.md`, and one
deployable entrypoint. It also owns a `tests/` directory beside `src/`, divided
by `unit`, `integration`, `contract`, and `characterization` where applicable.
`src/` is only the Python packaging boundary; it is not a business layer.

| Directory name | Ownership rule |
| --- | --- |
| import-package root | Process or ASGI entrypoint, composition, settings, startup checks, and service-wide logging |
| `domain/` | Framework-independent models, errors, and ports; no infrastructure I/O |
| `application/` or capability folder | Use-case policy and orchestration for that service |
| `adapters/` | Concrete network, store, artifact, provider, and callback integrations |
| `http/` | FastAPI schemas, dependencies, routers, middleware, and error translation only |
| `activities/` | Temporal activity validation, side effects, heartbeats, callbacks, and result boundary |
| `workflows/` | Deterministic Temporal orchestration and retry policy only |

Run one service from `python_rag` with `uv run --group test --package
<distribution-name> pytest services/<member>/tests`. The reranker uses the
separate `.venv-reranker` environment documented in `tests/README.md` because
its Transformers resolution intentionally conflicts with the indexer.

## `hawki_bridge`

### The Backbone of the System

The bridge is Laravel’s read-only HTTP connection to the Python side. Laravel sends requests with an already trusted authorization scope, and the bridge takes care of getting the right information back—whether that’s query results, related graph facts, health information, or Temporal control results. Behind the scenes, it talks to Qdrant, Neo4j, model providers, the reranker, and Temporal as needed.
Its job is mainly retrieval orchestration and translating between HTTP requests and the services behind them. It doesn’t handle authentication, ingestion, persistence writes, Laravel metadata, or worker execution. Laravel is always the caller; the bridge simply connects it to the Python services it needs.


### HTTP surface

| Route | Responsibility |
| --- | --- |
| `GET /health` | Liveness and optional lightweight runtime summary |
| `POST /query` | Authorized, dataset-scoped retrieval and grounded generation |
| `POST /graph/related` | Authorized, dataset- and namespace-scoped graph read |
| `POST /temporal/workflows/ingest` | Start the stable ingestion workflow |
| `POST /temporal/schedules/ingest` | Create or update an ingestion schedule |
| `POST /temporal/schedules/delete` | Delete an ingestion schedule |
| `POST /temporal/workflows/cancel` | Cancel an ingestion workflow |

### Directory ownership

| Path below `src/hawki_bridge/` | Role |
| --- | --- |
| root modules | ASGI entrypoint, composition factory, settings, startup checks, and logging |
| `adapters/` | Qdrant/Neo4j reads, external/local reranking, and Temporal client calls |
| `application/` | Query and graph-read use cases plus composition helpers |
| `application/query/` | Scope, rewrite, vector/lexical retrieval, recovery, hit fusion, ranking, reranking, context, generation, and output safety stages |
| `domain/` | Bridge-owned ports and application errors |
| `http/` | FastAPI schemas, dependencies, and transport composition |
| `http/errors/` | Stable JSON exception translation |
| `http/middleware/` | Request-ID propagation and request logging |
| `http/routers/` | Thin route adapters for health, query, graph, and Temporal control |


## `hawki_workflow_worker`

### The Conductor of the System

The workflow worker is the conductor behind `IngestSourceWorkflow`. It takes the shared ingestion payload and guides it through each phase until we either have a ready result or a stable failure. The bridge starts and controls the workflow, while the three activity workers pick up and execute the actual work. Its job is to keep everything moving in the right order. It owns phase sequencing, timeouts, retry policies, queue selection, and workflow history compatibility—but it never does the actual scraping, conversion, or indexing itself. It also stays away from network and filesystem operations. It coordinates the work; the activity workers do it.


### Durable sequence

```text
scrape_source
    -> inspect_and_convert_files
        -> ingest_markdown_files
            -> mark_source_ready
```

## `hawki_scraper_worker`

### Bringing the data in.

The scraper is where the ingestion journey begins. It owns the `scrape_source` activity, either staging a Laravel upload into shared storage or starting—or resuming—the configured external crawler. It works with the ingestion scope, URL or upload details, the raw path provided by Laravel, and crawler options, then produces a validated `ScrapeResult` along with signed status updates. From there, the converter picks up the artifacts while Laravel receives the callbacks. Its job is to find, collect, and normalize the raw content. It owns raw artifact production and crawler normalization, but that’s where its responsibility ends. Markdown conversion, indexing, public HTTP routes, and application metadata are handled elsewhere.


### Directory ownership

| Path below `src/hawki_scraper_worker/` | Role |
| --- | --- |
| root modules | Worker entrypoint and scraper-only environment settings |
| `activities/` | Temporal input/result validation, heartbeat resume, callbacks, and error boundary |
| `adapters/` | Confined upload staging and signed/redacted status delivery |
| `scraping/` | Crawler request, client, response normalization, path mapping, and scraping use case |


## `hawki_converter_worker`

### The Conversion Step!

The converter owns `inspect_and_convert_files`. It takes the trusted workflow scope and a validated scrape result, inspects the files, picks the right converter profile, and turns them into Markdown artifacts. The result is a `ConvertResult` containing artifact references, hashes, stable document identities, counts, and status. The indexer takes it from there, while Laravel gets the relevant updates through callbacks. The converter is responsible for everything around file inspection and conversion, including safely handling converter responses and building the final Markdown artifacts. Its job ends there—it doesn't deal with embeddings, vector or graph persistence, or Laravel metadata. Those belong to the systems further down the pipeline.


### Directory ownership

| Path below `src/hawki_converter_worker/` | Role |
| --- | --- |
| root modules | Worker entrypoint and converter-only environment settings |
| `activities/` | Temporal validation, callback delivery, result shaping, and retry classification |
| `adapters/` | External converter compatibility facade and signed status callback |
| `conversion/` | Profile resolution, candidate inspection, direct extraction, safe archive handling, passthrough, and Markdown artifact construction |



## `hawki_indexer_worker`

### Where the indexing magic happens

The indexer owns `ingest_markdown_files` and `mark_source_ready`. It validates
converter artifacts, plans incremental changes, chunks content, obtains
embeddings, writes Qdrant, optionally extracts and writes Neo4j facts, produces
manifests/summaries/previews, and sends indexing and terminal events to Laravel.

### Phase model

```text
validate request and artifacts
    -> plan unchanged / replaced / deleted pages
        -> prepare chunks, vectors, and optional graph facts   (no store mutation)
            -> commit deletions and new vector/graph state     (idempotent writes)
                -> finalize manifests, summaries, callbacks, and ready state
```


## `hawki_reranker`

### What do we mean here by Reranker?

The reranker is a small, Cohere-compatible HTTP service used by the bridge. It accepts a query, a non-empty list of documents, and an optional `top_n` parameter, then returns the original document strings and their indices ordered by descending relevance score. It is responsible for managing the cross-encoder model lifecycle and performing canonical rerank validation, but it does not handle retrieval, authorization, dataset scoping, or generation.

### HTTP and directory ownership

| Surface | Role |
| --- | --- |
| `GET /health` | Liveness without forcing model inference |
| `POST /v1/rerank` | Canonical Cohere-compatible reranking contract |
| `src/hawki_reranker/` | Flat FastAPI app, shared schema re-exports, request error, environment settings, and lazy model adapter |
