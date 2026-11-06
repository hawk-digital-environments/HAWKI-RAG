# Epoch 11 URL ingestion operations

Status: **BLOCKED by one external crawler runtime prerequisite**. Defaults and
production traffic are unchanged. The in-repository retirement gate, refresh
claims and mixed-owner compatibility are implemented. No supported deployed
crawler transport currently establishes connection-bound public-network safety;
URL dispatch and sitemap expansion therefore remain disabled.

## Configuration and ownership

| Setting | Default | Meaning |
|---|---|---|
| `CRAWLER_INGESTION_BACKEND` | `laravel` | BFF authority selector; `python` forwards URL mutations, invalid values fail closed |
| `HAWKI_BACKEND_URL_INGESTION_ENABLED` | `false` | Compose Python URL service |
| `HAWKI_PYTHON_URL_INGESTION_URL` | `http://hawki_backend` | Trusted BFF upstream origin |
| `EXTERNAL_SCRAPER_URL` | Existing deployment value | Canonical physical crawler address |
| `CUSTOM_CRAWLER_URL` | Legacy Laravel setting | Composition-time alias only if canonical value is absent |
| `HAWKI_URL_SITE_PROFILE_ROOT` | Empty | Approved server-owned profile root; empty rejects all profile paths |

Reuse Epoch 8 workflow control, callback signing keys, the Epoch 7 exact
dataset/model registry and the existing shared artifact mount. Never expose
scraper credentials or let callers override physical service configuration.
Readiness reports `scraper_configured` separately from `scraper_healthy: null`.
No unverified external health endpoint is probed. `ready` remains false.

Laravel resolves a concrete user through the existing managed mutation
principal resolver: valid session/PAT, or exactly one active user when no
explicit credential exists. No users or multiple users without credentials
return 401. Invalid explicit Authorization returns 401 even with a session.
The BFF signs a short-lived `rag:ingest` JWT with `azp=hawki-bff` and the
configured tenant. Python owns final exact INGEST authorization. Locked
dataset/grant rows are rechecked before request/source/generation allocation.

There is no automatic fallback, dual persistence or second dispatch owner.
Python runtime uses `hawki_backend.*` only. Legacy rows are retained solely
for pre-cutover operation history/drain. They are never adopted through a
two-way ingestion-source mapper.

## URL and sitemap safety

Allow only HTTP/HTTPS. Reject credentials in URLs, opaque/unsupported schemes,
localhost/internal names, unsafe parser forms and any DNS answer that is
loopback, private, ULA, link-local, multicast, unspecified or non-routable.
Metadata endpoints and mapped/transition IPv6 forms are rejected too.
Security validation does not canonicalize accepted identity spelling.
Identity is trim and HTTPS scheme insertion; host case, fragments, query
ordering, ports and trailing slash remain significant.

A public DNS result alone is not permission to fetch. Every redirect, child
page, asset and browser subrequest needs public-network enforcement bound to
the connection's actual address. A public-to-private redirect and DNS
rebinding must be impossible at that boundary. The current crawler cannot
prove this, so `ExternalCrawlerBoundary` and the scraper worker fail closed.
Do not replace the refusal with a boolean configuration assertion.

In Python mode Laravel does not load `sitemap_url` or `sitemap_path`.
Python validates remote sitemap targets then returns 503 pending a safe
fetch/expansion adapter. Local paths return 422. Site-profile paths reject
traversal and must resolve to a file below the configured allowed root,
including symlink resolution. The root must be controlled by the server.

## Durable state and recovery

Migration `0005` adds four tables: requests (batch idempotency and public task
identity), sources (tenant/dataset/exact URL identity), generations (frozen
workflow/sink/artifact identity and writer state), events (hash and receipt).
Workflow dispatch uses the existing Epoch 8 operation ledger. PostgreSQL
constraints and source locks serialize generation claims and publication.

An initial URL source starts at generation 1. A recrawl claims a successor,
closes the predecessor's writer gate, reconciles/cancels the exact run,
requires a sealed remote-job drainage receipt and signed writer drainage,
cleans historical vector/graph targets,
verifies artifact retirement, then prepares/dispatches the successor.
Retry workflow IDs retain `ingest-source-<source>-retry-<n>-<job>` form.
An uncertain start reconciles the same durable workflow; absence during
reconciliation never licenses another start. Replaying a key with changed
request content returns 409.

Delete and explicit recrawl claims check the observed generation under the
source lock. Recovery of a failed historical job cannot replace a newer
generation. A delete without an `Idempotency-Key` resumes the same pending
delete and replays its completed result; the BFF preserves HTTP 202 while
writer drainage or reconciliation is pending.

