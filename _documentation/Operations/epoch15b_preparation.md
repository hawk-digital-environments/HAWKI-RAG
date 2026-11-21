> **Historical Laravel-era reference.** The application/runtime described below has been removed. Do not run its Laravel/PHP commands. Use the [current Python deployment guide](python_runtime.md); the original text is retained as migration history.

# Epoch 15B-1: preparation while legacy work drains

Epoch 15B-1 is non-destructive code preparation on
`feat/python-rag-decommission`, following Epoch 15A commit
`222e3e4881b8eb08c54aedd11a0001bf6e49d993`. It does not activate a deployment,
close rollback, reconcile work, remove tables, or authorize data deletion.

The explorer's November 19, 2026 local observation was **not drained**:

| Recorded condition | Count |
| --- | ---: |
| Nonterminal stage states | 7 |
| Deleting ingestion sources | 1 |
| Failed managed deletions | 1 |
| Unreleased managed-writer records | 2 |
| Active managed outputs | 1,677 |

These are unresolved records, not proof that a process is running. This code
change does not alter or resolve them. Do not edit statuses, clear active flags,
release writers manually, or delete rows to manufacture a passing report.

The supported stage-only repair is documented in [terminal projection reconciliation](terminal_projection_reconciliation.md). It requires separate operational approval and does not resolve writer or output ownership blockers.

## Read-only reporting

Run the existing command against the intended deployment:

```sh
php artisan rag:legacy-drain-status --json
```

The repository performs schema inspection and SELECTs only. It reports:

- Nonterminal task, job, job index and stage counts, plus active/deleting sources.
- Nonempty recurring schedule IDs and open managed deletions.
- Active managed outputs, unreleased registered writers, and sources whose
  writer registry completeness cannot be established.
- Unconfirmed recovery reservations, unfinished managed replacement intent,
  and worker events not yet marked processed.
- Dataset/grant/token, document/output/deletion, task/job/stage/source,
  scraper/page, callback and telemetry history counts.

Tasks settle only at `completed` or `failed`. Jobs/stages additionally accept
`skipped`. NULL, cancelled, empty and unrecognized task/job/stage states remain
unfinished. Sources settle at `ready`, `failed`, `cancelled`, or `deleted`, but
their schedule and writer evidence are inspected separately. Managed deletions
settle at `completed`, `continued`, or `abandoned`; a completed **update** still
has unfinished replacement intent until it is continued or abandoned.

Every registered writer needs the exact `released` state. Malformed/null writer
registries and sources without protocol-1 complete-manifest evidence are
unproven, even if their index status is settled. A durable
`managed_dispatch.attempted=false` reservation establishes no external dispatch;
it does not waive the source-status or other gates. SQL NULL job metadata is
the original no-reservation representation; a present null/unknown recovery
state or malformed recovery metadata remains unresolved.

Missing tables or required columns produce null counts, never zero. Missing
history schema also prevents a complete drain report. Pipeline health and the
CLI use the same `LegacyDrainState` interpretation, so zero task/job counters
cannot hide active outputs, writers, stages or recovery work.

`legacy_work_drained` describes only the database observations. Exit 0 means
inspection succeeded; exit 1 means required schema is absent or inspection failed.
Neither authorizes removal. `safe_to_drop` remains **false**: this command has
no option to prove or waive operational, retention or destructive approval gates.
Store release evidence outside the checkout through the approved process.

## Surviving boundaries and retained machinery

Python remains the default for all seven authority selectors. Laravel keeps
authentication/session handling, frontend hosting, public BFF routes and JWT
signing. Explicit Laravel rollback remains available until operational sign-off.
No new default execution path is introduced into Laravel.

Laravel also retains authorized filesystem graph snapshots, read-only crawler
catalog/UI transport, worker callback ingress and original-file downloads.
Python download resolution keeps exact task/source and SHA-256 binding, root
containment and symlink rejection; original filenames never select a filesystem
search or a substitute artifact.

The existing Python clients are the current-runtime adapters. In-memory
absent-table regression tests exercise query, dataset listing, graph reads,
text create/delete, current managed CRUD and new pipeline starts without legacy
tables. This does **not** make mixed inventory/history reads or physical storage
deletion table-independent: those still require compatibility ownership proof.
In particular, `LegacyDatasetStatePreflight` remains a read-only removal gate,
never a source of grants or authority. Its inability to inspect state must
continue to block physical deletion.

Keep legacy status/recovery, historical read merging, secure legacy downloads,
writer reconciliation, deletion completion, the bridge, and direct storage
clients required by rollback/health. The failed deletion, deleting source,
writers and outputs still need this machinery. The job-upsert and hidden retry
routes remain until their legacy recovery/rollback callers are retired.
Python-backed recovery routes remain operational.

The already-unregistered MCP RAG wrapper and unreferenced graph-cache service
are removed. MCP exposes `WebSearchTool` only. `RagSearcher` and bridge query
support remain because legacy semantic graph rollback still uses them. The
historical MCP contract is not an active tool registration.

## Callback and terminal receipt preservation

Do not retarget workers. Keep exact-body HMAC and timestamp checks on:

