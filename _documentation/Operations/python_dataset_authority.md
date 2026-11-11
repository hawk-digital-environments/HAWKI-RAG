# Epoch 12 dataset authority

The Python dataset authority is implemented but not activated. The checked-in
defaults remain `DATASET_BACKEND=laravel` and
`HAWKI_BACKEND_QUERY_DATASET_SOURCE=environment`. Deploying this code does not
move production traffic, create datasets for you, or migrate legacy rows.

This runbook covers the coordinated one-time migration from Laravel-owned
dataset metadata, grants and grant tokens to Python ownership. Query cutover
(Epoch 7), workflow control (Epoch 8), direct text (Epoch 9), managed documents
(Epoch 10) and URL ingestion (Epoch 11) have their own runbooks. Do not combine
their activation windows without a single reviewed change plan.

## Ownership and selectors

Two independent selectors must change together.

| Setting | Owner | Pre-cutover | Python authority |
|---|---|---|---|
| `DATASET_BACKEND` | Laravel BFF | `laravel` | `python` |
| `HAWKI_QUERY_BACKEND` | Laravel BFF | `laravel` | `python` |
| `TEXT_INGESTION_BACKEND` | Laravel BFF | `laravel` | `python` |
| `MANAGED_DOCUMENT_BACKEND` | Laravel BFF | `laravel` | `python` |
| `CRAWLER_INGESTION_BACKEND` | Laravel BFF | `laravel` | `python` |
| `HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT` | Laravel compatibility | current value | must be `false` |
| `HAWKI_BACKEND_QUERY_DATASET_SOURCE` | Python runtime | `environment` | `database` |
| `HAWKI_BACKEND_QUERY_DATASETS` | Python runtime | required | must be unset |

`DATASET_BACKEND=python` is a complete activation, not a per-surface switch.
Laravel refuses it with `503 dataset_backend_configuration_invalid` unless the
query, text-ingestion, managed-document and crawler selectors are all exactly
`python` and query-all-by-default is exactly `false`. There is no silent
fallback to a partially activated state and no dual write. An unknown
`DATASET_BACKEND` fails every public dataset route the same way. An unknown
`HAWKI_BACKEND_QUERY_DATASET_SOURCE` refuses Python startup.

Create and delete are authenticated management operations on the shared BFF
routes, including Python mode. They require a Sanctum principal with either the
`admin` token ability or an administrator role; a query or ingestion token is
not enough. This is an
intentional compatibility change from the legacy single-user deployment:
unauthenticated create/delete now returns `401`, and an authenticated
non-manager returns `403`. List, show, query listing and the self-grant routes
keep their existing principal rules.

In Python mode:

- Laravel keeps the public routes `GET/POST /api/datasets`,
  `GET /api/datasets/{dataset_id}`, `DELETE /api/datasets/{dataset_id}/storage`,
  the two self-grant routes and `GET /api/query/datasets`. It validates the
  same input, forwards with an internal token and maps the identical envelopes.
- Laravel writes no `datasets`, `dataset_grants` or `dataset_grant_tokens` row.
  Legacy grant commands exit non-zero, the cleanup schedule is not registered,
  and direct service/repository writers raise
  `DatasetBackendUnavailableException` (HTTP 503).
- Python owns `hawki_backend.datasets`, `hawki_backend.dataset_grants` and
  `hawki_backend.dataset_grant_tokens`. It never reads Laravel `public.*` at
  runtime.
- Python-managed datasets are usable without restarting the backend. The
  database scope resolver reads the tenant-scoped row on every request.

Only a one-time operator process may read `public.*`, and only through a
SELECT-only credential. Never point a SELECT-only credential at the target and
never grant the runtime account access to `public`.

## Routes and internal scopes

| Route | Scope | Notes |
|---|---|---|
| `GET /v1/datasets` | `rag:datasets:manage` | Active datasets, newest first. `limit` is bounded 1–250. |
| `POST /v1/datasets` | `rag:datasets:manage` | `201` on first create, `200` on compatible replay, one `dgt_…` token on first create. |
| `GET /v1/datasets/{dataset_id}` | `rag:datasets:manage` | Active dataset details and Qdrant readiness. |
| `DELETE /v1/datasets/{dataset_id}/storage` | `rag:datasets:manage` | Archive first, `202` while owned sources remain, `200` once tombstoned. |
| `POST /v1/datasets/{dataset_id}/query-grants/self` | `rag:query` | Exact self grant; the dataset must be active, fully scoped and backed by a non-empty Qdrant collection. |
| `POST /v1/datasets/{dataset_id}/ingest-grants/self` | `rag:ingest` | Redeems the creator's one-time token into an exact ingest grant. |
| `GET /v1/query/datasets` | `rag:query` | Active, fully scoped datasets with an exact query grant, ordered by `name` then `dataset_id`. |

