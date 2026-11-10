# Epoch 11 crawler integration validation — 2026-11-09

Branch: `feat/customcrawler-security-contract`, based on `34cba4ac`. Changes remain
unstaged and uncommitted. Production selection was read back as `laravel`.
No production crawler/backend/worker was restarted or replaced.

## Result

**Ready for independent Epoch 11 review.** The current CustomCrawler contract is
consumed by Python URL admission, scraper polling, signed runtime evidence and
retirement. No remaining correctness blocker was found in this integration.
Production activation still requires the documented deployment and BFF preflight;
this report does not authorize traffic migration.

The external repository `/Users/ixdlab/Documents/CustomCrawler` was inspected
read only at `1c327244fed3b6f99ad08ad9203814942bc10f26`; its working tree was clean.
Inspection covered crawl models/routes, durable admission/store, cancellation,
network and browser runtime, `docker/BROWSER_EGRESS.md` and the complete
`docker/browser-seccomp.json`. Its transport implementation was not copied or
changed. This supersedes the external-runtime findings in the November 6 report.

## Contract and lifecycle

`POST /crawl` receives the generation's permanent `job_id`, source identity,
generation/operation metadata, server-owned artifact base and crawl configuration.
`job_submitted` and `job_exists` are accepted; retries submit the same frozen
payload even when a heartbeat preceded an ambiguous HTTP delivery. The permanent
job key is persisted before dispatch and the actual `external_job_id` and sealed
receipt are retained on that generation after polling/callback reconciliation.

The shared `CrawlerReceipt` validates exact job identity, owner metadata, artifact
root, consistent `status/state/terminal`, strict boolean seal/drainage fields and
an aware `sealed_at`. Terminal states are exactly `completed`, `failed`,
`cancelled`. Cleanup requires terminal AND sealed AND quiescent AND closed writer
admission AND a seal timestamp, followed by HAWKI managed-writer drainage. Unknown,
foreign, unavailable, malformed, legacy and terminal-but-unsealed evidence cannot
authorize cleanup. Cancellation acknowledgement alone is insufficient.

The crawler appends `job_id` to `output_dir`. RAWKI consumes the corresponding
`raw/<job_id>` subtree under its reserved source root. Permanent crawler admission
records remain outside source artifacts; deleting artifacts never reopens a key.

Recrawl reserves/fences a successor under the existing source lock, then drains
and retires its predecessor before preparing or dispatching that successor.
Manual and scheduled recrawls use this same path. Late old-generation receipts
may enrich that generation's audit evidence, but cannot publish or mutate a newer
source generation. No frozen-input Temporal refresh schedule is introduced.

URL converter artifact writes now acquire/release the existing signed managed
writer ledger; cancellation cannot release a still-running converter thread.
Uncertain conversion retains an uncertain writer. URL conversion requires the
existing synchronous/native extraction route: asynchronous external converter
jobs lack an independent drainage contract and remain rejected.

## Safe dispatch, sitemap and deployment

Backend and scraper both check the configured control origin's `/openapi.json`
and `/health`, without redirects or ambient proxy configuration. This establishes
API compatibility and liveness, **not** security attestation or kernel readiness.
The deployed implementation must be the reviewed current crawler. Its protected
browser must successfully establish its Linux user/network/PID namespaces before
Chromium starts; there is no unprotected fallback.

XML/JSON or sitemap-named resources use the crawler's resource-parser URL
entrypoint with `sitemap_only=true`, `sitemap=false`. Approved HTML/profile
sitemaps retain the crawler's separate `sitemap=true` route. Unsupported resource
names require an approved profile; local sitemap paths remain rejected.
Laravel and the Python backend do not fetch remote sitemap bodies in Python mode.

The runbook explicitly requires the crawler's seccomp profile, unprivileged
namespaces, procfs, unshare and Chromium. A disposable production-image
`SafeBrowser` startup probe succeeded with isolated network/PID namespace IDs,
PID 1 and loopback only. The existing running `crawl4ai-service` was an older
image without this runtime and without the required namespace setup; it was left
unchanged. Deploying the reviewed runtime and repeating its probe as the intended
service user are mandatory activation steps. API readiness alone cannot replace
that probe.

