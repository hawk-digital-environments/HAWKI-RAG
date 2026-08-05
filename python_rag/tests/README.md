# Python RAG test suite

This suite verifies the six-service Python RAG architecture on Python 3.13.11.
Pytest also collects the retained `unittest.TestCase` characterization tests.

## Trust and ownership boundaries

Laravel is the public control plane. It authenticates users, authorizes dataset
access, derives storage scope, and owns PostgreSQL metadata. The Python bridge
accepts only Laravel-derived `AuthorizedDatasetScope` values and performs
read-only Qdrant and Neo4j retrieval. Python workers never connect to Laravel's
database; they send typed, idempotent HMAC-signed callback events instead.

The bridge exposes query, scoped graph-read, configuration, health, and
Temporal-control routes. It deliberately has no `/ingest`, document-write,
graph-write, or graph-cache mutation routes. Indexing is executed directly by
the indexer worker and never makes an HTTP round trip through the bridge.

```text
Laravel -> bridge -> Qdrant / Neo4j -> optional reranker
   |
   +-> Temporal workflow -> scraper -> converter -> indexer
                              |           |           |
                              +---- artifact store ---+
                              +-- signed callbacks --> Laravel
```

## Test ownership

- `contracts/`: wire schemas, stable Temporal names, and workspace manifest
  invariants.
- `bridge/` and `characterization/query/`: read-only routing, authorization
  scope, retrieval, lexical fallback, reranking, graph reads, and Temporal
  control.
- `workflow/`, `scraper/`, and `converter/`: deterministic workflow commands,
  activity contracts, external-job behavior, artifact hand-off, and retries.
- `indexer/` and `characterization/indexer/`: validation, chunking, incremental
  state, idempotency, replacement/deletion, Qdrant/Neo4j commits, dry runs, and
  in-process indexing.
- `characterization/graph/`, `graph/`, and `stores/`: graph extraction,
  normalization, scoped Neo4j behavior, Qdrant payloads, and storage transport
  contracts.
- `providers/`: Ollama/LiteLLM adapters and indexer RAG-Anything composition.
- `reranker/`: standalone reranker API and query-side consumer contract.
- `worker_runtime/`: exact-body HMAC signatures, stable retry payloads,
  timeouts, retry classification, redaction, and callback validation.
- `reliability/`: package boundaries, startup checks, retry policy, log
  redaction, image/Compose invariants, and source line limits.
- `integration/`: opt-in live Qdrant, Neo4j, Temporal, Ollama, and LiteLLM
  compatibility. Missing services skip unless strict mode is enabled.

The files under `api/`, `query/`, `graph/`, and `temporal/` retain historical
test locations where moving a file would add noise. Their imports and asserted
behavior target the new packages and services exclusively.

## HTTP coverage

Bridge behavior tests cover:

- `GET /health`
- `GET /config`
- `POST /query`
- `POST /graph/related`
- `POST /temporal/workflows/ingest`
- `POST /temporal/schedules/ingest`
- `POST /temporal/schedules/delete`
- `POST /temporal/workflows/cancel`
- absence of `/ingest` and all former bridge write routes

Reranker tests cover `GET /health` and Cohere-compatible `POST /v1/rerank`.
The local ASGI test client runs synchronous handlers on the test thread so the
suite is deterministic in restricted CI environments; production FastAPI and
Uvicorn behavior is unchanged.

## Running tests

From the repository root:

```bash
make python-lock
make python-deps USE_OLLAMA_GPU=0
make python-test USE_OLLAMA_GPU=0
```

The reranker has an intentionally separate environment because the indexer and
reranker require incompatible major Transformers versions. Its focused suite
can be run with:

```bash
cd python_rag
UV_PROJECT_ENVIRONMENT=.venv-reranker \
  uv run --frozen --no-sync pytest -c pytest.ini tests/reranker
```

Live tests only probe already-running dependencies. They never start
containers, download models, or mutate application collections implicitly:

```bash
make python-integration
make provider-test
```

Set `RAWKI_INTEGRATION_REQUIRED=1` in a release environment to turn an
unavailable selected dependency into a failure. Useful endpoint overrides are:

```text
RAWKI_INTEGRATION_QDRANT_URL
RAWKI_INTEGRATION_NEO4J_URI
RAWKI_INTEGRATION_TEMPORAL_ADDRESS
RAWKI_INTEGRATION_OLLAMA_API_URL
RAWKI_INTEGRATION_LITELLM_API_URL
RAWKI_INTEGRATION_MODEL_TIMEOUT
```

For focused local execution, run from `python_rag` with the locked environment:

```bash
.venv/bin/python -m pytest -q tests/bridge
.venv/bin/python -m pytest -q tests/indexer tests/characterization/indexer
.venv/bin/python -m pytest --collect-only -q
```

Production images exclude this entire directory.
