# Laravel removal — uncommitted review

Verified 2026-11-21, Europe/Berlin, on `feat/laravel-off-cutover`.
HEAD remains `5391cb23 feat(cutover): run RAG without Laravel`.
All changes, including the preceding safety work, remain unstaged/uncommitted.

## 1. Laravel/PHP removal inventory

| Major area | Classification and disposition |
| --- | --- |
| `app/`, controllers/services/models/middleware/requests/providers/auth/Gates/BFF/signer | A — removed |
| `bootstrap/`, Laravel config/routes/Blade, `artisan`, public PHP front controller | A — removed; disposable compiled caches removed locally |
| Composer manifests/vendor, PHPUnit/PHP tests, Laravel Docker/PHP-FPM/supervisor/scheduler | A — removed |
| Source-level migrations/seeders/factories | A — removed from operational source; no reverse migration executed |
| `resources/js`, Svelte/TypeScript/DOM helpers, CSS, standalone frontend shell, favicon | B — retained in place for the active static build |
| Historical public schema, old API description, migration/forensic reports | C — retained as explicit history; no database table/data removed |
| PHP test oracles and shared fixture; retired staging template | D — captured/moved to explicit Python-owned test fixtures before removal |
| Old local ignored provider config | D — moved verbatim into ignored `.local-runtime/legacy-config`; excluded from images |

The tracked deletion set contains **599 files**. The complete names and review
outputs are in the [file inventory](laravel_removal_files.md).

## 2. Frontend preservation

The existing `resources/js/**` application, `resources/css/**`,
`frontend/index.html`, `frontend/main.js`, `public/favicon.svg`, Svelte config,
PostCSS and Tailwind remain. No application redesign or source relocation was
needed. The old Blade shell and legacy Vite configuration were removed.

`npm run build` uses `vite.frontend.config.js`; `npm run dev` now selects the same
standalone config. nginx serves `public/frontend` built inside the frontend image.
The browser uses `/ui-api/v1/*`; its bootstrap/config comes from static JS and
authorized Python APIs. There is no PHP rendering, generated route requirement,
Laravel session/CSRF token, or browser-visible service secret.

## 3. Compose removal and retained workers

Removed `hawki_rag_app`, `legacy-laravel`, `hawki_rag_bridge`, `legacy-bridge`,
old worker replica definitions and their `legacy-workers` overlay selectors.
Removed the Laravel source-mount/BFF/CA overlays. The old bridge Docker builder
and runtime targets were removed; its Python package remains compatibility tests.

Current worker definitions use their existing active names:
`rawki_local_workflow_worker`, `rawki_local_scraper_worker`,
`rawki_local_converter_worker`, `rawki_local_indexer_worker`. Their dedicated
local queues and direct signed Python callbacks remain. Base definitions now
describe these workers, and the local overlay supplies local queue/issuer/crawler
settings. CPU/GPU overlays merge correctly without duplicate groups or overriding
GPU selection back to CPU.

PostgreSQL, Temporal/UI, Qdrant, Neo4j, Ollama, optional LiteLLM, reranker,
FastAPI, gateway, frontend and upgraded crawler are retained. No persisted volume
was removed. Old stopped containers remain outside the current model to preserve
their historical logs/metadata; they are not running and have restart policy `no`.

## 4. nginx and runtime

The active nginx configuration serves static `index.html`, routes `/ui-api/v1/*`
to the Python gateway and intentionally exposes authenticated `/v1/*` to FastAPI.
It preserves security headers, asset caching, `/api/* -> 410`, internal ingress
denial and `.php -> 404`. There is no FastCGI, PHP upstream, PHP front controller
or Laravel fallback. Removed `docker/rag_app` and the Laravel Dockerfile.

The backend/gateway, frontend, four current workers and crawler were rebuilt from
the post-removal tree and deployed. The gateway shares the rebuilt backend image.
Worker callback class names are now `PipelineCallback*`; the signed byte protocol,
HMAC/replay/ownership checks and retries are unchanged. Metadata adapter naming
now describes FastAPI callbacks instead of a removed Laravel client.

