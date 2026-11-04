# Epoch 9 direct-text operations

The default remains `TEXT_INGESTION_BACKEND=laravel`. This guide describes an
explicit, coordinated activation; deploying code alone does not activate it.
Query (Epoch 7), workflow control (Epoch 8), managed documents, files, URL and
crawler ingestion retain their existing ownership.

## Ownership and credentials

In Python mode the public Laravel route retains Sanctum PAT authentication and
the exact `rag:text-ingest` ability. It forwards the original POST bytes and
Idempotency-Key. Laravel creates no direct-text task, source, job, artifact,
fingerprint, workflow identity or Temporal dispatch. Python validates, checks
`AuthenticatedPrincipal` → `AuthorizationAction.INGEST` → exact active
`(tenant, dataset, user, ingest)` grant, and owns the operation through deletion.
The BFF internal RS256 JWT uses the Laravel user ID as `sub`, the configured
canonical tenant, `azp=hawki-bff`, and scope exactly `rag:ingest`. Follow the
[JWT deployment guide](python_query_bff.md) for issuer/audience/key agreement.
No runtime Python query reads Laravel `public.*` tables.

Python persists `hawki_backend.ingest_text_operations` and
`hawki_backend.ingest_text_events`; Epoch 8 persists dispatch preparations in
`workflow_operations`. Apply Alembic through `0003` before enabling this service.
Authorization happens before writes, artifacts and workflow calls. Server-owned
query scope/model configuration supplies physical dataset targets. Clients may
not supply graph, embedding, collection, namespace, queues or workflow names.

## Grant backfill and drift

A **real production ingest-grant backfill and verification remain activation
prerequisites**. Local test success is not production evidence. Use a SELECT-only
Laravel source credential and a separately authorized target credential. The
operator tool is the only Epoch 9 code allowed to read source `public.*` tables.
It supports the current canonical tenant `default`; a different/ambiguous source
or target tenant is rejected. Do not change tenant identity during cutover.

From `python_backend`, provide `HAWKI_INGEST_MIGRATION_SOURCE_DSN` and
`HAWKI_BACKEND_DB_DSN` through secure operator environment injection, then run:

```bash
uv run --frozen --no-sync python scripts/ingest_grants.py --dry-run
uv run --frozen --no-sync python scripts/ingest_grants.py --apply --confirm-plan <reviewed-plan_sha256>
uv run --frozen --no-sync python scripts/ingest_grants.py --verify
```

Dry-run reads only; with a target configured it reports differences and exits 1
for missing/mismatched required rows. Review the plan before apply. Apply uses
one target transaction, PostgreSQL advisory/table locks, exact user IDs and exact
ingest grants, filtering removed users. Repeating apply is idempotent. Wildcards,
unknown and ambiguous identities fail closed. Extra target grants are reported
but never deleted automatically; `PASS` for required rows does not approve these
extras. Operators must review extras and revoke unwanted grants explicitly.
The plan hash gates application; errors omit DSNs, credentials and SQL details.

The ingest tool writes **only `hawki_backend.dataset_grants`**. Target datasets
must already exist with the exact canonical tenant and matching lifecycle status.
Missing or mismatched inventory fails apply/verify; it is never inserted,
reactivated or repaired by the grant tool. Run the separately reviewed dataset
migration first. Dry-run reports these prerequisites without proposing dataset writes.

Freeze Laravel grant changes, user removal and dataset lifecycle changes during
export, apply, verify and cutover. The source snapshot and target transaction
are separate; advisory locks do not prevent source grant drift. Re-export after
any change and review a new plan. After activation Python grants are authoritative;
keep grant/revocation administration synchronized explicitly. No continuous grant
sync or source fallback is provided.

## Coordinated cutover

1. Keep public ownership at `laravel`. Deploy Python migration 0003, shared volume,
   trusted dataset/model registry, Qdrant credentials, JWT verification and
   [Epoch 8 workflow control](python_workflow_control.md).
2. Configure `HAWKI_BACKEND_TEXT_INGESTION_ENABLED=true`, shared root (default
   `/shared`), Qdrant URL (default `http://qdrant:6333`) and optional API key.
   The event HMAC uses the same `HAWKI_RAG_WORKER_CALLBACK_SECRET` configuration as
   Epoch 8; confirm the actual callback secret agreement in both processes.
   Deploy the matching indexer workers **before accepting Python-owned text**.
   Python payloads require `text_writer_protocol=1`; these workers must use
   `ManagedWriterRegistration` and guarded adapters. Drain older workers and their
   activities before activation; mixed worker versions cannot supply writer proof.
3. Quiesce public direct-text POST/DELETE traffic and drain legacy direct-text
   workflows and pending callbacks. The event routing flag also changes completion
   ownership: do not switch while legacy `ingest-text-*` events are outstanding.
4. Execute the reviewed real grant backfill and verify it under the drift freeze.
   Validate existing direct-text inventory. This epoch does not migrate historical
   Laravel operation rows: Python cannot delete/replay them. Retire/clean up those
   sources through their original owner before cutover, or defer activation until
   an explicitly reviewed inventory transition exists. Shared identity and paths
   must never permit two live owners of the same source.
5. Set all BFF instances together to `TEXT_INGESTION_BACKEND=python`; configure
   `HAWKI_PYTHON_TEXT_INGESTION_URL=http://hawki_backend/v1/text-ingestions`,
   `HAWKI_PYTHON_TEXT_INGESTION_EVENTS_URL=http://hawki_backend/internal/text-ingestion/events`,
   and `HAWKI_PYTHON_TEXT_INGESTION_TIMEOUT=30`. Rebuild Laravel config cache/restart
   application instances through the deployment's normal procedure.
