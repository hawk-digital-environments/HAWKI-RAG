# Managed-document deletion

Managed deletion uses Laravel's durable `managed_document_deletions` record,
`DeleteManagedDocumentWorkflow`, and indexer activities. The bridge still does
not expose `DELETE /documents/{doc_id}` or graph mutation routes.

## Execution and recovery

1. `DELETE /api/documents/{documentId}` requires Sanctum authentication and an
   ingest grant on the managed document's dataset. PUT uses the same authority.
2. Laravel locks the document, synchronizes indexed-output metadata, verifies
   source ownership across every owned ingestion source, restores missing output
   projections from the durable writer target registry, and freezes all unverified
   output IDs, document/source/dataset identity, historical physical collection,
   graph namespace, and every recorded ingestion execution. Active rows alone do
   not establish the complete cleanup scope.
   The operation and source deletion tombstones are committed before dispatch.
3. Laravel signs the exact JSON body sent to
   `/temporal/workflows/delete-managed-document`. The workflow ID is
   `managed-{operation_id}`. An uncertain start remains durable and can be
   redispatched with the same ID. No output becomes inactive on a start receipt.
4. Laravel first requires complete target evidence and released writer permissions.
   The indexer then verifies every recorded Temporal execution has closed, deletes Qdrant points using
   document **and** source **and** dataset filters in the recorded collection,
   and checks an exact remaining count of zero. A count/transport failure is an
   error; an explicitly missing collection is empty.
5. Neo4j removes that document's provenance within the recorded dataset and
   namespace. Removing references, deleting unsupported edges/isolated entities,
   and verifying zero remaining contributions use one Neo4j transaction. Shared
   provenance and unrelated legacy orphan edges are retained.
6. The worker sends a signed receipt to
   `/api/internal/pipeline/managed-deletion-events`. Laravel validates the frozen
   output IDs and vector/graph proof before atomically deactivating those rows and
   marking a delete completed. Duplicate and late receipts cannot undo completion
   or touch replacement outputs. Missing metadata rows are already inactive;
   changed row scope is rejected.

A normal pending request returns **202**; verified deletion returns **200**.
Startup uncertainty or a known workflow failure returns **502** with a recoverable
operation identity. Authorization/ownership or conflicting-operation failures
return **401**, **404**, or **409** as appropriate. Repeat the original DELETE to
recover. Activity retries retain acknowledged sink results in Temporal history;
restarting a failed workflow under the same ID safely rechecks already-cleaned
sinks. Completed workflow IDs are reused, rather than creating another deletion.

## Managed replacement compatibility

PUT retains **delete old outputs before replacement** behavior. It waits for
verified cleanup before starting the replacement upload. This is not staged
revision publication: the document can have no indexed content between cleanup
and successful replacement ingestion.

Replacement upload eligibility is checked before deletion preparation. Laravel
then stores the file and checksum, deterministic replacement task/source/job,
and immutable workflow input in a SQL transaction. That transaction commits
before cleanup dispatch. Replacement dispatch occurs only after verified cleanup,
outside any SQL transaction; its acknowledgement is recorded separately.

Keep the original bytes, input fields and `Idempotency-Key`; the existing PUT
contract requires resubmitting them to resume a pending operation. Execution uses
the already persisted file and frozen workflow input, so the original temporary
upload can disappear. A missing or changed durable file is checked before startup.
If startup was never attempted, the operation becomes `abandoned`, permitting
corrected input with a new key or authorized DELETE. If startup was attempted,
its outcome must be reconciled or the original durable bytes restored; changing
intent would risk an untracked writer. A failed ingestion after an accepted start
requires writer reconciliation before another replacement.

A crash after SQL commit leaves durable preparation available for the same PUT.
A lost Temporal acknowledgement leaves the same deterministic workflow ID for
redispatch, with signed `reuse_completed` preventing duplicate completed runs.
An immediate writer callback can adopt the actual run before the HTTP start
acknowledgement. No in-memory after-commit callback is required. Deletion failures
remain terminal within the same run; only acknowledgement of a new run permits
pending recovery. Late callbacks cannot reopen an abandoned operation.

New managed uploads include their managed owner in source identity so identical
files uploaded as separate documents cannot overwrite each other's ownership or
reuse a deleted source's tombstone. Existing source IDs are preserved.

