# Permanent-job-v2 local crawler upgrade

Verified 21 November 2026 on `feat/laravel-off-cutover`, continuing
[the local runtime repair](laravel_off_local_runtime.md). Nothing was committed.

## Result

`hawki_backend` and the upgraded crawler are **running / healthy**. All **26 live
readiness checks PASS**, including `crawler_contract`, after removal of the
disposable fixture network and a crawler restart. URL ingestion remains enabled.
Laravel remains stopped. No authentication, authorization, schema guard or
crawler safety check was disabled or weakened.

Actual authenticated UI calls return 200 for operations stats, operations health
and query datasets. Operations health now reports `ready`, replacing `degraded`.
All three return 401 without local access credentials. Direct query dataset
requests with missing or invalid service JWTs still return 401.

## Implementation and deployment

The external CustomCrawler checkout is on `hawk/prod`, whose code/image lacks the
current protocol. Its reviewed security branch contains durable admission and
protected transport, but lacks current required ownership fields and typed
receipts. The local deployment now uses that pinned source plus a tracked patch.
The external checkout is still clean; its branch and running legacy container
were not changed.

Upstream revision: `1c327244fed3b6f99ad08ad9203814942bc10f26`.
The patch changes actual crawler request validation, durable storage, response
validation and schema generation. It does not fabricate or proxy a capability
advertisement over the old implementation.

- Every admission requires permanent job identity, dataset, task, source URL,
  source identity, positive generation, operation identity and confined output.
- The durable store retains all six owner fields in its receipts. Exact request
  replay returns `job_exists`; a changed owner/payload receives 409.
- Typed receipts require consistent lifecycle identity and terminal state. Sealing
  requires completed cancellation/resource drainage, quiescence, closed writer
  admission and an aware timestamp. Malformed legacy ownership receives 409.
- Existing fsynced permanent records, process ownership lock, write fences,
  child-task drainage, safe HTTP connections and isolated Chromium are retained.
  Removing source artifacts cannot erase or reopen a permanent job key.
- The real generated OpenAPI advertises `permanent-job-v2` and passes RAWKI's
  unchanged consumer validator. No validator exception or alternate readiness
  path was added.
- The transport additionally rejects Azure's platform address at connection time.

The explicit local overlay creates `rawki_local_crawler` at an internal endpoint,
using shared artifacts and the reviewed seccomp profile. Observed user is
`1000:1000`, with no added capabilities, privileged mode or host ports. The
post-smoke container is attached only to `rawki_default`.

Deployed image:
`sha256:328de70ebbfc77a08e73ed87d037da704ea97b03755ba66cc0cba0c4af4571a3`.

A real production-image browser probe as UID 1000 established distinct network
and PID namespaces, PID 1 and loopback only. Chromium, namespace and broker
closed successfully afterward. No NET_ADMIN or SYS_ADMIN is used by the deployed
crawler. The separate disposable browser test launcher used NET_ADMIN solely to
assign fixture IP aliases before dropping to the unprivileged service user.

New local workflow/scraper/converter/indexer workers use dedicated `rawki-local-*`
queues and current images. This was required to exercise the actual ingestion
pipeline while leaving historical worker replicas and queues untouched. Their
existing HMAC callbacks go directly to the Python owner and writer ledgers.
Only the backend loads the full cutover environment; workers use the base
environment plus narrow local queue/callback overrides. No browser credentials
or service authentication behavior changed.

## URL smoke and post-restart audit

Run: `crawler-v2-856053cefa42`. The controlled fixture's public-form numeric
address existed only on a disposable internal Docker network; no Internet site
was crawled and no runtime address policy was relaxed. Actual FastAPI, PostgreSQL,
Temporal, workers, Chromium, conversion, Ollama embeddings and Qdrant were used.

| Check | Result |
| --- | --- |
| HTML URL through protected UI boundary | Completed, query-ready |
| XML sitemap URL | Completed, query-ready |
| Repeated start with same Idempotency-Key | Same task/job, no extra execution |
| Indexed dataset visible under its actual query grant | PASS |
| Metadata/private URL submitted to ingestion API | 422, refused |
| Active slow URL cancellation | Cancelled and drained |
| Three task retirements through API | Complete |
| Dataset storage retirement through API | Deleted, cleanup not pending |
| Durable crawler receipt audit | Two completed, one cancelled; all three sealed |
| Ownership verification against frozen PostgreSQL input | PASS |
| Remaining source artifacts | 0 |
| Remaining dataset Qdrant points | 0 |
| Same read-only audit after crawler restart | PASS |

The initial smoke also completed HTML/sitemap/cancellation and task retirement.
Its dataset cleanup was completed through the proper `/datasets/{id}/storage`
route after correcting the smoke harness. The complete corrected run above then
passed without intervention. Both test datasets are retired; no historical
dataset or Laravel table was modified.

