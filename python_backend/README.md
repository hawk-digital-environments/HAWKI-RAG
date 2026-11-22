# HAWKI backend

FastAPI owns the RAG API, dataset grants and new operational state. HAWKI owns
human authentication. The backend accepts registered **service** JWT identities,
tenant/scope claims and exact dataset grants; it has no human account, password,
browser-session implementation.

The current [deployment guide](../_documentation/Operations/python_runtime.md)
documents provisioning and startup. Frozen contract fixtures live under
`tests/fixtures`; current runtime and operator tooling have dedicated tests.
The retired bridge, Laravel dataset/grant import commands, and BFF-only access
projection routes have been removed. Historical public records and migration
history remain preserved. Use the current dataset APIs for provisioning and
`python -m hawki_backend.readiness --live` for deployment checks.

## Boundaries

- Browser → nginx static Svelte frontend → `/ui-api/v1/*` → Python UI gateway
  → FastAPI `/v1/*`.
- HAWKI services → `/v1/*` with a short-lived issuer JWT validated against HTTPS
  JWKS. No service credential is returned to browser JavaScript.
- Workers → `/internal/pipeline/*` with the existing signed HMAC protocol;
  public nginx blocks internal ingress. Writer barriers and tenant/dataset/path
  fencing remain mandatory.
- New state lives in PostgreSQL `hawki_backend`, provisioned by Alembic revision
  **0009**. The restricted runtime role has no public-schema or DDL authority.
  Historical `public.*` rows are preserved evidence, not runtime ownership.

## UI service boundary

In the default `hawki` access mode, configure `HAWKI_RAG_UI_AUTHORIZATION_URL` to
a HAWKI endpoint returning **204 only
when this browser may manage the configured service tenant and dataset grants**.
Missing configuration, redirects, login pages, errors and all other statuses deny
access. The gate receives the original Cookie, method and URI; HAWKI owns login
and policy. Set `HAWKI_RAG_UI_ORIGIN` to the exact browser origin for mutation
checks.

The explicit local overlay instead sets `HAWKI_RAG_UI_ACCESS_MODE=local`: nginx
binds only `127.0.0.1:8080`, and the gateway requires loopback Host/origin and
same-origin mutation markers. It has no browser username/password, Basic Auth,
login, cookie identity or browser token. It still obtains its service JWT on the
server. `hawki` remains the default access mode for other deployments; it delegates
human management policy to HAWKI and never forwards browser Authorization.

Mount `HAWKI_RAG_UI_CREDENTIAL_DIR` read-only, outside the checkout in deployments.
It contains an issuer-rotated `access.jwt`, or `client.secret` plus the HTTPS
`HAWKI_RAG_UI_TOKEN_URL` and registered client ID. Tokens are acquired/cached only
on the server. This repository supplies no real secrets.

## Capabilities

Query and graph retrieval; dataset creation/grants/retirement; direct text;
managed upload/download/update/deletion; URL/sitemap ingestion, schedules and
cancellation; graph operations and snapshots; Python pipeline diagnostics and
readiness. New dataset graph support is controlled by
`HAWKI_BACKEND_DATASET_GRAPH_ENABLED`; uploads/tasks select graph ingestion through
their normal input. Physical collection and namespace remain server-derived.

The crawler must support and advertise genuine `permanent-job-v2`. URL ingestion
stays enabled and fails closed when its safety contract is unavailable.

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
docker compose exec -T hawki_backend python -m hawki_backend.readiness --live
```

The current runtime requires 26/26 PASS. Readiness never repairs state or replaces
authorization. Retained grant/import tooling is covered independently; its old
live activation switches are retired. Frozen contract fixtures remain independent
reference data for compatibility and security tests.
