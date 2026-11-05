# Epoch 10 managed-document ingestion

`MANAGED_DOCUMENT_BACKEND=laravel` is the default. `python` makes Laravel an
identity and compatibility BFF for managed CREATE, batch CREATE, PUT, DELETE,
and `POST /api/pipeline/controller/files`. Invalid selectors fail closed.
There is no fallback, dual persistence, or independent Laravel managed dispatch.
Production activation is a separate operator action.

URL and crawler ingestion remain Laravel-owned. Epoch 9 text semantics and
Epoch 8 Temporal control continue to use their existing implementations.
Managed files still execute `IngestSourceWorkflow` through the Epoch 8 operation
ledger and dispatcher, never through a second Temporal adapter.

## Identity and grants

Managed mutations require an active user, resolved from the existing browser
session or a valid Sanctum bearer token. A credential-free request is permitted
only when exactly one active user exists. Zero or multiple active users are
refused; an explicit invalid Authorization header is always 401, including
when a session exists. There is no additional external token ability.

The BFF signs `QueryTokenSigner::signIngest`: `sub` is the resolved Laravel user
ID, `tenant_id` is configured, `azp=hawki-bff`, scope is exactly `rag:ingest`,
and TTL is at most 60 seconds. This token stays on the server-to-server hop.
The existing RS256 issuer/audience/JWKS and key rotation procedure applies.
Python requires both the ingest scope and an exact active tenant/user/dataset
INGEST grant before publishing uploads or reserving managed operations.
Laravel does not decide the final dataset grant. Python runtime never reads
Laravel `public.*` tables. Use the existing operator ingest-grant inventory and
backfill procedure; do not create grants as a side effect of uploads.

## Deployment prerequisites

Run Alembic through revision `0004`. The additive tables are managed documents,
revisions, deduplicated events, and mutations. The mutation ledger is necessary
to recover cleanup that precedes creation of a replacement revision, as well
as DELETE, which has no successor. Existing datasets, grants and
`workflow_operations` are reused. Checksums and filenames are not unique.

Enable `HAWKI_BACKEND_MANAGED_DOCUMENTS_ENABLED=true` only after configuring:

- The existing backend authentication profile, dataset/model scope registry,
  active dataset inventory and exact ingest grants.
- Epoch 8 workflow control and callback HMAC secret.
- The same `HAWKI_RAG_TEMPORAL_SHARED_ROOT` visible to API and all workers,
  with upload/profile write permission. Uploads are streamed, SHA-256 checked,
  fsynced, and published immutably before dispatch.
- Existing Qdrant access and, where graph contributions are possible, Neo4j
  access. Collection, namespace and database are frozen with each revision.
- Consistent `RAGANYTHING_SUPPORTED_UPLOAD_EXTENSIONS`. Custom conversion
  retains extension-based acceptance and its converter URL/token/start path.

The BFF base URL is `HAWKI_PYTHON_MANAGED_DOCUMENT_URL`; the timeout is
`HAWKI_PYTHON_MANAGED_DOCUMENT_TIMEOUT`. Do not point the base URL at a public
redirecting endpoint. Network policy must protect internal signed endpoints.

## Public reads and legacy cutover compatibility

The existing GET `/api/documents` and GET `/api/documents/{id}` remain the
browser URLs. In Python mode the BFF uses the established browser query
principal resolver and a short-lived `rag:query` JWT. Python GET
`/v1/documents` and GET `/v1/documents/{id}` require exact active
tenant/user/dataset QUERY grants; INGEST permission does not imply read access.
Unauthorized detail reads are 404; unauthorized datasets are omitted from lists.
Python-owned documents are built exclusively from Python document/revision and
completion state. No Laravel source, job, output or synchronization row is needed.

Pre-cutover Laravel handles remain readable without adoption. Before projecting
any durable Laravel row, the BFF asks Python POST `/v1/documents/read-access`
to confirm both QUERY access to its dataset and absence of any Python owner for
that ID. This BFF-only compatibility helper returns allowed IDs, never document
content. Python ownership always excludes a legacy copy, even when the Python
detail read was denied. Unknown or unavailable ownership/authorization fails
closed; a Python outage never selects a legacy representation. The helper uses
the same query JWT and grants as Python detail/list reads.