Both initial creation paths now commit the managed owner, task/source/job and
frozen dispatch input in one SQL transaction before starting Temporal. The
prepared task identity is derived from the managed owner. A process crash at
this boundary leaves `managed_dispatch.attempted=false`; the committed task can
be resumed through `PipelineUploadService::dispatchPreparedManagedUpload()`
without the original temporary upload. This is a durable redispatch entry point,
not an automatic background dispatcher. Lost startup acknowledgements retain the
same task and workflow identity.

Replacement startup remains outside SQL. Its final document publication and
`continued` transition now share one transaction with document/operation locks.
Publication checks the current blocking operation, source/task/job generation
and deletion tombstone. A duplicate continuation returns its recorded result
without publishing again, including after a newer DELETE completes.

## Writer permissions and historical recovery

`POST /api/internal/pipeline/managed-writers` requires the same exact-body,
time-bounded HMAC middleware as worker events. It is an internal capability:
only trusted workers possessing the shared secret may call it. Source ownership,
dataset, committed job/task/workflow identity and actual Temporal run are checked
against Laravel state. Activity ID and attempt are retained in the writer ledger.

The real index activity acquires permission before creating indexing dependencies,
then registers each batch's canonical document IDs and relative paths before any
owned vector or graph mutation. Collection and namespace come from frozen ingestion
input. A successfully completed index activity releases its permission. There is
no finally-based release, expiry, or release based on workflow cancellation.
Transport retries on canonical managed sink writes are disabled, and even a
swallowed ambiguous sink error prevents release. Automatic retries and recovery
runs have distinct entries; historical entries and run IDs are never discarded.
Recovery requires released permissions and closed historical runs, reserves its
new identity in SQL, and retains the original sink settings.

Batch requirements are resolved before target registration. Converter passthrough
can enable canonical graph indexing despite the original `graph=false`; that
target therefore records the frozen graph namespace and requires Neo4j cleanup.
Registration returns acknowledged target grants. The worker checks their
document/source/dataset/collection/namespace identities before using them.
Registered adapters restrict collection selection, vector ownership and point
identity, payload mutations, and graph document/namespace scope. Earlier graph
ownership can expand from vector-only to graph-enabled, and cannot be downgraded.

Historical vector-only targets with a configured namespace but no effective
graph evidence fail closed. Old frozen snapshots with that ambiguity cannot
dispatch or accept completion; reconcile and retire them before freezing a new
operation. New operations record `attempts.effective_graph_scope=true` after
validating their targets. No schema migration is required for this JSON evidence.

Deletion takes the document/source locks before its snapshot and fences future
acquisitions. Acquisition takes the document lock before job/source locks because
output insertion also takes a document foreign-key lock. Release uses job/source
locks without needing the document lock. PostgreSQL tests verify both lock orders.
These permissions establish a barrier for cooperating canonical writers, **not a
sink-native fence** against untracked or old worker binaries.

Missing protocol evidence, unknown run IDs, incomplete targets, unreleased writers,
or ambiguous historical scope fail closed. For legacy or crashed writers, first
independently establish physical quiescence: stop every relevant worker process,
close all ingestion runs, disable schedules and drain in-flight sink requests.
Construct a complete manifest from durable converter/indexing artifacts and sink
ownership evidence, including every artifact from original and recovery attempts.
Never infer old collection or namespace from current dataset settings.

Only then run the local administrative command:

```sh
php artisan documents:reconcile-writer SOURCE_ID /shared/complete-owned-manifest.json --confirm-quiescence
```

The manifest is a JSON list of `document_id` and `relative_path` records. Each ID
must be `doc_` plus the first 40 hex characters of SHA-256 of
`source_id + "|" + relative_path`. Paths must be canonical relative paths, and the
manifest must reside under configured shared storage. The command validates
historical scope and closed recorded runs, commits a permanently closed writer
gate even when later validation fails, and records the manifest hash. It refuses
unknown runs or missing scope. The flag is an operator attestation, **not automatic
proof that remote writes have drained or that a legacy manifest is complete**.
An empty legacy manifest must be independently proven complete; never invent it
to bypass missing evidence. The closed gate permits cleanup, not recovery writing
to that same source; replacement uses a new source identity.