## Executed checks

| Check | Final result |
|---|---|
| Full backend, `pytest python_backend/tests -m 'not integration' -q` | **1,420 passed, 83 skipped, 5 deselected** |
| Backend URL suite | **130 passed, 13 skipped**; PostgreSQL cases executed separately |
| Optimized backend URL/managed/text/workflow/auth suites | **709 passed, 55 skipped, 1 deselected** |
| Scraper/converter/indexer/workflow/callback/contracts/workflow-control/external-job suites | **369 passed, 6 deselected** |
| Same worker suites with `python -O` | **369 passed, 6 deselected** |
| Real PostgreSQL Epoch 9–11 lifecycle/concurrency suites | **55 passed** normal and **55 passed** optimized |
| Laravel pipeline/scraper/query/auth/principal/text/managed BFF/writer/relevant unit suites | **475 passed, 2,750 assertions** |
| Laravel managed deletion/browser/unified API, isolated PHPUnit processes | **51 passed, 315 assertions** |
| Ruff check | Passed across backend and worker packages/services |
| Ruff format check | Passed, **546 files** already formatted |
| Backend and worker `uv lock --check` | Passed; **61** and **218** resolved dependencies |
| Pint on modified PHP configuration | Passed |
| `composer validate --no-check-publish` | Passed |
| `git diff --check` | Passed |

Existing blocker/security/lifecycle tests were retained. PostgreSQL tests used
isolated `epoch9_blockers_test`, `epoch10_blockers_test` and
`epoch11_blockers_test` databases on `review_pg`, including all 13 URL concurrency
cases: idempotency, recrawl/delete, stale callbacks, grant/dataset revocation,
manual/scheduled recrawl and overlapping ticks. Optimized runs emitted pytest's
standard warning about Python assertions outside test modules. Full artifact
tests ran with approved filesystem permissions because the macOS sandbox strips
setgid bits used by existing artifact tests. No assertions were relaxed.

Worker command scope was `services/hawki_{scraper,converter,indexer,workflow}_worker/tests`
and `packages/{pipeline_callbacks,contracts,workflow_control,external_jobs}/tests`
under `python_rag`, with `-m 'not integration'`. Real Chromium and real sinks were
tested separately below; the six deselected worker integration tests are not
claimed as executed.

## Local end-to-end evidence

Disposable run: `e11smoke_d53e15ee`. Dedicated backend database
`e11smoke_d53e15ee_test`, six temporary containers, shared-storage volume, Temporal
queues and Qdrant/Neo4j dataset scope isolated this run from application traffic.
The current backend image was built separately as
`rawki-epoch11-smoke-backend:local`; no production image tag was replaced.

The crawl target was a controlled fixture at `93.184.216.34:8099` assigned inside
the disposable crawler container. This exercised public-address validation and
real Chromium through the crawler's isolated namespace/proxy/broker; no Internet
site was crawled. Fixture-only `NET_ADMIN` assigned that address and is not a
production deployment requirement or recommendation. The production-image
browser probe separately succeeded without this fixture capability. External
crawler sources were mounted read only with bytecode writes disabled.

The actual Python HTTP API, PostgreSQL authority, Temporal service and workers,
CustomCrawler/Chromium, artifact conversion/indexing, Qdrant and Neo4j were used.
The temporary backend harness supplied one test principal/token, the test
dataset/model registry, unique task queues and a direct signed-writer callback
route. The deployed Laravel BFF/JWT hop was not exercised live; its principal,
ownership, no-fallback and callback contracts were covered by Laravel/backend
tests. A deployed authenticated BFF smoke remains an activation prerequisite.

Initial fixture setup exposed an unavailable Qdrant hostname and a missing
physical test collection. Generation 1 failed safely and retirement retained its
fence while deletion returned a sink error. After fixing only disposable network
attachment and provisioning the empty 1,024-dimensional cosine test collection,
the same authoritative recrawl path recovered. No source, writer or generation
state was manually edited to bypass a guard.

