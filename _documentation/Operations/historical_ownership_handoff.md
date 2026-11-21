# Historical ownership handoff — 21 November 2026

Follow-up: [gebuhren deletion forensics](gebuhren_deletion_forensics.md) records
the read-only component inventory, classification-D stop decision and the exact
remaining custody/handoff evidence gaps. The earlier crawler archive is unchanged.

Base: `5391cb23 feat(cutover): run RAG without Laravel`, branch
`feat/laravel-off-cutover`. Initial worktree was clean. The commit was not rewritten.

## Verdict

**NOT READY FOR LARAVEL REMOVAL.** A historical failed deletion remains unresolved:

- Dataset `gebuhren`, document `adoc_01m4xajrhm1andm0qdfy7ftcbf`.
- Source `source_ccc6a3b194d8658cffa05ff8a48ffdbb` still has public `index_status=deleting`.
- Operation `delete_a28c5f498d7fc085df3bb5c4a3af4d544b91bdba60f658775e9df74b4b5c8bf6`.
- Workflow `managed-delete_a28c5f498d7fc085df3bb5c4a3af4d544b91bdba60f658775e9df74b4b5c8bf6`,
  run `39bfb439-8c45-4d5e-9cf2-6bfb3d29ca93`.
- Its historical deletion ledger is `failed`, last updated 15 October 2026.
  There is no currently open application execution. Absence of an execution is
  not deletion/writer/sink drainage proof. No Python ownership handoff for this
  operation was fabricated. It needs an explicit disposition or verified migration
  before PHP source removal can be called safe. Laravel was not restarted.

## Four crawler imports

All four are **B: preserved historical archive**, not modern drained receipts.
Task, dataset, source, operation and generation identities are unavailable in
these imported records; no corresponding identity match was found in Python or
public historical task/job/source tables. Nested JSON metadata in the relevant
public tables was also searched with no matching crawler IDs. Do not infer owner
identities from directory names or old completed flags.

| Permanent job ID | Artifact started (UTC) | Artifact updated (UTC) |
| --- | --- | --- |
| `hawk_1780412849803` | 2026-06-02T15:07:29.829215Z | 2026-06-02T15:09:44.601089Z |
| `hawk_1780413098308` | 2026-06-02T15:11:38.319039Z | 2026-06-02T15:13:58.946378Z |
| `hawk_1780413192022` | 2026-06-02T15:13:12.029626Z | 2026-06-02T15:15:15.106619Z |
| `ingest_1645d04ed745e2950c76ce18` | 2026-07-29T09:10:32.317549Z | 2026-07-29T09:12:45.552805Z |

Imported record creation/start/finish timestamps are null. The timestamps above
are explicitly from old artifact state, not invented receipt timestamps. All
artifact directories remain present; each old artifact state says `completed`.
The legacy crawler's live `/jobs` inventory was empty and neither crawler process
tree had browser/child executions. Every imported modern record still says
`unknown`, `terminal=false`, `sealed=false`, `sealed_at=null`, `quiescent=false`,
`writer_admission_closed=false`, `cancel_requested=false`, with no fingerprint
and empty owner metadata. Writer drainage remains **unproven**, not claimed closed.

A sidecar archives exactly these four records under the exclusive crawler lock,
after the old workers/bridge/crawler and modern crawler were stopped. It includes
record and artifact-state SHA256 digests and the observed queue/producer evidence.
Original record and artifact bytes are unchanged and were checked after restart.
No modern receipt was synthesized; the status API still refuses incomplete
legacy ownership. Their permanent IDs and artifact-root reservations remain.
Changing an archived record or its artifact-state digest reverts to conservative
capacity accounting. Modern unknown jobs with real fingerprints/ownership cannot
be excluded by this path.

**Legacy occupied slots: 4 → 0; free admission slots: 1 → 5; configured limit stays 5.**
Two sequential fresh URL/sitemap jobs reached ready after the handoff; cancellation
and API retirement passed. A focused regression also admits all five modern jobs,
refuses a sixth, and refuses reuse of a legacy permanent ID. No capacity increase
or historical record deletion was used.

## Workers and queues

New workers are `rawki_local_{workflow,scraper,converter,indexer}_worker`, polling
`rawki-local-{workflow,scraper,converter,indexer}`. Their callback destination is
`http://hawki_backend/internal/pipeline/worker-events`; existing HMAC verification
and owner/writer ledgers remain authoritative. Local worker crawler fallback
configuration now explicitly points to the upgraded crawler as well as the
server-frozen URL inputs.

Stopped with automatic restart disabled:

- `hawki_rag_temporal_workflow_worker`
- `hawki_rag_temporal_scraper_worker`
- `hawki_rag_temporal_converter_worker`
- `hawki_rag_indexer_worker`
- `hawki_rag_bridge`
- `crawl4ai-service`

The old workers used `rag-workflow-task-queue`, `rag-scraper-task-queue`,
`rag-converter-task-queue`, `rag-ingestion-task-queue` and Laravel callbacks at
`http://hawki_rag_app/api/internal/pipeline/worker-events`. Their configured Python
commands register workflow/scraper/converter/indexer roles respectively. Pollers
were observed on each old queue before stopping. The local cutover overlay definitions now
require the explicit `legacy-workers` profile; legacy bridge/Laravel profiles
remain explicit. No queue was purged, workflow terminated or active work killed.

The read-only audit found:

- `public.jobs`: zero queued or reserved jobs; `failed_jobs`: zero.
- No pending job batches. Historical pipeline jobs/tasks: 18 completed, 17 failed.
- Default Temporal namespace: zero open executions and zero schedules.
- Six retained default-namespace executions, all from the preceding Python URL
  smoke: four completed, two canceled, all on `rawki-local-workflow`.
- `temporal-system` contains only its history/task-queue scanner workflows;
  these are unrelated system maintenance and were untouched.
- No RabbitMQ container exists in the local topology; the historical Laravel
  queue configuration uses the database driver. Remote brokers were not audited.

There was no current historical execution requiring Laravel to be restarted.
The failed historical deletion above was preserved without silently retrying it.

## New-work ownership and verification

Source/runtime review confirms FastAPI service principals, Python persistence,
server-owned queue selection, upgraded crawler, signed Python callbacks and
Python deletion/graph services. No Laravel user/session/Sanctum equivalent was
introduced. Inactive compatibility API paths and PHP files remain for history;
their presence does not route new local traffic through PHP. Harmless unused
legacy variables in non-worker environments were not broadly cleaned.

New local URL execution inputs use the dedicated queue names and upgraded crawler.
Text and managed ingestion are admitted into Python schemas and current workers.
The local Python URL reconciliation scheduler remains in FastAPI's lifespan;
no new-operation scheduler uses `php artisan`. Runtime-role checks still prohibit
reading historical public tables. Public historical reads in this audit used a
separate local administrator connection constrained to read-only transactions.
No public table was written.

Final smoke and readiness results are appended below. Graph-enabled indexing and
historical failed-deletion recovery are not claimed by the ordinary graph-disabled
fixture. A full graph-enabled ingestion/removal smoke remains a removal acceptance
limit alongside the historical deletion disposition.

## Tests and files

- Crawler lifecycle, permanent-job-v2, network and archive/capacity regressions:
  **77 passed**; existing datetime deprecation warnings remain.
- Backend URL/text/managed/ops/auth/query/readiness suites: **971 passed, 135 skipped**.
  The known unrelated macOS artifact permission tests were excluded.
- Relevant worker/workflow/callback/contracts/external-job suites: **187 passed**.
- Ruff and `git diff --check`: passed. No authentication or safety test was relaxed.

Files changed:

```text
docker-compose.cutover.local.yml
docker/crawler/permanent-job-v2.patch
docker/crawler/archive_legacy.py
docker/crawler/tests/test_permanent_job_v2.py
docker/crawler/README.md
_documentation/Operations/historical_ownership_handoff.md
```

No commit, merge, cherry-pick or PHP source deletion was performed.

## Evidence snapshots

Final readiness:

```json
{"checks": {"auth_config": "PASS", "callback_replay_window": "PASS", "callback_secret": "PASS", "callback_url": "PASS", "control_destination": "PASS", "crawler_contract": "PASS", "database_config": "PASS", "database_dataset_source": "PASS", "database_schema_role": "PASS", "graph_requirement": "PASS", "jwks": "PASS", "neo4j": "PASS", "qdrant.dataset_catalog": "PASS", "qdrant.managed_deletion": "PASS", "qdrant.ops_catalog": "PASS", "qdrant.query": "PASS", "qdrant.text_deletion": "PASS", "qdrant.url_deletion": "PASS", "query_config": "PASS", "query_models": "PASS", "query_reranker": "PASS", "service_clients": "PASS", "service_only": "PASS", "shared_storage_permissions": "PASS", "temporal": "PASS", "workflow_control_relay": "PASS"}, "phase": "runtime", "read_only": true, "required_revision": "0009", "status": "PASS"}
```

Historical deletion ledger summary: 29 completed, one failed (identified above).

| Post-handoff live smoke | Result |
| --- | --- |
| Frontend | HTTP 200 |
| Backend readiness | 26/26 PASS; health ready |
| URL + sitemap | Both reached ready, distinct sequential fresh jobs |
| URL cancellation, task/source/dataset deletion | PASS |
| Text ingestion | HTTP 202; current Python worker execution |
| Managed ingestion | HTTP 202 → indexed |
| File/source download | HTTP 200, 1819 bytes |
| Query | HTTP 200, actual retrieval (`generate=false`) |
| Text, managed and dataset deletion | HTTP 200 each |
| Graph overview | HTTP 409, correctly refuses graph-disabled fixture |
| Signed callbacks | Worker logs show direct Python event/writer endpoints |

The query smoke tests retrieval, not LLM answer generation. Full graph-enabled
operations were not exercised live. The managed API's success state is `indexed`;
a preliminary harness expected `ready`, then the actual indexed projection and
successful authorized download were verified. No access check was changed.
The disposable URL fixture container/network and all newly created smoke datasets
were retired. Existing public history and the failed deletion were preserved.



## Final git status

```text
 M docker-compose.cutover.local.yml
 M docker/crawler/README.md
 M docker/crawler/permanent-job-v2.patch
 M docker/crawler/tests/test_permanent_job_v2.py
?? _documentation/Operations/historical_ownership_handoff.md
?? docker/crawler/archive_legacy.py
```

Follow-up, 2026-11-21: see
[historical quarantine and graph acceptance](historical_orphan_graph_acceptance.md)
for the separately authorized unresolved-orphan archive and completed live graph
acceptance. The crawler reconciliation recorded here was not repeated.
