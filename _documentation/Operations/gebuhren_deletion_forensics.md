# Gebuhren deletion forensics — 21 November 2026

Branch: `feat/laravel-off-cutover`. Latest commit remains
`5391cb23 feat(cutover): run RAG without Laravel`. The earlier uncommitted
historical handoff changes were preserved. No commit was made.

## Verdict and stop decision

**NOT READY FOR LARAVEL REMOVAL:** classification **D**, incomplete current
ownership/handoff authority for an orphaned historical deletion. Graph-enabled
live acceptance remains outstanding.

The request explicitly says: **“If D: STOP and report the precise missing
evidence. Do not force reconciliation.”** Accordingly, this task performed
read-only forensics and focused test validation, then stopped. No handoff,
deletion, legacy replay, dataset recreation or live graph test was attempted.

There is positive historical evidence: the source's writer gate is closed, its
one registered writer is recorded as released, the target manifest is complete,
and the frozen target matches output 57 and the retained artifact manifest.
This evidence was retained and is not being dismissed as unknown.

However, neither `public.datasets` nor `hawki_backend.datasets` contains a row
for `gebuhren`, and neither schema retains a dataset grant for it. No tenant,
principal or owner binding was found in the matching historical record payloads.
There is no matching Python document, revision, operation or
handoff record. The retained legacy source/document/deletion input does not
supply a tenant assignment for the current service-principal authority. The
parent dataset's disposition and its authorized mapping into current Python
authority are unproven. Assigning the local default tenant, recreating a parent,
or treating the historical writer release as a Python ownership transfer would
invent authority that the retained records do not establish.

The actual deletion also has no completed sink receipt: `results=[]`; all 20
retained deletion runs are failed in the SQL ledger. Temporal cannot return the
original writer run or those deletion runs. Present-day sink absence cannot
establish which historical deletion phases completed or supply missing receipts.

A safe continuation needs an authoritative disposition for the missing parent
dataset and a verified tenant/scope custody mapping, followed by an explicit,
durable handoff bound to the original operation/version/target and retained
writer barrier. That must precede resuming cleanup. No new permission or receipt
was inferred merely from possession of local database credentials.

## Identity and initiation

Historical SQL timestamps without offsets are quoted as stored.

| Identity | Value |
| --- | --- |
| Dataset | `gebuhren` |
| Managed document | `adoc_01m4xajrhm1andm0qdfy7ftcbf` |
| Source | `source_ccc6a3b194d8658cffa05ff8a48ffdbb` |
| Task | `task_managed_create_adoc_01m4xajrhm1andm0qdfy7ftcbf` |
| Ingestion job | `ingest_59013c5670dab46d49f7a19a` |
| Output row / artifact row | `57` / `21` |
| Bridge document | `doc_262fd82b650c4f0ad40f1355e3f45e46f5e04328` |
| Document version | `3ee1654798da4d7feb2aa47e` |
| Source created | `2026-10-14T13:45:41` |
| Source last updated | `2026-10-15T19:11:21` |
| Deletion requested | `2026-10-15T19:11:21` |
| Deletion last updated | `2026-10-15T19:37:52` |
| Operation | `delete_a28c5f498d7fc085df3bb5c4a3af4d544b91bdba60f658775e9df74b4b5c8bf6` |
| Deletion workflow | `managed-delete_a28c5f498d7fc085df3bb5c4a3af4d544b91bdba60f658775e9df74b4b5c8bf6` |
| Last deletion run | `39bfb439-8c45-4d5e-9cf2-6bfb3d29ca93` |
| Ingestion workflow | `ingest-source-source_ccc6a3b194d8658cffa05ff8a48ffdbb` |
| Original writer run | `2a447459-f355-47a7-86cf-3f582bd2a415` |

The legacy schema records a document version, not a numeric Python generation.
No generation was invented. The last known owner is the historical managed
document/deletion ledger. Its frozen queues are `rag-workflow-task-queue`,
`rag-scraper-task-queue`, `rag-converter-task-queue` and
`rag-ingestion-task-queue`, not the new `rawki-local-*` queues.

## Evidence matrix

