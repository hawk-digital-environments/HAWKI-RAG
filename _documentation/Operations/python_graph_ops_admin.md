# Epoch 14: Python graph and operations authority

Epoch 15A uses Python defaults. Follow the [coordinated cutover and drain runbook](epoch15a_cutover.md) for current activation/rollback ordering; earlier epoch procedures below retain their migration-specific prerequisites.

`GRAPH_OPS_BACKEND=laravel|python` selects graph/ops authority. It defaults to
`python`. Unknown values fail closed. No production cutover is performed by
this change, and `.env` is not edited.

## Activation and rollback

Python selection requires `DATASET_BACKEND=python`,
`PIPELINE_EXECUTION_BACKEND=python`, and
`HAWKI_BACKEND_QUERY_DATASET_SOURCE=database`. The dataset-source setting must
be present in both the BFF and backend environments. The existing Epoch 13
pipeline, database, JWT signer/verifier, callback and worker prerequisites
continue to apply. Activation reuses the existing dataset and pipeline validators, including the earlier query/ingestion selectors and query-all restriction. Apply the existing schema through `0008`; Epoch 14 adds no
migration, settings database, writer table or admin tables.

Set `HAWKI_PYTHON_GRAPH_OPS_URL` to the internal backend `/v1/ops` URL
(default `http://hawki_backend/v1/ops`) and
`HAWKI_PYTHON_GRAPH_OPS_TIMEOUT` to a positive number of seconds (default 10).
Credentials, query strings, fragments and other URL paths are refused.
Canonical datasets must be active and explicitly graph-enabled. This epoch
does not change the dataset authority's graph provisioning policy.

Python transport failures, timeouts, invalid responses and upstream 5xx return
503. They never run Laravel graph queries or read stale Laravel statistics or
settings as a fallback. Rolling the selector back to `laravel` changes graph
execution and the legacy read projection; retain Python dataset authority and
the extended admission protocol while Python datasets or outstanding clears
exist. Coordinate deployment replicas before changing selectors.

## Graph API and identity

The public BFF contract is in `public/swagger/openapi.yaml`; the Python
operations companion is `python_backend/openapi/graph_ops.yaml`.

Public graph actions under `/api/rag/neo4j/graph` require the existing browser
query principal, `dataset_id`, and an exact dataset QUERY grant. Python handles
`GET /v1/ops/graph/overview`, `/search`, `/node`, `/semantic-search` and
`POST /v1/ops/graph/expand`. Limits are bounded (300 overview nodes, 50 search
results, 250 expansion nodes); depth is clamped to 1–3. Edges are independently
bounded to 1,000 and only join returned nodes.

Every Python graph operation uses both the canonical `dataset_id` and
`neo4j_namespace`; partial scope is refused before I/O. Overview, contains
search, name lookup, neighborhood, expansion, counts and deletion reuse
`Neo4jGraph` and its existing transport. There is no second Neo4j repository.
No index-discovery or global graph fallback occurs. Laravel-mode reads are
also scoped; stored legacy scope is used while Laravel owns datasets, and
Python resolves scope when Python owns datasets.

Python node IDs encode the stable `entity_key` with an opaque digest of both
scope components. The same entity in another dataset therefore has a different
public ID. Edges hash scoped endpoints and domain relation identity. Neither
uses Neo4j `elementId`. Scope properties and vectors are excluded from graph
properties. Legacy nodes without `entity_key` need reindexing to acquire a
stable domain identity; they are not assigned unstable fallback IDs.

Python semantic search invokes the existing query handler with
`generate=False` and uses scoped KG candidates for graph lookup. It does not
create another retrieval pipeline. Source-document enrichment resolves only
the current indexed Python managed-document revision and its registered target
IDs. Unresolved sources have `missing: true`; no Laravel managed-document
fallback is used in Python mode.

## Graph-only clear and writer safety

`POST /api/rag/neo4j/clear` and `POST /v1/ops/graph/clear` require the existing
dataset-manager authority, exact tenant ownership, and:

