# Epoch 15A: final Python cutover and legacy drain

This release changes checked-in defaults; it does not activate an existing
deployment whose environment explicitly selects Laravel. Review the effective
environment on every BFF/backend/worker replica. Do not copy `.env.example`
over an existing deployment. Never use a request failure as a rollback signal
inside application code: Python failures remain failures.

## Authority and activation ordering

Pause admission of new work and freeze configuration during the handoff. Keep
existing legacy workers and their durable task/job/source identities available
to drain. Stop legacy recurring schedule admission through the established
operator control process; the drain command does not stop schedules.

1. Back up and verify restoration before changing traffic. Deploy the reviewed
   Python runtime and workers with the current Python migrations through `0008`
   and Laravel compatibility schema applied. Keep `hawki_rag_bridge` deployed.
2. Complete the query/grant and dataset-authority migration plans, review,
   imports and deployed-target verification described in
   [query cutover](python_query_cutover.md) and
   [dataset authority](python_dataset_authority.md). Preserve exact physical
   collection/namespace ownership, tenants, principals and effective models.
   Do not create placeholder datasets or regenerate vectors to mask mismatches.
3. Provision database access, authenticated BFF signing/JWKS configuration,
   worker HMAC secrets, shared storage and effective retrieval/model settings.
   Enable backend text and managed document services explicitly after their
   preflight (`HAWKI_BACKEND_TEXT_INGESTION_ENABLED=true` and
   `HAWKI_BACKEND_MANAGED_DOCUMENTS_ENABLED=true`). Their example switches remain
   off until this preparation is complete; the Python selectors never fall back.
4. Set `HAWKI_WORKFLOW_CONTROL_ENABLED=true` and
   `HAWKI_BACKEND_URL_INGESTION_ENABLED=true`. Verify protected CustomCrawler
   runtime readiness, Temporal namespace/queues and worker readiness. Maintain
   the Chromium namespace/seccomp requirements from Epoch 11.
5. Disable wildcard dataset authorization:
   `HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT=false`. Activate and verify
   `HAWKI_QUERY_BACKEND=python`, `TEXT_INGESTION_BACKEND=python`,
   `MANAGED_DOCUMENT_BACKEND=python`, `CRAWLER_INGESTION_BACKEND=python`.
   Exact dataset grants and Python authorization must work before advancing.
6. Activate `DATASET_BACKEND=python` with
   `HAWKI_BACKEND_QUERY_DATASET_SOURCE=database` in both BFF/backend
   deployments. Remove `HAWKI_BACKEND_QUERY_DATASETS` in database mode. Activate
   `PIPELINE_EXECUTION_BACKEND=python` after crawler/workflow prerequisites.
7. Activate `GRAPH_OPS_BACKEND=python` last, after dataset and pipeline
   authority. It requires database scope, valid URLs and prior selectors.
8. Rebuild Laravel config caches/restart long-lived processes across all
   replicas, verify the effective selectors, then reopen admission. Never leave
   differently configured replicas serving new writes. Keep rollback configs
   and the bridge available throughout the agreed rollback/drain window.

Partial/unknown configuration fails closed. Missing Laravel config selectors
default to Python and cannot silently restore legacy RAG writes. Explicit
`laravel` values remain supported during the rollback window. Dataset and graph
activation validators are still mandatory; do not bypass them to make a
partially migrated environment appear ready.

## Preflight and acceptance evidence

Record the release/image digests, effective selector values, migration heads,
reviewed import digests, target verification and operational owners. Use the
existing query parity/readiness tools and test both allowed and denied exact
dataset access. Ensure canonical Qdrant collections exist, effective embedding
and generation models match, and graph scopes resolve through Python. A missing
owned collection fails Python stats/system-gate readiness with 503; an existing
empty collection is valid. `/v1/health` is liveness and cannot replace these gates.

Verify direct text, managed upload and URL/pipeline starts, status/events/logs,
recovery, dataset/grant management, graph reads and manager stats. Verify
idempotent replay, denied/expired credentials, Python outages with no local
execution, and authenticated callback routing for both legacy and Python owners.
Run `pipeline:health`, authenticated `/api/health/system-gate` and the read-only
drain report, and retain their results outside the checkout.

Laravel settings remain visible. `GET /api/settings/config` returns
`effective=false`, `managedBy=deployment` once any Python runtime selector is
active; `PUT` returns 409 before changing the settings file. Change effective
runtime settings through deployment configuration.

