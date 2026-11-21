# Laravel-off local runtime repair

This records the 20 November repair. The subsequent
[21 November crawler upgrade](crawler_v2_local_upgrade.md) clears the crawler
blocker and records healthy readiness plus real URL-ingestion smoke results.

Verified on 20 November 2026, branch `feat/laravel-off-cutover`. This continues
[the initial source cutover](laravel_off_cutover.md) in the same worktree.

The API and protected UI boundary now work with Laravel stopped. Live readiness
passes **25 of 26 checks**. `hawki_backend` remains **running / unhealthy** because
the external crawler lacks the required `permanent-job-v2` contract. The operator
explicitly requested that URL ingestion stay enabled and that this blocker be
reported. No readiness check, schema startup requirement or authorization rule
was disabled. This is not a fully healthy cutover.

## 1. Root cause

The backend inherited only the base environment. Its dedicated configuration was
absent, and the Python database schema had never been migrated locally. That
caused the schema startup guard to abort before the API could serve the gateway.
There was also no configured service issuer, trusted HTTPS JWKS or gateway
client credential.

After those were repaired, two live dependency problems became visible. The old
reranker image omitted the model identity required by the current contract; it
was rebuilt from existing repository source while preserving its cached weights.
The external crawler returns HTTP 200 for health and OpenAPI, but its OpenAPI has
no `x-hawki-crawler-contract` marker. Its permanent-job safety check still fails.

## 2. Files changed in this continuation

The previous worktree changes were preserved. This continuation changes or adds:

```text
.dockerignore
.env.example
.gitignore
docker-compose.yml
docker-compose.cutover.local.yml
docker/env/epoch15c.cutover.env.example
docker/local_auth/server.py
python_backend/.env.example
python_backend/src/hawki_backend/readiness.py
python_backend/src/hawki_backend/ui_gateway.py
python_backend/tests/test_laravel_off_configuration.py
python_backend/tests/test_local_service_auth.py
python_backend/tests/test_ui_gateway.py
scripts/local_cutover_database.py
scripts/provision_local_cutover.py
_documentation/Operations/laravel_off_cutover.md
_documentation/Operations/laravel_off_local_runtime.md
```

The global Compose environment anchor is unchanged. Only `hawki_backend` loads
the base environment followed by `./docker/env/epoch15c.cutover.env`. The gateway
gets a narrow set of local boundary overrides, a server-side credential mount
and public CA material. The frontend gets nginx configuration and a separate
local guard credential hash. It receives no backend environment file.

## 3. Ignored local files

The exact rule `/docker/env/epoch15c.cutover.env` is present. The real file is
ignored and excluded from the Docker build context; its `.example` remains
trackable. All generated credentials and keys are under the ignored `.secrets/`
directory, also excluded from the build context.

```text
docker/env/epoch15c.cutover.env
.secrets/ui-service/runtime-db-password
.secrets/ui-service/client.secret
.secrets/ui-service/hawki-client.secret
.secrets/ui-service/operator.secret
.secrets/ui-service/ca-bundle.pem
.secrets/ui-service/htpasswd
.secrets/ui-service/frontend.nginx.conf
.secrets/ui-service/curl.conf
.secrets/ui-service/issuer/ca.key
.secrets/ui-service/issuer/ca.pem
.secrets/ui-service/issuer/server.key
.secrets/ui-service/issuer/server.pem
.secrets/ui-service/issuer/signing.pem
.secrets/ui-service/issuer/jwks.json
.secrets/ui-service/issuer/issuer.json
```

The host `.secrets/` ancestor and issuer directory are mode 0700. The real env,
private keys, private registry, operator credential and curl configuration are
mode 0600. The gateway's `client.secret` is readable by its container user, with
host access protected by the private ancestor. The issuer directory is not
readable by that gateway user. No static `access.jwt` was needed.

## 4. Configured backend variable names

Names only, captured from the resolved configuration and compared with the
running container. There were no environment mismatches.