Listing preserves `{success, documents}` and the existing 1–250 limit, dataset
aliases and search aliases. There is no public pagination/cursor. The BFF merges
authorized candidates from both owners, orders lexicographically by `indexed_at`,
then `updated_at`, then `created_at`, each descending with missing values last
at that comparison, then by `document_id` ascending. These are successive
tie-breakers, not a coalesced timestamp. The single public limit is applied last.
Python supplies its already-authorized, authoritative top N. Every matching
undeleted legacy candidate is fetched without a candidate limit; ownership and
QUERY access are checked in batches of at most 250, and only allowed legacy
rows are synchronized/projected before global sorting. Candidate identity order
has no effect on public ordering, and SQL NULL ordering cannot exclude a row.
This deliberately trades memory and legacy projection work proportional to the
matching legacy set for correct ordering at the current scale. Laravel mode
retains its existing read path.
Inventory legacy datasets and backfill their Python QUERY grants before cutover
so their authorized users retain access. This does not migrate legacy mutations.
The existing upload-download compatibility route is unchanged.

## Public and internal contracts

Public Python routes are POST `/v1/documents`, POST `/v1/documents/batch`,
PUT and DELETE `/v1/documents/{document_id}`. Multipart uploads retain the
inclusive 100 MiB per-file limit. The Laravel pipeline controller is a mapper
to the same managed CREATE logic; it is not a second Python ingestion API.

Create requests with distinct keys (or without keys) may create identical
files as distinct managed documents. Same-key/same-canonical-input requests
retain their document and workflow identities; changed input conflicts.
Batch keys are `<base>:<index>`; the legacy item envelope returns 202 for all
success or 207 for partial failure. Authorization is checked for the batch
before item side effects. PUT keeps force, timestamp and checksum no-op rules;
same-key retries resume the existing cleanup/preparation state.

Artifact preparation performs no filesystem writes. Scope, graph requirements,
converter profile, canonical request and complete worker input are validated
before the mutation claim commits. The existing mutation JSON then records an
artifact intent with `state=prepared`, exact upload/profile destinations,
checksums, and the frozen candidate revision. This covers replacements before
their successor revision is inserted; no additional schema migration is needed.

Only after that commit does publication acquire the existing document session
lock, copy/fsync/link the upload and optional private converter profile, and
commit `state=published`. Workflow dispatch requires successful publication.
Before publication, storage configuration drift is rejected if it would change
any prepared destination. Restore the prepared configuration before retrying.
A matching destination is reconciled; conflicting bytes are never overwritten.
The deterministic `.publishing` staging file belongs to the same committed
intent and is cleaned/replaced under that lock after a crash.

A publication or publication-state failure returns a retryable 502 and retains
the prepared owner. It no longer claims that no document was created: the owner
is intentionally durable. Repeat the identical file/fields/key to reuse the same
document, revision, upload path and converter profile. Batch items and pipeline
controller uploads use this same lifecycle. Invalid graph/scope/profile requests
publish no artifacts. Cleanup-first replacement publication and Epoch 8 dispatch
semantics remain unchanged.

Workers keep the existing signed acquire/release protocol and guarded sink
adapters. Python file workflows opt into `managed_writer_protocol=1`, which
checks admission again for each sink write. Text uses its existing flag.
Graph grants require the exact frozen graph scope. Passthrough conversion may
require a graph target even when the request's graph flag is false; that target
must still have a frozen namespace/database and durable contribution ownership.

Laravel classifies callbacks through signed
`/internal/managed-documents/ownership`. A unique durable Epoch 8 source
operation without managed ownership remains Laravel-owned. Managed callbacks
require a valid managed ID and an exact expected Python revision/input match.
Unknown, old Laravel-managed, or ambiguous ownership fails closed. Workflow
name prefixes and `upload://` are never the final managed discriminator.
The BFF relays exact event/writer bytes to `/internal/managed-documents/events`
and `/internal/managed-documents/writers` with a fresh HMAC. Python owns the
lifecycle, event-ID/body-hash deduplication, writer gate and receipts.

