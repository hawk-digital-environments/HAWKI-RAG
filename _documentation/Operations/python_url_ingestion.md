# Epoch 11 URL ingestion operations

Status: **current CustomCrawler integration enabled behind the existing opt-in
selector**. Production remains on Laravel. The adapter checks the permanent-job
API before dispatch; the external runtime owns all connection-bound HTTP/browser,
redirect, sitemap, asset and WebSocket enforcement. Terminal status alone never
permits retirement: the shared receipt model requires seal and drainage evidence.

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
Readiness reports configuration separately from live crawler API compatibility
and liveness. `ready` requires the enabled service, workflow control and a
compatible healthy crawler. It does not attest browser namespace readiness;
the deployment probe below is mandatory before activation.

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

A public DNS result alone is not permission to fetch. RAWKI performs admission
validation and checks the configured crawler's current permanent-job API and
liveness. The reviewed CustomCrawler implementation owns connection-bound
resolution, redirects, Chromium subrequests, assets and WebSockets. No backend
or Laravel sitemap HTTP client is added. API compatibility is not a cryptographic
attestation of the deployed image or proof of kernel namespace readiness.

Remote XML/JSON/sitemap-named resources are passed as the crawler URL entrypoint
with `sitemap_only=true`; its resource parser handles fetch/redirect/target safety.
CustomCrawler's `sitemap=true` is a separate profile-driven HTML sitemap mode,
used only with an approved server-owned profile. Arbitrary resource endpoint names
require such a profile. Local sitemap paths remain rejected. Recrawl retains the
frozen sitemap request. Site-profile root/traversal/symlink restrictions remain.

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
Retirement uses the exact crawler receipt and the HAWKI writer ledger. URL
converter artifact writes now register in that same ledger; uncertainty is never
cleared by Temporal cancellation. Asynchronous converter jobs are not admitted
for Python URLs because their separate drainage contract is outside this epoch.

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
`writer_admission_closed: true` **and** `sealed: true` with `sealed_at` → drain registered writers → clean frozen
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

Run the tick even when all URL sources have refresh disabled: it also reconciles
pending prepared starts through the existing idempotent dispatch ledger, then
every dispatched URL's exact Temporal run. This includes manual ingestion after
a backend restart: a missing dispatch receipt is recovered for the same
generation and operation, without user request replay. Confirmed receipts are
reused. A dispatching/uncertain operation first describes Temporal: an owned
active or completed execution supplies its run receipt. For operations claimed
with URL dispatch protocol 1, `NOT_FOUND` permits resubmitting the same identity.
The protocol marker commits in the existing dispatch ledger before the RPC.
URL starts use `REJECT_DUPLICATE` plus `USE_EXISTING`. Source, generation, URL operation, control
operation and workflow family/type memo must match before adoption. Lookup
failures or foreign ownership remain pending for future ticks. Foreign ownership
rejection is persisted so later history expiry cannot authorize a new start. Other workflow
families retain their existing reuse policies.

Protocol 1 removes the retention deadline from recovery. URL workflows have no
workflow execution/run timeout; their activity deadlines and retry limits remain
unchanged. Once ingestion succeeds or finally fails, the workflow exposes
`url_terminal_outcome` and stays open until the backend acknowledges its exact run
and control operation. Open workflows do not enter closed-history retention.
The backend records the run receipt and final outcome in the existing PostgreSQL
generation/dispatch records, applies terminal failure or verifies publication,
then sends `acknowledge_url_terminal`. The scheduler retries missing acknowledgements
even after pending ownership has cleared. After acknowledgement, history may
expire: confirmed dispatch receipts and durable terminal evidence suffice, and
recovery never resubmits the operation. No new generation, operation, workflow ID,
database table, or distributed lock is introduced.

Deploy the updated workflow workers before the backend; remove older worker
processes from the workflow queue before enabling protocol 1 starts. Existing
histories without the protocol memo replay their original activity sequence.
An older unconfirmed operation without the ledger marker may adopt retained
owned history, but `NOT_FOUND` is ambiguous: its history may already have expired.
It remains pending for independent reconciliation rather than risking a duplicate.
Previously erased history cannot be reconstructed by this change. Cancel/retire
URL workflows through the backend so their receipts and writer fences are durable
before cancellation; direct administrative history deletion is outside the protocol.