## Removed paths and MCP decision

The permanently dead raw Qdrant collection DELETE route, action, request and
OpenAPI operation are removed. Use the manager-authorized dataset storage API.
The raw scraper jobs/status/start/cancel/pause/resume routes are unregistered;
`GET /api/scraper/tasks` remains the read-only catalog. Catalog execution in the
frontend uses only `/api/pipeline/tasks/start`; entries without a usable source
or sitemap cannot start. The embedded crawler UI refuses mutation proxying
while Python crawler or pipeline authority is selected, preventing a second
execution path through `/ui/api/...`.

`HawkiRagSearchTool` is **unregistered** from `/mcp/hawki_rag`. The remaining
WebSearch tool and MCP authentication are retained. No bridge-backed MCP RAG
search is active, including under explicit Laravel rollback selectors. The
legacy tool implementation is retained for later review, without exposing it.
Re-enabling RAG search requires a reviewed PythonQueryClient implementation
using the current Python identity, scope and authorization contract.

## Callback ingress freeze

Keep worker targets and HMAC verification on Laravel:

- `/api/internal/pipeline/worker-events`
- `/api/internal/pipeline/managed-writers`
- `/api/internal/pipeline/managed-deletion-events`

Do not retarget workers in 15A. Laravel resolves durable ownership and either
drains existing legacy work or forwards callbacks to the correct Python owner.
Do not remove callback secrets, owner lookups or the bridge during rollback.

Managed-deletion drain distinguishes current sources from historical tombstones.
Current sources retain exact operation ownership. A source already deleted when
the new cleanup was prepared may retain an earlier operation marker only when
that operation is completed/continued, belongs to the same document/dataset,
records the source, and retains verified immutable sink evidence. Historical
sources cannot supply sink evidence for the current cleanup; ambiguous or
foreign history fails closed.

## Read-only legacy drain checks

```sh
php artisan rag:legacy-drain-status
php artisan rag:legacy-drain-status --json
```

The command performs schema inspection and SELECT counts only. It neither
dispatches workflows nor edits statuses, grants, source artifacts or retention settings.
Run it repeatedly while legacy work drains and archive the JSON with release
evidence. Counts include hidden history and all legacy datasets/grants/tokens;
expired tokens and archived datasets are still residual records.

Task terminal statuses are exactly `completed, failed`; job/stage terminal
statuses are exactly `completed, failed, skipped`. Unknown, empty, cancelled
and any future status count as unfinished. Pipeline health uses the same
task/job rule. The command additionally reports nonterminal stage states,
active ingestion sources, nonempty recurring schedule IDs, open managed
document deletions (including failed retries), active managed outputs,
datasets/grants/tokens, scraper/page history, ingestion artifacts, graph
failures and retained task/job/source/document/callback history.

`ready, failed, cancelled, deleted` are settled source index states; a source
with a schedule ID remains a separate schedule gate even when settled. Deletion
operations remain open until `completed, continued, abandoned`. These counts
are database evidence, not a Temporal schedule audit: independently confirm
that no legacy schedule can admit new work.

`legacy_work_drained` requires all work counters, including schedules, to be
zero. `schema_complete=false` and null counts mean unknown evidence, never
zero. Exit 0 means inspection completed; exit 1 means missing schema or failed
inspection. **Neither exit code authorizes deletion.** These are point-in-time
counts; quiesce producers and repeat before a future destructive review.

`safe_to_drop` always remains **false in 15A**, even for an empty database.
History retention, verified backup restoration, rollback-window closure and
Epoch 15B destructive approval remain unresolved gates. There is no override
option to waive them. No DROP migrations or history cleanup are part of 15A.

Keep mixed pipeline/recovery inventories, managed-document compatibility
reads, legacy telemetry, uploaded-source lookup and legacy dataset preflight.
Uploaded sources try shared storage first in Python mode; absent legacy lookup
tables do not prevent a shared file from resolving. Legacy lookups remain
available when the shared file is absent. Existing path and authorization
checks still apply.

## Rollback window and point of no return

Agree and record the rollback-window end with operators; elapsed time alone
does not close it. Before any Python-owned ingestion or dataset/grant mutation,
rollback can restore the reviewed explicit Laravel selectors and prior config
through a coordinated, admission-paused deployment. Respect activation
dependencies in reverse: graph and pipeline first, dataset before changing its
prerequisite query/ingestion selectors. Keep database scope for Python work.

