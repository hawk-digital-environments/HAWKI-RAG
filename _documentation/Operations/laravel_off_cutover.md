# Laravel off runtime cutover

Date: 20 November 2026. Branch: `feat/laravel-off-cutover`.

This records the initial source cutover and its original acceptance attempt.
For subsequent local provisioning, migration and current runtime results, see
[the local runtime repair report](laravel_off_local_runtime.md). The file count,
runtime failures and statements about secret generation below describe that
earlier attempt.

The source now defines a standalone Svelte/nginx frontend, a protected server-side
UI transport, and service-authenticated FastAPI authority for new traffic. Local
acceptance is **blocked**, so the repository is **not yet ready** to claim
`feat(cutover): run RAG without Laravel` as a proven runtime cutover.

Laravel was stopped during the acceptance attempt and remains stopped. Its source
and historical PostgreSQL data remain intact. No commit, branch merge, cherry-pick,
HAWKI repository change, real secret generation, or PostgreSQL migration was performed.

## 1 Laravel dependencies found

| Previous runtime dependency | Cutover implementation or remaining condition |
| --- | --- |
| Blade shell, CSRF metadata, JSON bootstrap, PHP page routes | Static `frontend/index.html` and route/bootstrap selection in `frontend/main.js`; existing Svelte components retained |
| Browser `/api/*` calls and Laravel BFF | Browser calls use `/ui-api/v1/*`; the separate UI transport forwards an externally issued service JWT to FastAPI |
| Laravel browser principal and user-only Python restrictions | Active Compose forces service-only JWT authentication; dataset, managed and URL operations accept service grants |
| Laravel JWT signing and public BFF JWKS | External asymmetric issuer/JWKS; server-side OAuth client credentials or issuer-rotated token file; no local JWT signing |
| Worker events and derived writer endpoints on `hawki_rag_app` | Signed `/internal/pipeline/*` dispatcher resolves the durable Python owner and reuses the existing family handlers |
| Legacy Temporal deletion receipts and writer proof | Authenticated rejection with 409; legacy jobs must drain against their original ledger before removal |
| PHP uploaded-source and stage-log downloads | Authenticated Python streaming/download routes using existing owner and grant checks |
| `/ui/tasks/new` and crawler UI assets through PHP | Read-only `/ui/*` transport in the protected Python UI boundary; mutations return 409 |
| Compose health dependencies on Laravel | Removed from every active service; PHP and bridge remain explicit legacy profiles |
| Laravel shared-volume bootstrap | Python entrypoint initializes only the `/shared` mount root; historical artifacts are not recursively changed |
| `make up-core` calling PHP migrations | Default startup uses the Python runtime and health checks; migrations are explicit operator actions |
| `php artisan schedule:work` | Legacy profile only; Python dataset reconciliation retained and existing URL scheduler integrated into FastAPI lifespan |
| Legacy migration/readiness scripts that contact Laravel | Retained for migration, drain and rollback; not invoked by default runtime startup or health checks |
| Already-running old worker replicas | The existing indexer container was confirmed to still target Laravel. Existing workers were not silently redeployed or historical work adopted. |

No RabbitMQ service is defined in the current base Compose configuration. The
current Python worker topology uses Temporal. PostgreSQL, Temporal, Qdrant,
Neo4j, Ollama and the reranker have no active Laravel health dependency.

## 2 Exact files changed

81 paths, including this report:

```text
.dockerignore
.env.example
.gitignore
Makefile
_documentation/Operations/laravel_off_cutover.md
docker-compose.ui.yml
docker-compose.yml
docker/env/epoch15c.cutover.env.example
docker/frontend.Dockerfile
docker/frontend/nginx.conf
docker/python_rag/80-rawki-runtime-permissions.sh
frontend/index.html
frontend/main.js
package.json
python_backend/.env.example
python_backend/README.md
python_backend/alembic/env.py
python_backend/alembic/versions/0009_graph_snapshots.py
python_backend/src/hawki_backend/auth/dependencies.py
python_backend/src/hawki_backend/callbacks.py
python_backend/src/hawki_backend/datasets/api.py
python_backend/src/hawki_backend/datasets/service.py
python_backend/src/hawki_backend/deployment.py
python_backend/src/hawki_backend/main.py
python_backend/src/hawki_backend/managed_documents/api.py
python_backend/src/hawki_backend/managed_documents/repository.py
python_backend/src/hawki_backend/managed_documents/schema.py
python_backend/src/hawki_backend/managed_documents/service.py
python_backend/src/hawki_backend/ops/api.py
python_backend/src/hawki_backend/ops/schema.py
python_backend/src/hawki_backend/ops/service.py
python_backend/src/hawki_backend/ops/snapshots.py
python_backend/src/hawki_backend/readiness.py
python_backend/src/hawki_backend/text_ingestion/service.py
python_backend/src/hawki_backend/ui_gateway.py
python_backend/src/hawki_backend/url_ingestion/api.py
python_backend/src/hawki_backend/url_ingestion/catalog.py
python_backend/src/hawki_backend/url_ingestion/ownership.py
python_backend/src/hawki_backend/url_ingestion/repository.py
python_backend/src/hawki_backend/url_ingestion/scheduler.py
python_backend/src/hawki_backend/url_ingestion/schema.py
python_backend/src/hawki_backend/url_ingestion/service.py
python_backend/src/hawki_backend/url_ingestion/task_commands.py
python_backend/src/hawki_backend/workflow_control/api.py
python_backend/tests/api/test_health.py
python_backend/tests/api/test_query.py
python_backend/tests/auth/test_dependencies.py
python_backend/tests/auth/test_laravel_off_service.py
python_backend/tests/datasets/test_api.py
python_backend/tests/datasets/test_service.py
python_backend/tests/managed_documents/test_laravel_off.py
python_backend/tests/ops/test_api.py
python_backend/tests/ops/test_snapshots.py
python_backend/tests/test_deployment_configuration.py
python_backend/tests/test_epoch15c_readiness.py
python_backend/tests/test_laravel_off_configuration.py
python_backend/tests/test_retrieval_import.py
python_backend/tests/test_ui_gateway.py
python_backend/tests/text_ingestion/test_api.py
python_backend/tests/url_ingestion/test_laravel_off.py
python_rag/packages/artifact_store/tests/contract/test_shared_storage.py
python_rag/services/hawki_converter_worker/src/hawki_converter_worker/settings.py
python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/settings.py
python_rag/services/hawki_scraper_worker/src/hawki_scraper_worker/settings.py
resources/js/api.js
resources/js/datasets-dashboard.js
resources/js/health-gate.js
resources/js/pipeline-health-dashboard.js
resources/js/playground/graph-visualization.js
resources/js/playground/pipeline-start.js
resources/js/playground/pipeline.js
resources/js/playground/urls.js
resources/js/svelte/README.md
resources/js/svelte/apps/HawkiRagPlayground.svelte
resources/js/svelte/apps/PipelineUploadModule.svelte
resources/js/svelte/apps/SettingsPage.svelte
resources/js/svelte/apps/SystemTroubleshooter.svelte
tailwind.config.js
tests/js/api-boundary.test.js
tests/js/graph-ops.test.js
vite.frontend.config.js
```

## 3 FastAPI endpoints and authority

Existing Python query, dataset, grant, text-ingestion, managed-document,
URL/pipeline, graph and operations services remain authoritative. Equivalent
services were reused rather than copied behind another Laravel BFF.

| Added or changed surface | Behavior |
| --- | --- |
| `GET /v1/health`, `/v1/readiness` | Existing liveness and protected readiness retained; schema requirement advances to 0009 |
| `POST /v1/query`, dataset/grant routes | Existing API; active runtime refuses user-kind tokens and uses service/tenant/action/dataset grants |
| `GET /v1/query/datasets` | Includes only query-ready datasets granted to the service |
| `POST /v1/datasets/{id}/query-grants/self` | Grantless query services cannot bootstrap access without `rag:datasets:manage`; existing grants may replay |
| Existing managed-document and URL APIs | Removed user-only assumptions; durable ownership records and recovery retain principal type |
| `GET /v1/documents/uploads/download` | Requires query scope and exact tenant/document/dataset authorization; uses recorded upload identity, path containment and checksum verification; no directory scan fallback |
| `GET /v1/pipeline/tasks/{id}/stages/{stage}/logs/download` | Downloads the existing authorized Python log projection |
| `GET /v1/scraper/tasks` | Sanitized external crawler catalog with bounded requests and generic failures |
| `GET /v1/ops/config` | Existing configuration API extended with safe UI bootstrap and upload capabilities; deployment-managed settings remain read-only |
| `GET /v1/ops/health` | Python readiness, monitor compatibility envelope and effective configuration; management scope required |
| `GET/POST /v1/ops/graph/snapshots`, `GET/DELETE /v1/ops/graph/snapshots/{id}` | Python-owned graph views scoped to the service, tenant and currently authorized dataset |
| `/internal/workflow-control/*` | Existing signed control plane retained; Laravel-off mode refuses new legacy `deletion/start` operations |