| Component | Expected terminal evidence | Observed state and evidence | Safe conclusion |
| --- | --- | --- | --- |
| Dataset authority | Existing owner or verified retired-parent/handoff mapping | Legacy dataset absent; Python dataset count 0; grant counts 0 in both schemas | Current tenant/dataset custody is unproven |
| Python ownership | Bound document/revision/operation or auditable handoff | No matches for source, document or deletion ID across Python tables, including JSON input | Current Python deletion service does not own this operation |
| Historical operation | Terminal receipt and consistent final lifecycle | `status=failed`, `purpose=delete`, `results=[]`, last error “Sink cleanup failed; retry the original request.” | Deletion was requested and failed; completion is not acknowledged |
| Dispatch | Exact operation/run identity | `dispatch_sequence=322`; 20 retained run entries failed, 19 superseded | Dispatch happened; this does not prove 322 independent executions or sink outcomes |
| Writer barrier | Closed admission, released registered writer, frozen targets | Protocol 1, gate `closed`, activity `3` attempt 1 `released`; manifest complete; matching ingestion workflow/run/job | Historical barrier evidence exists; it is not a current ownership handoff |
| Original ingestion | Durable terminal ingestion event | Pipeline job/task completed; six status events end in `mark_source_ready` on 14 October | Ingestion completed before deletion initiation |
| Frozen output | Stable exact identity and scope | Output 57 active/indexed, bridge document matches manifest; collection and namespace `hawki_gebuhren` | Target identity is recoverable; output has not been retired in SQL |
| Qdrant | Operation-bound verified cleanup receipt | Current configured collection endpoint returns HTTP 404; no receipt in deletion results | Collection is absent on this instance; historical completion is not proven |
| Neo4j | Verified zero exact owned contributions after fenced cleanup | Current configured instance has database `neo4j`; dataset/namespace, document and source node/edge counts all 0 | No matching contributions observed now; this is not a historical deletion receipt |
| Source artifacts | Verified retirement after ownership and writer checks | Source root exists with five files, 73,340 bytes | Artifact deletion remains physically incomplete |
| Original upload | Verified removal of the exact owned upload | Task upload exists, 70,068 bytes; checksum matches SQL | Original source file remains; no cleanup was performed |
| Temporal | Exact retained terminal history or approved durable reconciliation | Writer and all 20 retained deletion run lookups return `NOT_FOUND`; current retention 86,400 seconds | Histories are unavailable; `NOT_FOUND` is not termination or sink proof |
| Active work | No live owner/execution/queued work | Zero open application executions; SQL jobs and failed_jobs both 0; legacy producers exited | No current historical worker/execution observed; this alone cannot complete deletion |
| Callback | Matching authenticated completion with full results | Failed SQL operation/run state exists; no completed deletion receipt; six retained pipeline callbacks describe ingestion only | A lost successful acknowledgement cannot be asserted |
| Logs | Scoped deletion phase outcome | Existing host log has the 14 October ingestion start; no scoped deletion outcome was found in retained logs | Exact failed sink/callback sequence cannot be reconstructed from logs |

### Storage details

Known collection and graph namespace: `hawki_gebuhren`. The frozen historical
input does not pin a Neo4j database. The current configured server was inspected
across its available non-system databases; only `neo4j` exists. Queries included
both `doc_id` and `doc_ids`, dataset/namespace scope and source identity, across
nodes and relationships. No write or broad deletion query was run.

Retained source root:

```text
/shared/sources/source_ccc6a3b194d8658cffa05ff8a48ffdbb/
  ingest/manifest.json
  raw/gebuehrenordnung-2021-rftxjvwk.pdf
  markdown/.rawki-converter-paths.json
  markdown/gebuehrenordnung-2021-rftxjvwk-1e7dc921aee30bbc/output/meta.json
  markdown/gebuehrenordnung-2021-rftxjvwk-1e7dc921aee30bbc/output/chunks/00001.md
```

The original upload remains at:

```text
/shared/task_managed_create_adoc_01m4xajrhm1andm0qdfy7ftcbf/gebuehrenordnung-2021-rftxjvwk.pdf
```

Both PDF copies have SHA256
`2decf412562e7d7dfa8a56507cc1747413700e5837dd245eefd0d435d3d6ae37`, matching
the managed document's recorded source checksum. The manifest has one entry,
matching the bridge document and the frozen relative Markdown path; its SHA256
is `2bb9c881415e80fea6e6a64bfc50b301038b5be810104b814e1e09395da54d6f`.
Document text and credential values were not printed.

## Classification and supported mechanism review

- **A is not established:** files remain and no completed sink receipt exists.
- **B is not established:** physical work remains, but the safe Python-ownership
  prerequisite is missing. Partial physical state alone does not authorize a
  transfer into a guessed tenant or replacement dataset.
- **C is contradicted:** the operation was created, fenced and dispatched;
  failed run records exist.
- **D applies:** parent/current authority and deletion phase evidence are
  incomplete. Preserve the original operation and artifacts.

The current Python callback handlers deliberately return
`409 legacy_deletion_requires_drain` for legacy deletion events/writer proofs,
and Laravel-off workflow control refuses legacy `deletion/start`. These guards
were not bypassed. The ordinary Python managed-deletion path requires a
Python-owned dataset/document/revision and frozen writer/target state; there is
no existing adopted owner here.

