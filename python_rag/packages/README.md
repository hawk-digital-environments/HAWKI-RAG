# Shared Python packages

`python_rag/packages/` is the shared toolbox behind HAWKI RAG’s Python services. It contains seven reusable libraries that give every service the same contracts, safety rules, and core behavior without forcing them to depend on each other. This is where common concerns live: validating serialized inputs, keeping artifact paths safely inside Laravel’s allocated storage, providing typed interfaces for model providers and backing stores, and making sure text normalization and chunking behave consistently across the pipeline. It also provides shared handling for retries, safe logging, signed callbacks, and external jobs, so each worker doesn’t have to reinvent the same infrastructure.

The important distinction is that these packages **support the system; they don’t control it**. They don’t run services, decide the order of the ingestion workflow, or determine what should happen next. Laravel remains the control plane and owns authentication, authorization, application metadata, dataset configuration, and the pipeline state shown to operators. The shared Python packages simply operate within the trusted scope they receive, providing a reliable foundation that the bridge, workflow, and workers can build on.


## Rules for every package

- A package may be added here only for a stable cross-service contract or
  genuinely reused behavior. Service-specific orchestration stays in the
  owning service.
- Packages must not import from `python_rag/services/`. Services communicate
  through contracts, Temporal commands, HTTP, artifacts, callbacks, or backing
  stores, never through cross-service imports.
- Domain transformations should be deterministic. Network, database, and
  filesystem I/O belongs in clearly named adapter or transport modules.
- Trusted dataset, collection, graph namespace, and provider values must not be
  replaced by caller-supplied filters or defaults.
- Network calls require finite timeouts. Retry only classified transient
  failures; writes additionally require an idempotent operation and a stable
  operation, event, or request identifier.


## `artifact_store/`

### Safe In, Safe Out!

`artifact_store` is the safe filesystem layer between HAWKI’s activity workers and the shared volume. Given a canonical storage root and stage paths already allocated by Laravel, it makes sure every file operation stays where it belongs. It also creates deterministic content and document identities and writes complete JSON manifests atomically, so readers never end up seeing an artifact that is only half-written. Its responsibility is intentionally focused: **safe local storage and reliable artifact identity**. It doesn’t decide where paths should be allocated, who is authorized to access them, or how long artifacts should live. Object storage, application metadata, lifecycle policies, and converter-specific cleanup all belong elsewhere. `artifact_store` simply makes sure that once a worker touches the shared filesystem, it does so safely and predictably.


### Inputs, outputs, and implementation map

- Inputs: an existing absolute shared root, absolute paths or hostless
  `file:///` URIs, source IDs, relative artifact paths, text/bytes, and manifest
  values.
- Outputs: confined `Path` values, file lists/content, SHA-256 hashes, stable
  document IDs, and atomically replaced JSON manifests.
- `src/hawki_artifact_store/local.py`: `LocalArtifactStore` and all filesystem
  I/O.
- `src/hawki_artifact_store/identity.py`: pure `sha256_text` and `document_id`
  helpers.


## `contracts/`

### The Common Language

`contracts` is the common language shared across HAWKI RAG’s independently deployed services. It takes serialized values—artifacts, authorization scope, ingestion data, queries, rerank requests, callbacks, and Temporal messages—and turns them into validated Pydantic models with stable identifiers. This gives every part of the system the same understanding of the data crossing process boundaries and prevents services from quietly drifting into incompatible formats. Its responsibility is simple but important: **define what shared data looks like and guarantee the rules around it**. It owns serialization shapes and cross-process invariants, but it never acts on the data itself. Filesystem access, databases, networking, frameworks, workflow execution, and service I/O all remain outside this package. `contracts` defines the agreement; the rest of HAWKI decides what to do with it.


### Module map

- `artifacts.py`: raw and Markdown artifact references.
- `auth_scope.py`: trusted authorized query scope.
- `ingestion.py`: workflow input, storage/options, activity inputs/results,
  ready input, and the stable failure result.
- `query.py`: query request, hit, and response models.
- `rerank.py`: Cohere-compatible rerank request and result models.
- `status.py`: worker producer/stage/status enums and callback events.
- `temporal.py`: durable workflow/activity/task-queue names and queue
  resolution.

## `model_providers/`

### One Gate, Many Models

`model_gateway` gives HAWKI a single, consistent way to work with different model providers without tying the rest of the system to any one of them. It accepts the selected provider along with text, messages, images, and safe model overrides, then handles the provider-specific details behind the scenes. Whether the result is generated text or an embedding vector, callers interact with the same predictable interface instead of adapting their code for every provider. The package owns provider protocols, selection, HTTP adapters, payload normalization, and provider-specific validation, including making sure embeddings contain valid, finite numeric values.


### Module map

- `ports.py`: embedding, model-provider, and provider-resolver protocols.
- `factory.py`: selection of the supported `ollama` or `litellm` adapter.
- `ollama.py` and `ollama_helpers.py`: Ollama HTTP behavior, payloads, timeouts,
  dimension inference, bounded text cleanup, and NaN handling.
- `litellm.py`: LiteLLM-compatible HTTP behavior and validation.
- `overrides.py`: canonical request-scoped model-alias overrides.
- `settings.py`: retained compatibility settings surface.

