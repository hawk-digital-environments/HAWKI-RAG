# HAWKI backend — public API boundary

`python_backend` hosts the public API. This document describes the
authentication boundary that protects it, the dataset authorization boundary
behind it, and the authenticated query endpoint built on both.

The authentication boundary is deliberately narrow. It answers *who is
calling*, *for which tenant*, and *with which coarse scopes*. It does not
decide what a caller may read or write. The separate dataset grant boundary
that does is described in [Authorization Boundary](#authorization-boundary),
and the endpoint that combines both is described in
[Query API](#query-api).

## Supported signature algorithms

| Algorithm | Key type |
|---|---|
| `RS256` | RSA, at least 2048 bits |
| `PS256` | RSA, at least 2048 bits |
| `ES256` | EC P-256 |

`none` and the symmetric `HS*` family are rejected. `HS256` is rejected
whether or not it is configured, and a token that names an algorithm outside
the configured allowlist is refused before any key is looked up.

## Configuration

All settings are read from `HAWKI_BACKEND_AUTH_*` environment variables.

| Variable | Required | Default | Notes |
|---|---|---|---|
| `HAWKI_BACKEND_AUTH_ISSUER` | yes | — | Compared exactly. No case folding, no trailing-slash tolerance. |
| `HAWKI_BACKEND_AUTH_AUDIENCE` | yes | — | The audience of this API. See the warning below. |
| `HAWKI_BACKEND_AUTH_JWKS_URL` | yes | — | Explicit `https://` URL of the signing key set. |
| `HAWKI_BACKEND_AUTH_CLIENTS` | yes | — | JSON object mapping a client id to `"service"` or `"user"`. |
| `HAWKI_BACKEND_AUTH_ALGORITHMS` | no | `RS256,ES256` | Comma separated. A subset of `RS256`, `PS256`, `ES256`. |
| `HAWKI_BACKEND_AUTH_TENANT_CLAIM` | no | `tenant_id` | Name of the claim carrying the tenant. |
| `HAWKI_BACKEND_AUTH_LEEWAY_SECONDS` | no | `30` | 0 to 300. Clock skew allowance for `exp`, `nbf` and `iat`. |
| `HAWKI_BACKEND_AUTH_MAX_TOKEN_TTL_SECONDS` | no | `3600` | 1 to 86400. Cap on a token's lifetime. |
| `HAWKI_BACKEND_AUTH_JWKS_CACHE_TTL_SECONDS` | no | `600` | How long a fetched key set is served. |
| `HAWKI_BACKEND_AUTH_JWKS_MIN_REFRESH_SECONDS` | no | `60` | Minimum interval between refresh attempts. |
| `HAWKI_BACKEND_AUTH_JWKS_MAX_STALE_SECONDS` | no | `3600` | How long a cached key set may be served after a failed refresh. |
| `HAWKI_BACKEND_AUTH_JWKS_TIMEOUT_SECONDS` | no | `3` | 1 to 30. Timeout of a key service request. |
| `HAWKI_BACKEND_AUTH_JWKS_MAX_BYTES` | no | `524288` | Maximum size of a key set response, in bytes. |

Two states are possible and only two:

* **No `HAWKI_BACKEND_AUTH_*` variable is set.** The application starts with
  no authentication boundary. Protected routes then answer `503`. This never
  means anonymous access is allowed.
* **At least one is set.** The configuration must be complete and valid, or
  the application refuses to start. A partially understood configuration is
  never built, and an invalid one is never downgraded to "no authentication".

The client registry is server-side configuration:

```json
{"hawki-console": "user", "batch-importer": "service"}
```

A duplicate client id in that JSON is rejected rather than resolved silently.
An unknown `HAWKI_BACKEND_AUTH_*` variable name is rejected too, so a typo
fails at startup instead of quietly falling back to a default.

## Expected claims

| Claim | Required | Notes |
|---|---|---|
| `iss` | yes | Must equal the configured issuer exactly. |
| `aud` | yes | A string, or a list containing the configured audience. |
| `exp` | yes | Numeric date. Booleans and strings are refused. |
| `sub` | yes | Non-empty string, at most 255 characters. Not normalized. |
| `azp` or `client_id` | yes | At least one. Both, if present, must match. |
| configured tenant claim | yes | Must match the tenant identifier pattern. |
| `scope` | no | Space-delimited string. Missing means no scopes. |
| `iat` | no | Numeric date. Used for the lifetime cap when present. |
| `nbf` | no | Numeric date. |
| `jti` | no | Non-empty string, surfaced as the principal's token id. |

The **principal kind** — `service` or `user` — comes from the client
registry, never from the token. A token that carries a `kind`, `principal_type`
or similar claim cannot change it.

`scope` is a single space-delimited string, as in `rag:query rag:ingest`.
Repeated spaces are normalised. A list, an object, a number or a boolean is
refused. `roles`, `groups`, `scp`, `realm_access` and `resource_access` are
ignored entirely and never become scopes.

`act` and `may_act` are refused. Delegation is not supported.

## Error behaviour

| Situation | Status | Body | `WWW-Authenticate` |
|---|---|---|---|
| No credential | 401 | `{"detail": "authentication_required"}` | `Bearer` |
| Unusable or invalid credential | 401 | `{"detail": "invalid_token"}` | `Bearer error="invalid_token"` |
| Required scope missing | 403 | `{"detail": "insufficient_scope"}` | `Bearer error="insufficient_scope", scope="..."` |
| Key service unavailable | 503 | `{"detail": "authentication_unavailable"}` | none |

Response bodies are stable and generic. They never contain the token, the
key id, the issuer, the subject, the tenant, the client id or the scopes the
caller holds.

## Operator warnings

**Do not use a client id as the API audience.** The audience must identify
this API or resource, not an OAuth client. There is no check on the token
`typ`, so an ID token minted for the same audience by the same issuer would
be accepted as an access token. Use a dedicated audience value.

**Publish a new signing key before you use it.** The key set is cached and
refreshes are throttled to at most one per `JWKS_MIN_REFRESH_SECONDS`. A
token whose `kid` is not in the key set is refused until the next refresh, so
publish the new key at least that long before issuing tokens with its `kid`.

**Revoked keys stay usable until the cache turns over.** A key that the
identity provider removed is still served from the cached key set until the
cache expires and a refresh replaces it. During an outage the previous key
set is served for up to `JWKS_MAX_STALE_SECONDS`. Revoke a token by other
means — short expiry, or an issuer-side blocklist — rather than relying on
key removal alone.

**The lifetime cap needs an issue time to mean anything.** When a token
carries `iat`, the cap applies to the lifetime the issuer gave it
(`exp - iat`). When it does not, the best available bound is the remaining
validity (`exp - now`).

## Health

`GET /v1/health` stays public and is not behind the authentication
boundary. It reports process health only; it does not report whether the
authentication boundary is configured or whether the key service is
reachable.

## Query API

`POST /v1/query` is the authenticated, authorized, dataset-scoped query
endpoint. It authenticates the caller, authorizes the logical dataset, resolves
trusted physical scope on the server, and runs the shared retrieval use case
**in process**. It never calls the bridge over HTTP and imports no bridge code.

The route is synchronous. The grant lookup and the retrieval pipeline are
blocking, so FastAPI runs the handler in its worker threadpool.

### Request

The body carries logical query input only. Unknown fields are rejected with
`422`, so a caller cannot smuggle a physical or model-selection field in.

| Field | Type | Default | Notes |
|---|---|---|---|
| `dataset_id` | string | — | Required. 1–191 characters, `[A-Za-z0-9][A-Za-z0-9._:-]*`. |
| `query` | string | — | Required. 1–4000 characters. |
| `top_k` | integer | `5` | 1–100. |
| `is_optimized` | boolean | `false` | |
| `generate` | boolean | `true` | |
| `fast_mode` | boolean | `true` | `true` disables graph retrieval. |
| `smart_lookup` | boolean | `false` | |
| `preferred_tags` | string list | `null` | At most 20, each 1–80 characters. |
| `filters` | object | `{}` | At most 20 scalar metadata filters; keys ≤ 200 characters, string values ≤ 4000. |

`tenant_id`, `authorized_scope`, `auth_context`, `collection`,
`qdrant_collection`/`qdrantCollection`, `neo4j_namespace`/`neo4jNamespace`,
`graph_enabled`, `provider`, `embedding_provider`, `embedding_model`,
`chat_model`, `graph_model` and `vision_model` are not accepted. The tenant
comes only from the authenticated principal.

Filter keys that name authorization or storage state (`dataset_id`,
`authorized_scope`, `auth_context`, `collection`, `qdrant_collection`,
`neo4j_namespace`, `graph_enabled`, `provider`, `embedding_provider`,
`embedding_model`, `chat_model`, `graph_model`, `vision_model`) are rejected
with `422`. The mandatory `dataset_id` predicate is always added by the
server, so a caller filter cannot replace it.

### Scope registry

The registry is **migration-time** configuration: a server-owned source of
physical dataset scope that an operator fills from the deployment inventory.
It is never read from Laravel's `public` tables, and this service never writes
it. It exists so the Python query API can resolve trusted Qdrant and Neo4j
targets before Python owns dataset metadata itself.

Epoch 12 adds the Python-owned database registry. Select it with
`HAWKI_BACKEND_QUERY_DATASET_SOURCE=database` and leave
`HAWKI_BACKEND_QUERY_DATASETS` unset. In that mode the authenticated tenant's
row in `hawki_backend.datasets` is the only source of physical scope; the
environment registry is not consulted. The default selector stays
`environment`, so existing deployments keep the pre-cutover behavior until an
operator changes it.

All settings are read from `HAWKI_BACKEND_QUERY_*` variables. A present but
incomplete or invalid configuration refuses to start; an unknown
`HAWKI_BACKEND_QUERY_*` name is rejected rather than ignored.

| Variable | Required | Default | Notes |
|---|---|---|---|
| `HAWKI_BACKEND_QUERY_DATASET_SOURCE` | no | `environment` | `environment` or `database`. `database` requires `HAWKI_BACKEND_DB_DSN` and forbids `HAWKI_BACKEND_QUERY_DATASETS`. |
| `HAWKI_BACKEND_QUERY_DATASETS` | environment mode | — | JSON object keyed by logical `dataset_id`. Forbidden in `database` mode. |
| `HAWKI_BACKEND_QUERY_MODELS` | yes | — | JSON object keyed by model provider. |
| `HAWKI_BACKEND_QUERY_RERANKER` | no | `none` | Server-owned default reranker mode. |
| `HAWKI_BACKEND_QUERY_RERANK_TOP_N` | no | `20` | 1–1000. |
| `HAWKI_BACKEND_QUERY_RERANKER_MIX_MODE` | no | `true` | Boolean. |
| `HAWKI_BACKEND_QUERY_RERANKER_MIX_WEIGHT` | no | `0.5` | Finite, 0.0–1.0. |

A dataset entry requires `qdrant_collection`, `embedding_provider` and
`embedding_model`. `graph_enabled` defaults to `false`; when it is `true`,
`neo4j_namespace` is required. Every provider a dataset references must have
a model entry with `chat_model` and `vision_model`.

```json
{
  "example-dataset": {
    "qdrant_collection": "hawki_example_dataset",
    "neo4j_namespace": "hawki_example_dataset",
    "embedding_provider": "ollama",
    "embedding_model": "bge-m3",
    "graph_enabled": true
  }
}
```

```json
{
  "ollama": {
    "chat_model": "llama3.1",
    "vision_model": "llava"
  }
}
```

The internal retrieval request is built from the public input plus this
configuration. `provider` is the authorized scope's `embedding_provider`; the
embedding model comes from the scope; the chat and vision models come from the
provider entry; reranker and mix defaults come from the settings. The client
has no way to override any of them.

When the query service itself is not configured, the route fails closed with
`503 query_unavailable`.

### Error contract

Authentication responses are unchanged:

| Situation | Status | Body |
|---|---|---|
| No credential | 401 | `{"detail": "authentication_required"}` |
| Invalid credential | 401 | `{"detail": "invalid_token"}` |
| Missing `rag:query` | 403 | `{"detail": "insufficient_scope"}` |
| Authentication unavailable | 503 | `{"detail": "authentication_unavailable"}` |

Query responses use a stable, non-leaking envelope:

| Situation | Status | `detail.error` |
|---|---|---|
| Authorization denied (missing, wrong tenant, inactive) | 404 | `dataset_not_found` |
| Authorization infrastructure unavailable | 503 | `authorization_unavailable` |
| Authorized dataset without valid scope | 409 | `dataset_not_ready` |
| Vector store not ready | 409 | `dataset_not_ready` |
| Invalid query / unsupported model provider | 400 | `invalid_query` |
| Embedding generation failed | 500 | `embedding_failed` |
| Answer generation failed | 502 | `answer_generation_failed` |
| Unexpected query failure | 500 | `query_failed` |

Bodies never carry a raw database error, a provider message, a credential, a
collection name, a namespace name, or tenant/grant internals.

### Response

The response is the shared logical `QueryResponse` shape: `ok`, `count`,
`hits`, `kg`, `answer` and `retrieval`. Before it leaves the public boundary,
physical identifiers (`qdrant_collection`, `neo4j_namespace` and their camel
case variants) are removed recursively from hits and nested payloads. The
shared retrieval package keeps its current output semantics; sanitization
happens only here.

## Query parity (Epoch 6)

`tests/parity/` verifies that Laravel `POST /api/query` and Python
`POST /v1/query` agree before Epoch 7 cutover. The harness is self-contained:
it does not import the API test doubles and never runs a real model. The
canonical contract lives in
`tests/parity/fixtures/laravel_query_parity.json`; it is **test-only**, and no
production module may read it (`tests/test_boundaries.py` enforces that).

The deterministic suite runs with the normal test command. The live checks are
collected but skipped unless enabled:

```sh
HAWKI_PARITY_INTEGRATION=1 \
HAWKI_PARITY_QDRANT_URL=http://localhost:6333 \
HAWKI_PARITY_NEO4J_URL=http://localhost:7474 \
HAWKI_PARITY_NEO4J_AUTH=neo4j:password \
HAWKI_PARITY_BRIDGE_URL=http://localhost:8000 \
uv run --frozen --no-sync pytest tests/parity -m integration
```

`HAWKI_PARITY_DATASETS` restricts the inventory check to a comma-separated
list of fixture dataset ids; `HAWKI_PARITY_DATASET` selects the dataset for the
bridge comparison. The bridge is contacted over plain HTTP; `hawki_bridge` is
never imported.

The live checks use the fixture's synthetic inventory (`dataset-alpha`,
`dataset-graph`, `hawki_dataset_*`), so they must run against a staging stack
that actually contains those datasets. Pointing them at another environment
requires updating the inventory, bumping `_meta.inventory_version`, and
refreshing the goldens. The live assertions compare hit identity, order,
score, count and `kg`; full payload parity stays with the deterministic
suite.

### MUST MATCH

- The public request fields: `dataset_id`, `query`, `top_k`, `is_optimized`,
  `generate`, `fast_mode`, `smart_lookup`, `preferred_tags`, `filters`.
- The internal `QueryRequest` built from those fields and trusted scope,
  including collection, namespace, embedding provider/model, graph flag,
  provider, chat/vision models, reranker values and tag/filter pass-through.
- Dataset inventory resolution: the Python registry equals the Laravel dataset
  inventory field for field, with no missing or extra dataset.
- `provider == authorized_scope.embedding_provider`, and a graph-enabled
  dataset always has a non-empty namespace.
- The stable status and error code of every error case recorded as
  `must_match` in the fixture.
- Deterministic response payloads: hit order, ids, scores (6 decimals), count,
  `kg`, answer semantics and retrieval metadata other than timings.
- Generation runs only when the request asks for it **and** the shared
  `RAG_GENERATE_ANSWER` switch is enabled.

### MAY DIFFER INTENTIONALLY

| Deviation | Difference | Classification |
|---|---|---|
| D1 | Python recursively removes `qdrant_collection`, `qdrantCollection`, `neo4j_namespace`, `neo4jNamespace` from responses. | Accepted |
| D2 / G12 | Laravel browser/session/single-user principal and flat bodies vs Python JWT `rag:query` and `detail` bodies. | Cutover blocker |
| D3 | Validation envelope (`{message,errors}` vs FastAPI `detail`) while both reject with 422. | Accepted |
| D4 | Query error envelope (Laravel flat or bridge nested vs `{detail:{error,message}}`). | Accepted |
| D5 | Laravel ignores unknown top-level fields; Python rejects them with 422. | Accepted |
| D6 | Laravel forwards a `filters.embedding_provider`; Python rejects it as reserved. | Accepted |
| D7 | Python strips whitespace-padded filter keys; Laravel forwards them and the shared builder strips them later. The effective filter is identical. | Accepted |
| D8 | Vector-store-not-ready is 409 in Python and 503 through the bridge/Laravel. | Accepted |
| D9 | Authorization-unavailable is 503 in Python and a generic 500 in Laravel. | Accepted |
| D10 | Python returns generic non-leaking messages; Laravel can echo bridge or exception text. | Accepted |
| D11 | Laravel's bridge-unreachable 502 surface does not exist in-process. | Accepted |
| D12 | Shared generation defaults to off while the deployment currently sets `RAG_GENERATE_ANSWER=true`. | Cutover blocker |
| D-GRAPH | Laravel derives `graph_enabled` per request; Python resolves it from the registry and suppresses graph access during execution when `fast_mode=true`. | Accepted |

Each intentional difference is also annotated on the relevant fixture case
with an explicit `_deviation`; the tests fail if a case documents a deviation
that is not registered.

### MUST NOT REGRESS

- No request field may widen trusted scope. Unknown fields, physical fields and
  reserved filter keys stay rejected, and the mandatory `dataset_id` predicate
  stays server-derived.
- Tenant and dataset isolation stay exact; a missing grant, wrong tenant and
  inactive dataset remain the same `dataset_not_found` refusal.
- An authorization outage stays distinct from a denial (`503` vs `404`).
- `graph_enabled=false` and `fast_mode=true` never touch Qdrant-adjacent graph
  reads or Neo4j facts.
- Physical identifiers never appear in a public Python response, even nested
  in lists or payloads.
- Errors never echo a database/provider message, collection, namespace, tenant
  or grant detail.
- `retrieval.timings_ms` is the only response field dropped by parity; no
  functional difference may be normalized away.

### Epoch 7 cutover checklist

Epoch 7 must not begin until every item below is resolved or explicitly
approved.

**Authorization / data**

- [ ] Laravel `HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT=true` is disabled or an
      equivalent policy is formally decided (G1).
- [ ] Python query grants are populated (G2).
- [ ] A tenant convention is defined for grants (G3).
- [ ] Grant backfill is verified against the Laravel dataset inventory.
- [ ] Negative authorization tests are green (missing, wrong tenant, inactive,
      unavailable).

**Scope**

- [ ] The Python registry equals the Laravel dataset inventory.
- [ ] `qdrant_collection`, `neo4j_namespace`, `embedding_provider` and
      `embedding_model` match for every dataset.
- [ ] The graph readiness invariant is decided (static registry vs dynamic
      readiness) and the live scope test is green.

**Models / retrieval configuration**

- [ ] Reranker mode is identical (currently `RERANKER_MODE=external` vs Python
      `HAWKI_BACKEND_QUERY_RERANKER=none`, C-RERANKER).
- [ ] `rerank_top_n=20`, mix mode and mix weight match.
- [ ] Chat and vision models match the deployment settings (C-MODELS).
- [ ] Both processes receive identical `RAG_GENERATE_ANSWER`, `RAG_FUSION_*`,
      `RAG_MIN_SCORE*`, `RAG_CONTEXT_*`, `RAG_ITERATIVE_RETRIEVAL`,
      `RAG_STRUCTURAL_*`, `RAG_SEARCH_TOP_K_*` and `QDRANT_TEXT_SCROLL_LIMIT`
      values (C-RAGENV).
- [ ] Provider credentials and endpoints are available to `python_backend`.

**Contract**

- [ ] The deterministic parity suite is green.
- [ ] The graph-disabled and graph-enabled integration checks are green.
- [ ] Physical identifiers remain stripped.
- [ ] Consumers accept the error envelopes and the `409` vector-store status
      (C-CONTRACT).
- [ ] Consumers accept the JWT authentication mechanism, or a BFF/token
      decision is made (G12).
- [ ] The `/api/query` consumer inventory is complete.
- [ ] MCP and `GET /api/rag/neo4j/*` surfaces are explicitly out of scope.
- [ ] Ownership of `GET /api/query/datasets` is decided.
- [ ] Timeout and rate-limit behavior is decided.

## Authorization Boundary

`hawki_backend.authorization` answers one question: may an authenticated
principal perform an action on a logical dataset?

### Actions and coarse scopes

| Action | Coarse scope |
|---|---|
| `query` | `rag:query` |
| `ingest` | `rag:ingest` |

There is no delete action. Text-ingestion deletion is authorized with the
ingest grant, so a separate action would have no counterpart to be granted
from.

### Grant identity

A grant is held by one principal, on one dataset, for one action, inside one
tenant. All five parts are part of the identity:

```
tenant + principal kind + principal id + dataset + action
```

The tenant is part of the identity even though the current Laravel
authorization model has no tenant at all. The authentication boundary
already requires a validated tenant, and fixing the identity now keeps a
later persistence branch from having to change it.

### What authorization requires

Both of the following, never one of them:

* the coarse scope the action requires, and
* the exact local dataset grant, confirmed by the grant reader.

A request is denied by default. The boundary has no query-all fallback, no
anonymous fallback, no self-grant, and no way to create, widen or revoke a
grant. The policy answers with a plain boolean and no reason: a missing
dataset, an inactive dataset, a grant held by another tenant, a missing grant
and a missing coarse scope are all the same `False`, so a caller cannot learn
whether a dataset exists. A grant source that cannot be reached is not a
denial; it raises `AuthorizationUnavailableError` instead.

A dataset identifier is compared exactly. It is never trimmed, it is at most
191 characters, and it may not carry control, format or surrogate characters.

The reader is where the tenant isolation and the dataset conditions live. The
in-memory reader treats every grant it holds as referring to an existing,
active dataset; the persistent reader performs that check in its query, in
the persistence branch.

### Current surface

`POST /v1/query` uses this policy. It is the only route that authorizes a
dataset today; `GET /v1/health` stays public. See [Query API](#query-api).

## Persistence Boundary

`hawki_backend.persistence` makes the dataset grant reader real against a
database. It is an adapter: it knows the domain, and the domain never knows
it. The authorization package imports no database driver.

### The owned schema

The backend owns one PostgreSQL schema, `hawki_backend`. The application that
still serves the legacy surfaces owns `public` and pins its search path to it,
so the backend's tables are never addressed by it and the two migration
histories never collide. The backend never reads the application's tables.

| Table | Primary key | Purpose |
|---|---|---|
| `hawki_backend.datasets` | `(tenant_id, dataset_id)` | which datasets exist, their lifecycle state, metadata and physical scope |
| `hawki_backend.dataset_grants` | `(tenant_id, dataset_id, principal_type, principal_id, permission)` | which principal holds which action on which dataset |
| `hawki_backend.dataset_grant_tokens` | `id` | one-time bootstrap tokens, stored as SHA-256 hashes only |

The tenant is part of both identities. A grant references its dataset by
`(tenant_id, dataset_id)`, so a grant that names a dataset of another
tenant cannot be stored at all. The dataset status is limited to `active`,
`archived` and `deleted`; `deleted_at` records the final tombstone, and only an
active dataset answers a lookup.

`datasets` carries the canonical name, description, embedding configuration,
Qdrant collection and Neo4j namespace, so database-mode runtime resolution has
no second source. `dataset_grant_tokens` keeps the `dgt_` prefix convention,
a 24-hour expiry and only the SHA-256 hash; the plaintext exists once in the
create response and is never stored or logged.

### The lookup

One statement answers the whole question: the tenant, the principal kind,
the principal id, the dataset, the action and the active state of the
dataset. There is no second question about the dataset, so the answer
cannot be assembled from two lookups. An unknown dataset, an inactive
dataset and a missing grant are all the same empty answer.

### Failure

A database that cannot be reached is not a refusal. It raises
`AuthorizationUnavailableError`, which carries no detail of the
connection, so a refused request and an outage never look the same.

### Configuration

| Variable | Required | Default | Notes |
|---|---|---|---|
| `HAWKI_BACKEND_DB_DSN` | yes | — | `postgresql+psycopg://…` URL of the database. |
| `HAWKI_BACKEND_DB_POOL_SIZE` | no | `5` | Connections in the pool. |
| `HAWKI_BACKEND_DB_POOL_TIMEOUT_SECONDS` | no | `3` | How long a caller waits for a connection. |
| `HAWKI_BACKEND_DB_POOL_RECYCLE_SECONDS` | no | `300` | Age at which a connection is replaced. |
| `HAWKI_BACKEND_DB_CONNECT_TIMEOUT_SECONDS` | no | `3` | Connection timeout. |
| `HAWKI_BACKEND_DB_STATEMENT_TIMEOUT_MS` | no | `2000` | Statement timeout. |

No `HAWKI_BACKEND_DB_*` variable means the backend runs without
persistence. Any variable that is present must form a complete and valid
configuration.

### Migrations

The schema is versioned with Alembic. The version table lives in the owned
schema, so the backend's migration history is separate from the
application's. `alembic upgrade head` applies the migrations and
`alembic check` reports any drift between the schema and the definition
the runtime uses.

### Tests

The persistence tests are destructive: they run `alembic downgrade base`,
delete every row of the owned tables and recreate the migration state. They
run only when **both** of the following hold:

* `HAWKI_BACKEND_DB_TESTING=1`, and
* the database name ends with `_test`.

The database name has one authoritative source, the connection string read
the way the driver reads it. A connection string that also names a database
in its query string, with a `dbname` parameter, is refused by the test
safety guard, because the driver would connect to the database in the query
rather than the one in the path. The guard is never satisfied by a host, a
port, an account or a schema name.

### Current surface

Revision `0001` created the grant tables. Revision `0007` adds the dataset
metadata, physical scope, lifecycle tombstone and one-time token table that
Epoch 12 needs; it backfills deterministic placeholders for existing
authorization rows. The one-time operator tool
[`scripts/dataset_authority.py`](scripts/dataset_authority.py) replaces those
placeholders and imports the reviewed legacy grants and token hashes before
activation. There is no dual write: after activation the backend's dataset
service is the only writer, and the legacy application's rows stay where they
are. `main.py` builds the reader when `HAWKI_BACKEND_DB_DSN` is configured, and
`POST /v1/query` consults it through `LocalGrantPolicy`. In database dataset
mode it also builds the tenant-scoped scope resolver and the dataset
management service.

> **A dedicated database account is required before the reader is wired
> into production.** The connection above is expected to use an account
> that may only reach the `hawki_backend` schema. An account that can also
> read `public` would break the rule that the backend never queries the
> application's tables.

## Deployment

The backend ships as a container image built from
[`python_backend/Dockerfile`](Dockerfile). It reuses the repository's Python
runtime image, so it is served the same way as the other Python services:
nginx in front of gunicorn with a uvicorn worker, running as `www-data`.

| Property | Value |
|---|---|
| Compose service | `hawki_backend` |
| Container name | `hawki_backend` |
| Internal URL | `http://hawki_backend` (networks `default` and `hawki-network`) |
| Container port | `80` (nginx, the only exposed port) |
| Health check | `GET /v1/health` |
| ASGI runtime | gunicorn with `uvicorn.workers.UvicornWorker` (`uvicorn[standard]==0.30.6`) |
| Workers | `GUNICORN_WORKERS=1` (override in the service definition if needed) |
| Image | `hawki-backend:local` |

The service publishes no host port. A future Laravel BFF can reach it over the
internal Docker network; nothing routes production query traffic to it yet.

### Configuration injection

The image contains no secrets and no `.env` file. Configuration is injected by
the deployment; the compose service receives the shared env file through the
`x-service-env` anchor, so the keys are added there exactly like the other
services.

[`python_backend/.env.example`](.env.example) documents the keys and holds
placeholders only:

* `HAWKI_BACKEND_AUTH_*` — the JWT boundary. With none of these variables
  present the application starts without an authentication boundary and
  `/v1/query` answers `503`; the health endpoint still answers `200`. With any
  of them present the whole configuration must be valid or the application
  refuses to start. There is no anonymous mode.
* `HAWKI_BACKEND_DB_*` — the grant store. The account should only reach the
  `hawki_backend` schema.
* `HAWKI_BACKEND_QUERY_*` — the server-owned dataset/model scope registry.

The shared retrieval package reads plain process environment variables. The
backend must therefore receive the same `RAG_*`, `QDRANT_*`, `NEO4J_*`,
`RERANKER_*` and provider endpoint/model variables that `hawki_bridge` runs
with. Do not give the backend its own retrieval defaults: copy the bridge's
effective values so both query paths execute the same pipeline.

### Runtime model

`POST /v1/query` is intentionally synchronous. The grant lookup and the
retrieval pipeline are blocking calls, and FastAPI runs synchronous routes in
its worker threadpool, so a request does not block the event loop. Gunicorn
runs one uvicorn worker by default; increase `GUNICORN_WORKERS` only after
measuring the deployment, because each worker is a separate process with its
own threadpool and database pool.

Query timeout and retry policy belong to the caller of this service (the
future Laravel BFF or the reverse proxy), not to the application. The backend
keeps the retrieval client timeouts it shares with `hawki_bridge`.

### Building and running

```bash
docker build -f python_backend/Dockerfile -t hawki-backend:local .
docker compose up -d hawki_backend
docker compose ps hawki_backend
docker compose exec hawki_backend python -c \
  "import urllib.request; print(urllib.request.urlopen('http://127.0.0.1/v1/health').read())"
```

Without `HAWKI_BACKEND_*` and query registry configuration, the service is
healthy while `/v1/query` fails closed. That is the expected state for this
slice: the runtime exists, but query traffic is not cut over.

### Laravel BFF trust boundary

The disabled-by-default [BFF deployment guide](../_documentation/Operations/python_query_bff.md)
documents the server-owned RS256 token profile, verifier configuration agreement,
opt-in key/JWKS mounts, HTTPS/CA requirements, rotation and activation gates.
No Python authentication requirement is relaxed for this integration.

### Operator query cutover tooling

[`scripts/query_grants.py`](scripts/query_grants.py) plans, explicitly applies,
and verifies the one-time dataset/query-grant migration. It runs outside the
backend runtime and is never invoked by startup. `--expand-default-grants` is
deliberate, `--apply` requires a reviewed plan hash, and readiness fails on
unexecuted external gates. Source credentials must be SELECT-only; no Laravel
writes or target deletes occur. See the
[Epoch 7C runbook](../_documentation/Operations/python_query_cutover.md) for exact
export, grant, scope/model/reranker/auth checks, actual-inventory live parity,
the separate query-all transition, controlled activation, and rollback commands.

## Epoch 8 workflow control

The backend now owns signed internal workflow control through a durable dispatch
ledger. Public ingestion stays in Laravel by default; Epoch 9 below adds the
opt-in direct-text BFF. See the coordinated
[deployment and rollback procedure](../_documentation/Operations/python_workflow_control.md)
before enabling `HAWKI_WORKFLOW_CONTROL_ENABLED`.

## Epoch 9 direct-text ingestion

`TEXT_INGESTION_BACKEND=laravel` remains the default. In Python mode Laravel
is a compatibility BFF for `/api/integrations/text-ingestions`: it checks the
Sanctum PAT and exact `rag:text-ingest` ability, bounds raw request bytes,
mints an internal JWT, forwards the raw POST body, and maps safe responses.
Python owns POST validation, exact dataset `AuthorizationAction.INGEST` grants,
identities, immutable artifacts, idempotency, persistence, workflow preparation,
completion and direct-text DELETE. There is no fallback or dual dispatch.
Managed-document/file ingestion is separately opt-in in Epoch 10 below.
URL and crawler ingestion remain Laravel-owned.

The internal token has `sub=<Laravel user ID>`, the configured canonical
`tenant_id`, `azp=hawki-bff` and scope exactly `rag:ingest`. The existing RS256
issuer/audience/JWKS agreement still applies. Python runtime SQL uses only its
owned schema; Laravel `public.*` reads occur exclusively in explicit operator
migration tools.

Enable the service with `HAWKI_BACKEND_TEXT_INGESTION_ENABLED=true` only after
migration `0003`, server-owned dataset/model registry, shared artifact volume,
Qdrant access, Epoch 8 workflow control and verified ingest grants are ready.
`POST /v1/text-ingestions` returns 202 initially and 200 on replay; changed input
under the same key returns 409. The signed `/internal/text-ingestion/events`
endpoint owns completion. Laravel relays exact `ingest-text-*` event bytes;
other worker events remain Laravel-owned. `DELETE /v1/text-ingestions/{source_id}`
requires the same ingest authorization, cancels active writers and verifies
owned Qdrant point removal before releasing the source for reuse. No Neo4j
cleanup or contribution occurs.

Python text workers reuse the signed managed-writer protocol and registered sink
adapters. The BFF relays text writer callbacks to `/internal/text-ingestion/writers`;
Python alone stores writer receipts and fences. DELETE freezes one locked snapshot,
closes admission and requires acknowledged writer drainage before vector cleanup.
Workflow closure alone never releases ownership. Missing/uncertain writer evidence
blocks cleanup and re-ingestion pending reconciliation. Grant backfill writes only
grants and refuses missing/mismatched dataset inventory.

The [Epoch 9 operations guide](../_documentation/Operations/python_text_ingestion.md)
covers the required real grant backfill, drift freeze, coordinated cutover,
rollback and interrupted cleanup. No production activation is implied by this
implementation or its tests.

Focused checks (from `python_backend`):

```bash
uv run --frozen --no-sync pytest tests/text_ingestion
# Only a disposable database whose name ends in _test is accepted:
HAWKI_EPOCH9_TEST_DSN='<disposable PostgreSQL DSN>' \
  uv run --frozen --no-sync pytest tests/text_ingestion/test_postgres.py
uv run --frozen --no-sync python -O -m pytest tests/text_ingestion tests/test_workflow_control.py tests/auth tests/authorization
```

## Epoch 10 managed uploads

`MANAGED_DOCUMENT_BACKEND=laravel` remains the default. The `python` selector
forwards the four managed mutation routes and the pipeline file mapper to
Python, without local authoritative rows, fallback, or dual dispatch.
Enable the backend with `HAWKI_BACKEND_MANAGED_DOCUMENTS_ENABLED=true` after
migration `0004`, exact INGEST grants, the existing scope/model registry,
shared uploads, compatible workers, and Epoch 8 control are ready.

Python owns POST `/v1/documents`, POST `/v1/documents/batch`, PUT and DELETE
`/v1/documents/{document_id}`, revision publication, signed completion,
writer fences/drainage, exact historical vector cleanup and shared-safe
graph contribution cleanup. Identical uploads with distinct keys remain
distinct documents. No Python runtime reads Laravel tables.

GET `/v1/documents` and GET `/v1/documents/{id}` serve authoritative managed
reads through the existing Laravel public URLs, using QUERY grants and query
JWTs. A BFF compatibility access check permits only authorized, proven legacy
rows to retain their Laravel read projection during cutover. Artifact intents
commit in the existing mutation JSON before upload/profile publication; retries
reconcile the same owned paths after a crash. See the runbook for staging,
storage drift and recoverable publication errors.

The [Epoch 10 deployment and recovery runbook](../_documentation/Operations/python_managed_documents.md)
specifies the principal boundary, signed callback ownership discriminator,
crash recovery, coordinated cutover, old in-flight policy and rollback.
Production traffic is unchanged by implementation.

## Epoch 11 URL ingestion (opt-in)

`CRAWLER_INGESTION_BACKEND=laravel` remains the BFF default.
`HAWKI_BACKEND_URL_INGESTION_ENABLED=false` keeps the new module disabled.
Migration `0005` adds Python-owned URL requests, sources, generations and
events, with linkage to the existing `workflow_operations`. No public-schema
ingestion state is imported, mirrored or read by this module.

The BFF resolves a concrete session/PAT principal or the sole active user.
An explicit invalid Authorization header is always rejected. Python checks
exact tenant/user/dataset INGEST access and rechecks locked grant/dataset
rows before claiming URL state. Starts retain the HTTP 201
`success/task_id/task` envelope, URL aliases, multi-URL input and metadata.

Only HTTP and HTTPS are accepted. Identity normalization is legacy trim and
scheme insertion; host case, fragments, query order, ports and trailing
slashes stay distinct. Security resolution requires every answer to be
public. This is **not sufficient for safe fetching**: the existing external
crawler now provides connection-bound transport and permanent-job receipts.
Backend and worker verify API compatibility and liveness before dispatch; the
crawler owns network enforcement and protected Chromium startup. There is no
unsafe-fetch fallback. Deploy the reviewed runtime with its namespace/seccomp
prerequisite; liveness alone does not attest browser isolation.

Remote sitemap resources are validated and dispatched to the crawler parser; Laravel never fetches them in
Python mode. Local sitemap paths are rejected. Site profiles must resolve
inside `HAWKI_URL_SITE_PROFILE_ROOT`, including symlink resolution; an empty
root rejects profile paths. `EXTERNAL_SCRAPER_URL` is the canonical physical
scraper setting. At composition only, `CUSTOM_CRAWLER_URL` may fill an absent
canonical value. Callers cannot choose service URLs/tokens.

The generation repository extends the existing managed writer protocol with
an explicit URL operation/dataset/generation binding. Recrawl/delete close
the previous admission gate, cancel/reconcile the exact execution, require
writer drainage and use frozen vector/graph scopes. Artifact retirement
also requires terminal, sealed, quiescent, admission-closed external-job receipts. Python refresh ticks now claim fresh generations through manual
recrawl; they never create frozen-input Temporal schedules. The reviewed crawler runtime and its namespace/seccomp deployment preflight
are mandatory; production connectivity and traffic migration remain activation
prerequisites.

`/api/pipeline/health` is retained. Its Python URL readiness checks crawler API
compatibility and liveness separately from configured/enabled state. This does
not attest Linux browser isolation; the deployment preflight remains mandatory.
See the [Epoch 11 runbook](../_documentation/Operations/python_url_ingestion.md)
for deployment requirements and the mandatory cutover/rollback sequence.

```bash
uv run --frozen --no-sync pytest tests/url_ingestion
HAWKI_EPOCH11_TEST_DSN='<disposable PostgreSQL DSN ending in _test>' \
  uv run --frozen --no-sync pytest tests/url_ingestion/test_postgres.py
uv run --frozen --no-sync python -O -m pytest tests/url_ingestion -k 'not postgres'
```

Epoch 11 now consumes CustomCrawler's current permanent-job contract. See the
[current URL runbook](../_documentation/Operations/python_url_ingestion.md) and
[integration validation](../_documentation/Operations/epoch11_crawler_integration_validation.md)
for receipt semantics, deployment preflight and current test/smoke evidence.

## Epoch 12 dataset authority (opt-in)

`DATASET_BACKEND=laravel` remains the default. The public Laravel dataset
routes keep their names, payloads, HTTP statuses and error codes. With
`DATASET_BACKEND=python`, Laravel is a compatibility BFF: it validates the same
public input, mints an internal RS256 token, forwards the operation to Python
and maps the Python answer back onto the legacy envelope. It creates no
dataset, grant or grant-token row. An unknown selector, or a Python activation
whose query/text/managed/crawler selectors are not all `python` or whose
query-all compatibility flag is not `false`, fails every dataset route with
`503 dataset_backend_configuration_invalid`; there is no fallback and no dual
write.

Create and delete are authenticated management operations on the shared BFF
routes, including Python mode. They require a Sanctum principal with the
`admin` token ability or an administrator role. Unauthenticated create/delete
returns `401`; a
non-manager token returns `403`. A caller may not choose `qdrant_collection` or
`neo4j_namespace` (or the camel-case aliases): the BFF rejects them with `422`
and Python derives a bounded `hawki_<slug>_<digest>` identity from the
authenticated tenant and logical dataset id for both stores. The migration
script is the only direct-import exception.

Python owns `hawki_backend.datasets`, `hawki_backend.dataset_grants` and
`hawki_backend.dataset_grant_tokens`. Revision `0007` adds the dataset
metadata, physical scope, lifecycle tombstone and token table. In Python mode
the Laravel `public.*` dataset rows are frozen legacy data: the Python runtime
never reads them, and neither service updates them.

### Routes and scopes

| Route | Internal scope | Behavior |
|---|---|---|
| `GET /v1/datasets` | `rag:datasets:manage` | Active datasets, newest first, with Qdrant readiness. |
| `POST /v1/datasets` | `rag:datasets:manage` | Idempotent create: `201` first, `200` compatible replay, one `dgt_…` token on first create. |
| `GET /v1/datasets/{dataset_id}` | `rag:datasets:manage` | One active dataset with details and graph readiness. |
| `DELETE /v1/datasets/{dataset_id}/storage` | `rag:datasets:manage` | Archive first; tombstone once owned sources are clear. |
| `POST /v1/datasets/{dataset_id}/query-grants/self` | `rag:query` | Exact self grant; requires a ready active dataset. |
| `POST /v1/datasets/{dataset_id}/ingest-grants/self` | `rag:ingest` | Redeems the one-time token into an exact ingest grant. |
| `GET /v1/query/datasets` | `rag:query` | Active, fully scoped datasets with an exact query grant, ordered by name then id. |

`rag:datasets:manage` is minted only by the trusted BFF and is never exposed to
a browser. The self-grant routes take the user identity from the authenticated
token `sub`; they never accept a tenant, a principal, a physical target or a
wildcard. One-time tokens keep the `dgt_` prefix, 32 random bytes, SHA-256-only
persistence and a 24-hour TTL. Redemption locks the token row, consumes it
atomically and binds it to exactly one dataset and one permission.

`GET /v1/query/datasets` has no query-all fallback. Service principals receive
an empty list. A dataset whose Qdrant collection cannot be verified is omitted
from the list, and an ordinary query grant for it fails with
`dataset_not_ready` rather than becoming queryable.

### Deletion

Deletion is archive-first. The dataset disappears from listing and query
resolution immediately. A `202` `cleanup_pending` answer means Python-owned
text, managed-document or URL source rows still exist; each is driven through
its own deletion protocol by the cleanup coordinator. The archived dataset row
is the durable work item: startup and a periodic reconciler resume it after a
restart without a new delete request. Repeating the delete finalizes `deleted`
only when those source counts are zero. The dataset lifecycle does not bypass a
source protocol, delete a Qdrant collection or clear Neo4j. A source failure
leaves the row archived and retryable, never reported as complete.

In Python mode, Laravel runs a read-only preflight before forwarding a delete.
If the id still has a legacy active/archived dataset row, grant, token,
undeleted managed document/output, ingestion source or non-terminal pipeline
task/job, the BFF returns `409 legacy_dataset_state_present` and sends nothing
to Python. Python never reads `public.*`; migrated datasets remain undeletable
through this route until their legacy state is retired.

### One-time migration and activation

Apply Alembic through `0007`, then run the reviewed operator tool
[`scripts/dataset_authority.py`](scripts/dataset_authority.py). It reads
Laravel `public.datasets`, `public.dataset_grants` and
`public.dataset_grant_tokens` through a SELECT-only source credential, derives a
deterministic SHA-256 plan, imports exact grants and token *hashes* (never
plaintext), and refuses extra or mismatched target rows. `--apply` and
`--verify` require the reviewed `query:cutover-export` report with
`query_all: false`; `--laravel-report` also supplies graph readiness. A
report-free plan imports every dataset with `graph_enabled=false` but cannot be
applied. The reviewed digest binds the source rows, report, desired state,
target snapshot and permitted transitions. Only an exact revision-`0007`
placeholder may be updated; canonical target metadata, extras, mismatched
tokens, tombstones and archived-to-active transitions refuse all writes.
Tombstones are never revived, and `--verify` reports revival separately. A
successful apply changes the target, so re-plan and re-review before the
idempotency apply; the old digest must fail closed.

Activate only after query, direct-text, managed-document and crawler ingestion
selectors are already Python, exact grants are imported, and
`HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT=false`. Set `DATASET_BACKEND=python`,
`HAWKI_QUERY_BACKEND=python`, `TEXT_INGESTION_BACKEND=python`,
`MANAGED_DOCUMENT_BACKEND=python`, `CRAWLER_INGESTION_BACKEND=python` and
`HAWKI_BACKEND_QUERY_DATASET_SOURCE=database`, and leave
`HAWKI_BACKEND_QUERY_DATASETS` unset. The checked-in defaults remain `laravel`
and `environment`. A half-cutover can create datasets that the active query
registry cannot resolve, and Laravel fails closed rather than falling back. No
production activation is implied by this implementation.

```bash
uv run --frozen --no-sync pytest tests/datasets tests/test_dataset_authority_migration.py
HAWKI_BACKEND_DB_TESTING=1 \
HAWKI_BACKEND_DB_DSN='<disposable PostgreSQL DSN ending in _test>' \
  uv run --frozen --no-sync pytest \
    tests/persistence/test_dataset_authority_postgres.py \
    tests/persistence/test_dataset_cleanup_postgres.py
```

See the [Epoch 12 runbook](../_documentation/Operations/python_dataset_authority.md)
for the coordinated migration, verification and rollback procedure.
