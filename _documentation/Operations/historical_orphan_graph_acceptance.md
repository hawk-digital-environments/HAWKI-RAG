# Historical orphan quarantine and graph acceptance

Verified on 2026-11-21, Europe/Berlin, against the explicit local cutover overlay.
Branch: `feat/laravel-off-cutover`; HEAD remains
`5391cb23 feat(cutover): run RAG without Laravel`. No commit, merge, Laravel
restart, Laravel source removal, historical SQL update, or crawler safety bypass
was performed. This report supersedes the removal blocker in the earlier
[forensic report](gebuhren_deletion_forensics.md), while retaining its unresolved
ownership/deletion findings.

## 1. Existing mechanism

The crawler already uses content-addressed historical manifests with preserved
permanent identity reservations (`docker/crawler/archive_legacy.py`,
`/shared/.crawler-runtime/legacy-archive`). That format establishes the existing
immutable-manifest pattern. Its crawler-specific ownership contract cannot own
a managed-document orphan. Python dataset/source/workflow ledgers require known
current ownership and are inappropriate for this record. No suitable generic
historical-orphan table was found. The same file-manifest pattern was therefore
used in a separate directory; the crawler archive was not reconciled again.

## 2. Exact custody mechanism

The exclusive-create, SHA-256-addressed evidence record is:

```text
/shared/.historical-orphans/48e50c6ffe69b535c9c7a478c859547126d9ca687b09dc6abd1afe5446cfdbb7.json
```

Its filename is its complete file SHA-256. Timestamp:
`2026-11-21T11:15:24.248451+00:00` (12:15:24 Europe/Berlin). The directory is
mode 0750; the file is mode 0640. The application identity can read it.
`scripts/archive_historical_orphan.py` required fresh stopped-producer evidence,
empty historical queues, no open application executions, no matching Python
ownership, and the exact 16-row/six-file inventory. SQL was read-only.

`historical_archive.py` creates files with `O_EXCL`, fsyncs file and directory,
and never overwrites an existing record. Admission validates content hashes and
record semantics. Missing, empty, corrupt or linked configured records fail
closed. This is operator-managed, tamper-evident evidence, not a database owner,
tenant grant, completed deletion, or sink receipt. Preserve the manifest together
with the shared-volume backups and original database evidence.

## 3. Recorded facts

- Category: `unresolved_legacy_deletion`; preservation: `in_place`.
- Source: `source_ccc6a3b194d8658cffa05ff8a48ffdbb`.
- Managed document: `adoc_01m4xajrhm1andm0qdfy7ftcbf`.
- Historical task: `task_managed_create_adoc_01m4xajrhm1andm0qdfy7ftcbf`.
- Historical job: `ingest_59013c5670dab46d49f7a19a`.
- Operation: `delete_a28c5f498d7fc085df3bb5c4a3af4d544b91bdba60f658775e9df74b4b5c8bf6`.
- Observed dataset label: `gebuhren`; this label is reserved without assigning
  current dataset custody.
- Deletion completion: `UNVERIFIED`; active runtime owner: `null`.
- Historical writer recorded released, writer gate closed, deletion failed with
  no completion results. Original public rows remain historical authority.
- Stopped producer snapshot, archival time, original identities/reservations,
  hashes of all 16 original rows, and all six file paths/sizes/hashes.

The linked forensic report separately preserves the 21 `NOT_FOUND` Temporal
lookups, absent historical Qdrant collection and zero matching Neo4j counts.
Those observations are not substituted for deletion receipts.

## 4. Intentionally UNKNOWN / UNVERIFIED

Current tenant custody and dataset custody remain `UNKNOWN`. No parent dataset,
grant, modern source, ownership handoff, tenant attribution or sink-completion
receipt was created. Deletion completion remains `UNVERIFIED`; zero current sink
counts and expired histories do not prove successful historical deletion.

## 5. Historical SQL unchanged

Final read-only row hashes match the pre-archive forensic snapshot exactly:
**16 rows across nine public tables**, with **zero matching Python owner rows**.

| public table | Rows preserved |
| --- | ---: |
| ingestion_sources | 1 |
| managed_document_deletions | 1 |
| managed_document_outputs | 1 |
| managed_documents | 1 |
| pipeline_jobs | 1 |
| pipeline_stage_states | 3 |
| pipeline_tasks | 1 |
| pipeline_worker_events | 6 |
| rag_ingestion_artifacts | 1 |

Evidence: `/tmp/rawki-orphan-sql-final.json`, compared with
`/tmp/rawki-gebuhren/sql-final.json`. No historical row was made terminal.