Source deletion follows the same fence/drain/cleanup protocol and publishes
`deleted` only after verification and final generation checks. An active or
uncertain writer keeps the operation pending. Workflow closure is not writer
drainage. Vector cleanup uses frozen collection/dataset/source/document scope;
graph cleanup uses frozen database/namespace and document contributions.
Shared graph entities are not broadly deleted. Cleanup failure retains the
durable fence for reconciliation.

Artifact layout remains `sources/<source_id>/raw/`, `markdown/`, and the existing
`ingest/manifest.json`. One multi-page crawl remains one logical source.
The manifest is verified before publication. Content identity remains unknown
with versioned manifest evidence; a URL hash is never labeled a content hash.
Production recrawl/delete cannot complete artifact retirement until the external
job boundary can prove scraper/converter quiescence, including delayed attempts.

Signed callback classification checks a unique durable workflow operation and
its expected explicit `owner=url`, `url_ingestion_operation_id` and generation.
Missing managed IDs, workflow prefixes and URL heuristics do not establish
Python URL ownership. URL callbacks relay to Python URL endpoints, managed
callbacks retain the existing managed boundary, and proven legacy operations
retain the legacy handler. Unknown/ambiguous ownership fails closed.
Writers reuse `ManagedWriterRegistration` and the existing guarded sinks;
URL acquire/release binds operation, source, dataset, generation and execution.

### External-job ownership and retirement

Migration `0006` upgrades existing Epoch 11 databases with durable `external_job`
evidence and source cadence/due-time fields. The unique generation job ID is
reserved before dispatch; its generation, workflow ID and run ID are retained on
the same row. Observations include lifecycle, cancellation intent timestamp,
last evidence time and terminal receipt. A lost cancellation response resumes
that exact job; it never starts a replacement job.

Retirement order is fence → close the exact workflow → request crawler cancel
when active → verify remote terminal **and** `quiescent: true` **and**
`writer_admission_closed: true` → drain registered writers → clean frozen
Qdrant/Neo4j targets → verify artifact deletion → publish deletion or prepare the
successor. The admission receipt must guarantee that delayed `/crawl` requests
for that same job ID cannot restart it. Terminal status, Temporal cancellation,
404, an unavailable crawler, or cancellation acknowledgement alone keep HTTP
202 reconciliation pending. No sink cleanup or artifact retirement occurs.
Evidence only updates its own old generation and cannot publish a successor.

### Generation-aware refreshes

Python URL refreshes use source-level cadence/due-time state and the executable
`python -m hawki_backend.url_ingestion.scheduler`. Run it once per minute under
an external supervisor/cron in the backend environment. It creates no Temporal
refresh schedule and stores no frozen scheduled ingest input. UTC boundaries
are daily at 02:00, Sundays at 02:00, or the first of the month at 02:00.
`null`, `none`, or an empty cadence disables refreshes.

A tick rechecks the last requesting user's current exact INGEST grant and calls
the manual `recrawl` path with the observed generation. PostgreSQL source locks
and generation/revision comparisons admit at most one successor. Running,
deleting, not-due, changed or already-claimed sources skip; duplicate ticks may
replay the same idempotent operation. Pending scheduled retirement resumes the
same operation on subsequent ticks. Manual recrawls can change cadence; delete
clears it atomically, and recreate requires an explicit new cadence. Remove old
Laravel Temporal refresh schedules during cutover; they are never adopted.

### Mixed ownership and public compatibility

One Python classifier joins generation/revision/text authority with Epoch 8
operations. One Laravel classifier requires positive task/job/source evidence
in addition to Python absence before permitting legacy work. Conflicting owners
and unknown operations fail closed. Prefixes and URL shapes are not ownership.
Callbacks in Python URL mode use this classifier even when a workflow has an
`ingest-text-` prefix. URL callbacks go to URL authority, managed callbacks to the
managed handler, direct-text callbacks to text authority, and proven legacy
callbacks to Laravel. Duplicate/event collision checks remain at each owner.

All recovery variants enumerate or normalize individual IDs, authorize, dedupe
and partition before dispatch. A per-owner error is reported for that item and
never redirects another item. Legacy items retain their existing recovery
handler during drain. Python URL recovery reconciles/recrawls its generation.
Python-managed recovery uses the existing managed replacement protocol and its
frozen upload, with locked expected generation/revision checks and a stable
recovery key. Unconfirmed managed dispatch is reported as failure. Direct-text
pipeline retry has no new safe equivalent and returns an explicit 409 directing
the caller to its owning text API; no legacy dispatch occurs.

