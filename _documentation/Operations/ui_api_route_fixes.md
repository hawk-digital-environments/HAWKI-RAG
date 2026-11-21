# Frontend API integration fixes

Verified 2026-11-21, Europe/Berlin, for the Python RAG runtime.
HEAD remains `315e129a`. Existing worktree changes were preserved. Nothing staged or committed.

## 1. Exact failures before the fix

Captured through credential-free `http://localhost:8080`, including headers and
bodies, in `/tmp/rawki-ui-routes-before.json` and
`/tmp/rawki-ui-routes-upload-before.json`:

| Request under `/ui-api/v1` | Status | Exact body |
| --- | --- | --- |
| `GET /pipeline/tasks?limit=30` | 200 | `{"success":true,"tasks":[]}` |
| `GET /scraper/tasks` | 503 | `{"detail":"crawler_catalog_unavailable"}` |
| `GET /documents` | 200 | `{"success":true,"documents":[]}` |
| `POST /documents` | 404 | `{"success":false,"message":"The requested dataset is not available."}` |

The POST used the real frontend contract: multipart `dataset_id=controller-uploads`,
`graph=false`, `converter_mode=native`, and a small controlled `file`; no manual
Content-Type or multipart boundary. It included Origin, the browser marker and an
idempotency key, without browser credentials.

All four responses had JSON Content-Type, `Cache-Control: no-store`, nosniff and
same-origin referrer policy. None had WWW-Authenticate or Set-Cookie. Gateway and
backend logs confirm the requests reached `/v1/scraper/tasks` (503) and
`/v1/documents` (404); these were application failures rather than missing proxy
routes. Logs are retained under `/tmp/rawki-ui-routes-before-runtime.log` and
`/tmp/rawki-ui-routes-after-runtime.log`; no service credential was printed.

## 2. Scraper root cause and fix

The local backend inherited `CUSTOM_CRAWLER_TASK_UI_URL=http://crawl4ai-service`,
the retired crawler hostname, while URL ingestion correctly used
`EXTERNAL_SCRAPER_URL=http://rawki-local-crawler:8080`. The catalog used the stale
override. The active crawler also has no `/ui/api/profiles` or `/ui/api/tasks`
routes: both return 404. Its healthy permanent-job API is distinct from the
optional Svelte saved-profile/task UI.

The local overlay now selects the actual crawler for catalog reads. If that
exact API returns 404 for the default profile path, the existing full
permanent-job-v2 contract and health validator must pass before the backend
returns an explicit empty catalog:

```json
{"success":true,"tasks":[],"message":"This crawler has no saved profile/task catalog. URL ingestion remains available through the pipeline API."}
```

No task definitions or historical jobs are fabricated. A separate catalog's
404, a custom missing path, HTTP errors, an invalid contract or unhealthy crawler
still return 503. The URL-ingestion contract gate is unchanged and remains
mandatory. `/scraper/tasks` still requires `rag:ingest` service authentication.

## 3. Upload root cause and fix

`PipelineUploadModule.svelte` defaulted to `controller-uploads` and posted the
file immediately. There was no active dataset or exact ingest grant for that
identifier. FastAPI correctly refused it before ingestion; the multipart parser,
service JWT and route worked. Global ingest scope alone does not grant a dataset.

The form now prepares the named dataset through existing canonical APIs:

1. `POST /datasets` creates it or returns a compatible existing dataset.
2. `POST /datasets/{id}/ingest-grants/self` redeems the new dataset's one-time
   bootstrap capability, or reuses an existing exact ingest grant.
3. Only successful preparation proceeds to the original multipart `/documents`.
4. Completed pipeline polling requests `/datasets/{id}/query-grants/self` so the
   server's readiness and query-grant rules allow indexed documents in listings
   and downloads.

Existing datasets without an ingest grant remain denied: creation replay issues
no replacement capability, and no wildcard or management-scope bypass was added.
Reserved, inactive or conflicting identities still fail through the canonical
dataset authority. Query access is requested only after completed ingestion and
still requires its real indexed-content checks.

The one-use dataset capability already belongs to the existing dataset API. It
is consumed in memory and never persisted in browser storage; it is not a service
JWT, OAuth client secret, signing key or human identity.