## 6. Preserved files

Source prefix `S` below means:
`/shared/sources/source_ccc6a3b194d8658cffa05ff8a48ffdbb`.
Converted prefix `C` means:
`S/markdown/gebuehrenordnung-2021-rftxjvwk-1e7dc921aee30bbc/output`.
These aliases are presentation only; the archive stores full absolute paths.

| Path | Bytes | SHA-256 |
| --- | ---: | --- |
| S/ingest/manifest.json | 421 | `2bb9c881415e80fea6e6a64bfc50b301038b5be810104b814e1e09395da54d6f` |
| S/markdown/.rawki-converter-paths.json | 128 | `876818d9a1344074b30579f7c187240d7d643f64a8346435b210d9caf432e1d9` |
| C/chunks/00001.md | 2226 | `fafe5ab9fb6d9d89db00f1b8f7dc592b4938d2db317162ed86fb38f45163e3a3` |
| C/meta.json | 497 | `0cb02c44e83cf82737d40eb1f76a96a90924c161a9b3155e7f31d0ed1521a33c` |
| S/raw/gebuehrenordnung-2021-rftxjvwk.pdf | 70068 | `2decf412562e7d7dfa8a56507cc1747413700e5837dd245eefd0d435d3d6ae37` |
| /shared/task_managed_create_adoc_01m4xajrhm1andm0qdfy7ftcbf/gebuehrenordnung-2021-rftxjvwk.pdf | 70068 | `2decf412562e7d7dfa8a56507cc1747413700e5837dd245eefd0d435d3d6ae37` |

The last file is the original upload; the others are source artifacts referenced
by retained source metadata/storage paths and the ingest manifest. All six hashes
and sizes were checked again after acceptance, as the application identity.
No contents are included here.

## 7. In-place preservation

Files were left byte-for-byte in their original locations. Relocation would break
historical path evidence and potentially unknown references. The separate archive
records explicit preservation and reservations. New workers process only frozen
Python workflow paths, not a filesystem-wide discovery scan; neither these
source paths nor the archive directory are adopted by directory presence.

## 8. No active owner / producer

Throughout acceptance, these containers remained `exited`, restart policy `no`:

```text
hawki_rag_app
hawki_rag_temporal_workflow_worker
hawki_rag_temporal_scraper_worker
hawki_rag_temporal_converter_worker
hawki_rag_indexer_worker
hawki_rag_bridge
crawl4ai-service
```

Final public `jobs` and `failed_jobs` counts are both zero. All four legacy
application task queues have zero pollers. The `default` application namespace
has zero open executions and zero schedules. `temporal-system` contains only its
two normal scanner workflows. Dedicated `rawki-local-*` queues have Python
pollers. There is no Python owner row or workflow waiting for this orphan.
Frontend, ingestion, query, graph and readiness acceptance all ran while it
remained unresolved. No Laravel process is required to service the archive.

## 9. Admission / automatic-adoption exclusion

`HAWKI_BACKEND_HISTORICAL_ARCHIVE_ROOT=/shared/.historical-orphans` enables the
reservations in the active local backend. Dataset creation rejects the original
label across tenants; managed/URL admission, trusted workflow preparation,
callback owner resolution and managed artifact cleanup fence reserved identities.
These checks grant no authority and do not change JWT verification, HMAC checks,
tenant matching, exact grants, writer barriers or physical scope derivation.

Live authenticated dataset recreation returned **409**
`historical_identity_reserved`. Reading the historical managed ID returned **404**
because there is no Python-owned document. It is absent from current dataset
configuration. Tests verify cross-tenant denial before store writes, denial
before workflow dispatch, callback authentication before reservation checks, and
fail-closed missing/tampered evidence. Existing absent-owner guards still apply.

## 10. Graph-enabled live acceptance

Final disposable dataset: `graph-accept-bc8bd75ae7b3`.
Managed source: `source_1a48a203e5284b26a05f6f1d3a6689b7`.
Task: `task_managed_511ced97c51c407b962cd372dc2edcb7`.

Creation and all operations used the protected `/ui-api/v1` Python gateway.
Server setting `HAWKI_BACKEND_DATASET_GRAPH_ENABLED=true` enables graph for newly
created local datasets; normal multipart upload selected `graph=true`. Dataset
physical scope remains server-derived. No private SQL toggle or fabricated
triplets were used. Real converter, Temporal/indexer, Ollama `llama3.1:8b` and
`bge-m3`, Qdrant and Neo4j executed the work.