```json
{"dataset_id":"example","confirm":"CLEAR_DATASET_GRAPH"}
```

The operation is irreversible. It deletes only that dataset's graph. It never
deletes Qdrant points, snapshots, artifacts, source lifecycle records or cached
bridge files. A successful repeated clear returns zero deleted counts.

The existing PostgreSQL dataset row lock now serializes graph-clear admission
with source admission and every managed sink-writer acquisition. Existing
`workflow_operations` records retain `action=graph_clear` dispatching,
uncertain and confirmed receipts. This extends the existing admission and
dispatch protocol; there is no second writer registry or time-based lease.

Under that lock clear inspects direct-text operations, managed revisions,
URL generations and compatibility workflow evidence. Active ingestion,
unknown writer evidence or active/uncertain registrations return 409 before
Neo4j is contacted. A terminal Temporal status alone is insufficient. Writer
release remains available. New unregistered compatibility dispatch cannot
enter canonical Python graph scope, including converter-triggered graph work.
Historical unregistered compatibility dispatch remains a conflict until its
writer ownership and retirement are independently reconciled.

Laravel's existing frozen-state preflight also rejects graph clear while
Laravel-owned dataset artifacts remain. It does not equate empty Python
ledgers with proof that legacy workers are drained. Laravel never reads or
writes Python tables, and Python never reads Laravel tables.

Python mode executes the scoped Neo4j transaction directly. Laravel mode uses
the internal Python clear-admission and clear-receipt operations, then executes
the scoped transaction through the existing Laravel Neo4j client. Consequently
destructive clear requires Python dataset/admission authority even when the
graph execution selector is Laravel; an unavailable authority fails closed.

The Neo4j transaction deletes scoped relationships before scoped nodes, without
`DETACH DELETE`. Unexpected foreign relationships cause transaction rollback,
preserving the other scope. Driver mutation retries are disabled. PostgreSQL
transactions are not held across sink RPCs.

A crash, timeout, lost response or lost receipt leaves the existing operation
dispatching/uncertain. New ingestion and graph-writer admission remain closed;
repeating clear returns `graph_clear_reconciliation_required` (409). It never
blindly retries an uncertain destructive request. Rollback does not remove
this fence. An operator must first freeze relevant admissions, drain workers
and queued sink requests, establish that the exact clear is no longer running,
and verify its physical scope before reconciling its existing operation
receipt. Do not expire, delete or confirm an unresolved record merely because
a request or workflow appears closed. Keep evidence for independent review.

## Tenant observability and authority boundaries

`GET /v1/ops/stats` and `/config` require the existing
`rag:datasets:manage` authority. The BFF mints that internal scope only after
its existing dataset-manager browser/token check. Stats enumerate only active
tenant-owned canonical datasets. Only their stored Qdrant collections are
counted; unowned/legacy names are omitted. Graph counts use each exact scope.
An unavailable count is an unavailable response, not an empty tenant inventory.

Config is an explicit read-only projection of effective query models,
embedding contracts, reranking and numeric graph limits. No raw environment,
DSN, credential, token or API key is returned. There is no Python settings
store. Laravel settings GET/page responses carry `authority`, `effective`,
`managedBy` and `notice`. With any Python runtime selector active they are
non-effective/deployment-managed; PUT returns 409
`settings_managed_by_deployment` before validation or file writing. Save is
disabled in the UI. Fully Laravel mode retains the original settings behavior.

`GET /api/rag/health` selects Python `/v1/health` when Python owns query
execution; otherwise it retains bridge health. The monitor uses selected
health, Python stats and safe effective config in Python ops mode. Laravel
`rag_ingestion_artifacts` and `rag_graph_failures` remain explicitly labeled
legacy telemetry; no Python mirrors are created.

System readiness remains advisory (`advisory=true`, `enforce=false`), based on
selected health and tenant/dataset-aware statistics. It is not authorization.

The former raw collection DELETE route is unregistered in Epoch 15A. Use the existing manager-authorized
`DELETE /api/datasets/{id}/storage` flow for authoritative storage deletion.