## 4. Files changed for this task

| File | Purpose |
| --- | --- |
| `docker-compose.cutover.local.yml` | Correct local catalog origin. |
| `python_backend/src/hawki_backend/url_ingestion/catalog.py` | Distinguish a verified API without an optional catalog from failure. |
| `python_backend/tests/url_ingestion/test_catalog.py` | Catalog success, absence, malformed contract, outage, separate/custom configuration and auth cases. |
| `python_backend/tests/test_ui_gateway.py` | Regression for binary multipart boundary, body, length, idempotency and service Bearer preservation. |
| `resources/js/playground/upload-dataset.js` | Existing dataset creation/grant API sequencing. |
| `resources/js/svelte/apps/PipelineUploadModule.svelte` | Prepare dataset before upload. |
| `resources/js/playground/pipeline.js` | Request readiness-checked query access after completion. |
| `tests/js/upload-dataset.test.js` | New/existing/ungranted datasets, creation errors, proxy outage and query readiness. |
| `scripts/smoke_local_ui_upload.mjs` | Execute actual frontend helpers and FormData through nginx, then remove disposable content. |
| `_documentation/Operations/python_runtime.md` | Current upload/catalog acceptance behavior. |
| `_documentation/Operations/ui_api_route_fixes.md` | This report. |

Existing worktree changes were preserved.

## 5. Frontend URLs

The failing URLs remain `/ui-api/v1/scraper/tasks` and `/ui-api/v1/documents`.
The existing `pipeline/controller/files` frontend alias still intentionally maps
to canonical `documents`. Added preparation/query-access requests use existing
dataset API URLs; no obsolete route or duplicated business API was added.

## 6. Gateway and FastAPI route trace

Registered FastAPI routers confirm `GET /v1/scraper/tasks`,
`GET|POST /v1/documents`, and `GET /v1/pipeline/tasks`. No registration changed.
The gateway already accepts GET/HEAD/POST/PUT/DELETE through its common
`/ui-api/v1/{path}` transport and forwards to `/v1/{path}` without special
authorization or allowlist differences for these paths.

Gateway routing did not change in this task. It injects its own service Bearer,
copies Accept, Content-Type and Idempotency-Key, and excludes browser cookies and
Authorization from FastAPI requests. Responses preserve status/body and the
allowlisted content/download headers, without relaying cookies or challenges.

## 7. Service scopes and dataset authorization

No issuer, registry, key, client-secret or scope change. Signature-verified live
claims remain issuer `https://rawki-local-auth:8443`, audience `hawki-backend`,
client/subject `rag-management`, tenant `default`, scopes
`rag:query rag:ingest rag:datasets:manage`.

Pipeline/catalog reads and managed admission require `rag:ingest`; admission
additionally requires an active exact dataset ingest grant. Document reads require
`rag:query` plus a dataset query grant. Dataset creation requires the existing
management scope. The fix uses these rules rather than relaxing them.

## 8. Multipart forwarding

Unchanged. Browser FormData carries `file`, `dataset_id`, `graph`, and
`converter_mode`. The browser supplies the boundary. The gateway buffers the
original bytes within its existing 103 MiB limit, forwards the original
Content-Type and computes Content-Length from those same bytes. It does not
rebuild multipart data. Native file validation retains its 100 MiB limit.

The new gateway regression forwards a binary payload larger than 128 KiB and
asserts exact bytes, boundary, Content-Length, idempotency key and downstream
service Authorization. Nginx temporary-body buffering seen in the old logs is
normal transport behavior; it was not the cause of the 404.

## 9. Final HTTP results

No browser username/password, Basic credentials, bearer token or identity cookie:

| Request | Status |
| --- | --- |
| `GET /` | 200 |
| `GET /ui-api/v1/pipeline/tasks?limit=30` | 200 |
| `GET /ui-api/v1/scraper/tasks` | 200, explicit empty optional catalog |
| `GET /ui-api/v1/documents` | 200 |
| `POST /ui-api/v1/documents`, prepared disposable dataset | 202 |

Final headers/bodies are in `/tmp/rawki-ui-routes-final-http.json`. The successful
admission body contains `success: true`, its document and `pipeline.task_id` /
`pipeline.job_id`; the full body is retained in that report and the upload smoke
report. The smoke also asserts no challenge or cookie on its API responses.