For historical `graph=false` ingestion with a configured namespace, each manifest
record must also include an explicit boolean `graph` established from converter
and sink evidence. Use `true` if passthrough or another effective requirement
could publish canonical contributions. This retains the original namespace;
the flag cannot supply a different storage scope.

Operations created before this writer protocol cannot redispatch or accept a
completion callback using their old frozen snapshot. After reconciling **every**
owned source and closing the old deletion run, retire that legacy operation with:

```sh
php artisan documents:reconcile-writer SOURCE_ID /shared/complete-owned-manifest.json --confirm-quiescence --retire-operation=OPERATION_ID
```

Then resubmit the authorized operation with a new key to freeze and verify the
complete target list. Retirement requires a confirmed old deletion run. Already
soft-deleted legacy documents and missing run/scope records require an audited
operator repair of their historical state; this command does not automatically
undo a completed document deletion or guess missing execution identities.

## Deployment

- Apply `database/migrations/2026_10_09_000000_create_managed_document_deletions_table.php`
  and the additive
  `database/migrations/2026_10_12_000000_add_managed_deletion_recovery_state.php`
  migration with the normal Laravel migration process before serving these APIs.
  The latter adds nullable replacement/attempt state and preserves existing rows.
- Build/deploy the Laravel app, bridge, workflow worker, and indexer worker from
  the same checkout. Restart the workers so the new workflow and four activities
  are registered. Existing ingestion workflow histories are unchanged; deletion
  is a new workflow type and its history replay was checked.
- Configure the same nonempty `HAWKI_RAG_WORKER_CALLBACK_SECRET` in Laravel,
  the bridge, and indexer. Keep Temporal namespace and activity queues aligned.
  The indexer callback URL must point to Laravel's worker-events route; the managed
  deletion and writer-registration callbacks are sibling routes.
- Stop/drain old indexer processes before enabling deletion. Verify all mutation
  paths use the registered writer adapters; reconcile legacy and interrupted
  sources, including any pre-protocol deletion operations. Rolling deployment
  with an untracked old writer cannot establish quiescence.
- Drain old indexers before deploying these scope changes. Deploy Laravel's grant
  response and the signed bridge startup contract before starting new indexers.
  New workers fail closed if an older Laravel response omits target grants.
  Reconcile ambiguous pre-fix targets and retire their frozen snapshots before
  enabling deletion. Temporal workflow commands and ingestion histories were not
  changed by these final safety fixes.
- Ensure managed PUT/DELETE callers have authentication and dataset ingest grants.
- No production migration, worker deployment, or automatic commit was performed
  during verification.

## Disposable live test

The Compose fixture uses an independent project/network, test credentials, and
loopback ports. It has no deployment mounts, env files, or external networks.
The live test requires both opt-in flags and explicit service endpoints; it never
falls back to deployment URLs. Qdrant cleanup names exactly one UUID-owned test
collection. Neo4j cleanup matches only test-owned datasets and exact namespaces.
The signed callback receiver binds to localhost and never calls deployed Laravel.

From the repository root:

```sh
docker compose -p rawki-managed-deletion-test -f python_rag/services/hawki_indexer_worker/tests/integration/compose.managed-deletion-test.yml up -d --pull never
```

After the disposable services are ready, from `python_rag/`:

```sh
RAWKI_MANAGED_DELETION_LIVE=1 \
RAWKI_MANAGED_TEST_DISPOSABLE=1 \
RAWKI_MANAGED_TEST_QDRANT_URL=http://127.0.0.1:16333 \
RAWKI_MANAGED_TEST_NEO4J_URI=bolt://127.0.0.1:17687 \
RAWKI_MANAGED_TEST_NEO4J_PASSWORD=disposable-test-password \
RAWKI_MANAGED_TEST_TEMPORAL_ADDRESS=127.0.0.1:17233 \
.venv/bin/python -m pytest services/hawki_indexer_worker/tests/integration/test_managed_deletion_live.py services/hawki_indexer_worker/tests/integration/test_managed_writer_live.py -q -s
```

Remove only that test project from the repository root:

```sh
docker compose -p rawki-managed-deletion-test -f python_rag/services/hawki_indexer_worker/tests/integration/compose.managed-deletion-test.yml down --volumes
```