**The first accepted Python-owned ingestion operation is the point of no
return for a selector-only full data rollback.** Once Python owns operations,
keep its database, artifacts, callbacks, identity/owner routing and workers
available. A legacy selector never transfers ownership of existing Python
work. Changing a new-start selector is not data migration, operation replay,
grant synchronization or a transfer of ownership. Replaying accepted payloads
through Laravel risks duplicate work and split authority. Dataset/grant writes
can also diverge legacy metadata and require an explicit reconciliation plan.

If rollback is needed after that point, pause new ingestion and choose a
reviewed reconciliation/restore plan. Keep accepted Python operations routed
to Python until resolved. Do not re-enable query-all, use legacy grants as a
Python authorization substitute, or drop Python data to make Laravel appear
authoritative. The removed raw scraper and collection routes and MCP search
registration do not return when selectors are changed.

## Backups and later removal

Back up Laravel metadata/history and the complete Python schema, grant/import
plans and reconciliation receipts; Qdrant snapshots; Neo4j data; shared raw,
markdown and manifest artifacts; Temporal persistence; and deployment/key
references through the approved secret backup process. Verify restoration and
cross-store ownership consistency, and retain backups for the agreed history
and compliance retention period. Do not print secrets into cutover evidence.

Bridge removal, callback retargeting, compatibility-read removal and legacy
table/history deletion require Epoch 15B review after drain, retention,
backups and rollback gates close. Laravel auth/BFF/frontend remain in service.

## Builder validation (2026-11-18)

| Check | Result |
| --- | --- |
| Full Laravel unit/feature/system regression | 799 tests; 4,945 assertions; 32 skipped; no failures |
| Full Python backend regression | 1,720 passed; 199 skipped |
| Final Python ops/deployment/workflow-control checks | 114 passed; 23 skipped |
| JavaScript regression | 22 passed; 1 skipped |
| Production frontend build | Passed; output in `/tmp/epoch15a-vite-final` |
| Ruff on touched Python modules/tests | Passed |
| `docker compose --env-file .env.example config --quiet` | Passed |
| Whitespace/diff checks | Passed |

Laravel ran with `USE_ZEND_ALLOC=0 php vendor/bin/phpunit` to avoid the local
Herd PHP allocator crash. Python ran with `python_backend/.venv/bin/python -m
pytest python_backend/tests -q` outside the filesystem sandbox: the sandbox
strips setgid directory bits and breaks the existing artifact permission tests.
A standalone temporary-directory probe reproduced the sandbox difference;
the complete suite passed outside it without relaxing permission assertions.
JavaScript used `node --test tests/js/*.test.js`; Vite used
`npm run build -- --outDir /tmp/epoch15a-vite-final`.

Opt-in live service/database/browser tests remain skipped without their
disposable targets. This is repository validation; deployed imports, store
readiness, backup restoration and the operational traffic switch still require
the preflight evidence above. No deployment, commit, merge or push was performed.

## Second fix-pass validation (2026-11-19)

This pass fixes completed historical source drain, isolates Python original-file
downloads to authorized persisted document/revision metadata, and verifies nine
shared publication fixtures against Python's real upload storage. Laravel uses
the persisted basename and checksum rather than a second slug implementation.

| Check | Result |
| --- | --- |
| Focused managed deletion / browser-download / cutover coverage | 92 / 42 / 9 tests, included in the affected regression below |
| Affected Laravel document, authorization, writer/callback and HMAC regression | 229 tests; 1,499 assertions; no failures |
| Python shared publication fixture and authorized-read coverage | 21 passed |
| Python managed-document regression | 76 passed; 26 opt-in live tests skipped |
| Final download-only rerun after restricting filename recovery to explicit Laravel selectors | 42 tests; 110 assertions; no failures |
| PHP syntax and Pint test for this pass's seven PHP files | Passed |
| Ruff lint/format for this pass's three Python files | Passed |
| Production frontend build to `/tmp/epoch15a-second-fix-vite-20261119` | Passed |
| `git diff --check` | Passed |

The earlier deployment configuration and shared-volume fix were retained;
Compose was not modified in this pass. All changes remain uncommitted on
`feat/python-rag-decommission` at `68ad4697`. No cutover or deployment was made.