```text
HAWKI_BACKEND_AUTH_ALGORITHMS
HAWKI_BACKEND_AUTH_AUDIENCE
HAWKI_BACKEND_AUTH_CLIENTS
HAWKI_BACKEND_AUTH_ISSUER
HAWKI_BACKEND_AUTH_JWKS_CACHE_TTL_SECONDS
HAWKI_BACKEND_AUTH_JWKS_MAX_BYTES
HAWKI_BACKEND_AUTH_JWKS_MAX_STALE_SECONDS
HAWKI_BACKEND_AUTH_JWKS_MIN_REFRESH_SECONDS
HAWKI_BACKEND_AUTH_JWKS_TIMEOUT_SECONDS
HAWKI_BACKEND_AUTH_JWKS_URL
HAWKI_BACKEND_AUTH_LEEWAY_SECONDS
HAWKI_BACKEND_AUTH_MAX_TOKEN_TTL_SECONDS
HAWKI_BACKEND_AUTH_TENANT_CLAIM
HAWKI_BACKEND_CUSTOM_UPLOADS_ENABLED
HAWKI_BACKEND_DATASET_CLEANUP_ENABLED
HAWKI_BACKEND_DATASET_EMBEDDING_MODEL
HAWKI_BACKEND_DATASET_EMBEDDING_PROVIDER
HAWKI_BACKEND_DB_CONNECT_TIMEOUT_SECONDS
HAWKI_BACKEND_DB_DSN
HAWKI_BACKEND_DB_POOL_RECYCLE_SECONDS
HAWKI_BACKEND_DB_POOL_SIZE
HAWKI_BACKEND_DB_POOL_TIMEOUT_SECONDS
HAWKI_BACKEND_DB_STATEMENT_TIMEOUT_MS
HAWKI_BACKEND_LARAVEL_OFF
HAWKI_BACKEND_MANAGED_DOCUMENTS_ENABLED
HAWKI_BACKEND_QUERY_DATASET_SOURCE
HAWKI_BACKEND_QUERY_MODELS
HAWKI_BACKEND_QUERY_RERANKER
HAWKI_BACKEND_QUERY_RERANK_TOP_N
HAWKI_BACKEND_READINESS_JWKS_KEY_ID
HAWKI_BACKEND_REQUIRE_READY_SCHEMA
HAWKI_BACKEND_SERVICE_ONLY
HAWKI_BACKEND_TEXT_INGESTION_ENABLED
HAWKI_BACKEND_TEXT_INGESTION_QDRANT_API_KEY
HAWKI_BACKEND_TEXT_INGESTION_QDRANT_URL
HAWKI_BACKEND_TEXT_INGESTION_SHARED_ROOT
HAWKI_BACKEND_URL_INGESTION_ENABLED
HAWKI_BACKEND_URL_RECONCILE_ENABLED
```

## 5. Service authentication and verified HTTPS JWKS

`docker-compose.cutover.local.yml` is an explicit development overlay. Its
internal-only issuer uses the existing OAuth client-credentials flow and serves
public JWKS over HTTPS. It is absent from the default service set and requires
an explicit development enable flag. It refuses a non-local issuer identity.
No production configuration was modified.

The two fixed development service clients are `rag-management` and
`hawki-service`. The former has the existing query, ingestion and dataset
management scopes; the latter has query scope. Their tenant and allowed scopes
come from the private development registry. A request cannot select a different
tenant, user subject or delegation. JWTs use RSA 3072 / RS256, a unique token ID
and a five-minute lifetime. The normal verifier still enforces signature, key
selection, issuer, audience, client type, tenant, time claims and scopes. Dataset
grants remain database-authoritative; no data or grant bootstrap was fabricated.

The gateway reads `client.secret`, exchanges it server-side and caches the
short-lived JWT in process memory. The service credential and JWT never enter
browser JavaScript or browser responses. FastAPI receives a service principal.
There is no Python Laravel User, Sanctum token, browser principal fallback,
Gate policy, login/password API or browser session.

