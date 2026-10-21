# HAWKI backend — authentication boundary

`python_backend` hosts the public API. This document describes the
authentication boundary that protects it: how a bearer token is turned into
an `AuthenticatedPrincipal`, and what an operator has to configure for that
to work.

The boundary is deliberately narrow. It answers *who is calling*, *for which
tenant*, and *with which coarse scopes*. It does not decide what a caller may
read or write. The separate dataset grant boundary that does is described in
[Authorization Boundary](#authorization-boundary).

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

No HTTP route uses this policy yet. The only route is the public
`GET /v1/health`.

## Persistence Boundary

`hawki_backend.persistence` makes the dataset grant reader real against a
database. It is an adapter: it knows the domain, and the domain never knows
it. The authorization package imports no database driver.

### The owned schema

The backend owns one PostgreSQL schema, `hawki_backend`, with two tables in
it. The application that still serves the legacy surfaces owns `public` and
pins its search path to it, so the backend's tables are never addressed by
it and the two migration histories never collide. The backend never reads
the application's tables.

| Table | Primary key | Purpose |
|---|---|---|
| `hawki_backend.datasets` | `(tenant_id, dataset_id)` | which datasets exist, and in which state |
| `hawki_backend.dataset_grants` | `(tenant_id, dataset_id, principal_type, principal_id, permission)` | which principal holds which action on which dataset |

The tenant is part of both identities. A grant references its dataset by
`(tenant_id, dataset_id)`, so a grant that names a dataset of another
tenant cannot be stored at all. The dataset status is limited to `active`,
`archived` and `deleted`, and only an active dataset answers a lookup.

The tables carry no storage target. The collection, the namespace and the
embedding configuration stay with the application that owns them.

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

The tables start empty. There is no data migration and no dual write: the
backend's own grant management populates them, and the rows of the legacy
application stay where they are. No HTTP route uses the reader yet, and
`main.py` does not construct it.

> **A dedicated database account is required before the reader is wired
> into production.** The connection above is expected to use an account
> that may only reach the `hawki_backend` schema. An account that can also
> read `public` would break the rule that the backend never queries the
> application's tables.