| Acceptance operation | Result |
| --- | --- |
| Dataset create + exact ingest grant | PASS |
| Converter + indexer | PASS, pipeline completed / index ready |
| Managed document | `indexed`, graph enabled |
| Vector retrieval | 1 hit |
| Neo4j overview | 9 nodes, 10 edges |
| Query with graph retrieval | 10 graph facts |
| Graph node, expand, search, semantic search | PASS |
| Original upload download | Byte-identical, HTTP 200 |
| Download SHA-256 | `6e343c6ea8068c8bfde6392788cd17f0e405115b0a5b19b081314fdbed39a357` |
| Direct-text ingestion | Completed, index ready |
| Document/text deletion + dataset retirement | PASS through Python API |
| Graph clear operation | PASS on empty scope after document deletion |

The first graph run exposed a genuine managed artifact-cleanup gap. The fix
removes only a drained Python revision's validated frozen source tree and
checksum-matching upload after verified sink cleanup, before marking cleanup
complete. Scope drift/symlinks/checksum changes fail closed and remain retryable.
The final graph run verified this corrected API behavior. Initial frontend 502
after gateway recreation was resolved by refreshing local nginx DNS; that
pre-creation attempt allocated no dataset.

## 11. Disposable cleanup

Read-only audit of the final graph run passed:

```json
{"dataset_status":"deleted","managed_revisions_cleaned":1,
 "text_sources_deleted":1,"active_grants_remaining":0,
 "qdrant_points_remaining":0,"neo4j_remaining":{"entities":0,"triplets":0},
 "source_upload_artifacts_remaining":0,"open_application_workflows":[]}
```

Only SQL tombstones/immutable receipts and ineffective historical grant rows
remain for audit, as intended by the existing lifecycle. The first graph run's
six leftover disposable files were removed using its already-cleaned Python
revision, released writers and closed workflow evidence, with the corrected
bounded routine. Neither historical source path was touched. Both graph datasets
have zero scoped or matching temporary extraction nodes. After workflow
quiescence, the local indexer was recreated to discard its ephemeral extraction
files and in-memory cache; `/app/rag_storage` contains zero files.

URL acceptance also passed for HTML, sitemap, idempotent start, private-URL
rejection, active cancellation, task retirement and dataset retirement:
`crawler-v2-6f335a839496`. Its separate read-only audit found three deleted
sources, three genuine sealed/quiescent receipts (two completed, one cancelled),
zero source artifacts and zero Qdrant points. The disposable fixture container
and network were removed; the crawler was restored to its normal local overlay.
URL ingestion remains enabled; `crawler_contract` was never bypassed.

## 12. Readiness and other acceptance

Final readiness: **26/26 PASS**, read-only live probes, required revision `0009`.
`crawler_contract`, direct Python callback destination, service-only identities,
JWKS, database role/schema, query models/reranker, graph and all stores pass.

Frontend, gateway and deployment config return HTTP 200. Missing/invalid JWTs
return 401; public internal callback ingress returns 404; old `/api` returns 410.
Live worker logs confirm signed callbacks directly to
`http://hawki_backend/internal/pipeline/worker-events`, with writer registration
at the Python `/internal/pipeline/managed-writers` endpoint.

## 13. Active Laravel dependency audit

| Finding | Class | Evidence / disposition |
| --- | --- | --- |
| Laravel app, PHP-FPM/artisan/scheduler/queue code | A, dormant rollback source | Existing source retained; `legacy-laravel` profile; app exited/restart=no; no active PHP process in container inventory |
| Laravel User/UserRepository, Sanctum, currentAccessToken, BrowserQueryPrincipalService, Gate/browser fallback, login/logout/password/session code | A | Retained PHP source only; no corresponding active Python user system; runtime accepts registered service identities only |
| Laravel BFF/query proxy/JWT signer/controllers/routes | A | Frontend uses `/ui-api/v1`; gateway obtains JWT server-side from configured HTTPS issuer and calls `hawki_backend`; no browser service credential; old `/api` blocked |
| Old workers/queues and bridge | A | Explicit legacy profiles, containers exited/restart=no, old queue pollers zero; new workers use separate local queues |
| Legacy callback URLs inherited in gateway/Postgres/Ollama/Temporal environment | A, unused configuration | Those processes do not run callback workers; gateway reads UI boundary settings. Every running Python worker and backend has the direct Python callback URL. No broad inherited-env cleanup performed |
| Original public rows/files, immutable orphan and crawler archives, legacy receipt refusal, compatibility enum/wire fields and PHP test oracles | B, historical evidence/compatibility | No current owner/grant fabricated; signed old deletion callbacks remain fail-closed; historical data does not dispatch new work |
| Active dependency on Laravel for new work | C | **None found** in the audited local runtime |