For this local profile only, nginx binds to loopback and restricts the Host
header to localhost or 127.0.0.1. A distinct shared development access credential
guards the UI through nginx HTTP Basic and the helper's `/authorize` endpoint.
It is separate from both service clients and creates no human identity records
or sessions. The gateway forwards it only to the authorization gate, with an
explicit local opt-in. Backend requests always use the separate service JWT.
The production boundary continues to rely on HAWKI for human authentication and
management authorization.

The development CA and server certificate have separate RSA keys and expire
after 30 days. Backend and gateway trust the generated public CA bundle; hostname
and certificate verification remain enabled. The backend reads keys through real
HTTPS JWKS, never from local private-key files. The helper does not publish a
host port. Expired local certificate material must be renewed before reuse.

Worker callbacks retain the existing signed/HMAC mechanism. Legacy Sanctum
environment entries have no authentication role. The local backend Temporal
identity is explicitly Python-specific; unrelated legacy variables were retained.

## 6. Local database migration

The resolved backend DSN was verified present before migration and constrained
to the existing local `postgres` service and database. The migration helper uses
the local admin connection only for schema migration and role grants, then checks
the actual runtime login. It does not log either connection string.

**Python Alembic revision: absent → `0009` (head).**

The dedicated runtime role is not a superuser, cannot create roles or databases,
has no role memberships, and cannot read historical public tables. It receives
only the Python schema's required runtime table/sequence permissions and schema
usage. Schema ownership/DDL remains with the local migration administrator.

All 27 historical `public` tables had identical row counts before and after.
No historical table was dropped, rewritten, imported or adopted. There are no
Python datasets yet, so the authorized query dataset list is legitimately empty.

## 7. Live readiness

Actual command inside the backend:

```sh
docker compose -f docker-compose.yml -f docker-compose.cutover.local.yml \
  exec -T hawki_backend python -m hawki_backend.readiness --live
```

The probe reports runtime phase, read-only operation, required revision `0009`,
and overall **FAIL**, with these individual results:

| Check | Result |
| --- | --- |
| `auth_config` | PASS |
| `callback_replay_window` | PASS |
| `callback_secret` | PASS |
| `callback_url` | PASS |
| `control_destination` | PASS |
| `crawler_contract` | FAIL |
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

The crawler still has URL ingestion enabled. A compatible implementation of the
reviewed permanent-job-v2 API and its safety semantics is required to clear the
blocker. Adding a marker alone would not establish that contract. The existing
crawler preflight continues to refuse incompatible execution.

## 8. Compose validation and service state

The resolved base and explicit local overlay configuration validates. The only
warnings are existing unset Temporal UI bind/port variables. Only the backend
image and the directly relevant reranker image were rebuilt. The issuer, backend,
gateway, frontend and reranker were created/recreated as needed. The frontend
assets did not change in this continuation; nginx config is a local bind mount.
The reranker's existing model files were copied to the external local cache
volume before its old container was replaced.

Backend status: **running / unhealthy**. The sole failing health dependency is
the retained crawler contract check. The API process itself now starts and serves
authenticated traffic. The gateway/frontend were started independently for
diagnostics after backend startup; their Compose dependency requirements and the
backend health check remain intact. Normal dependency-gated startup still cannot
claim readiness until the crawler is repaired.

Observed service states:

```text
hawki_backend running unhealthy
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
temporal running
```

Postgres, Qdrant, Neo4j, Ollama, Temporal, bridge and existing worker replicas were
not restarted. Laravel remains stopped. No production resources were contacted.

## 9. Actual HTTP requests through localhost:8080

These curl requests traversed nginx, the UI authorization gate, server-side OAuth,
the gateway and the real FastAPI/Postgres service. The separate local development
guard credential was read privately using `--config .secrets/ui-service/curl.conf`.