6. Verify POST, replay, completion, DELETE and denial with controlled authorized
   test data before resuming traffic. There is no automatic fallback. Invalid
   selectors fail closed; Python outages surface safe errors.

## Idempotency, artifacts and completion

Raw body limit is 4,300,000 bytes inclusive; text limit is 1,048,576 Unicode
characters inclusive; metadata limit is 65,536 legacy canonical JSON bytes.
Idempotency-Key is required, at most 191 characters, and matches
`[A-Za-z0-9][A-Za-z0-9._:-]*`. Unknown/physical fields return 422; raw oversize
returns 413. Initial acceptance is 202; replay is 200; changed request is 409.
An unconfirmed dispatch returns 502 and must be retried with the **same key**.
Epoch 8 reconciles the exact prepared workflow identity without a second start.
The prepared workflow input and physical collection are persisted with the
operation; configuration drift cannot redirect a replay or deletion. Moving an
existing source to another collection requires verified deletion first.
A simultaneous replay may return this safe unconfirmed response while the first
RPC is in flight; retry after it settles.

Artifacts retain `sources/<source_id>/revisions/<fingerprint>/markdown/document.md`
and `rawki_passthrough.json`, legacy bytes and permissions. Publication uses an
atomic hard link and fails closed on unsupported filesystems. No `chunks.md` is
created by the API. A reconciliation marker identifies write/persistence failures;
retain it for operator reconciliation rather than deleting artifacts blindly.
IngestTextWorkflow remains the selected workflow with `graph=false`; no Neo4j
contribution is introduced.

Worker events remain HMAC signed. Laravel preserves raw bytes and event ID while
re-signing the relay. Python records completion and deduplicates IDs; a different
body with the same ID conflicts. Ready/failed events project Python state; skipped
maps to failed. Non-text events continue through Laravel's existing event service.

## DELETE and interrupted cleanup

Python DELETE resolves an owned source and rechecks its INGEST grant. It refuses
unconfirmed starts. Under the same source/admission lock it selects one immutable
operation snapshot and closes `managed_writer_gate` for all undeleted revisions.
The snapshot freezes tenant/dataset/source/document, operation/workflow/run,
historical collection, artifact path, owner identities and a unique fence identity.
Cleanup targets only Qdrant points matching doc_id, source_id
and dataset_id in the configured collection. Require completed deletion plus an
exact count of zero before removing artifacts and marking the source deleted.
Unconfirmed HTTP responses, including ambiguous 404s, fail closed. No Neo4j
cleanup occurs. Successful repeated deletion is idempotent, including an old
DELETE key retried after re-ingestion. Same-content new keys identify separate
operations after the prior workflow completes; concurrent active owners are
refused. Failed cleanup blocks reuse until cleanup succeeds. A failed worker event also
retains source ownership: it can precede a worker retry, so DELETE must confirm
workflow closure before a new key may reuse that source.

Overlapping deletion is refused even with the same key. A process crash that
leaves `deleting` deliberately blocks automatic reuse: first stop/drain the
original cleanup process and verify its workflow is closed. Then, in an audited
operator transaction on the exact tenant/source/operation, move `deleting` to
`delete_failed` and retry DELETE. Never release ownership based only on elapsed
time, or mark deleted without verifying vectors and artifacts. This prevents a
delayed cleanup from erasing newly ingested content.

Direct text reuses the existing `/managed-writers` signed acquire/release protocol,
`ManagedWriterRegistration`, and registered Qdrant adapter. Laravel relays
`ingest-text-*` callbacks to Python `/internal/text-ingestion/writers` without
recording a Laravel writer. Python stores `managed_writers` and gate evidence on
its operation. Each new sink call rechecks admission; already-admitted calls retain
their writer until local in-flight calls drain and release is acknowledged. A
released adapter closes permanently, and a source fence rejects reacquisition.

Workflow cancellation/closure is **not** drainage proof. DELETE cannot clean vectors
until every admitted writer on every selected revision is acknowledged released.
Missing registration evidence, active writers, or uncertain acknowledgements leave
`delete_failed` and block re-ingestion. Retry DELETE after confirmed release;
crashed/uncertain writers require audited reconciliation after the original process
and sink calls have been drained. Timeouts never count as release. Artifact removal
and final publication recheck the same snapshot under the source lock; stale
operation, target, path or fence evidence fails closed. No network call holds that
transaction open.

The H1 fault reproduction used isolated Temporal/Qdrant containers: an admitted
request was delayed, Temporal reported CANCELED, old DELETE verified zero and
marked deleted, then the delayed request created an owned point. The regression
now exercises the real indexer transport, guarded adapter, signed Python receiver,
Temporal cancellation and Qdrant: DELETE blocks while that request remains active,
then removes its point only after acknowledged release.

JSON parity tests execute the actual PHP reference for control characters, DEL,
Unicode, numeric/array shapes and key ordering. Fingerprints use PHP's default
slash escaping; metadata sizing and passthrough use `JSON_UNESCAPED_SLASHES`.
DEL remains literal while non-ASCII characters retain PHP's escaped encoding.

## Rollback

Quiesce direct-text POST/DELETE traffic and drain Python-owned workflows and event
relays before changing flags. Retain Python ownership until its sources have been
cleaned up or explicitly migrated; switching the flag does not transfer ledger
rows or callback ownership. Never blindly replay Python operation keys through
Laravel. Preserve shared artifacts and all Python ledgers. After the inventory
transition and grant drift checks, switch all BFF instances back to `laravel`
together, rebuild config, verify legacy behavior, then resume traffic. Do not
roll back schema tables while Python state or callbacks remain live.