## 5. Environment cleanup

Removed **89 obsolete variable names** from shared example/local configuration,
including Laravel `APP_*`, PHP settings, Sanctum/session/cache/queue/mail/MCP
configuration, BFF signing configuration and Laravel Python-client proxy URLs.
Their consumers were removed application code, rather than current Python roles.
The full name-only list is in the inventory; no secret values are included.

Retained DB/Postgres aliases consumed by infrastructure and local operator tools,
shared UID/GID/storage settings, provider/model/store keys, generic proxy/subpath
settings, service JWT/JWKS, UI gateway credentials and worker callback security.
`TEMPORAL_CLIENT_IDENTITY` is Python-named and callback defaults point to FastAPI.

Historical migration selectors stay `python` where validators still consume them.
`HAWKI_BACKEND_LARAVEL_OFF=true` remains a security fence for legacy starts and
receipts; it does not enable a removed service. The old Laravel staging template
is now inert test data. Ignored operator credentials remain ignored.

## 6. PHP/Composer tooling

Removed `composer.json`, `composer.lock`, the local Composer vendor installation,
`phpunit.xml`, PHP tests/oracles, `artisan`, Laravel entrypoint/configuration,
and the legacy Vite configuration. Removed `laravel-vite-plugin` and the
Composer-dev-only `concurrently` dependency and their unused lock entries.
`npm ci --offline` verified the updated lock and refreshed local Node dependencies.

Captured independent historical expectations before deleting the PHP oracles:
48 text metadata cases, eight metadata-size boundaries, seven signed control
wire cases, and the original managed-upload fixture. JWT tests now generate
ephemeral service keys in memory. No deployment signing key or secret was generated or committed.

## 7. CI and development tooling

Removed the `laravel-callbacks` PHP/Composer CI job and its deleted-source triggers.
Preserved Python/backend/reranker/worker and documentation CI. Added a Node-only
frontend install/test/build job. Compose validation in CI uses the placeholder
cutover template rather than requiring an ignored local credential file.

Make targets now build/check Python and frontend code; retired Laravel migration
and test commands are gone. Local startup uses the current Python overlay. The
crawler helper starts the pinned permanent-job-v2 service instead of the old
sibling runtime. Explicit Alembic migration remains an operator action.

The deployment audit is Python-only. The old BFF live-activation checker is
test-only under `python_backend/tests/historical`, refuses actual PHP commands,
and is not imported by runtime/operator tools. Old live activation CLI switches
fail with a direction to current readiness. Historical data planning remains
separate from runtime authority.

## 8. Database/history preservation

No migration/reverse migration, table drop, historical SQL update or fabricated
ownership/grant row was executed. All **27 public tables** have identical
before/after row counts and SHA-256 digests over their sorted complete row hashes.
The column inventory is documented in [public schema history](../History/public_schema.md).

Alembic remains **0009**, authoritative only for the new `hawki_backend` schema.
Fresh Python deployments do not need the removed source-level Laravel migrations.
Their original source remains in Git at `5391cb23:database/migrations/`.
The restricted API database role and read-only history audits remain enforced.

## 9. Orphan archive and files

The exact archive remains:

```text
/shared/.historical-orphans/48e50c6ffe69b535c9c7a478c859547126d9ca687b09dc6abd1afe5446cfdbb7.json
```

Its SHA-256, custody **UNKNOWN**, deletion **UNVERIFIED**, original identifiers,
reservations and six file paths/sizes/hashes match the pre-removal snapshot.
The source/upload files were not moved, rewritten or deleted. Full paths/hashes
remain in the [custody report](historical_orphan_graph_acceptance.md).
All four archived crawler records and their artifact-state hashes also match;
the archive was only read and validated, never reconciled or resealed.

## 10. The 16 legacy SQL records