Raw scraper start/pause/resume/cancel stay closed in Python mode. Python-owned
task mutations without an equivalent source/document/text operation return 409;
proven legacy task controls retain their handler after authorization.

Task lists, job/failed-job reads, per-job status, task events and stage log
view/download have authenticated Python projections. These read existing URL,
managed and text authority without creating Laravel rows. URL stage logs render
signed callback evidence, not arbitrary shared filesystem files. Laravel merges
explicit authorized legacy candidates with Python results, dedupes ownership,
and applies common filtering/order before a single global limit: task start
time descending or failure timestamp descending, then public identity ascending.
Bulk mutation inventories are complete and are not truncated display lists.

### Concrete external-runtime prerequisite

The external crawler is built outside this repository from
`/Users/ixdlab/Documents/CustomCrawler` (container `crawl4ai-service`). Runtime
inspection confirmed `CrawlConfig` has no validated IP, allowed IP set, resolver,
proxy, redirect policy, transport adapter or crawl-security-policy field.
`CrawlStatus` contains only job ID/status/message/counts/times/output directory.
`cancel_job` calls `task.cancel()` and immediately returns acknowledgement.
`run_crawl_job` publishes terminal status before its final cleanup; submission
can overwrite the same job ID. Neither status nor cancellation proves permanent
drainage. The parser and asset downloader use independent aiohttp connections
with automatic redirects, while pages use browser crawling.

The exact call chain is backend `UrlIngestionService` → Epoch 8 dispatch →
`IngestSourceWorkflow` → `scrape_source` → `ScraperService` →
`ExternalJobClient` (`POST /crawl`, `GET /status/{job_id}`) → CustomCrawler.
The current scraper payload has no way to pin connections or mediate redirects.

Required external deliverable: **a reviewed CustomCrawler runtime release with
connection-bound public-only fetch/expansion for every browser request, redirect,
asset and sitemap, plus durable sealed-job drainage receipts**. It must validate
all discovered sitemap targets before queueing, verify TLS against the original
hostname, reject non-public/transition addresses and prevent delayed duplicate
job starts after closure. It must expose an actual enforceable contract that the
backend and scraper adapter can verify. There is no environment assertion that
enables the present unsupported crawler. Keep both dispatch gates closed until
that real contract is available and its adapter is reviewed and tested.

## Activation gates and sequence

The remaining merge blocker is the external deliverable above, including the
connection-bound sitemap path. The local protocols and deterministic tests do
not claim that the deployed crawler implements these capabilities. Production
connectivity and traffic migration alone are activation prerequisites.

1. Deploy additive schema and backend, keeping traffic on Laravel.
2. Deploy the BFF with the selector still `laravel`.
3. Deploy compatible callback/writer workers.
4. Configure the canonical scraper setting and approved profile registry/root.
5. Quiesce URL mutations across all entry points, including CLI/MCP/control routes.
6. Drain Laravel-owned URL workflows; retain their durable ownership evidence.
7. Remove/reconcile legacy refresh schedules.
8. Reconcile pending recovery identities and uncertain starts.
9. Reconcile writer admission, release and uncertainty state.
10. Confirm there are no ambiguous or late unclassified callbacks.
11. Switch all BFF/backend replicas together after all readiness gates pass.
12. Smoke-test start, recrawl, delete, schedule, callback and denied principals.
13. Reopen URL mutation traffic.

## Rollback

Quiesce mutations first. Drain/reconcile Python URL workflows, schedules,
callbacks, writers and cleanup before changing any replica to `laravel`.
Retain Python generations and dispatch evidence. Never copy Python URL state
into Laravel or let Laravel retry it. Existing selectors for query, text and
managed documents remain independent. An outage or rejected URL is not a
reason to retry through legacy ingestion.

## Validation

Use a disposable PostgreSQL database whose name ends in `_test` for the
`HAWKI_EPOCH11_TEST_DSN` suite. It exercises actual advisory/row locking,
idempotency, concurrent recrawl, callback collisions, generation constraints,
delete/recrawl exclusion and dataset/grant rechecks. Unit tests use fake
external ports and do not prove external crawler or live sink behavior.

### Remaining-blocker validation on 2026-11-06

See [Epoch 11 blocker validation](epoch11_blocker_validation.md) for the current
commands, counts, limitations, and live-check classifications. No production
selector or traffic was changed. No commit, reset, clean, or push was performed.