## Browser scene and snapshots

The graph workspace has one Dataset selection for overview, both searches,
node loading, expansion and clear. Empty selection disables controls and stops
polling. Switching clears the scene and invalidates pending responses before
loading the selected dataset. The clear confirmation names that dataset and
sends the explicit confirmation token. Collection deletion controls are removed
and point counts lead to dataset storage management.

Snapshots remain Laravel filesystem/UI artifacts. New snapshots contain
trusted `tenant_id` and canonical `dataset_id`. Listing, loading and deletion
require an exact QUERY grant for the stored dataset in the authenticated BFF
tenant, independently of legacy query-all. Caller-selected dataset context is
never ownership evidence. The browser only restores scenes for its selected
dataset. Snapshots lacking trusted tenant/dataset ownership are unavailable,
including to administrators, until ownership is explicitly assigned through a
trusted process. `allow_legacy=true` does not bypass this boundary.

## Remaining non-blocking reviewer findings

Epoch 15A leaves F5 in the explicitly selected Laravel graph implementation;
that runtime is no longer the default. F6 is fixed on the surviving Python
stats/system-gate path: any missing canonical collection returns 503 instead
of a healthy zero count. Existing empty collections still report zero.

## Validation and Epoch 15 boundary

The graph-store tests cover real Neo4j reads, depth/size bounds, deterministic
identities, duplicate names, foreign IDs, exact counts and idempotent scoped
clear. Ops tests use real SQL for grants and lifecycle state; disposable
PostgreSQL tests prove admission serialization and durable closure. A combined
test uses real PostgreSQL, Neo4j and Qdrant to verify tenant statistics and that
graph clear preserves vectors and other scopes. The JS tests cover selection,
request scope, stale responses, disabled controls and UI authority notices.
Build output belongs in a disposable directory, never `public/build`.

Epoch 15 retains final legacy cleanup: no pipeline table removal, telemetry
migration, deployment redesign or Laravel decommissioning occurs here. Laravel
continues to own public/browser authentication, HTTP/BFF response shaping,
frontend hosting, filesystem snapshots and legacy telemetry/drain compatibility.

### Recorded validation

| Check | Result |
| --- | --- |
| Backend auth/grants/datasets/query/ingestion/ops regression | 1,104 passed; 120 opt-in cases skipped |
| Existing PostgreSQL text, managed-document, URL and dataset-fence regression | 87 passed |
| New PostgreSQL ops and combined real-service tests | 8 passed |
| Graph store, including 19 real Neo4j cases | 62 passed |
| Indexer writer registration/drain and graph-scope contracts | 44 passed |
| Laravel graph/RAG/settings/query/auth/dataset/document/pipeline/API contracts | 435 passed; 3,030 assertions |
| Final focused Laravel verification after review corrections | 48 passed; 554 assertions |
| JavaScript regression | 22 passed; 1 existing browser opt-in case skipped |
| Production frontend build | Passed; `/tmp/rawki-epoch14-vite-build` |

There are 106 test cases in new test files: 42 graph-store, 39 backend ops,
18 Laravel BFF, and 7 frontend cases. Existing contract tests were also updated.

The combined ASGI integration uses real PostgreSQL, Neo4j and Qdrant for
authorization grants, canonical dataset state, counts, overview/search/node/
expansion, semantic candidate lookup and scoped graph clear. Authentication
and semantic retrieval inputs in that test are deterministic doubles; JWT
cryptography and the existing query path have separate regression coverage.
Only retrieval candidate generation is mocked in the semantic graph assertion;
the scoped graph lookup uses real Neo4j. Graph clear leaves the owned Qdrant
points and both other graph scopes intact.

The existing artifact-permission tests require filesystem execution outside
the sandbox to preserve setgid bits. The full PHP run used
`USE_ZEND_ALLOC=0` to avoid a local Zend allocator abort. Neither workaround
changes application code or test assertions. Ruff, PHP syntax/Pint, the
existing OpenAPI route-contract tests, YAML duplicate-key/local-reference
checks and `git diff --check` passed.