The historical receipt verifier requires terminal protocol evidence, the exact
operation/run and complete verified sink results. The retained failed operation
does not satisfy that contract. The read-only legacy writer proof logic provides
strong historical barrier checks, but is not a dataset/tenant ownership importer
or a terminal deletion receipt.

Relevant source:

- `app/Services/Document/ManagedDocumentDeletionReceiptVerifier.php` (historical source at `5391cb23`: Legacy terminal receipt verifier)
- `app/Services/Document/ManagedDeletionWriterProofService.php` (historical source at `5391cb23`: Historical writer proof checks)
- `app/Services/Document/ManagedWriterReconciliationAssessment.php` (historical source at `5391cb23`: Writer reconciliation assessment)
- `python_backend/src/hawki_backend/callbacks.py` (repository reference: Python legacy callback guard)
- `python_backend/src/hawki_backend/workflow_control/api.py` (repository reference: Python workflow-control guard)
- `python_backend/src/hawki_backend/managed_documents/cleanup.py` (repository reference: Python exact-scope managed cleanup)

## Final states and graph acceptance

No reconciliation/handoff was performed. PostgreSQL remains failed/deleting,
with the output active/indexed. The current Qdrant collection remains absent;
the current Neo4j counts remain zero; retained files were not removed. No new
Temporal execution was started. The exact writer/deletion histories remain
unavailable. Original identities, receipts, markers and versions were preserved.

**Graph-enabled live acceptance: not run**, pursuant to the explicit
classification-D stop rule. No disposable graph dataset, points, nodes or
artifacts were created in this task; graph cleanup is therefore not applicable.
This acceptance gap remains open, and removal approval was not issued.

## Runtime and dependency status

Full live readiness remains **26/26 PASS**, required revision `0009`. The backend
and upgraded crawler remain healthy. `hawki_rag_app`, the four old workers,
`hawki_rag_bridge` and `crawl4ai-service` are all exited, each with restart policy
`no`. No legacy service was restarted, and no new traffic was routed through it.
The four previously archived crawler imports were not reconciled again.

The unresolved operation is a historical-only authority dependency, not evidence
that normal new traffic needs Laravel. Its old callback/proof mechanisms and
public ledger cannot be treated as an active Python owner. A complete final
removal acceptance/dependency sweep was not substituted for the requested stop
decision. The earlier Python-only smoke results remain documented in
[the handoff report](historical_ownership_handoff.md); they were not relabelled
as a new graph-enabled acceptance run.

## Validation and non-mutation evidence

All PostgreSQL forensic connections used `default_transaction_read_only=on`.
Neo4j used read access and read queries; Qdrant used collection lookup/count/scroll
operations. The before/after digests of all **16 matching SQL records across nine
historical tables are identical**. No matching Python owner record was created.
No data migration, artifact cleanup, signature generation or callback replay ran.

| Check | Result |
| --- | --- |
| Relevant backend managed cleanup, Laravel-off ownership, dataset cleanup, ops, readiness/security and workflow-control tests | 293 passed, 23 skipped |
| Worker managed deletion, writer proof, graph identity, callback and workflow tests | 109 passed |
| Ruff on inspected Python deletion/proof boundary modules | PASS |
| `git diff --check` | PASS |
| Actual backend `readiness --live` | 26/26 PASS |

The tests exercise existing safeguards and do not establish live graph acceptance
or a successful historical handoff. No implementation tests were weakened or
changed. Unrelated platform failures were not pursued.

## Files and worktree

This task adds this report and a follow-up link in the preceding handoff report.
All pre-existing uncommitted handoff implementation changes remain intact. No
application/runtime code, deployment configuration or secrets were changed by
this forensic task. No commit or Laravel source deletion was performed.

```text
 M docker-compose.cutover.local.yml
 M docker/crawler/README.md
 M docker/crawler/permanent-job-v2.patch
 M docker/crawler/tests/test_permanent_job_v2.py
?? _documentation/Operations/gebuhren_deletion_forensics.md
?? _documentation/Operations/historical_ownership_handoff.md
?? docker/crawler/archive_legacy.py
```

Final verdict:

**NOT READY FOR LARAVEL REMOVAL:** classification D — authoritative custody of
the missing `gebuhren` parent dataset and a verified Python handoff are absent;
the original failed deletion has no completed sink receipt and retains source
files. Graph-enabled live acceptance remains unperformed under the stop rule.

Follow-up, 2026-11-21: the subsequent authorized
[historical quarantine and graph acceptance](historical_orphan_graph_acceptance.md)
supersedes this removal blocker. All findings above remain historical evidence;
tenant/dataset custody is still UNKNOWN and deletion completion still UNVERIFIED.