All 16 matching records across nine public tables remain unchanged. This follows
both the retained forensic row hashes and the complete public-table digest
comparison. The failed deletion was not made terminal. There is still no fake
tenant, parent dataset, grant, Python owner, handoff or sink-completion receipt.

## 11. Compose validation and image rebuilds

Default/base and local `docker compose config --quiet` pass.
All-profile base, local and GPU+local models validate with no Laravel or bridge
service/profile. CPU models select CPU indexer builds; GPU+local selects GPU.

The active local service list is:

```text
ollama postgres qdrant rawki_local_auth rawki_local_crawler hawki_rag_neo4j
hawki_backend temporal rawki_local_workflow_worker hawki_rag_rerank
hawki_rag_ui_gateway hawki_rag_frontend rawki_local_indexer_worker
rawki_local_scraper_worker rawki_local_converter_worker
```

Optional all-profile services are `litellm` and `temporal-ui`. The crawler's source
remains the pinned exported/validated context. No rebuild depended on deleted PHP
files or a previously built Laravel application image.

## 12. Readiness and processes

Final backend readiness: **26/26 PASS**, revision **0009**. The separate Python
deployment audit passes **43/43**, including callback secret/window/destination
agreement, running workers and real indexer Qdrant credentials. No active PHP
process was found in the container process inventory. Dedicated Python queues
have current pollers; legacy queues have zero pollers.

During disposable dataset initialization, strict Qdrant catalog probes temporarily
reported FAIL before collection initialization. Final idle readiness and UI health
were rechecked successfully; no probe, timeout, auth or catalog check was relaxed.

## 13. Live acceptance after source removal/rebuild

| Check | Result |
| --- | --- |
| Frontend + Python gateway | HTTP 200 |
| Authorized `/ui-api/v1/ops/stats` | HTTP 200 |
| Authorized `/ui-api/v1/ops/health` | HTTP 200, `ready` |
| Authorized `/ui-api/v1/query/datasets` | HTTP 200 |
| Missing/invalid service JWT | HTTP 401 |
| Valid service query | 1 vector hit + 7 graph facts |
| Text + managed upload | Completed; managed document `indexed` |
| Converter and indexer | Real activities executed successfully |
| Managed download | Byte-identical, SHA-256 `6e343c6ea8068c8bfde6392788cd17f0e405115b0a5b19b081314fdbed39a357` |
| Real graph ingestion | 7 Neo4j nodes / 7 edges |
| Graph overview/node/search/expand/semantic search/clear | PASS |
| Managed/text deletion and dataset retirement | PASS through Python API |
| Qdrant / Neo4j / source-upload cleanup | Zero remaining contributions/content |
| URL HTML/sitemap/idempotency/private-address rejection/cancellation | PASS |
| URL receipts | Three sealed/quiescent owner-verified receipts: two completed, one cancelled |
| URL source/storage retirement | Three sources deleted; zero Qdrant points/artifacts |
| Disposable workflows and extraction cache | Zero open app workflows; zero cache files |
| Archived `gebuhren` recreation | HTTP 409 `historical_identity_reserved` |
| Archived document identity | HTTP 404, no Python owner |
| Public `/internal/*` / obsolete `/api/*` | HTTP 404 / 410 |
| Browser secret scan | 27 built JS assets checked; no actual service/operator credential or private key |

Graph dataset: `graph-accept-98171e64e185`; URL dataset:
`crawler-v2-e0d78ee06887`. Both are retired. Only normal immutable audit/tombstone
rows and ineffective grant-history rows remain. The real model extraction count
differs from the previous 9/10-node/edge run; no fixed/fabricated triples were used.
The disposable URL fixture and network were removed, normal crawler networking
restored, and durable receipts rechecked after restart. No historical data was
cleaned up. Worker callbacks went directly to signed FastAPI endpoints.

## 14. Frontend and documentation validation

Clean locked Node install: PASS. Frontend tests: **24 passed**. Standalone build:
PASS; the existing large graph-layout bundle warning remains. The documentation
site build passes with historical notices and source references corrected for
removed paths. Existing Svelte source/style behavior was retained.