Activity failure callbacks
retain attempt evidence and pending ownership while Temporal can retry. Only a terminal
failed/timed-out/canceled/terminated run, or a completed run returning the existing
failed/skipped result, finalizes failure, closes writer admission and releases
pending ownership. Unavailable or unrecognized outcomes retain ownership. Existing
active or uncertain writer entries still require drainage before recrawl/delete.

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

### Current external contract and deployment prerequisite

The external repository `/Users/ixdlab/Documents/CustomCrawler` is inspected read
only. RAWKI does not modify or duplicate its transport implementation. Deploy the
reviewed current image, not an older image behind the same tag. The adapter
checks `/openapi.json` for the permanent job schema and unfiltered durable status
endpoint, and `/health` for liveness. Unknown/legacy/unavailable APIs fail closed
before source allocation or external dispatch. Workers repeat the check.

`POST /crawl` receives the frozen `job_id`, `url`, `source_id`, `source_url`,
`generation`, `url_ingestion_operation_id` and server-owned `output_dir`. It returns
`job_id`, `event=job_submitted|job_exists`, and `data.status/sealed/message`.
`GET /status/{job_id}` and `POST /jobs/{job_id}/cancel` return the durable receipt:
`job_id`, `external_job_id`, `status`, `state`, `terminal`, `sealed`, `sealed_at`,
`quiescent`, `writer_admission_closed`, `output_directory`, and ownership metadata.
Known terminal states are exactly `completed`, `failed`, `cancelled`. Unknown,
inconsistent or foreign receipts cannot publish completion or authorize cleanup.

The backend reserves the external identity before Temporal dispatch. Ambiguous
POST delivery and activity replays submit the same immutable payload/key; the
crawler's durable admission ensures one execution. The scraper polls through a
terminal-but-unsealed state. Its signed result stores the verified receipt in
its own generation, without publishing the URL source before indexing completes.

The crawler appends `job_id` to `output_dir`. RAWKI retains its source artifact
layout and consumes `/shared/sources/<source>/raw/<job_id>` (including supported
shared-mount aliases). New job roots are siblings, never reused permanent roots.
Retirement removes the old owned source artifacts only after all drainage gates.

Protected Chromium requires Linux unprivileged user/network/PID namespaces,
`/usr/bin/unshare`, procfs, installed Chromium, and CustomCrawler's documented
`docker/browser-seccomp.json`. See its `docker/BROWSER_EGRESS.md`. Do not grant
privileged/SYS_ADMIN/NET_ADMIN to the production runtime. Default Docker seccomp
can reject namespace creation. The crawler fails startup before unprotected
Chromium can launch; RAWKI never falls back to another fetcher.

Before activation, run this non-fetching browser startup probe in the *new crawler
container*, as its service user, with the intended seccomp/LSM configuration:

```python
import asyncio
from hawki_crawler.runtime.browser import SafeBrowser

async def check():
    async with SafeBrowser() as browser:
        print(browser.proof)  # isolated namespace IDs, PID 1, loopback only

asyncio.run(check())
```

RAWKI's `/internal/url-ingestion/readiness` now reports live API compatibility and
liveness. `/health` alone cannot verify namespace/seccomp. The startup probe and
a disposable real Chromium crawl remain deployment checks; interface compatibility
must never be described as proof that a different deployed image is safe.

## Activation gates and sequence

The external contract is available. Activate only after deploying the reviewed
crawler and passing its namespace/browser preflight, current workers, backend,
and authenticated BFF checks. Default URL ownership remains Laravel.

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

### Validation history

The [2026-11-06 blocker validation](epoch11_blocker_validation.md) records the old
unsupported runtime. See [current integration validation](epoch11_crawler_integration_validation.md) for current
commands, counts, limitations, and live-check classifications. No production
selector or traffic was changed. No commit, reset, clean, or push was performed.