## Verification results

- Full Laravel suite with both PostgreSQL opt-ins enabled: **485 passed**, **0 failed**, **0 skipped**, **2962 assertions**. Includes **12** managed PostgreSQL transaction/race/migration tests and **8** existing PostgreSQL upgrade tests.
- Full Python non-integration suite: **573 passed**, **0 failed**, **14 integration tests deselected**. Includes **7** PHP-to-FastAPI deletion contract cases, also run separately with **7 passed**.
- Explicit disposable live run: **5 passed**, **0 failed** (three live scenarios and two configuration-safety checks). The three managed live cases are among the 14 deselected above; the other 11 live/model integration cases were not executed.
- Branch-file Pint and PHP syntax (**38 files**), Ruff lint/format and Python AST syntax (**26 files**), and patch whitespace checks pass.

- The isolated PostgreSQL suite uses an explicit disposable service on loopback
  port 15432, a new UUID schema per test and independent PHP/PDO processes. It
  verifies preparation visibility, rollback, crash recovery, immediate callback
  before lost acknowledgement, callback/status races, additive migration,
  acquisition/deletion fencing and release without document-lock deadlock.
  It also verifies committed ownership before immediate registration through both
  creation APIs, process exit after preparation with subsequent redispatch, and
  duplicate PUT continuations separated by a completed DELETE.
- Disposable live testing uses actual Qdrant, Neo4j and Temporal. It covers scoped
  cleanup, shared provenance, partial cleanup, lost sink/callback acknowledgements,
  failed-run recovery, worker restart and deletion history replay. A separate fault
  harness invokes the real registered index activity but injects its indexing
  body: an actual Qdrant write occurs after Temporal reports cancellation, while
  writer permission stays active. Its signed receiver is isolated; Laravel's
  fail-closed behavior is verified separately against PostgreSQL/feature tests.
- A third disposable scenario runs real batch preparation, signed target grants,
  registered canonical Neo4j publication and scoped graph cleanup for vector-only
  passthrough. Its indexing body supplies deterministic triplets instead of model
  extraction; shared provenance remains after the owned contribution is removed.
- Three Pydantic sandbox import warnings occur during replay. The cancellation
  fault also emits the expected Temporal late-activity-completion warning.
- There is no claim that mocked HTTP tests are live integration tests.

## Limits and release gate

The writer protocol cannot automatically prove physical quiescence of old,
unregistered processes or remote writes after a crash. Such sources remain
blocked until independently verified administrative reconciliation. Correctness
of a legacy complete manifest and physical drain is an external prerequisite,
not something the CLI flag proves. Operations support at most 1000 targets and
at most 1000 recorded activity attempts per source. Unknown or overwritten legacy
ownership/scope requires an audited repair rather than broad sink deletion.

Sink verification and Laravel state transitions span independent databases;
retries and tombstones provide recovery rather than a distributed transaction.
A production Laravel HTTP → bridge → Temporal → indexing → callback round trip
was not exercised as one end-to-end deployment. PostgreSQL concurrency checks
use real Laravel requests with mocked bridge responses; live sink/Temporal checks
use an isolated signed callback receiver. Model-dependent conversion and unrelated
live ingestion integrations were not run.

The branch requires independent re-review. Deployment quiescence and legacy
ownership audits remain release prerequisites even when automated tests pass.

## Exact changed-file inventory

Compared with `development`, including existing changes and untracked files: 71 files.
The active branch is `fix/yazdan/managed-document-deletion-workflow`; it was not renamed.