## 15. Python, worker and crawler tests

- Full backend: **1929 passed, 205 skipped, 48 known macOS setgid failures**.
  Independent captured expectations pass before the unchanged `0o2775` assertion.
  Clean relevant rerun: **1929 passed, 205 skipped, 48 deselected**.
- Full deterministic RAG: **758 passed, 33 integration cases deselected,
  one pre-existing line-limit failure**. `graph_store/graph.py` is 638 lines both
  at HEAD and now, with no diff; the 600-line boundary was not weakened.
  Clean relevant rerun: **758 passed, 34 deselected**.
- Focused updated deployment/storage/auth checks: **162 passed**.
- Rebuilt crawler contract/lifecycle/network/broker tests: **77 passed**.
- Ruff across backend and RAG source/tests: PASS. Changed Python formatting: PASS.
- Live graph/text/managed/URL and separate cleanup/history/security audits: PASS.

There are **no remaining new test failures**. Opt-in database/live/model cases
are distinguished from the two existing baselines; no unrelated behavior was
changed to make them green. Real runtime acceptance covers the configured stores,
model, callback and crawler paths.

## 16. Remaining Laravel/PHP references

| Classification | Remaining references |
| --- | --- |
| A — historical documentation | Marked Laravel-era guides, old epoch notes/OpenAPI, forensic source paths and Git migration history |
| B — migration/removal notes or compatibility | Inert captured fixtures, test-only mocked old deployment models, negative security tests, archive producer identifiers, retained wire/schema enum values and fail-closed legacy receipt handling |
| B — benign name matches | nginx `.php` denial, CSS query `composer` class, safety ignore patterns, repository skills, sibling file-converter's own local Compose filename |
| C — active missing runtime dependency | **None found** |

No BrowserQueryPrincipalService/UserRepository/Sanctum/Gate/BFF/signer/controller
is required by current work. The PHP implementations were deleted; no Python
equivalent human user system was introduced. Source search and actual processes,
image builds, service configuration and live requests support this distinction.

## 17. Files deleted

All 599 tracked deletions are expanded in the inventory, including application,
auth, routes, migrations, Blade, PHP tests/tooling, old overlays and Dockerfiles.
Local compiled framework/PHPUnit caches and installed Composer dependencies were
also removed. Unrelated local storage/app data and logs were preserved.

## 18. Files modified

The inventory expands all **110 modified tracked files**, including Compose/Make/env/build/CI,
current/historical documentation, migrated test consumers, shared callback names
and worker imports. Prior uncommitted safety changes are retained and separately
identified by the earlier reports.

## 19. Files added

New files include the current runtime guide, this report/inventory, historical
schema/OpenAPI/epoch notes, frontend CI, independent test fixtures and service-JWT
tests, test-only historical validator, and the renamed metadata adapter. Existing
uncommitted archive/smoke/security files from the preceding work remain visible.
The inventory lists all **26 expanded untracked paths**.

## 20. git diff --stat

The exact full output is preserved in the inventory. It describes tracked
unstaged changes; newly added untracked files are listed separately, as Git
normally excludes them from unstaged diff statistics.

```text
709 files changed, 862 insertions(+), 80555 deletions(-)
```

## 21. git diff --check

PASS. No patch-whitespace errors remain.

## 22. Final git status and safety

The inventory records exact `git status --short`. The index is empty; HEAD is
unchanged. No commit/amend/rebase/merge/cherry-pick/tag/push was performed.
No `.secrets`, JWT/private key, ignored cutover env, `.local-runtime` private
config, or `/tmp` evidence is tracked or staged. All historical volumes/data
remain. Six-file and complete public-table/archive hash checks pass.

Local audit evidence is retained under `/tmp/rawki-removal-*` and is not tracked.
Detailed durable custody evidence remains in the original shared-volume archive,
not in temporary acceptance logs.

## 23. Verdict

LARAVEL REMOVAL COMPLETE — UNCOMMITTED