`rag:datasets:manage` is minted only by the Laravel BFF with `sub=hawki-bff` and
`azp=hawki-bff`, after the management principal has authenticated. It is never
returned to a browser. Ensure the Python client registry contains `hawki-bff` as
configured for the existing query BFF, and do not issue this scope to any other
client. User self-grant tokens keep the browser user's id in `sub`,
`azp=hawki-bff`, and exactly one of `rag:query` or `rag:ingest`.

A runtime create may not choose a physical target or storage identity. The BFF
rejects `qdrant_collection`, `qdrantCollection`, `neo4j_namespace` and
`neo4jNamespace` with `422`; the Python API independently rejects physical,
embedding, graph and tenant/principal scope fields with `422
invalid_dataset_request`. Python derives one bounded, collision-resistant
identity from the authenticated tenant and the logical dataset id
(`sha256(tenant + "\0" + dataset_id)[:16]` plus a sanitized slug, at most 191
characters) and uses it as both the Qdrant collection and Neo4j namespace. The
one-time migration script is the only direct-import exception and preserves the
reviewed source scope.

The legacy error contract is preserved:

| Situation | Status | Error code |
|---|---|---|
| Unknown, inactive or other-tenant dataset | 404 | `dataset_not_found` |
| Active but incomplete scope or unverified/empty Qdrant collection | 409 | `dataset_not_ready` |
| Create metadata mismatch | 409 | `dataset_creation_conflict` |
| Ingest grant without the one-time token | 403 | `grant_token_required` |
| Token unknown, expired, consumed or bound elsewhere | 403 | `grant_token_invalid` |
| Legacy Laravel dataset state still present before delete | 409 | `legacy_dataset_state_present` |
| Store, Qdrant catalog or Python authority unavailable | 503 | `dataset_unavailable` |
| Unknown or incomplete activation | 503 | `dataset_backend_configuration_invalid` |

A BFF upstream failure is always `dataset_unavailable`; an unrecognized or
malformed Python success body is not trusted and is never retried against the
legacy implementation. A `dataset_creation_conflict` message is accepted only
when its dataset id equals the requested id and its bracketed field is one of
`dataset_id`, `name`, `description`, `status` or `physical_scope`; an unknown
field, malformed bracket syntax or identity mismatch is a `503`, not a 409.

In Python mode, `DELETE /api/datasets/{dataset_id}/storage` first runs a
Laravel-only, read-only preflight. If the id still has a legacy active/archived
dataset row, grants, tokens, undeleted managed documents or outputs, ingestion
sources, or non-terminal pipeline tasks/jobs, the BFF returns `409
legacy_dataset_state_present` and forwards nothing. Python never reads
`public.*`. Migrated datasets remain undeletable through the Python route until
their Laravel legacy state is formally retired; Python-only datasets delete
normally.

## Prerequisites

- Alembic revision `0007` is applied and `alembic check` reports no drift.
- The runtime database account can only reach `hawki_backend`; it is not a
  superuser and cannot read `public`.
- Python can reach the Qdrant HTTP API. `QDRANT_HTTP_URL` and, when required,
  `QDRANT_API_KEY` are configured. A catalog outage fails closed: the dataset
  list omits unverified collections and self-grants answer `dataset_not_ready`.
- Query, direct-text, managed-document and URL ingestion selectors are already
  Python and their grants are verified. Dataset authority must not move while
  those writers still use Laravel tables.
- `HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT=false`, and the materialized legacy
  query grants required for rollback have been reviewed. Python has no
  query-all path.
- A change freeze covers Laravel dataset creation, grant changes and grant-token
  issuance from export until activation and verification.
- Operator DSNs come from a secret manager or a silent prompt. Do not put them
  in command arguments, shell history, tickets, reports or logs. Keep
  `HAWKI_BACKEND_DB_DSN` target credentials separate from the SELECT-only
  source credential.

## One-time migration

Run from `python_backend` on an operator host with PostgreSQL connectivity and
`uv`. The target must already be at revision `0007`. This tool performs no
DDL, writes no Laravel row and deletes no target row.

```bash
cd python_backend
umask 077
read -rs 'HAWKI_DATASET_MIGRATION_SOURCE_DSN?SELECT-only Laravel source DSN: '
export HAWKI_DATASET_MIGRATION_SOURCE_DSN
read -rs 'HAWKI_BACKEND_DB_DSN?Python target DSN: '
export HAWKI_BACKEND_DB_DSN
uv run --frozen --no-sync alembic upgrade head
uv run --frozen --no-sync alembic check
```