The audit uses the runtime database role and `SET TRANSACTION READ ONLY`. It
validates actual HTTP receipts against the unchanged consumer model and exact
frozen owner/path identity. No database rows or seal flags were edited to pass.
The disposable fixture container and network were removed. Durable receipts and
normal Python audit/tombstone records remain.

The fixture datasets disable graph indexing by the ordinary server defaults;
this smoke does not claim graph ingestion or an LLM answer test. Neo4j's normal
live readiness probe passes.

## Validation

| Validation | Result |
| --- | --- |
| Patched crawler ownership, lifecycle and network suites | 76 passed |
| Required real Chromium security suite | 29 passed, no skips |
| Backend URL, readiness and configuration suites | 360 passed, 69 skipped |
| Current crawler/worker image builds | PASS |
| Fresh pinned archive and safe patch application | PASS |
| Ruff on new scripts/tests and changed crawler modules | PASS |
| Ruff format check on new scripts/tests | PASS |
| Compose configuration and Git whitespace checks | PASS |

Browser tests cover real rendering/subresources, direct private addresses,
redirects, DNS rebinding, WebSockets, TLS/SNI/certificate validation, proxy bypass,
missing proxy failure, real artifact sealing and real browser cancellation.
Lifecycle tests retain restart reconciliation, writer fencing, cancellation
drainage and permanent replay assertions. Existing upstream datetime deprecation
warnings remain; no assertion was relaxed. The earlier full backend suite and
its macOS baseline failures were not rerun for this crawler and local runtime change.

Individual final readiness results:

| Check | Result |
| --- | --- |
| `auth_config` | PASS |
| `callback_replay_window` | PASS |
| `callback_secret` | PASS |
| `callback_url` | PASS |
| `control_destination` | PASS |
| `crawler_contract` | PASS |
| `database_config` | PASS |
| `database_dataset_source` | PASS |
| `database_schema_role` | PASS |
| `graph_requirement` | PASS |
| `jwks` | PASS |
| `neo4j` | PASS |
| `qdrant.dataset_catalog` | PASS |
| `qdrant.managed_deletion` | PASS |
| `qdrant.ops_catalog` | PASS |
| `qdrant.query` | PASS |
| `qdrant.text_deletion` | PASS |
| `qdrant.url_deletion` | PASS |
| `query_config` | PASS |
| `query_models` | PASS |
| `query_reranker` | PASS |
| `service_clients` | PASS |
| `service_only` | PASS |
| `shared_storage_permissions` | PASS |
| `temporal` | PASS |
| `workflow_control_relay` | PASS |

Final Compose status:

```text
hawki_backend running healthy
ollama running healthy
qdrant running healthy
hawki_rag_app exited 
hawki_rag_bridge running healthy
hawki-rag-indexer-worker running 
hawki_rag_neo4j running healthy
postgres running healthy
hawki_rag_rerank running healthy
hawki-rag-temporal-converter-worker running 
hawki-rag-temporal-scraper-worker running 
hawki-rag-temporal-workflow-worker running 
hawki_rag_frontend running 
hawki_rag_ui_gateway running 
rawki_local_auth running healthy
rawki_local_converter_worker running 
rawki_local_crawler running healthy
rawki_local_indexer_worker running 
rawki_local_scraper_worker running 
rawki_local_workflow_worker running 
temporal running
```

## Remaining historical state

Four pre-existing legacy crawler records remain `unknown`, unsealed and without
drainage evidence. They were retained and consume four of the configured five
admission slots. They were not deleted, adopted or falsely sealed. Their proper
reconciliation remains part of historical ownership handoff. All new smoke jobs
have real drained receipts; the local smoke used the remaining slot serially.

Old worker replicas and the legacy crawler remain untouched. New Python traffic
uses the upgraded crawler and dedicated local workers. This is not a claim that
historical workflows have been drained or migrated. Laravel was not restarted.

## Review and reproduction

The source patch and exact commands are documented in
[`docker/crawler/README.md`](../../docker/crawler/README.md).
Source generation stays under ignored `.local-runtime/`. The seccomp policy is
explicitly trackable despite the repository's broad JSON ignore rule, with its
upstream Apache license included. No secret material was generated for this task.

Files added/changed in this continuation:

```text
.dockerignore
.gitignore
docker-compose.cutover.local.yml
docker-compose.crawler-smoke.yml
docker/crawler/Dockerfile
docker/crawler/README.md
docker/crawler/permanent-job-v2.patch
docker/crawler/browser-seccomp.json
docker/crawler/browser-seccomp.LICENSE
docker/crawler/tests/test_permanent_job_v2.py
scripts/prepare_local_crawler.py
scripts/crawler_smoke_fixture.py
scripts/smoke_local_url_ingestion.py
scripts/audit_local_url_ingestion.py
_documentation/Operations/laravel_off_local_runtime.md
_documentation/Operations/crawler_v2_local_upgrade.md
```

All earlier uncommitted cutover work was preserved. No commit, merge,
cherry-pick, production change, historical row rewrite or Laravel restoration was
performed.
