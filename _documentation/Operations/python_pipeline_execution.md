> **Historical Laravel-era reference.** The application/runtime described below has been removed. Do not run its Laravel/PHP commands. Use the [current Python deployment guide](python_runtime.md); the original text is retained as migration history.

# Epoch 13: Python pipeline execution and legacy drain

Epoch 15A uses Python defaults. Follow the [coordinated cutover and drain runbook](epoch15a_cutover.md) for current activation/rollback ordering; earlier epoch procedures below retain their migration-specific prerequisites.

## Selector and activation

`PIPELINE_EXECUTION_BACKEND=laravel|python` chooses the authority for **new URL
pipeline starts**. The checked-in default is now `python`. Invalid values return 503;
there is no fallback. No production environment is changed by this epoch.

Python activation requires:

- `CRAWLER_INGESTION_BACKEND=python`, with the existing URL crawler configuration.
- Alembic through `0008`, the existing authenticated BFF JWT configuration,
  active Python datasets and exact tenant/user INGEST grants.
- `HAWKI_BACKEND_URL_INGESTION_ENABLED=true`,
  `HAWKI_WORKFLOW_CONTROL_ENABLED=true`, valid Python workflow control and its
  PostgreSQL ledger, the existing callback secret and shared storage.
- Valid `HAWKI_WORKFLOW_CONTROL_URL`, `HAWKI_PYTHON_URL_INGESTION_URL`,
  `HAWKI_RAG_TEMPORAL_ENABLED=true`, and a nonempty
  `HAWKI_RAG_WORKER_CALLBACK_SECRET` in Laravel.
- The existing compatible CustomCrawler permanent-job API and protected browser
  deployment preflight. Its network/browser security is unchanged.
- Python URL readiness must answer `ready=true` before the BFF forwards a start.
  Python independently validates authorization, scope and safe crawler capability.

Coordinate all BFF replicas and freeze new admissions while changing selectors.
With pipeline execution set to `python`, the URL start goes straight to the
existing Python URL service. Laravel creates no pipeline task/job/stage/source
rows and dispatches no workflow. The response remains HTTP 201 with
`{success, task_id, task}`. Reuse the same `Idempotency-Key` after response loss.
A supplied task identity cannot alias another durable owner.

With pipeline execution set to `laravel`, new starts use the existing Laravel
path. The crawler selector remains the earlier epoch's compatibility boundary;
it is not another pipeline-start selector. Python task reads/commands continue
through durable ownership while that boundary is active.

## Ownership and rollback

The execution selector never classifies an existing task. Keep
`CRAWLER_INGESTION_BACKEND=python`, Python authority storage, workflow control,
workers and callback routing available while Python work exists. Rolling back
**only `PIPELINE_EXECUTION_BACKEND`** to `laravel` changes new admissions. Python
URL tasks still route to Python for detail, jobs, failed jobs, events, logs,
status, cancel, delete and recovery. Python outages fail; they never permit local
mutation. Rolling back the crawler boundary itself retains its existing Epoch 11
restriction: Python work must be drained first. The CLI also preserves an explicitly supplied Python task identity.
A config rollback is not a schema rollback; migration `0008` refuses downgrade with explicit cancellation state.

For a start replay following response loss during a pipeline-selector rollback,
the BFF resolves the authenticated user's idempotency key in the existing URL
request authority (`GET /v1/pipeline/start-request`). An existing request is
replayed through Python; it is not admitted as a new Laravel task.

Legacy routing requires matching durable Laravel task/job/source evidence and
an absent or compatible Python owner. Unknown or conflicting identities fail
closed (409). Neither prefixes, a selector nor absence alone proves legacy
ownership. Mixed inventory authorizes legacy rows, rejects conflicts before
merging, deduplicates deterministically, orders Python rows first and applies
one global limit after merge. Within each partition, timestamp descending and
public identity ascending break ties.

## Task cancellation

`POST /api/pipeline/tasks/{taskId}/cancel` authorizes before intent or external
side effects. The original `url_ingestion_requests.task_command` freezes the
action, original authorized principal and source/generation membership. This
single nullable JSON field is the task intent; no task table or generic command
ledger is added. One accepted action owns a pending task, even when callers use
different/no idempotency headers. Pending intents reserve their sources against
new recrawls before the first fence is allocated. Overlapping task commands
cannot reserve the same source.

Each source then uses a deterministic existing URL request/generation transition
with explicit `purpose=cancel`. Sources have explicit `cancelling` / `cancelled`
states. Generation claims close the old managed writer gates and advance the
source generation under the existing dataset, request and source locks.

The existing source resume protocol reconciles uncertain workflow starts through
`workflow_operations`, cancels/waits for the exact Temporal run, records writer
closure evidence and invokes the existing external-job quiescence protocol.
That protocol persists crawler cancel intent before POST cancellation and requires
an ownership-bound terminal, sealed, quiescent, writer-admission-closed receipt.
Unknown/404 crawler state is not quiescence. Writer releases remain necessary.
Owned refresh schedules are stopped and database refresh cadence is cleared.

Cancellation never calls Qdrant, Neo4j or artifact cleanup. Old generations remain
available for later source deletion; already indexed data, current-generation
references and artifacts are retained. Only after source completion is verified
is the task projected as `cancelled`, rather than failed or deleted.

