# HAWKI RAG

RAWKI indexes normalized text and typed source metadata, retrieves and reranks
chunks, and returns source metadata with the results. Integrations can acquire
and extract their own source material and use the [canonical text API](_documentation/Reference/direct_text_ingestion.md).
Managed files and crawler URLs remain supported ingestion adapters. A static
Svelte frontend provides management access to the Python services.

Browser → nginx frontend → `/ui-api/v1/*` → Python UI gateway → FastAPI.
HAWKI services call FastAPI with issuer-validated service JWTs. HAWKI owns human
authentication; the UI gateway keeps service credentials on the server.

FastAPI owns current dataset grants and operational state in PostgreSQL
`hawki_backend` (Alembic **0009**). Python workers run through Temporal and report
directly to signed FastAPI callbacks. Qdrant and Neo4j provide vector/graph storage.
URL ingestion requires the upgraded `permanent-job-v2` crawler.

Start with the [current Python deployment guide](_documentation/Operations/python_runtime.md).
It covers required schema/role/JWKS/service credentials, local overlays, builds,
startup and 26-check readiness. Use [Testing](_documentation/Developer/testing.md)
for Python and frontend commands.

```sh
npm ci
npm test
npm run build
make help
```

The existing frontend remains under `resources/js`, `resources/css` and
`frontend`; it needs no PHP, Blade or Composer. The standalone Vite build is
served by `docker/frontend.Dockerfile`.

Historical `public.*` tables, the `gebuhren` orphan archive and original files,
and crawler historical archives are preserved evidence. They must not be deleted
or automatically adopted. See the [runtime preservation rules](_documentation/Operations/python_runtime.md#historical-evidence-and-reservations)
and [schema history](_documentation/History/public_schema.md).

Fast vector retrieval remains the default. Deep retrieval (`fast_mode: false`)
can include graph facts when the query omits `metadata_filters`. The deployment
controls graph support for new datasets. Managed uploads and URL tasks opt into
graph ingestion. Direct text remains vector-only.