## Replacement and deletion recovery

A mutation records its expected published generation, freezes every uncleaned
historical revision and closes writer admission under the document lock.
Relevant runs are canceled through Epoch 8. Active or uncertain registrations
must be released/reconciled; a workflow's closed status alone is insufficient.
A closed run with a provably empty protocol admission ledger is also safe:
the fence prevents any subsequent registration. Unknown evidence stays pending.

A PostgreSQL session advisory lock serializes the complete cleanup owner across
sink RPCs, without an open transaction. Use a direct or session-pooled PostgreSQL
connection for this session lock. A process crash releases that lock;
a time-based lease never permits an overlapping delayed cleaner. Historical
Qdrant points are filtered by exact document/source/dataset in the frozen
collection, then counted to verify zero. Neo4j uses the existing contribution
cleanup adapter in the frozen dataset/namespace/database; shared contributions
and shared entities survive. Partial or failed cleanup cannot mark deletion
complete. Repeating cleanup is safe while the same fence remains authoritative.

Replacement stages are durable: fenced, drained, cleaned, prepared, dispatched,
and completed. A successor is prepared only after cleanup, remains pending while
workers execute, and becomes current only in a final transaction checking the
expected generation, released writers, exact run/ownership and manifest binding.
Stale/superseded/post-deletion callbacks cannot publish. A timeout after Temporal
submission retains the same operation and workflow ID for reconciliation.

Retry the same PUT with identical file, normalized fields and Idempotency-Key,
or repeat the same DELETE. Do not generate a new key to bypass a pending fence.
Restore missing/changed immutable upload bytes before retrying dispatch.
Never clear uncertain writer evidence merely because a workflow is closed.
After verified deletion, a new replacement can reuse the stable managed handle
with a new generation and source identity. Old deletion replay remains scoped
to its completed operation.

Previously completed Laravel handles are not imported by migration `0004`.
Inventory and explicitly retire or reconcile those handles before activation;
Python mutations return 404 for handles outside its ownership instead of
consulting Laravel. This implementation does not perform historical adoption.

## Coordinated activation

1. Deploy migration/API with the public selector still `laravel`.
2. Deploy the Laravel compatibility BFF and principal boundary.
3. Deploy compatible workers with the managed admission flag support.
4. Quiesce new managed mutations.
5. Drain/reconcile all old Laravel-owned managed workflows.
6. Reconcile pending Laravel replacements and deletions.
7. Confirm all old writer registrations are released or explicitly reconciled.
8. Confirm no ambiguous managed callbacks remain. Old in-flight Laravel managed
   documents are **not automatically adopted** by Python.
9. Switch every Laravel replica together to `MANAGED_DOCUMENT_BACKEND=python`.
10. Smoke-test create, identical-file/new-key create, update, delete, signed
    writers and terminal events against a disposable authorized dataset.
11. Reopen managed traffic after verifying the authority and frozen sink targets.

For rollback, quiesce traffic first and finish/reconcile Python-owned operations
with Python still serving their callbacks. Do not flip replicas independently
or re-create Python documents in Laravel. Preserve Python rows, uploads,
workflow identities, HMAC configuration and writer evidence until all such
operations are closed. Returning to Laravel requires an explicit inventory
decision for Python-owned handles; neither selector automatically transfers
existing ownership. Crawler/URL authority stays Laravel throughout.

## Validation

From `python_backend`, run lock/lint/format, non-integration pytest, and the
managed tests under optimized Python. Real concurrency tests require a
disposable database whose name ends in `_test`:

```bash
HAWKI_EPOCH10_TEST_DSN='<disposable PostgreSQL DSN>' \
  uv run --frozen --no-sync pytest tests/managed_documents/test_postgres.py
uv run --frozen --no-sync python -O -m pytest tests/managed_documents
```

Also run worker writer/drain, source workflow, managed deletion and shared graph
cleanup regressions; Laravel managed BFF/principal, public contract, callbacks,
query/text and crawler regressions. Keep PostgreSQL race results distinct from
SQLite functional tests. The tests and this runbook do not activate production.