### Rules

- Accept only supported provider names; an unknown provider fails explicitly.
- LiteLLM base URLs must be absolute HTTP(S) URLs without embedded credentials,
  a query, or a fragment. Timeouts must be finite and positive.
- Request overrides may change model aliases, never endpoints, credentials, or
  secrets. Changing the embedding model invalidates the cached dimension.
- Ollama's optional NaN zero-vector response is a bounded operational
  workaround. It is not permission to retry arbitrary provider failures or
  silently route to a different provider.

## `resilience/`

### Keeping Failure under Control

This package gives HAWKI RAG’s adapters and services a shared way to deal with the things that inevitably go wrong. It defines what counts as a safe retry, how idempotent writes should behave, how optional dependencies are handled, and how diagnostics are logged without exposing secrets. It also keeps event names consistent, so failures look and behave the same way across the system instead of every service inventing its own rules. Its role is to **make failures predictable, not magically fix them**. The package provides the primitives for classifying errors and sanitizing diagnostic information, but it doesn’t run business-level retry loops or orchestrate services. Most importantly, it never assumes that every failure can—or should—be retried. `resilience` provides the rules; each service decides how to respond.


### Module map

- `reliability.py`: canonical retry classification, attempt normalization,
  request IDs, and safe request/body previews.
- `redaction.py`: public redaction exports.
- `optional_imports.py`: optional and required dependency loading.
- `events.py`: retained stable observability event names.
- `__init__.py`: supported package-root exports.

### Rules

- A write is retry-safe only when its operation is allowlisted as idempotent and
  the caller supplies a stable identifier.
- HTTP retries cover transient transport failures and the explicit status
  allowlist `429`, `500`, `502`, `503`, and `504`, not validation or other
  permanent failures.
- Redaction must remove known secret-bearing fields and cap preview length.
- Optional import handling may return absence only when the requested root
  module is missing. It must not hide an import error raised inside an installed
  dependency.
- `reliability.py` is the canonical implementation. Do not recreate facade
  modules that can diverge from it.

## `stores/`

### Our Gateway to the Data Layer

`stores` is HAWKI RAG’s shared gateway to Qdrant and Neo4j. It handles the low-level details that services shouldn’t have to repeat: configuration, request construction, response parsing, transport, retries, storage-shape normalization, and scoped access through clean facades. This gives the bridge a consistent way to read and the indexer a consistent way to write, without either of them needing to understand or duplicate the underlying database protocols. Its responsibility is **how HAWKI talks to its data stores, not what it asks them to do**. 

### Directory map

- `src/hawki_rag_stores/`: common namespace and public Qdrant/Neo4j exports.
- `src/hawki_rag_stores/qdrant/`: settings, collections, payload builders,
  request/response parsing, HTTP transport, idempotent gateway, scoped client,
  interpretation, and search strategies.
- `src/hawki_rag_stores/neo4j/`: settings, relation normalization, scoped
  Cypher request builders, response parsing, driver transport, persistence
  facade, and traversal.

### Rules

- A scoped Qdrant client is locked to one authorized collection and must never
  fall back to another collection.
- Qdrant writes retry only when supplied an operation ID. Request construction
  and response interpretation remain separate from transport.
- Neo4j reads and writes require both dataset and namespace. Missing scope must
  fail or skip safely; it must never become a global read, write, or delete.
- Normalize relationship labels and reverse duplicate triplets before use.
- Keep historical persisted shapes readable until deployed data has been
  inventoried and migrated.
- Optional dependency behavior comes from
  `hawki_rag_resilience.optional_imports`; do not restore a private copy.

## `text_processing/`

### Preprocessing Package

This package contains deterministic text and Markdown transformations shared by ingestion and retrieval. Given text and explicit options, it produces chunks, cleaned Markdown, extracted terms, normalized/fallback tags, sanitized context, and heuristic safety results. It owns pure transformation semantics and packaged language data. 

### Module map

- `chunking.py`: canonical chunk splitting.
- `markdown.py`: opt-in removal of recognized converter noise.
- `terms.py`: stopword loading and term extraction.
- `tags.py`: keyword flattening, tag normalization, and deterministic fallback.
- `safety.py`: prompt/output heuristics and snippet helpers.
- `preprocessing.py`: older combined facade still used by active call paths.
- `resources/`: packaged language data, currently the German stopword list.

## `worker_runtime/`

### Purpose and outcome

This package provides common infrastructure for scraper, converter, and indexer activity workers. It signs and delivers callbacks, resumes external jobs from heartbeats, configures safe worker logging/settings, and builds bounded Temporal activity executors. 

### Module map

- `callbacks.py`: structurally typed callback events, exact-body HMAC signing,
  delivery, response validation, and retry classification.
- `external_jobs.py`: start-or-resume and poll behavior for crawler/converter
  jobs.
- `heartbeats.py`: retained public heartbeat helper.
- `retries.py`: retained public retry-delay value object.
- `logging.py`: worker log setup and structured event output.
- `settings.py`: common worker runtime settings.
- `temporal.py`: bounded activity-executor construction.