Both DSNs must use the `postgresql+psycopg` driver. The target DSN may not carry
an `options` query override. The source transaction is `REPEATABLE READ` and
`READ ONLY`; the target transaction is separate and atomic.

### Plan

`--laravel-report` is required for `--apply` and `--verify` and must be the
reviewed JSON from `php artisan query:cutover-export --check-key`. It is the
only source of `graph_enabled`; its dataset set must match the source exactly,
its tenant must be `default`, and it must carry the reviewed
`query_all: false`. Python has no query-all path: materializing exact grants
while legacy query-all is still enabled would silently drop access, so the plan
refuses a missing, true or non-boolean value. Without the report, a
`--plan`-only run imports every dataset with `graph_enabled=false` (safe for
query, no graph retrieval), but that plan cannot be applied or verified.

The plan digest binds the exact source rows, the reviewed report, the desired
state, the current target snapshot and the only permitted target transitions.
Planning against the target is therefore mandatory for apply. Any source,
report or target change produces a different digest.

```bash
REPORT=/secure/review/query-cutover-export.json
uv run --frozen --no-sync python scripts/dataset_authority.py \
  --plan --laravel-report "$REPORT" > plan.json
jq '{counts, plan_sha256, target_check, target_diff}' plan.json
```

Planning without `HAWKI_BACKEND_DB_DSN` is possible, but it reports
`target_check: "NOT EXECUTED"` and must not be approved. Review the full
missing/extra/mismatched/revived inventory. Re-running an unchanged plan and an
unchanged target produces the same `plan_sha256`; source, report or target drift
changes it. Regenerate and re-review the plan after any change.

A plan may insert missing rows. It may update a dataset only when the target row
is exactly the deterministic revision-`0007` placeholder for that id. Canonical
target metadata, an extra dataset, grant or token, a mismatched token, a target
tombstone (`deleted`/`deleted_at`) or an archived-to-active transition refuses
all writes. Tombstones are final: the tool never revives one, and `--verify`
reports `revived.datasets` separately from ordinary mismatches.

The plan imports:

- all source datasets with their lifecycle status, canonical physical scope,
  embedding provider/model and creation time;
- all source grants with exact principal type, principal id and permission;
- every source grant-token hash with its exact `expires_at`, `consumed_at` and
  timestamps. A consumed token stays consumed, so its old plaintext cannot be
  replayed. Laravel stores no plaintext and this tool never creates one.

Unknown statuses, permissions, principal types, malformed IDs, wildcards,
duplicate collections/namespaces, duplicate grants, cross-dataset tokens and
source/target tenant drift fail closed. A non-default tenant column in any
source table stops the migration.

### Apply

```bash
PLAN_SHA=$(jq -r '.plan_sha256' plan.json)
uv run --frozen --no-sync python scripts/dataset_authority.py \
  --apply --laravel-report "$REPORT" --confirm-plan "$PLAN_SHA"
uv run --frozen --no-sync python scripts/dataset_authority.py \
  --verify --laravel-report "$REPORT"

# The successful apply changed the target. The pre-apply digest is now stale
# and must never apply again. Re-plan, review the settled target, then run the
# required idempotency apply with its new digest.
uv run --frozen --no-sync python scripts/dataset_authority.py \
  --plan --laravel-report "$REPORT" > settled.json
SETTLED_SHA=$(jq -r '.plan_sha256' settled.json)
jq '.target_check, .changes' settled.json
uv run --frozen --no-sync python scripts/dataset_authority.py \
  --apply --laravel-report "$REPORT" --confirm-plan "$SETTLED_SHA"
# Required settled apply: datasets_inserted=0, datasets_updated=0,
# grants_inserted=0, tokens_inserted=0.
```

Apply takes a PostgreSQL advisory lock and table locks, re-checks the source,
report, target snapshot and transition set inside the transaction, and rolls
back completely on any error. It updates only an exact revision-`0007`
placeholder and never rewrites a grant or token. Any unexpected target row,
tombstone or archived-to-active change refuses all writes. Review extras
manually and resolve them outside this tool; the tool never deletes an extra row
automatically.

A successful apply changes the target, so the reviewed digest is stale
immediately afterwards. The example re-runs `--plan` to obtain a new digest
before the required idempotency apply. Applying with the old digest must fail
closed; do not reuse a pre-apply confirmation.