An unprepared or ungranted dataset remains refused by POST admission; frontend
preparation is now part of the upload contract.

## 10. Managed upload end to end

`node scripts/smoke_local_ui_upload.mjs` passed using the actual frontend
`prepareUploadDataset`, `grantReadyDatasetQuery`, `apiFetch`, File and FormData.
It read no service credential. Dataset `ui-upload-1152d76cb20a` received 202,
created a task, ran real converter/indexer/model/store work and reached completed
with all jobs ready. Its document appeared as indexed in the document listing,
downloaded byte-identically, and produced **7 graph nodes / 7 edges**.

The test waits for the UI boundary before mutation. An initial deployment-warmup
attempt encountered nginx 502 before dataset creation; GET confirmed that unique
dataset absent. A bounded startup-read wait was added to the smoke. All later
admission, lifecycle and cleanup checks passed.

## 11. Cleanup

API deletion emptied the graph and retired the disposable dataset. An independent
read-only audit confirms one cleaned managed revision, service ownership, zero
Qdrant points, zero Neo4j entities/triplets, zero source/upload artifacts, completed
workflow and zero open application workflows. Two inactive grant audit rows and
deleted ownership/history rows remain as required by the normal lifecycle; no
active grants or disposable content remain.

The idle indexer was recreated after quiescence to discard extraction cache and
in-memory test state; its disposable cache file count is zero. All 27 historical
public SQL tables and four historical crawler records still match prior evidence.
No historical content was adopted or deleted.

## 12. Direct backend security

Direct `GET /v1/documents`: missing JWT **401**, invalid JWT **401**, valid
registered service JWT **200**, verified independently from the gateway container.
Public `/v1/documents` also returns 401 without a valid JWT. Foreign Origin and
cross-site browser requests to the local UI boundary return **403**.
Issuer/audience/JWKS/tenant/client/scopes/grants and signed callback/HMAC enforcement
remain intact.

## 13. Browser authentication and asset inspection

Browser username/password, Basic Auth, login, browser service Bearer and service
secrets remain unnecessary. The four GET responses have no WWW-Authenticate or
Set-Cookie. No local user or human authentication abstraction was introduced.

Rebuilt assets (34 files) and the actual served frontend (36 files) matched byte
for byte and had zero matches for existing service credential values, JWT
patterns, private-key markers or the retired browser username. Service JWTs,
client secrets and private keys remain server-side. See
`/tmp/rawki-ui-routes-assets.json` and `/tmp/rawki-ui-routes-service-auth.json`.

## 14. Readiness

Final live readiness **26/26 PASS**; Python deployment audit **43/43 PASS**.
URL ingestion remains enabled, with the unchanged mandatory crawler contract.
Only backend/gateway/frontend deployment and idle test-cache disposal required
service recreation; stores, issuer and crawler were not recreated for this fix.

## 15. Tests and builds

- Focused catalog/gateway tests: **42 passed**.
- Full backend: **1,958 passed, 205 skipped, 48 deselected** with
  `cd python_backend && .venv/bin/python -m pytest -q -k 'not test_historical_fingerprint_and_artifact_bytes'`.
  The 48 exclusions are the documented macOS setgid filesystem cases. No test or
  filesystem rule was relaxed. Managed documents, URL ingestion, authorization,
  gateway, issuer and signed callbacks are covered by this run.
- Frontend: **31/31 passed**, build passed. Existing bundle-size/Browserslist
  warnings remain.
- Backend and frontend Docker images built successfully; final served assets
  match the verified build.
- Ruff check/format check, Node syntax checks and `git diff --check` passed.
- Live managed acceptance and independent cleanup audit passed as above.

Results/logs use `/tmp/rawki-ui-routes-` prefixes, including `readiness.json`,
`deployment-audit.json`, `upload-smoke.json`, `upload-audit.json`, `history.json`,
`backend-tests.log`, `frontend-tests.log`, `frontend-build.log`, and runtime logs.

## 16. Final git status

All changes remain unstaged. No commit created; HEAD remains `315e129a`.


UI API ROUTES FIXED — BROWSER AUTH REMOVED, SERVICE AUTH PRESERVED