```text
app/Console/Commands/ReconcileManagedDocumentWriterCommand.php
app/Http/Controllers/Document/ManagedDocumentDeletionEventController.php
app/Http/Controllers/Document/ManagedDocumentWriterController.php
app/Http/Controllers/Document/UnifiedDocumentController.php
app/Http/Requests/Document/DeleteManagedDocumentRequest.php
app/Http/Requests/Document/ManagedDocumentDeletionEventRequest.php
app/Http/Requests/Document/ManagedDocumentWriterRequest.php
app/Http/Requests/Document/MutateManagedDocumentRequest.php
app/Http/Requests/Document/UpdateManagedDocumentRequest.php
app/Models/ManagedDocumentDeletion.php
app/Services/Document/Clients/ManagedDocumentBridgeClient.php
app/Services/Document/Exceptions/ManagedDeletionException.php
app/Services/Document/ManagedDocumentOutputDeletionService.php
app/Services/Document/ManagedDocumentRecoveryGuard.php
app/Services/Document/ManagedDocumentService.php
app/Services/Document/ManagedDocumentSyncService.php
app/Services/Document/ManagedDocumentSyncStateResolver.php
app/Services/Document/ManagedDocumentWriterReconciliationService.php
app/Services/Document/ManagedDocumentWriterService.php
app/Services/Document/Repositories/ManagedDocumentDeletionRepository.php
app/Services/Document/Repositories/ManagedDocumentOutputRepository.php
app/Services/Document/Repositories/ManagedDocumentWriterRepository.php
app/Services/Pipeline/Clients/PythonTemporalBridgeClient.php
app/Services/Pipeline/Recovery/PipelineRecoveryAttemptService.php
app/Services/Pipeline/Repositories/IngestionSourceRepository.php
app/Services/Pipeline/Tasks/PipelineTaskRetryService.php
app/Services/Pipeline/Uploads/PipelineUploadService.php
database/migrations/2026_10_09_000000_create_managed_document_deletions_table.php
database/migrations/2026_10_12_000000_add_managed_deletion_recovery_state.php
docs/managed-document-deletion.md
python_rag/packages/contracts/src/hawki_rag_contracts/pipeline/deletion.py
python_rag/packages/graph_store/src/hawki_graph_store/graph.py
python_rag/packages/vector_store/src/hawki_vector_store/client.py
python_rag/services/hawki_bridge/src/hawki_bridge/adapters/temporal_client.py
python_rag/services/hawki_bridge/src/hawki_bridge/http/control_signature.py
python_rag/services/hawki_bridge/src/hawki_bridge/http/routers/temporal.py
python_rag/services/hawki_bridge/src/hawki_bridge/http/schemas.py
python_rag/services/hawki_bridge/src/hawki_bridge/settings.py
python_rag/services/hawki_bridge/tests/contract/http/test_managed_deletion_api.py
python_rag/services/hawki_bridge/tests/unit/test_behavior.py
python_rag/services/hawki_bridge/tests/unit/test_managed_deletion_temporal.py
python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/activities/deletion.py
python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/activities/index.py
python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/adapters/managed_writer.py
python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/adapters/neo4j_writer.py
python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/adapters/qdrant_writer.py
python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/application/index_execution.py
python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/domain/ports.py
python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/indexing/batch_execution.py
python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/indexing/managed_deletion.py
python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/main.py
python_rag/services/hawki_indexer_worker/tests/contract/test_boundaries.py
python_rag/services/hawki_indexer_worker/tests/integration/compose.managed-deletion-test.yml
python_rag/services/hawki_indexer_worker/tests/integration/test_managed_deletion_live.py
python_rag/services/hawki_indexer_worker/tests/integration/test_managed_writer_live.py
python_rag/services/hawki_indexer_worker/tests/unit/activities/test_managed_deletion_writers.py
python_rag/services/hawki_indexer_worker/tests/unit/activities/test_managed_writer_registration.py
python_rag/services/hawki_indexer_worker/tests/unit/indexing/test_managed_deletion.py
python_rag/services/hawki_workflow_worker/src/hawki_workflow_worker/main.py
python_rag/services/hawki_workflow_worker/src/hawki_workflow_worker/workflows/__init__.py
python_rag/services/hawki_workflow_worker/src/hawki_workflow_worker/workflows/delete_managed_document.py
python_rag/services/hawki_workflow_worker/tests/unit/test_managed_deletion_workflow.py
routes/api.php
tests/Feature/Database/ManagedDeletionPostgresTest.php
tests/Feature/Documents/DocumentUnifiedApiTest.php
tests/Feature/Documents/ManagedDocumentDeletionTest.php
tests/Feature/Documents/ManagedDocumentWriterTest.php
tests/Feature/Pipeline/PipelineControllerDashboardTest.php
tests/Support/ManagedWriterFixture.php
tests/Support/managed_deletion_wire.php
tests/System/PdfIngestionFlowTest.php
```