`--verify` is read-only. `PASS` means every expected dataset, grant and token
hash is present and no extra, mismatched or revived row exists. A missing,
mismatched or revived required row makes the command exit non-zero. Treat a
`FAIL` as a blocked cutover, not as a reason to rerun apply blindly.

## Activation

Only after apply and verify produce a reviewed `PASS` and the change freeze is
confirmed, change both services in one coordinated release.

Laravel BFF environment:

```dotenv
DATASET_BACKEND=python
HAWKI_QUERY_BACKEND=python
TEXT_INGESTION_BACKEND=python
MANAGED_DOCUMENT_BACKEND=python
CRAWLER_INGESTION_BACKEND=python
HAWKI_PYTHON_DATASET_URL=http://hawki_backend/v1/datasets
HAWKI_PYTHON_QUERY_DATASETS_URL=http://hawki_backend/v1/query/datasets
HAWKI_PYTHON_DATASET_TIMEOUT=10
HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT=false
```

The checked-in defaults stay `laravel` for all five selectors and
`HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT=true`; this runbook changes them only
in the reviewed activation environment.

Keep the existing `HAWKI_QUERY_BFF_*` issuer, audience, client id, tenant, key
and timeout settings. The BFF uses the same signing key for query, ingest and
dataset-management tokens.

Python environment:

```dotenv
HAWKI_BACKEND_QUERY_DATASET_SOURCE=database
# HAWKI_BACKEND_QUERY_DATASETS must be absent
HAWKI_BACKEND_DB_DSN=postgresql+psycopg://...
QDRANT_HTTP_URL=http://qdrant:6333
# QDRANT_API_KEY=...
HAWKI_BACKEND_DATASET_EMBEDDING_PROVIDER=ollama
HAWKI_BACKEND_DATASET_EMBEDDING_MODEL=bge-m3
```

The embedding provider/model seed newly created datasets; they do not
retroactively change imported datasets. Revision `0007` placeholders are
replaced by the reviewed migration, not by these defaults.

Deploy the Python service and the Laravel BFF together. Python validates the
selector at startup and fails to start with a database selector but no
database configuration, or with the environment registry still present. The
Laravel BFF refuses an incomplete Python activation: a partially changed
selector set, an invalid selector value, or query-all still enabled makes every
dataset route return `503 dataset_backend_configuration_invalid`; it never
falls back to Laravel ownership. Changing only one selector is a half-cutover:
datasets created through the new authority may be unresolvable through the old
registry. Do not operate that state beyond a controlled rollback.

Dataset create and delete now require an authenticated administrator (Sanctum
with the `admin` ability or an administrator role). Review dashboard and
automation credentials before activation so no unauthenticated client depends
on the legacy public mutation behavior.

## Verification gates

Record exact commands and outputs for the change record. Do not treat local test
success as production evidence.

1. `alembic check` is clean and the schema is at `0007`.
2. The one-time apply is idempotent: the second apply reports zero changes.
3. `GET /api/datasets` through the BFF returns exactly the imported active
   datasets; the response is the legacy `{"success":true,"datasets":[...]}`
   envelope.
4. `GET /api/datasets/{dataset_id}` returns the legacy envelope, and an unknown
   id returns `404 dataset_not_found` without physical scope details.
5. `POST /api/datasets` from an administrator creates only in `hawki_backend`,
   returns `201` with a `dgt_` token, and a compatible replay returns `200`
   without a token. The Laravel `datasets` table gains no row. An
   unauthenticated call returns `401`, a non-manager `403`, and a caller-chosen
   `qdrant_collection`/`neo4j_namespace` is rejected before forwarding.
6. `POST /api/datasets/{id}/query-grants/self` succeeds only for a ready
   collection; a missing/empty collection returns `409 dataset_not_ready`.
7. `POST /api/datasets/{id}/ingest-grants/self` succeeds once with the exact
   token and returns `403 grant_token_invalid` on reuse or a different dataset.
8. `GET /api/query/datasets` returns exactly the user's exact query grants and
   no query-all rows. Service principals receive an empty list.
9. `DELETE /api/datasets/{id}/storage` first refuses residual Laravel legacy
    state with `409 legacy_dataset_state_present`. For a Python-only dataset it
    hides the dataset from list/query immediately. While text, managed-document
    or URL source rows remain it returns `202`/`cleanup_pending`; each source is
    deleted through its own protocol, and a restart resumes the archived-row
    reconciler without a new delete request. Repeatedly until complete, then a
    repeat returns `200` and the row is tombstoned. A mid-cleanup failure stays
    retryable and never reports completion.