| Route | Without local access credential | Authorized request | Result |
| --- | --- | --- | --- |
| `/ui-api/v1/ops/stats` | 401 | 200 | `ok: true`, Python authority |
| `/ui-api/v1/ops/health` | 401 | 200 | `ok: true`, Python authority, `status: degraded` |
| `/ui-api/v1/query/datasets` | 401 | 200 | `success: true`, zero datasets |

None returns the previous `503 ui_boundary_unavailable`. Operations health
honestly reports degradation while crawler readiness fails. Browser access uses
the local guard username `local-management` and the ignored operator credential;
the service client credential is never used as a browser credential.

## 10. Direct service authentication remains enforced

Verified from inside the gateway network:

| Probe | HTTP status |
| --- | --- |
| Gateway → backend liveness | 200 |
| Real HAWKI-style service client-credentials exchange | 200 |
| Direct `/v1/query/datasets` with the resulting service JWT | 200 |
| Same direct request without a JWT | 401 |
| Same direct request with an invalid JWT | 401 |

Tokens were obtained and consumed in server-side memory and were not printed.

## 11. Remaining runtime dependencies and validation limits

The repaired API/UI path has no Laravel runtime dependency. Existing historical
worker replicas were deliberately not redeployed or assigned ownership of old
workflows. The running old indexer was previously confirmed to target Laravel;
that historical worker topology still needs the separately reviewed drain and
handoff described in the source cutover report. It is not evidence of a completed
worker cutover. Laravel remains stopped; no fallback was restored.

The external crawler is the current readiness blocker. No end-to-end URL ingest
or historical workflow adoption is claimed. The empty Python dataset inventory
also means this smoke test does not demonstrate retrieval over existing content.

Validation performed:

- Focused auth, local issuer, gateway, Compose boundary and readiness tests:
  **96 passed**.
- Full backend suite: **1933 passed, 209 skipped, 48 failed**, two warnings,
  20.51 seconds. The same 48 pre-existing macOS setgid artifact assertions failed
  in the initial baseline; no test was relaxed.
- Ruff for the changed Python files and new local helpers: **passed**.
- `git diff --check`: **passed**.
- Real backend and reranker image builds: **passed**.
- Real reranker inference: **200**, including the active model identity and
  expected document echo.
- Resolved/running backend environment comparison: **no mismatches**.
- Git ignore checks: real cutover file and generated material ignored; template
  remains trackable.
- Browser boundary and direct service HTTP probes: results above.

The prior worker-suite result remains recorded in the initial report: one
pre-existing file-length failure. That suite was not rerun for local auth/env work.

The generator is `python_backend/.venv/bin/python scripts/provision_local_cutover.py`.
It creates the real env from the template on a fresh local setup, reuses existing
valid local credentials, refuses remote Docker endpoints and suppresses values.
The database operation was performed explicitly with:

```sh
docker compose run --rm --no-deps --entrypoint python \
  -e RAWKI_LOCAL_DATABASE_BOOTSTRAP=true \
  -v "$PWD:/workspace:ro" hawki_backend /workspace/scripts/local_cutover_database.py
```

No commit, merge, cherry-pick, Laravel restart, readiness bypass, historical data
rewrite or production change was performed.

## 12. Final git status

Complete `git status --short`, including the preserved earlier cutover work:

```text
 M .dockerignore
 M .env.example
 M .gitignore
 M Makefile
 M docker-compose.ui.yml
 M docker-compose.yml
 M docker/env/epoch15c.cutover.env.example
 M docker/python_rag/80-rawki-runtime-permissions.sh
 M package.json
 M python_backend/.env.example
 M python_backend/README.md
 M python_backend/alembic/env.py
 M python_backend/src/hawki_backend/auth/dependencies.py
 M python_backend/src/hawki_backend/datasets/api.py
 M python_backend/src/hawki_backend/datasets/service.py
 M python_backend/src/hawki_backend/deployment.py
 M python_backend/src/hawki_backend/main.py
 M python_backend/src/hawki_backend/managed_documents/api.py
 M python_backend/src/hawki_backend/managed_documents/repository.py
 M python_backend/src/hawki_backend/managed_documents/schema.py
 M python_backend/src/hawki_backend/managed_documents/service.py
 M python_backend/src/hawki_backend/ops/api.py
 M python_backend/src/hawki_backend/ops/service.py
 M python_backend/src/hawki_backend/readiness.py
 M python_backend/src/hawki_backend/text_ingestion/service.py
 M python_backend/src/hawki_backend/url_ingestion/api.py
 M python_backend/src/hawki_backend/url_ingestion/ownership.py
 M python_backend/src/hawki_backend/url_ingestion/repository.py
 M python_backend/src/hawki_backend/url_ingestion/scheduler.py
 M python_backend/src/hawki_backend/url_ingestion/schema.py
 M python_backend/src/hawki_backend/url_ingestion/service.py
 M python_backend/src/hawki_backend/url_ingestion/task_commands.py
 M python_backend/src/hawki_backend/workflow_control/api.py
 M python_backend/tests/api/test_health.py
 M python_backend/tests/api/test_query.py
 M python_backend/tests/auth/test_dependencies.py
 M python_backend/tests/datasets/test_api.py
 M python_backend/tests/datasets/test_service.py
 M python_backend/tests/ops/test_api.py
 M python_backend/tests/test_deployment_configuration.py
 M python_backend/tests/test_epoch15c_readiness.py
 M python_backend/tests/test_retrieval_import.py
 M python_backend/tests/text_ingestion/test_api.py
 M python_rag/packages/artifact_store/tests/contract/test_shared_storage.py
 M python_rag/services/hawki_converter_worker/src/hawki_converter_worker/settings.py
 M python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/settings.py
 M python_rag/services/hawki_scraper_worker/src/hawki_scraper_worker/settings.py
 M resources/js/datasets-dashboard.js
 M resources/js/health-gate.js
 M resources/js/pipeline-health-dashboard.js
 M resources/js/playground/graph-visualization.js
 M resources/js/playground/pipeline-start.js
 M resources/js/playground/pipeline.js
 M resources/js/playground/urls.js
 M resources/js/svelte/README.md
 M resources/js/svelte/apps/HawkiRagPlayground.svelte
 M resources/js/svelte/apps/PipelineUploadModule.svelte
 M resources/js/svelte/apps/SettingsPage.svelte
 M resources/js/svelte/apps/SystemTroubleshooter.svelte
 M tailwind.config.js
 M tests/js/graph-ops.test.js
?? _documentation/Operations/laravel_off_cutover.md
?? _documentation/Operations/laravel_off_local_runtime.md
?? docker-compose.cutover.local.yml
?? docker/frontend.Dockerfile
?? docker/frontend/
?? docker/local_auth/
?? frontend/
?? python_backend/alembic/versions/0009_graph_snapshots.py
?? python_backend/src/hawki_backend/callbacks.py
?? python_backend/src/hawki_backend/ops/schema.py
?? python_backend/src/hawki_backend/ops/snapshots.py
?? python_backend/src/hawki_backend/ui_gateway.py
?? python_backend/src/hawki_backend/url_ingestion/catalog.py
?? python_backend/tests/auth/test_laravel_off_service.py
?? python_backend/tests/managed_documents/test_laravel_off.py
?? python_backend/tests/ops/test_snapshots.py
?? python_backend/tests/test_laravel_off_configuration.py
?? python_backend/tests/test_local_service_auth.py
?? python_backend/tests/test_ui_gateway.py
?? python_backend/tests/url_ingestion/test_laravel_off.py
?? resources/js/api.js
?? scripts/local_cutover_database.py
?? scripts/provision_local_cutover.py
?? tests/js/api-boundary.test.js
?? vite.frontend.config.js
```