Alembic revision `0009` adds graph views and principal-type fields to the four
Python managed/URL owner tables. Existing rows retain their historical user type;
new service identities cannot collide with a similarly named historical user in
idempotency keys. No `public.*` table is changed. SQL was rendered offline for
review only. Applying the migration and granting the dedicated runtime role the
required privileges remain deployment work. The new graph-view table requires
SELECT, INSERT and DELETE privileges; runtime DDL remains forbidden.

Historical generic user-principal representations remain for migration and
recovery compatibility. No Laravel user repository, Sanctum token abstraction,
Gate policy, browser fallback, login, logout, password flow or browser-session
system was introduced in Python.

## 4 Frontend calls and server authentication

The existing pages remain at `/admin`, `/admin/pipeline`, `/admin/datasets`,
`/admin/graph`, `/admin/retrieve`, `/pipeline-controller`, `/datasets`,
`/neo4j-graph-explorer`, `/hawki-rag-playground`, `/settings`, and `/pipeline-health`.
The existing Svelte components and DOM runtimes still render them.

`apiUrl()` sends requests through `/ui-api/v1/` and maps the former operation names:

- `query`, `query/datasets`, datasets, documents and pipeline operations use their existing Python equivalents.
- `rag/neo4j/graph/*` becomes `ops/graph/*`; graph clear becomes `ops/graph/clear`.
- `rag/stats` becomes `ops/stats`.
- `rag/monitor`, `pipeline/health` and `health/system-gate` become `ops/health`.
- `settings/config` becomes `ops/config`.
- `pipeline/controller/files` becomes the existing multipart `documents` API; the upload UI consumes its nested pipeline response and retains retry keys.

The UI transport accepts management authorization only when the configured HAWKI
endpoint returns 204. It forwards the original Cookie and request method/path to
that endpoint; ordinary login HTML or another status does not authorize access.
HAWKI must authorize management of the configured service's tenant and grants.
No HAWKI code was changed to invent or bypass that policy.

The transport obtains its service JWT either from a read-only `access.jwt` file,
rotated by the issuer, or through HTTPS OAuth client credentials using
`client.secret`. OAuth tokens are cached until shortly before expiry. API calls
receive the server credential, never a browser-supplied Authorization header or
browser cookie. Outbound responses cannot create shared human sessions. Unsafe
browser methods require the exact configured Origin and a custom request marker.
Service credentials are never returned in JavaScript, bootstrap JSON or responses.

The examples were updated; they deliberately contain placeholders and blank
credentials. Only an empty ignored `.secrets/ui-service` directory was created
locally. No actual token, client secret or signing key was generated.

## 5 Worker callback paths

All three callback-producing worker defaults and active Compose overrides now use:

```text
http://hawki_backend/internal/pipeline/worker-events
```

The existing derived endpoints become:

```text
/internal/pipeline/managed-writers
/internal/pipeline/managed-deletion-events
/internal/pipeline/managed-deletion-writer-proof
```

The dispatcher checks the timestamp and HMAC over the original bytes, then
classifies ownership from persisted preparations/revisions/generations. Missing,
legacy, mixed or conflicting ownership is refused. The existing text, managed
and URL handlers retain their source/job/task/run checks, exact-payload collision
checks, writer barriers and replay behavior. Tests cover first delivery, replay,
collision, invalid/stale signatures, unknown owners and revoked grants.

New text operations now freeze the same normalized queue input as the control
ledger. Historical Python text input that omitted server-selected queues is
compared after that precise normalization; changed physical scope still fails.

New Python deletions continue to use the existing family cleanup services,
quiescence checks and frozen targets. The legacy Temporal deletion ledger is not
recreated or guessed in Python. Its signed callbacks are refused with 409, and
legacy work must drain before final cutover. Compatibility class names such as
`LaravelCallbackClient` remain source names for the unchanged HTTP/HMAC protocol;
they do not require a Laravel server when configured for the Python endpoint.

## 6 Compose and nginx