| Observation | Result |
|---|---|
| Real Chromium URL crawl | Completed and sealed; artifacts converted and indexed |
| Main source `source_7d185536c8cd326b9ddf512ed21a531c` | Generation 2 reached `ready`, then recrawl generation 3 reached `ready` |
| Qdrant before deletion | **6 scoped points** |
| Neo4j before deletion | **13 scoped nodes, 7 scoped relationships** |
| Repeated frozen generation-3 `/crawl` | `job_exists`; total job count remained **3**, no execution added |
| Signed late generation-2 completion | `ignored: true`; generation 3 unchanged |
| Sitemap-only source `source_84339dfd23feadb11d7e8096e2ee9979` | Reached completed/ready; one page, terminal/sealed/quiescent/admission-closed receipt |
| Delete completed URL and sitemap sources | HTTP **200** after verified retirement |
| Active source `source_416e7b1c07b3c990a47324e9771ec0dc` | Observed pending/running and unsealed before delete; cancellation drained within delete request, HTTP **200** |
| Active crawl's final durable receipt | `cancelled`, `cancel_requested=true`, terminal/sealed/quiescent/admission-closed all true, zero pages |
| Final source audit | All **3 deleted**, `current_generation=null`; generation counters **4, 2, 2** |
| Final artifact audit | All three owned `/shared/sources/<source>` roots absent |
| Final writer audit | All start generations released or explicitly closed with no writers; all external receipts sealed |
| Final sink audit | **0 Qdrant points, 0 Neo4j scoped nodes, 0 scoped relationships** |

The live cancellation completed synchronously; deterministic tests separately
verify HTTP 202 retention while cancellation or writer drainage remains pending.
Permanent external sealed receipts remained readable after source artifact
retirement. All six temporary containers and their artifact volume were removed
after validation. The isolated test database and this report retain the
retirement audit; the test collection is empty.

## Remaining crawler URL variable references

Repository configuration/source/test/documentation references are listed below.
Canonical execution always prefers `EXTERNAL_SCRAPER_URL`; the legacy value fills
an absent canonical value at composition. Frozen Python workflow input carries
that resolved endpoint; request metadata cannot select an execution origin.

| File | Remaining use |
|---|---|
| `.env.example` | Both sample settings; canonical/alias and opt-in deployment comments |
| `config/scraper.php` | Canonical API URL with legacy fallback; legacy URL also supplies the separate task-UI default |
| `config/temporal.php` | Canonical workflow scraper URL with legacy fallback |
| `python_backend/src/hawki_backend/url_ingestion/settings.py` | Canonical startup setting, legacy fallback and validation error |
| `python_rag/services/hawki_scraper_worker/src/hawki_scraper_worker/settings.py` | Canonical worker setting with legacy startup fallback |
| `app/Services/Pipeline/Health/PipelineWorkerHealthCheck.php` | Operator-facing configuration/worker diagnostics |
| `python_backend/tests/url_ingestion/test_security.py` | Alias fallback and canonical-precedence tests |
| `_changelog/1.5.0-upgrade.md` | Historical crawler configuration reference |
| `_documentation/Operations/5_environment_db_queue.md` | Configuration inventory and canonical/alias explanation |
| `_documentation/Operations/python_url_ingestion.md` | Current configuration and activation runbook |
| `python_backend/README.md` | Backend configuration explanation |
| This validation report | Reference inventory and semantics |

`CRAWLER_INGESTION_BACKEND` defaults to `laravel`, and
`HAWKI_BACKEND_URL_INGESTION_ENABLED` defaults to false. The task-UI address does
not authorize Python URL execution; its mutation proxy remains closed in Python
mode. The currently deployed Laravel selector was verified read only.

## Diff review

Reviewed tracked and new implementation/tests for dual dispatch/persistence,
fallback, ambiguous delivery, premature cleanup, stale writes, endpoint authority,
sitemap fetch paths, exception handling, production selection and scope. No
Laravel mirror rows, fallback fetcher, alternate execution owner, broad cleanup
or unrelated refactor was introduced. Existing status/event/log projections and
mixed ownership remain covered by regression tests. Network security remains in
CustomCrawler. No staging, commit, merge, rebase or push was performed.