10. An unreachable Python service returns `503 dataset_unavailable` and never
    legacy data. An invalid or incomplete activation (including any selector not
    `python` or query-all enabled) returns
    `503 dataset_backend_configuration_invalid` on every dataset route.
11. SQL or query logs show no Python runtime access to `public.*`, and a
    `git`/database check confirms Laravel's frozen rows are unchanged during
    the smoke window.

## Rollback

### Before activation

Revert `DATASET_BACKEND`, `HAWKI_QUERY_BACKEND`, `TEXT_INGESTION_BACKEND`,
`MANAGED_DOCUMENT_BACKEND` and `CRAWLER_INGESTION_BACKEND` to their reviewed
pre-cutover values, restore `HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT`, and set
`HAWKI_BACKEND_QUERY_DATASET_SOURCE` back to `environment` (and restore the
reviewed `HAWKI_BACKEND_QUERY_DATASETS` value). The imported Python rows are
additive in the Python-owned schema; keep them for the next activation attempt.
Do not drop revision `0007` while any service can start in database mode.

### After activation

There is no automatic reverse migration. Laravel's dataset tables were frozen
while Python owned the authority, so switching only `DATASET_BACKEND=laravel`
would resurrect stale metadata and grants. Treat a post-activation rollback as
a new reviewed migration:

1. Freeze dataset creation, grants, token issuance and all ingestion writes.
2. Export and review the exact Python rows (datasets, grants, token metadata).
3. Reconcile them into Laravel in a maintenance window with a separately
   reviewed tool or documented manual procedure. Never copy rows while both
   services are live.
4. Verify the reconciled Laravel rows, then switch all five selectors back and
   smoke the legacy routes.
5. Keep the Python rows until the rollback is accepted; do not delete either
   authority's data as part of an emergency switch.

Never reactivate the Laravel cleanup schedule or legacy grant commands while
Python still owns the authority. Never run both writers concurrently.

## Troubleshooting

| Symptom | Meaning | Action |
|---|---|---|
| `dataset_backend_configuration_invalid` | Selector is unknown, or Python activation is incomplete (query/text/managed/crawler selector or query-all not ready) | Fix the full Laravel environment and redeploy; no fallback exists. |
| `legacy_dataset_state_present` | Laravel legacy rows still exist for the id (dataset, grants, tokens, documents/outputs, sources, tasks/jobs) | Retire the legacy state through its owning runbook; do not bypass the preflight. |
| `dataset_unavailable` | Python unreachable, database unavailable, or Qdrant catalog failed | Check `/v1/health`, the Python database and Qdrant; do not retry against legacy. |
| `dataset_not_found` | Unknown, inactive, tombstoned or other-tenant dataset | Verify the exact id and tenant; do not treat this as an outage. |
| `dataset_not_ready` | Scope incomplete, collection missing, or collection has zero points | Check `qdrant_collection`, ingestion state and `QDRANT_HTTP_URL`. |
| `grant_token_required` / `grant_token_invalid` | No token supplied, or it is consumed, expired or bound to another dataset | Issue a new dataset only; the old plaintext cannot be recovered or reused. |
| New dataset is not listed | Wrong selector, missing grant, or unverified collection | Check both selectors, the exact grant row and Qdrant readiness. |
| Migration tool exits non-zero | Source/target drift, extras, malformed rows or an unapproved digest | Re-read the plan; fix the cause; never bypass `--confirm-plan`. |

Tool errors are deliberately generic and never contain a DSN, credential,
token, SQL statement or driver message. Keep it that way when collecting
evidence.

## Tests

Focused checks from `python_backend`:

```bash
uv run --frozen --no-sync pytest tests/datasets tests/test_dataset_authority_migration.py
HAWKI_BACKEND_DB_TESTING=1 \
HAWKI_BACKEND_DB_DSN='<disposable PostgreSQL DSN ending in _test>' \
  uv run --frozen --no-sync pytest \
    tests/persistence/test_dataset_authority_postgres.py \
    tests/persistence/test_dataset_cleanup_postgres.py
```

The PostgreSQL suites are destructive and run only when the database name ends
in `_test`. They cover real CRUD, replay/conflict, lifecycle/tombstones, tenant
isolation, exact grants, query listing, token issuance/redemption/expiry/
binding, concurrent redemption, the durable dataset-row admission fence,
archive/cleanup restart recovery and blocking-source counts. The migration
tests cover source/target digest binding, placeholder-only updates, tombstone
and archived-to-active refusal, query-all refusal and CLI safe failures. These
are implementation checks, not production activation evidence.