The default topology is:

```text
HAWKI service -- service JWT --> nginx /v1/* --> hawki_backend
Browser --> nginx static Svelte UI
Browser --> /ui-api/v1/* --> protected UI transport -- service JWT --> hawki_backend
Workers -- signed callbacks --> hawki_backend /internal/*
```

`/ui/*` is a protected, read-only external crawler transport. nginx rejects public
`/internal/*` with 404, obsolete `/api/*` with 410, and PHP paths with 404. It has no
FastCGI/PHP request path. `hawki_rag_app` and the bridge remain available under
`legacy-laravel` and `legacy-bridge` profiles. No service or persisted volume was
deleted. `docker-compose.ui.yml` no longer publishes Laravel's port; the standalone
frontend owns the configurable loopback port.

`docker compose config --services` and `ps` supplied the actual service names.
The active backend, worker, storage and supporting-service dependency graph no
longer requires Laravel. Python services share `/shared` and keep the existing
non-root/group contract. The Python entrypoint initializes the mount root without
relying on the PHP entrypoint or changing historical files recursively.

## 7 Scheduler and migration commands

New work requires no PHP scheduler. Existing Python archived-dataset cleanup and
its enable flag remain intact. FastAPI periodically runs the existing URL
reconciliation/refresh scheduler, preserving its grant checks and recovery state.
The one-shot Python scheduler CLI remains available.

Default `make up-core` no longer shuts down the stack to run PHP migrations.
`make migrate-core` is an explicit Python Alembic action requiring an operator's
migration DSN. `migrate-legacy`, the PHP scheduler configuration, and old source
remain legacy-only. No migration command was executed against local PostgreSQL.

## 8 Tests and builds

| Command or check | Exact result |
| --- | --- |
| `cd python_backend && .venv/bin/pytest -q --tb=short` | 1922 passed, 209 skipped, 48 failed, 2 warnings; 21.77 seconds |
| Backend baseline before edits | 1886 passed, 209 skipped, the same 48 failed |
| Backend failures | All 48 are `text_ingestion/test_artifacts.py::test_real_php_fingerprint_and_artifact_bytes[...]`; macOS does not retain the asserted setgid directory mode in these temporary directories |
| `cd python_rag && .venv/bin/pytest -q --tb=short` | 758 passed, 33 skipped, 1 failed; 2.99 seconds |
| Worker failure | Existing `test_store_sources_respect_line_limit`: unchanged `graph_store/graph.py` has 638 lines against a 600-line assertion; HEAD already contains those 638 lines |
| `node --test tests/js/*.test.js` | 24 passed, 1 skipped, 0 failed |
| Ruff across backend source/tests/Alembic and `python_rag` | Passed, using `python_backend/.venv/bin/ruff` |
| `npm run build` | Passed; Vite warns about the large graph bundle |
| `docker compose build hawki_backend hawki_rag_frontend` | Both final local images built successfully |
| `docker compose config --quiet`, shell syntax, `git diff --check` | Passed; Compose warns about unset optional Temporal UI host variables |
| `make -n up-core BUILD_STACK=0` | Default startup contains `up -d --wait` and no PHP migration invocation |
| Alembic `upgrade head --sql` with a placeholder DSN | Passed; SQL rendered offline, not applied |

Focused tests additionally cover service JWT cryptography/tenant/scope refusals,
service query grants, text/managed/URL ingestion, direct callbacks, graph views,
source integrity and cross-tenant downloads, HAWKI gate refusal, CSRF, server-side
OAuth exchange, cookie isolation, compressed responses and the crawler read-only
transport. External stores, model runtimes and Temporal are faked in deterministic
API tests; those results are code verification, not live ingestion acceptance.
The opt-in PostgreSQL, live model and other integration cases remain skipped.

## 9 Laravel stopped smoke results

`hawki_rag_app` was stopped using its discovered Compose service name and was
never restarted. The final frontend, UI transport and backend images were started
individually with `--no-deps` for the limited HTTP checks. Backend schema readiness
remained enabled throughout. The ordinary combined Compose startup correctly
failed on the backend health dependency, so this is not a successful full-stack
acceptance run.

Final local HTTP results:

| Request | Status |
| --- | --- |
| GET `/` | 200 |
| GET `/assets/index-D6SwDmEf.js` | 200 |
| GET `/admin` | 200 |
| GET `/admin/pipeline` | 200 |
| GET `/admin/datasets` | 200 |
| GET `/admin/graph` | 200 |
| GET `/admin/retrieve` | 200 |
| GET `/settings` | 200 |
| GET `/pipeline-health` | 200 |
| GET `/ui/tasks/new` | 503 |
| GET `/v1/health` | 502 |
| GET `/v1/readiness` | 502 |
| GET `/ui-api/v1/datasets` | 503 |
| GET `/ui-api/v1/pipeline/tasks` | 503 |
| GET `/ui-api/v1/ops/graph/overview?dataset_id=example` | 503 |
| GET `/ui-api/v1/documents/uploads/download?document_id=example` | 503 |
| GET `/internal/pipeline/worker-events` | 404 |
| GET `/api/query` | 410 |
| GET `/index.php` | 404 |
| POST `/v1/query` | 405 |
| POST `/ui-api/v1/query` | 503 |
| POST `/ui-api/v1/text-ingestions` | 503 |
| POST `/ui-api/v1/documents` | 503 |
| POST `/ui-api/v1/pipeline/tasks/start` | 503 |
| POST `/internal/pipeline/worker-events` | 404 |
| POST `/ui/api/crawler/submit` | 409 |

The 200 results prove static page shells and the asset bundle load without PHP.
They do not prove authenticated data rendering. The UI boundary returns 503
because it is unconfigured. FastAPI cannot boot its ASGI application: filtered
logs explicitly report `Backend schema readiness failed before cleanup startup`.
The direct API's 405 response is an unbooted upstream result, not a successful JWT
refusal test; the authentication contract is verified by the deterministic tests.

| Requested acceptance path | Verification state |
| --- | --- |
| FastAPI health and readiness | Code tests pass; live blocked by schema/configuration |
| RAG frontend loads | Locally smoke-tested: shells/assets 200 with Laravel stopped |
| Dataset listing | Code tests pass; live UI transport 503 |
| Authenticated query | Service-grant tests pass; live blocked |
| Unauthorized query | JWT/scope tests return 401/403; live application unavailable |
| Text ingestion | Service API and direct callback tests pass; live blocked |
| Managed ingestion/read/delete | Existing and new service/download tests pass; live blocked |
| URL/crawler ingestion | Service/ownership/callback tests pass; live blocked |
| Pipeline/workflow status and control | Existing suites pass; live blocked |
| Worker callback | Signed Python dispatch tests pass; public nginx blocks it with 404 |
| Graph access and saved views | Scope/tenant/service tests pass; live blocked |
| Source and log download | Code tests pass; live source route 503 through unconfigured boundary |
| HAWKI-style service JWT | Real RS256 request tests pass; no live issuer credential was supplied |

The pre-existing storage/model services remained running. The existing indexer
replica still targets Laravel; it was not represented as a successfully cut-over
worker. Its code/default/Compose target is changed, but live replicas need a
verified drain and redeployment.

## 10 Concrete blockers

1. The local `.env` has no Python database DSN, issuer, JWKS URL, client registry
   or query-model configuration. The supplied example files are placeholders.
2. A read-only PostgreSQL inventory transaction found no `hawki_backend` schema.
   Revision 0009, the dedicated runtime role, imported ownership and explicit
   service grants therefore cannot be verified in this environment.
3. No server-side UI token or client-secret directory existed. The authentication
   mechanism is implemented, but real issuer credentials must be provisioned
   externally. They were not generated to make the test pass.
4. The HAWKI management authorization endpoint/origin is not configured locally.
   Its 204 authorization contract must be deployed and verified by HAWKI; this
   repository does not implement human authentication or modify HAWKI.
5. Legacy workflow/writer/deletion drainage is not proven, and existing old worker
   replicas still need configuration reconciliation and redeployment. Legacy
   deletion receipts cannot safely be adopted into an absent Python ledger.
6. Full model/store/crawler/Temporal ingestion acceptance remains blocked behind
   those prerequisites. No readiness or security guard was disabled to bypass them.

## 11 Commit readiness

**Not yet ready for `feat(cutover): run RAG without Laravel` as a proven cutover.**
The code and local images prepare that topology, with deterministic service and
security tests passing apart from the identified baseline failures. Resolve the
provisioning and legacy-drain blockers, then repeat all live acceptance paths with
Laravel still stopped before treating runtime cutover as complete. Nothing was
committed, and PHP deletion remains a separate later task.