- `/api/internal/pipeline/worker-events`
- `/api/internal/pipeline/managed-writers`
- `/api/internal/pipeline/managed-deletion-events`

Ownership relays and nonterminal legacy completion retain their existing
validation. The extracted `ManagedDocumentDeletionReceiptVerifier` has no
repositories, source access, network client, dispatch or write method. It proves
operation/document/dataset, workflow/final run, dispatch sequence, protocol
flags and canonical stored sink evidence against the immutable targets.
Late replay does not consult source markers changed by later deletions.

The current deletion service delegates immutable verification to this class
and retains all nonterminal source ownership, tombstone, writer barrier and
sink checks. No operation is created or repaired by terminal verification.
Completion-before-start-acknowledgement remains supported through the stored
final run and verified receipt.

## Health, settings and operational dependencies

The query health/monitor default is Python. System-gate retrieval descriptions
and the playground runtime label identify that authority; explicit rollback
still has legacy bridge diagnostics. The gate remains advisory (`enforce=false`).
Legacy drain/history visibility remains while work is unresolved.

Keep the existing Laravel infrastructure probes until equivalent bounded
Python-owned database, Qdrant, Neo4j and worker checks exist. Current Python URL
readiness and query liveness alone do not replace all those probes. Missing
store/worker evidence must not become readiness. Laravel's shared-storage
health probe creates temporary files, so do not invoke it during strict
read-only evidence gathering.

Settings remain deployment-managed and updates remain refused under Python
authority. Keep upload validation, converter capabilities/token handling and
frontend bootstrap helpers; no mutable Python settings database is introduced.
Keep bridge, Neo4j, Qdrant, Temporal, CustomCrawler, converter and shared storage
dependencies needed by current operations. Callback and JWT credentials remain.

## Gates before removing drain machinery

Record evidence for **every** condition on the actual target deployment:

1. Zero nonterminal legacy tasks, jobs and stage states, including NULL/unknown
   states; no active/deleting sources or represented recurring schedules.
2. Zero unresolved managed deletions, unreleased writers, unproven writer
   registries, unprocessed events, recovery reservations or replacement intent.
3. Active output ownership resolved through verified cleanup or a separately
   reviewed ownership-transfer process; never by changing flags alone.
4. Temporal legacy executions, activities, schedules and workflow-control
   operations reconciled. SQL terminal statuses are not a Temporal audit.
5. Callback quiet window completed, covering all three routes and their actual
   retry/replay policies; the latest worker-event timestamp alone is insufficient.
6. No new legacy writes during the approved observation window. Fence CLI,
   scheduler, recovery and compatibility synchronization writers as well as
   request admission. Some historical GET paths still synchronize metadata.
7. Retention disposition approved for document/download, URL/text, pipeline,
   scraper, telemetry, grant and receipt history and their referenced artifacts.
8. Backup created **and restore tested**, including cross-store ownership.
9. Rollback window formally closed, with image/configuration evidence retained.
10. Python production acceptance verified: migrations/imports, exact grants,
    physical scopes, storage/model readiness, workers and end-to-end operations.

Code can be prepared and reviewed before these gates. Deploying removal that
prevents unresolved work from completing must wait. This pass proves none of
the external gates and does not include a DROP migration.

## Epoch 15B-2 plan only

Use a later, separately reviewed commit on the same branch. First remove
eligible writers/readers, verify drain and retention, archive required history,
restore-test the backup, and verify application operation without the tables.
Only then introduce an explicit irreversible migration for the approved set.
Do not ship that migration early in an automatically executed migration path.

Retain `managed_documents` and `managed_document_deletions` read-only while
immutable terminal receipt replay is supported. The candidate remaining drop
order, subject to a fresh deployed FK inventory, is:

1. `rag_graph_failures`
2. `rag_ingestion_artifacts`
3. `pipeline_worker_events`
4. `pipeline_stage_states`
5. `managed_document_outputs`
6. `dataset_grant_tokens`
7. `dataset_grants`
8. `scraped_elements`
9. `scrape_statistics`
10. `scrape_jobs`
11. `ingested_pages`
12. `documents`
13. `ingestion_sources`
14. `pipeline_jobs`
15. `pipeline_tasks`
16. `datasets`

No general managed-history/telemetry exporter or importer exists today. Existing
dataset/grant migration tools do not supply one. Approved database/store backups
or a separately implemented and verified export must cover retained history.
Do not migrate telemetry just for symmetry with Python event tables.

Scope a future cleanup explicitly to Laravel's `public` objects. Preserve the
Python `hawki_backend` schema, authentication/session/token tables, infrastructure
tables, migration ledger, shared artifacts and backing stores. Test the migration
on disposable PostgreSQL with representative rows and FKs, then prove surviving
BFF routes and terminal callbacks work. `down()` cannot reconstruct deleted data:
rollback is verified backup restoration plus the compatible previous image.

Keep `rag:legacy-drain-status` through deployment/gate verification; archive its
final evidence before retiring it after schema cleanup. Do not remove existing
reconciliation commands while their work remains unresolved.