Crashes after intent, fencing, Temporal cancellation, crawler cancellation,
drainage or source completion resume the same durable operation. Repeated remote
calls are idempotent and use the same exact run/job identities. The existing URL
scheduler (`python -m hawki_backend.url_ingestion.scheduler`) resumes pending task
intents, including intent accepted before any generation was fenced. It rechecks
the original principal's current INGEST grant. Request retries also resume them.

## Task deletion and recovery

`DELETE /api/pipeline/tasks/{taskId}` freezes every task source, then composes the
existing per-source delete protocol. It adopts already accepted source deletes
rather than competing with them. Sources already verified deleted are skipped.
Independent sources continue after a sibling cleanup failure. Successful source
cleanup remains committed; retries resume unfinished source transitions.

Existing cleanup uses frozen historical Qdrant filters (collection, dataset,
source, document), scoped Neo4j contribution cleanup and owned source artifact
paths. Its deterministic cleanup identities tolerate lost receipts and partial
completion. The task remains listed/readable as `deleting` until every frozen
source is verified `deleted`. Only then is the original request marked deleted
and its task projection hidden. Durable ownership and receipts remain; repeated
DELETE succeeds without deleting a later recrawl. Dataset archive cleanup can
finish accepted source commands before applying its existing internal deletion.

Recovery remains `POST /api/pipeline/recovery/tasks/{taskId}/retry-failed` and the
existing selected-job/dataset recovery protocols. Python task recovery forwards
to that same endpoint. `/api/pipeline/tasks/{taskId}/retry` stays unavailable for
Python tasks; no second recovery implementation was introduced.

## Legacy drain, catalog and events

No `PIPELINE_LEGACY_ADMIN` selector was added. Durable ownership and authorization
already gate legacy commands. Counts describe drain progress; zero active work
must not disable authorized historical reads or deletes. Legacy tables are kept,
without importing historical rows into Python. The surviving scraper projection
preserves paused/cancelled status through stage and job rollups.

`GET /api/scraper/tasks` reads the existing external UI task/profile catalog.
The catalog stores no execution authority in Python. Unsupported crawler
`GET /tasks` and `POST /tasks/{id}/run` fallbacks are disabled/removed. Raw scraper
start/cancel/pause/resume mutations remain blocked in Python crawler mode.
Normal crawler execution remains `POST /crawl` with a caller-supplied job id.

The worker edge retains exact-body HMAC verification. Durable Python URL owners
receive the original bytes relayed to Python, which records the authoritative
idempotent receipt. Laravel does not write worker events, jobs, stages or sources
for them. Proven legacy owners retain the local mutation path. Explicit owner
markers must agree with durable ownership.

`GET /api/pipeline/health` adds `pipeline_execution_backend`,
`active_legacy_task_count`, `active_legacy_job_count`, and `legacy_drain_complete`.
When Python is selected, `python_execution` reports readiness and readiness or
configuration failure returns 503 with `success=false`. Legacy table health
cannot establish Python readiness. Missing legacy tables produce unknown counts
and never report a completed drain. Tasks are terminal only at `completed` or
`failed`; jobs only at `completed`, `failed` or `skipped`. Unknown statuses count
as unfinished. Use `rag:legacy-drain-status --json` for stage, source, deletion,
output and retention evidence; pipeline task/job drain alone cannot authorize
legacy schema deletion.

Graph exploration, global RAG monitoring, the system gate, Neo4j/Qdrant admin and
runtime settings remain outside this transition (Epoch 14). Retrieval, models,
authentication/authorization architecture and the existing dataset, text and
managed-document authorities are unchanged.

## Epoch 13 review corrections: starts and stop commands

The launcher retains one idempotency key and serialized request body in memory
until a start succeeds. Retrying an uncertain submission reuses its task identity
and key; a subsequent successful, intentional submission gets a new key.
Keep the page open while resolving an uncertain start.

Python CLI starts require an operator-retained key:

```bash
php artisan pipeline:start-task --dataset-id=web \
  --source-url=https://example.org/a --idempotency-key=operator-submission-001
```

Retry with the same key and options, including any explicit task ID. The CLI
resolves this key through Python after a pipeline-selector rollback too. Choose
a new key only for a new intentional submission.

Task cancellation drains failed terminal generations as well as running ones;
workflow completion alone does not prove crawler/writer quiescence. It disables
owned refreshes and retains indexed data. A completed historical task never
fences its successor generation. Stop commands can fence prepared/dispatched
recrawls with completed retirement history. Unfinished retirement is resumed
without dispatching another crawl; a source-locked absence of any dispatch-ledger
entry proves a prepared generation was never submitted. Uncertain dispatch still
uses the existing workflow reconciliation protocol.

Legacy scraper paused/cancelled details remain on stage projections, while job
lifecycle values remain running/failed so task counters stay coherent. In Python
execution mode, job upsert accepts only an existing, proven legacy job on the
same task and updates it without creation or reparenting. Cross-task job
reparenting is rejected in Laravel execution mode as well.

Python-mode legacy drain updates have no editable metadata keys: nonempty
submitted metadata returns 409, and empty/omitted metadata preserves existing
ownership and recovery evidence exactly. Stored task/source/workflow identifiers
are not accepted as editable fields through the job route.
The launcher keeps its pending key/body after error responses, including 4xx;
an error during replay does not rule out earlier acceptance. Successful start
resolution clears that pending submission.
