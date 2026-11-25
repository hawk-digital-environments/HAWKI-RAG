# HAWKI backend

FastAPI owns the RAG API, dataset grants and new operational state. HAWKI owns
human authentication. The backend accepts registered **service** JWT identities,
tenant/scope claims and exact dataset grants; it has no human account, password,
browser-session implementation.

The current [deployment guide](../_documentation/Operations/python_runtime.md)
documents provisioning and startup. Frozen contract fixtures live under
`tests/fixtures`; current runtime and operator tooling have dedicated tests.
Historical public records and migration history remain preserved. Use the
dataset APIs for provisioning and `python -m hawki_backend.readiness --live`
for deployment checks.

## Service boundaries

- API clients on the private Docker network send service JWTs to FastAPI `/v1/*`.
- Workers send timestamped, signed callbacks over the project network.
- Alembic revision **0009** owns the operational `hawki_backend` schema. The
  restricted login lacks DDL and historical-table privileges.

## Capabilities

Query and graph retrieval; dataset creation/grants/retirement; direct text;
managed upload/download/update/deletion; URL/sitemap ingestion, schedules and
cancellation; graph operations and snapshots; Python pipeline diagnostics and
readiness. New dataset graph support is controlled by
`RAG_BACKEND_DATASET_GRAPH_ENABLED`; uploads/tasks select graph ingestion through
their normal input. Physical collection and namespace remain server-derived.

The crawler must support and advertise genuine `permanent-job-v2`. URL ingestion
fails closed when enabled when its safety contract is unavailable.

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

The current runtime requires 26/26 PASS. Readiness never repairs state or replaces
authorization. Retained grant/import tooling is covered independently; its old
live activation switches are retired. Frozen contract fixtures remain independent
reference data for compatibility and security tests.
