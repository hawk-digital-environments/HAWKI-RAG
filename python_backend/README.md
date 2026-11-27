# HAWKI backend

FastAPI serves the HAWKI-RAG API and stores datasets, grants, and operation
records. Client applications authenticate their users and send registered
**service** JWTs. The backend checks the tenant and scopes and applies the
dataset grant required by the route.

The current [deployment guide](../_documentation/Operations/python_runtime.md)
documents provisioning and startup. Frozen contract fixtures live under
`tests/fixtures`; current runtime and operator tooling have dedicated tests.
Historical public records and migration history remain preserved. Use the
dataset APIs for provisioning and `python -m hawki_backend.readiness --live`
for deployment checks.

## Service boundaries

- API clients on the private Docker network send service JWTs to FastAPI `/v1/*`.
- Workers send timestamped, signed callbacks over the project network.
- The runtime expects Alembic revision `0009` for the `hawki_backend` schema. The
  restricted login lacks DDL and historical-table privileges.

## Capabilities

The backend provides retrieval, graph operations and snapshots, dataset
management, and pipeline diagnostics. The document API accepts files and
inline text and supports download, replacement, and deletion. URL tasks
support sitemap ingestion, scheduling, and cancellation. New dataset graph support is controlled by
`RAG_BACKEND_DATASET_GRAPH_ENABLED`; uploads/tasks select graph ingestion through
their normal input. Physical collection and namespace remain server-derived.

URL ingestion requires a crawler that implements
`crawler-api`. The scraper worker checks the contract through Temporal
before the backend admits URL work.

## Historical isolation

Preserve `/shared/.historical-orphans`, `/shared/.crawler-runtime/legacy-archive`,
their reserved source identities and all referenced original files. The unresolved
`gebuhren` orphan has custody UNKNOWN and deletion UNVERIFIED; it is not adopted,
retried or deleted automatically. Follow the
[runtime preservation rules](../_documentation/Operations/python_runtime.md#historical-evidence-and-reservations).

Old signed deletion callbacks and starts remain authenticated refusals. They
cannot create an owner or receipt. Remaining `user` wire/schema values exist for
historical compatibility; service-only deployment rejects human identities.

## Validation

```sh
cd python_backend
uv sync --frozen --all-groups
uv run --frozen pytest
uv run --frozen ruff check .
```

Live readiness, inside the configured backend container:

```sh
docker compose exec -T rag_backend python -m hawki_backend.readiness --live
```

Readiness requires every applicable check to pass. The set of probes depends
on the enabled capabilities and configured stores. Retained grant/import tooling is covered independently; its old
live activation switches are retired. Frozen contract fixtures remain independent
reference data for compatibility and security tests.
