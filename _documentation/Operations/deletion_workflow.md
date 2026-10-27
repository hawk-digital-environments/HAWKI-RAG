# Deletion Workflow

How content disappears from HAWKI-RAG: what becomes unretrievable when, who
performs the physical cleanup, and how failures recover. Deletion is
**logical-first, physical-second**: Laravel metadata hides content immediately;
durable Temporal orchestration removes and verifies sink data in the
background.

:::warning Nothing is ever bulk-deleted from the sinks

All sink mutations go through scoped, verified, per-target operations.
Whole-dataset deletion is a loop over per-document operations plus final
read-only verification. Never delete a Qdrant collection or Neo4j namespace
to "clean up" a dataset: collections and namespaces can be shared, and shared
graph provenance must survive.

:::

## Principles

1. **Laravel owns application state.** Dataset/source/document/output rows and
   their statuses are the source of truth. Workers never write application
   SQL; they report through signed callbacks
   (`/api/internal/pipeline/worker-events`, `/managed-deletion-events`,
   `/managed-writers`).
2. **Temporal owns sink orchestration.** `DeleteManagedDocumentWorkflow`
   retries sink mutations durably; activities verify writers drained before
   deleting and verify zero remaining data after.
3. **Fail closed.** Missing ownership evidence, unreleased writers, or
   ambiguous historical scope block deletion with 409 instead of guessing a
   cleanup scope.
4. **Idempotent everywhere.** Repeat DELETE requests reuse the same operation
   and workflow identity; duplicate or late receipts cannot undo completion.

## Retrieval gating (why deleted content stops being findable)

Archived/deleted state is enforced **at the Laravel authorization layer**, not
by marking datapoints in the sinks:

| Level | Gate | Effect |
|---|---|---|
| Whole dataset | `datasets.status` (`active` / `archived` / `deleted`); query and ingestion grants require `active` | Archived dataset is instantly unqueryable, uningestible, and hidden from dashboards — while its points still physically exist |
| Managed document | Points remain queryable until **verified physical cleanup completes** (the 202 → 200 window) | See [known gaps](#known-gaps) |
| Direct-text source | Same window as documents, but deletion is synchronous (200), so it is usually immediate | — |

There is deliberately **no `archived` flag inside Qdrant or Neo4j**: Neo4j
contributions are reference-counted on shared entities and relationships
(`doc_ids`), and query-path flag filtering would complicate the provenance
model. The sink contract is *physically clean, verified*: either a scope has
a verified remaining count of zero, or it is untouched.

## Path A: Managed document (a dataset entry)

`DELETE /api/documents/{documentId}` (Sanctum token plus dataset ingest
grant), optional `Idempotency-Key`. Responses: **202** pending, **200**
verified complete, **502** startup/failure uncertainty, **409** conflict.
Repeat the DELETE to resume the same operation.

```text
prepare()                              dispatch()                 applyReceipt()
┌──────────────────────────────┐  ┌─────────────────────────┐  ┌──────────────────────────┐
│ lock document                │  │ bridge (signed HTTP)    │  │ worker → signed callback  │
│ cancel owned active sources  │  │ → /temporal/workflows/  │  │ /internal/pipeline/       │
│ freeze immutable scope:      │  │   delete-managed-       │  │   managed-deletion-events │
│  targets (output/doc/source/ │  │   document              │  │ verify per-target proof:  │
│  collection/namespace) +     │  │ workflow id:            │  │  qdrant.verified=true,    │
│  writers (workflow/run ids)  │  │ managed-{operation_id}  │  │  remaining_points=0;      │
│ commit tombstones BEFORE any │  │ uncertain start stays   │  │  neo4j.verified=true,     │
│ network call                 │  │ pending, redeliverable  │  │  remaining_contributions  │
│  → status: pending           │  │  → status: pending/     │  │  =0, exact scope match    │
│                              │  │    failed               │  │ then atomically: outputs  │
│ document.status=deleting     │  │                         │  │ deactivated, sources      │
│ sources.index_status=deleting│  └─────────────────────────┘  │ DELETED, document         │
└──────────────────────────────┘                                │ deleted_at set → 200      │
                                                                └──────────────────────────┘
```

### The Temporal workflow

`hawki_workflow_worker/.../workflows/delete_managed_document.py`, all
activities on the indexer queue: five-minute start-to-close, one-hour
schedule-to-close, five attempts.

1. `verify_managed_deletion_writers` — every recorded ingestion execution
   (the frozen `writers[]`) must be in a terminal Temporal state. Outputs are
   never cleaned while their writer may still be running.
2. Per target: `delete_managed_vectors` — Qdrant delete-by-filter
   (`doc_id` + `source_id` + `dataset_id`, idempotency key
   `{operation_id}:{output_id}:vector`), then a **verified remaining count of
   zero**. A transport failure is an error; an explicitly missing collection
   counts as empty.
3. Per target: `delete_managed_graph` — Neo4j `delete_scoped_document` in one
   transaction: prune the document from shared relationships' `doc_ids`,
   delete a relationship only when its reference list empties, remove
   unsupported edges and isolated entities, and verify zero remaining
   contributions in the recorded dataset and namespace. Shared provenance
   from other datasets and documents is retained.
4. `report_managed_deletion` — signed receipt to Laravel (a separate activity,
   so delivery retries never repeat acknowledged sinks). On activity failure
   the workflow reports `failed` and raises non-retryable
   "Managed deletion requires recovery."

### Writer permission ledger

The real index activity acquires permission via
`POST /api/internal/pipeline/managed-writers` (the same exact-body,
time-bounded HMAC as worker events) before any sink mutation, registers each
batch's canonical targets, and releases only on confirmed completion — no
`finally` release, no expiry, no release on workflow cancellation. Deletion
requires every ledger entry to be `released`. This is a barrier against
cooperating canonical writers, **not** a sink-native fence against untracked
legacy worker binaries; those require
[`documents:reconcile-writer`](#recovery-matrix).

### Replacement (PUT)

`PUT /api/documents/{documentId}` reuses this path: upload eligibility is
checked first, the replacement is durably prepared (SQL commit before any
dispatch), the old outputs must reach verified `completed` cleanup, and only
then is the replacement dispatched (`continueUpdate`). A replacement that was
never started can be `abandoned`, permitting corrected input with a new
`Idempotency-Key` or an authorized DELETE. A failed replacement ingestion
after an accepted start requires writer reconciliation before retry.

## Path B: Whole dataset

`DELETE /api/datasets/{datasetId}/storage` returns **202 immediately** without
contacting Temporal or the sinks.

```text
active ──DELETE──▶ archived ──scheduler──▶ (per-document Path A) ──verify──▶ deleted (tombstone)
                     │      every minute      all documents + sources DELETED
                     │ instantly:              + Qdrant count(dataset_id) = 0
                     │ unqueryable, no         + Neo4j nodes/rels(dataset_id, namespace) = 0
                     │ ingestion, retries,
                     │ or grants
                     └─ re-upload / create allowed only after `deleted`
```

- The `dataset-cleanup` schedule (`bootstrap/app.php`) runs
  `DatasetService::resumeArchivedDeletions()` **every minute** with
  `withoutOverlapping(5)`. It requires `php artisan schedule:work`; the app
  image runs it under Supervisor
  (`docker/rag_app/supervisor/dataset-cleanup.conf`).
- The scan **reuses Path A** for every undeleted managed document. It never
  starts replacements in an archived dataset; pending replacement intent is
  abandoned after verified cleanup. Pending or failed operations simply retry
  on the next scan, including after restarts; errors are logged with the
  dataset ID.
- The final step (`DatasetStorageCleanupService`) only **verifies** — all
  mutations already happened through Path A — then flips `archived` to
  `deleted`. The row remains as a tombstone; a later creation or upload with
  the same dataset identity reactivates it. Archived datasets can never be
  reactivated while cleanup is pending. Repeated DELETE is idempotent.

## Path C: Direct-text ingestion source

`DELETE /api/integrations/text-ingestions/{sourceId}` (token plus ingest
grant, idempotent). Synchronous, Laravel-driven, vector-only:

1. Mark the source `deletion_requested`. If a workflow is still open, the
   bridge's `/temporal/workflows/cancel-and-wait` establishes closure first,
   so late indexing cannot recreate vectors.
2. Laravel deletes the source's Qdrant points directly (filter `doc_id` +
   `source_id` + `dataset_id`, `wait=true`; a missing collection counts as
   clean).
3. Delete the shared-storage artifacts under `sources/{sourceId}/` and mark
   the source `deleted` (the audit row is retained).

Differences from Path A: no Temporal deletion workflow, no writer ledger, and
no post-delete remaining-count verification. That is acceptable today because
direct text writes vectors only. **If direct text ever gains graph output,
this path must adopt Path A's verified scoped deletion.**

## State model (Laravel metadata only)

| Table | Field | Values |
|---|---|---|
| `datasets` | `status` | `active` → `archived` (DELETE accepted) → `deleted` (verified clean; tombstone) |
| `managed_documents` | `status` / `deleted_at` | `ready` → `deleting` → `deleted` (timestamp set on verified completion) |
| `ingestion_sources` | `index_status` | `pending`/`running` → `cancelled`/`deleting` → `deleted`, with `metadata.managed_deletion.{operation_id,status}` tombstone |
| `managed_document_outputs` | `deactivated_at` | set only after verified sink cleanup |
| `managed_document_deletions` | `status` | `preparing`/`pending`/`failed`/`completed`/`continued`/`abandoned`, frozen `workflow_input` (targets + writers), `attempts` run ledger |

Qdrant and Neo4j carry **no archived/deleted markers**; a scope is either
physically clean (verified count of zero) or untouched.

## Recovery matrix

| Symptom | Where | Action |
|---|---|---|
| 202 pending, no error | operation `pending` | Wait; the scheduler or the next DELETE re-dispatches the same workflow ID |
| 502 startup unconfirmed | `last_error`, `attempts.runs` | Repeat DELETE/PUT; the deterministic workflow ID redelivers, and signed `reuse_completed` prevents duplicate completed runs |
| Workflow `failed` | operation `failed` | Repeat the original request — a new run may recover; activity retries keep acknowledged sink results in Temporal history |
| 409 "writer evidence missing / unreleased" | writer ledger | Stop and reconcile: `php artisan documents:reconcile-writer {sourceId} {manifest.json} --confirm-quiescence`. The operator attests physical quiescence (all relevant workers stopped, runs closed, schedules disabled, sink requests drained); the manifest is a JSON list of `{document_id, relative_path}` records under shared storage, with an explicit `graph` boolean for historical graph-enabled records. Legacy frozen operations additionally need `--retire-operation={id}` after every owned source is reconciled and the old deletion run is closed |
| Dataset stuck `archived`, 409 "unverified or untracked writers" | scheduler log | Usually a non-managed source (for example direct text) that is not yet `DELETED` — delete it through its own API and the scan proceeds; or an unreleased writer → reconcile |
| Crash mid-scan | schedule overlap lock | The lock expires within five minutes; the next scan resumes |

Limits: at most 2000 outputs per deletion operation and at most 1000 recorded
activity attempts per source. Verification spans independent stores (Postgres,
Qdrant, Neo4j, Temporal): tombstones and retries provide recovery, not a
distributed transaction.

## Known gaps

1. **Document-level retrieval window.** Between the 202 acceptance and
   verified completion, a pending-deletion document remains retrievable:
   queries filter by dataset, and there is no per-document gate. Possible
   mitigations: a query-time exclusion list fed from
   `managed_document_deletions`, or accepting and documenting the window.
2. **Direct-text sources are not auto-cleaned by dataset archive.** They block
   finalization with 409 until individually deleted through the integrations
   API (which still works on an archived dataset).
3. **Direct-text deletion bypasses the writer protocol** (see Path C).
4. **Dataset cleanup needs the scheduler running.** Without
   `php artisan schedule:work`, archived datasets stay archived silently.
   Confirm `php artisan schedule:list` includes `dataset-cleanup`.
5. **Partial deletion is not generation-based.** The planned generation
   model (see [future deletion architecture](#future-deletion-architecture))
   is whole-dataset only; deleting a document or source inside the active
   generation remains in-place scoped deletion (Path A). That is a permanent
   design element, not a gap to close.
6. **Graph generations are property-based.** Neo4j Community runs a single
   database, so per-generation isolation is a versioned `neo4j_namespace`
   property (plus index), not a separate graph per generation; teardown is
   `DETACH DELETE` by property with verified zero counts. A store with
   native graph-per-generation (FalkorDB) would map generations to graph
   keys instead. Today the namespace is fixed at dataset creation.

## Future deletion architecture

Agreed target design for dataset deletion when the control plane moves to
Python. Nothing here is implemented yet; the paths above remain
authoritative. Interim work (replacing the scheduler loop with a Temporal
dataset-deletion workflow) is deliberately structured so this drops in
without another redesign.

### Core idea

Never delete dataset storage in place. Retire a generation, create a fresh
one on demand, and delete old physical resources asynchronously.

### The registry is the switch

The Postgres `datasets` row carries an `active_generation`. Every query,
ingestion, and deletion path resolves
`dataset → active generation → physical resource names` through this row —
there is no other resolver. Retired and `creating` generations fail closed.
Retiring a generation and blocking access happen in **one transaction**
(row lock on `dataset_id`, unique `(dataset_id, generation)`), so concurrent
deletes and resets cannot interleave.

### Generation-specific resources

| Store | Per generation | Teardown | Aliasing |
|---|---|---|---|
| Qdrant | one collection per generation (`hawki_{safe}_g{n}`) | `DELETE /collections/{gen}`, verified via GET → 404 | Optional — the registry is the resolver |
| Neo4j Community | versioned `neo4j_namespace` property (plus index) | `DETACH DELETE` by property, verified count of zero | None — single database |
| FalkorDB (if ever adopted) | one graph key per generation | `GRAPH.DELETE {gen}` — whole-graph drop | None — FalkorDB has no alias mechanism |

Aliasing (Qdrant only) is deliberately **not** load-bearing: a two-store
alias swap would be two atomic operations that must commit together,
reintroducing exactly the cross-store atomicity problem the registry exists
to eliminate. Physical resource names stay opaque and backend-only.

### Delete and reset

- `delete`: one Postgres transaction retires the current generation — the
  dataset then resolves to nothing, blocking retrieval and new writes at
  once. A background Temporal workflow tears the old resources down
  idempotently, with the verified-zero checks used throughout this document.
- `reset` (= delete + recreate): the retire transaction plus a create
  transaction that provisions empty resources for generation *n+1* and marks
  them `creating` until both stores are ready, then `ACTIVE`. Recreation
  never waits for physical cleanup of the old generation.

### Late writers

Ingestion is pinned to the resolved physical names at dispatch time (the
codebase already freezes per-target collection and namespace). A job that
writes into a retired generation terminates as `discarded/superseded` —
never retried forever, never reported as success. Its writes are destroyed
with the generation: generations make late writes self-cleaning.

### What does not change

- **Partial deletion stays in place.** Generations are whole-dataset;
  document- and source-level deletion inside the active generation remains
  the scoped, verified Path A.
- Fail-closed evidence rules and verified-zero teardown checks.
- Retrieval denial remains application-level. The registry is the gate; the
  stores only hold data.

### Migration

Adopt-then-version: today's `qdrant_collection` / `neo4j_namespace` columns
become generation-1 resource pointers; the first reset creates `_g2`. No
backfill of sink data is required.

## Where to look

- `app/Services/Document/ManagedDocumentOutputDeletionService.php` —
  prepare / dispatch / applyReceipt, writer-release checks, replacement
- `app/Services/Dataset/DatasetService.php` — archive, background resume,
  finalization; `DatasetStorageCleanupService.php` — final verification only
- `app/Services/TextIngestion/TextIngestionDeletionService.php` — Path C
- `python_rag/services/hawki_workflow_worker/src/hawki_workflow_worker/workflows/delete_managed_document.py`
  — the deletion workflow
- `python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/activities/deletion.py`
  and `.../indexing/managed_deletion.py` — activities and scoped deletion
- Tests: `tests/Feature/Documents/ManagedDocumentDeletionTest.php`,
  `tests/Feature/Datasets/DatasetDeletionBackgroundTest.php`,
  `tests/Feature/Database/ManagedDeletionPostgresTest.php`, and the
  disposable live fixtures
  `python_rag/services/hawki_indexer_worker/tests/integration/test_managed_deletion_live.py`
  and `test_managed_writer_live.py`
- Operations context: [Temporal Operations](./temporal_operations.md)
  (§ *Dataset deletion in the background*) and
  [Ingestion Recovery](./ingestion_recovery.md)