Process inventory checks command names, not secret-bearing command arguments or
full environments. HAWKI remains the human authentication authority; the local
development access guard and server-side service token acquisition are separate
from human-user accounts. This acceptance validates the current local deployment;
production issuer/HAWKI configuration must follow its normal deployment process.

## 14. Tests and validation

- Relevant backend suites: **1320 passed, 135 skipped**. Includes archive,
  cross-tenant/ownership, managed artifact deletion/retry/path isolation, graph
  ops/clear, readiness/security, service auth/gateway, workflow, text/URL and
  signed callbacks.
- Managed-document focused run: **91 passed, 26 skipped** (overlaps above).
- Graph store, graph scoping/cancellation/provider, callback and workflow-worker
  suites: **101 passed, 19 skipped**. Live acceptance supplies actual infrastructure
  coverage omitted by opt-in integration test skips.
- Broader backend run including the unchanged direct-text artifact parity file:
  **1334 passed, 135 skipped, 48 failed**. All 48 are the previously documented
  macOS setgid assertion (`0o2775`) failures in
  `tests/text_ingestion/test_artifacts.py`; that implementation/test was not
  modified. The clean relevant run excludes that baseline file. No unrelated
  baseline fix was made.
- Ruff on all changed/new Python files: PASS. `git diff --check`: PASS.
- Real graph, managed, text, query, download, deletion and URL smokes: PASS.
- SQL/file hash comparisons and storage/workflow cleanup audits: PASS.

## 15. Files changed

This task adds `historical_archive.py`, archive tests, the explicit archive CLI,
live graph smoke and read-only audit, managed artifact cleanup tests, and this
report. It modifies dataset server defaults/startup/example configuration,
managed artifact cleanup/admission, URL/workflow/callback reservation boundaries,
and the local overlay's two backend settings. Follow-up links were added to the
earlier reports. Existing uncommitted crawler archive/contract files and worker
profile isolation from earlier work are preserved.

## 16. Alembic

No revision added. Database remains at **0009**. File evidence is sufficient;
there was no new ownership table, public-schema alteration, fabricated dataset
or grant for the orphan.

## 17. Final git status

```text
 M docker-compose.cutover.local.yml
 M docker/crawler/README.md
 M docker/crawler/permanent-job-v2.patch
 M docker/crawler/tests/test_permanent_job_v2.py
 M python_backend/.env.example
 M python_backend/src/hawki_backend/callbacks.py
 M python_backend/src/hawki_backend/datasets/service.py
 M python_backend/src/hawki_backend/main.py
 M python_backend/src/hawki_backend/managed_documents/artifacts.py
 M python_backend/src/hawki_backend/managed_documents/service.py
 M python_backend/src/hawki_backend/url_ingestion/service.py
 M python_backend/src/hawki_backend/workflow_control/service.py
?? _documentation/Operations/gebuhren_deletion_forensics.md
?? _documentation/Operations/historical_orphan_graph_acceptance.md
?? _documentation/Operations/historical_ownership_handoff.md
?? docker/crawler/archive_legacy.py
?? python_backend/src/hawki_backend/historical_archive.py
?? python_backend/tests/managed_documents/test_artifact_cleanup.py
?? python_backend/tests/test_historical_archive.py
?? scripts/archive_historical_orphan.py
?? scripts/audit_local_graph.py
?? scripts/smoke_local_graph.py
```

Detailed local evidence (no credentials): `/tmp/rawki-orphan-evidence-final.json`,
`/tmp/rawki-orphan-runtime.json`, `/tmp/rawki-orphan-sql-final.json`,
`/tmp/rawki-orphan-graph-smoke.json`, `/tmp/rawki-orphan-graph-audit.json`,
`/tmp/rawki-orphan-url-audit.json`, `/tmp/rawki-orphan-http-checks.json`,
`/tmp/rawki-orphan-final-sinks.json`, `/tmp/rawki-orphan-readiness-final.json`,
`/tmp/rawki-orphan-final-focused-tests.log`, `/tmp/rawki-orphan-worker-tests.log`.
The durable historical evidence itself remains in the shared-volume archive;
temporary acceptance logs are not its authority.

## 18. Verdict

READY FOR LARAVEL REMOVAL
