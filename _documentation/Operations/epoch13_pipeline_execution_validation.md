# Epoch 13 validation — 2026-11-15

Validation ran on `feat/python-pipeline-execution`, without a commit, merge,
push, production configuration change or production-service mutation.

## New coverage

40 Python cases were added:

- `tests/url_ingestion/test_task_commands.py`: 32 cases covering start response
  loss and duplicate identity, pre-side-effect authorization, multi-source and
  mixed-terminal cancellation, crash boundaries, stale writers, retained data,
  cancellation schedule shutdown, source reservation, scheduler reconciliation,
  partial deletion, actual cleanup adapter failures and historical sink scope,
  archived-dataset cleanup, API contracts, and rollback start-request lookup.
- `tests/url_ingestion/test_postgres.py`: 4 new cases for concurrent task cancel,
  cancel/delete intent races, delete/recrawl admission fencing and refusing a
  schema downgrade that would discard accepted intent.
- `tests/url_ingestion/test_temporal_dispatch_recovery.py`: 4 new real Temporal
  cancel/delete cases, including accepted cancellation with a lost reply.

17 Laravel cases were added: 16 BFF/selector/routing/readiness/ownership,
worker relay, catalog, rollback/API/CLI and health cases in
`PythonUrlIngestionBffTest`, plus a legacy paused/cancelled rollup regression in
`PipelineStatusTest`. The existing catalog fixture also runs in Python crawler
mode. Three Python OpenAPI allowlists were updated for the new cancel route.

## Full regression

| Command | Result |
| --- | --- |
| `cd python_backend && .venv/bin/pytest -q -rs` with the disposable variables below | **1812 passed, 5 skipped** |
| `USE_ZEND_ALLOC=0 php artisan test` | **707 passed, 31 skipped** |
| `cd python_rag && .venv/bin/pytest -q` | **701 passed, 16 skipped** |

The full backend run includes all **278 URL tests**. The new task-command file
passes all **32 cases**. The PostgreSQL and Temporal URL integration files contain
**61 cases** and ran with real disposable services in the full backend run.

Backend skips were four operator query-parity checks requiring a reviewed live
admission report/controlled tokens, plus the separately run text writer-fence
proof below. The 31 Laravel skips are exactly the PostgreSQL tests, all separately
passed below. RAG skips include opt-in live/model services; the relevant
owned-deletion, writer and graph-store live suites were separately passed.

The backend full-suite environment was:

```bash
cd python_backend
HAWKI_BACKEND_DB_TESTING=1 \
HAWKI_BACKEND_DB_DSN='postgresql+psycopg://epoch13:epoch13-test-only@127.0.0.1:55438/rawki_epoch13_test' \
HAWKI_EPOCH9_TEST_DSN='postgresql+psycopg://epoch13:epoch13-test-only@127.0.0.1:55438/rawki_epoch13_test' \
HAWKI_EPOCH10_TEST_DSN='postgresql+psycopg://epoch13:epoch13-test-only@127.0.0.1:55438/rawki_epoch13_test' \
HAWKI_EPOCH11_TEST_DSN='postgresql+psycopg://epoch13:epoch13-test-only@127.0.0.1:55438/rawki_epoch13_test' \
HAWKI_EPOCH11_TEMPORAL_ADDRESS=127.0.0.1:57238 \
.venv/bin/pytest -q -rs
```

PHP 8.4.1's allocator aborted the initial combined run with
`zend_mm_heap corrupted`, also reproducible in an existing managed-deletion
suite by itself. That suite passed with process isolation (37 tests) and with
`USE_ZEND_ALLOC=0`; disabling that allocator allowed the complete regression run.
This is a test-process environment workaround, not a source/production change.

## Disposable integrations

Services were created only for these tests, without deployment env files,
production mounts or external networks:

- `postgres:16`, loopback `55438`, database `rawki_epoch13_test`.
- `temporalio/auto-setup:1.25.2`, loopback `57238`, on the test PostgreSQL.
- `qdrant/qdrant:v1.19.2`, loopback `16333`.
- `neo4j:5.22`, loopback `17687`, test-only credentials.
- The text proof's fixed Temporal port `17233` forwarded only to the disposable
  server at `57238`. The proxy and all four containers were removed afterward.

**31 Laravel PostgreSQL cases passed**, including existing deletion concurrency,
callback ordering, process recovery, and migration suites:

```bash
RUN_MANAGED_POSTGRES_TESTS=1 RAWKI_MANAGED_TEST_DISPOSABLE=1 \
RAWKI_MANAGED_TEST_POSTGRES_HOST=127.0.0.1 RAWKI_MANAGED_TEST_POSTGRES_PORT=55438 \
RAWKI_MANAGED_TEST_POSTGRES_DATABASE=rawki_epoch13_test \
RAWKI_MANAGED_TEST_POSTGRES_USER=epoch13 RAWKI_MANAGED_TEST_POSTGRES_PASSWORD=epoch13-test-only \
RUN_POSTGRES_MIGRATION_TESTS=1 MIGRATION_TEST_DB_HOST=127.0.0.1 MIGRATION_TEST_DB_PORT=55438 \
MIGRATION_TEST_DB_DATABASE=rawki_epoch13_test MIGRATION_TEST_DB_USERNAME=epoch13 \
MIGRATION_TEST_DB_PASSWORD=epoch13-test-only USE_ZEND_ALLOC=0 \
php artisan test tests/Feature/Database
```

**7 live Qdrant/Neo4j/Temporal cases passed**:

```bash
cd python_rag
RAWKI_MANAGED_DELETION_LIVE=1 RAWKI_MANAGED_TEST_DISPOSABLE=1 \
RAWKI_MANAGED_TEST_QDRANT_URL=http://127.0.0.1:16333 \
RAWKI_MANAGED_TEST_NEO4J_URI=bolt://127.0.0.1:17687 \
RAWKI_MANAGED_TEST_NEO4J_PASSWORD=disposable-test-password \
RAWKI_MANAGED_TEST_TEMPORAL_ADDRESS=127.0.0.1:57238 \
.venv/bin/pytest -q \
  services/hawki_indexer_worker/tests/integration/test_managed_deletion_live.py \
  services/hawki_indexer_worker/tests/integration/test_managed_writer_live.py
```

**2 graph-store integration cases passed** with dependency skips forbidden:

```bash
cd python_rag
RAWKI_INTEGRATION_REQUIRED=1 RAWKI_INTEGRATION_NEO4J_URI=bolt://127.0.0.1:17687 \
NEO4J_USER=neo4j NEO4J_PASSWORD=disposable-test-password \
.venv/bin/pytest -q packages/graph_store/tests/integration
```

**1 real delayed text-writer/cancellation proof passed**. Both existing virtual
environments' dependencies were needed in that one process:

```bash
cd python_backend
HAWKI_EPOCH9_LIVE=1 .venv/bin/python -c \
'import site; site.addsitedir("../python_rag/.venv/lib/python3.13/site-packages"); import pytest; raise SystemExit(pytest.main(["-q", "tests/text_ingestion/test_live_writer_fence.py"]))'
```

Temporal safety evidence includes real dispatch, accepted-but-receipt-lost,
concurrent dispatch, cancellation, terminal acknowledgement, recovery and
fencing tests; unit tests alone are not the evidence.

## Static and build results

- `git diff --check`: passed.
- `python_backend/.venv/bin/ruff check python_backend`: passed.
- `python_backend/.venv/bin/ruff format --check python_backend`: passed (198 files).
- The same installed Ruff checked/formatted `python_rag/packages`,
  `python_rag/services`, `python_rag/tests`: passed (382 files). The RAG virtual
  environment does not contain its own Ruff executable.
- Pint and `php -l` passed for all 19 changed PHP files.
- `vendor/bin/pint --test` reports **115 pre-existing formatting failures**.
  Every reported file was verified byte-for-byte identical to `HEAD`; none is
  part of this epoch's changes. No unrelated formatting rewrite was made.
- `npm run build -- --outDir /private/tmp/rawki-epoch13-build`: passed. The normal
  host output path is a container-oriented `public/build` symlink to
  `/var/www/built_resources`; plain `npm run build` cannot create that target on
  this host. The explicit disposable output directory verifies the build without
  changing the symlink or frontend source.

## Production and review boundary

`PIPELINE_EXECUTION_BACKEND=laravel` remains the checked-in default. Python
production mode was not enabled. No Laravel historical state was migrated or
removed. No Python pipeline mirror tables or public-schema runtime reads were
added. The existing crawler browser/network isolation and authentication
architecture are unchanged.

The [execution runbook](python_pipeline_execution.md) records the schema invariant,
legacy-admin selector decision, rollback restrictions and Epoch 14 exclusions.

## Changed file manifest

### Python (8)

- [python_backend/src/hawki_backend/url_ingestion/api.py](../../python_backend/src/hawki_backend/url_ingestion/api.py)
- [python_backend/src/hawki_backend/url_ingestion/ownership.py](../../python_backend/src/hawki_backend/url_ingestion/ownership.py)
- [python_backend/src/hawki_backend/url_ingestion/reads.py](../../python_backend/src/hawki_backend/url_ingestion/reads.py)
- [python_backend/src/hawki_backend/url_ingestion/repository.py](../../python_backend/src/hawki_backend/url_ingestion/repository.py)
- [python_backend/src/hawki_backend/url_ingestion/scheduler.py](../../python_backend/src/hawki_backend/url_ingestion/scheduler.py)
- [python_backend/src/hawki_backend/url_ingestion/schema.py](../../python_backend/src/hawki_backend/url_ingestion/schema.py)
- [python_backend/src/hawki_backend/url_ingestion/service.py](../../python_backend/src/hawki_backend/url_ingestion/service.py)
- [python_backend/src/hawki_backend/url_ingestion/task_commands.py](../../python_backend/src/hawki_backend/url_ingestion/task_commands.py)

### Laravel (14)

- [app/Console/Commands/StartOrchestratedPipelineTask.php](../../app/Console/Commands/StartOrchestratedPipelineTask.php)
- [app/Http/Controllers/Health/PipelineHealthController.php](../../app/Http/Controllers/Health/PipelineHealthController.php)
- [app/Http/Middleware/CrawlerIngestionBoundary.php](../../app/Http/Middleware/CrawlerIngestionBoundary.php)
- [app/Services/Pipeline/CrawlerCallbackBoundary.php](../../app/Services/Pipeline/CrawlerCallbackBoundary.php)
- [app/Services/Pipeline/PipelineCompatibilityBoundary.php](../../app/Services/Pipeline/PipelineCompatibilityBoundary.php)
- [app/Services/Pipeline/PipelineExecutionBackend.php](../../app/Services/Pipeline/PipelineExecutionBackend.php)
- [app/Services/Pipeline/PipelineOwnership.php](../../app/Services/Pipeline/PipelineOwnership.php)
- [app/Services/Pipeline/Repositories/PipelineOwnershipRepository.php](../../app/Services/Pipeline/Repositories/PipelineOwnershipRepository.php)
- [app/Services/Pipeline/State/PipelineStageRollupService.php](../../app/Services/Pipeline/State/PipelineStageRollupService.php)
- [app/Services/Pipeline/Status/PipelineScrapeStageSynchronizer.php](../../app/Services/Pipeline/Status/PipelineScrapeStageSynchronizer.php)
- [app/Services/Pipeline/Status/PipelineStageStatusMerger.php](../../app/Services/Pipeline/Status/PipelineStageStatusMerger.php)
- [app/Services/Pipeline/Tasks/PipelineTaskStarter.php](../../app/Services/Pipeline/Tasks/PipelineTaskStarter.php)
- [app/Services/Scrape/Tasks/ScrapeCrawlerTaskFallbackStarter.php](../../app/Services/Scrape/Tasks/ScrapeCrawlerTaskFallbackStarter.php)
- [app/Services/Scrape/Tasks/ScrapeTaskService.php](../../app/Services/Scrape/Tasks/ScrapeTaskService.php)

### Migrations (1)

- [python_backend/alembic/versions/0008_url_task_commands.py](../../python_backend/alembic/versions/0008_url_task_commands.py)

### Tests (9)

- [python_backend/tests/api/test_health.py](../../python_backend/tests/api/test_health.py)
- [python_backend/tests/auth/test_dependencies.py](../../python_backend/tests/auth/test_dependencies.py)
- [python_backend/tests/test_retrieval_import.py](../../python_backend/tests/test_retrieval_import.py)
- [python_backend/tests/url_ingestion/test_postgres.py](../../python_backend/tests/url_ingestion/test_postgres.py)
- [python_backend/tests/url_ingestion/test_task_commands.py](../../python_backend/tests/url_ingestion/test_task_commands.py)
- [python_backend/tests/url_ingestion/test_temporal_dispatch_recovery.py](../../python_backend/tests/url_ingestion/test_temporal_dispatch_recovery.py)
- [tests/Feature/Pipeline/PipelineStatusTest.php](../../tests/Feature/Pipeline/PipelineStatusTest.php)
- [tests/Feature/Pipeline/PythonUrlIngestionBffTest.php](../../tests/Feature/Pipeline/PythonUrlIngestionBffTest.php)
- [tests/Feature/Scraping/ScraperTaskProxyTest.php](../../tests/Feature/Scraping/ScraperTaskProxyTest.php)

### Configuration/docs (6)

- [.env.example](../../.env.example)
- [_documentation/Operations/epoch13_pipeline_execution_validation.md](../../_documentation/Operations/epoch13_pipeline_execution_validation.md)
- [_documentation/Operations/python_pipeline_execution.md](../../_documentation/Operations/python_pipeline_execution.md)
- [config/config.php](../../config/config.php)
- [config/scraper.php](../../config/scraper.php)
- [python_backend/README.md](../../python_backend/README.md)


## Independent review fixes F1–F5 — 2026-11-15

The original validation above predates these corrections. The following results
were rerun after fixing failed-generation drainage, recrawl stop commands,
canonical legacy job rollups, launcher/CLI start idempotency and legacy job
update ownership. No branch, commit, merge, push or production activation occurred.

- Focused task commands: **40 passed** (8 additional cases).
- Complete URL authority suite with disposable PostgreSQL and Temporal:
  **294 passed**, including **69 PostgreSQL/Temporal integration cases**.
  New integration coverage exercises completed and interrupted recrawl retirement
  on PostgreSQL and exact-run generation-2 cancellation/deletion on Temporal.
- Full Python backend regression: **1828 passed, 5 skipped**, with the same
  disposable DSN variables listed above. Skips remain four operator parity
  checks and the separate live text writer-fence proof.
- Focused Laravel pipeline/unit/scraper regression: **214 passed**.
- Full Laravel regression: **712 passed, 31 skipped**, with
  `USE_ZEND_ALLOC=0`. The PostgreSQL cases run separately below.
- Final focused Laravel BFF/status tests after metadata hardening: **44 passed**.
- Laravel PostgreSQL database/migration regression: **31 passed** separately.
- Node tests: **15 passed, 1 opt-in browser test skipped**, including **12 launcher tests**. The launcher test imports
  the exact submission module used by the UI and simulates acceptance followed
  by response loss, retry, frozen key/body/identity and a new intentional start.
  Backend URL tests separately prove same-key replay has one workflow dispatch.
- `npm run build -- --outDir /private/tmp/rawki-epoch13-fixes-build`: passed.
- `git diff --check`, backend Ruff lint/format, changed-file Pint and PHP syntax:
  passed (21 PHP files).

Tests use isolated containers `rawki-epoch13-fixes-pg` (55438) and
`rawki-epoch13-fixes-temporal` (57238), without production volumes. An initial
sandbox networking refusal was resolved by running against those local test
services with network permission. PostgreSQL caught an overly broad draft
relaxation of recrawl admission; the final code limits the exception to stop
commands and the existing concurrent-recrawl regression passes.

Files newly touched for the corrections include the existing Laravel job updater
and creation repository, plus `resources/js/playground/pipeline-start.js` and
`tests/js/pipeline-start.test.js`. The execution runbook documents retained CLI
keys and in-memory launcher retries. No additional persistence model or selector
was introduced.

The final metadata check rejects all nonempty submitted metadata in Python-mode
legacy drain updates and verifies unchanged stored metadata under the row lock.
Regression tests cover ownership marker insertion/removal, nested recovery/source
evidence, and attempted top-level task/source/dataset/workflow substitutions.
Empty metadata preserves the existing evidence. No job identity is rewritten.
The launcher retains key/body after all error responses (including 4xx), since
an error on a replay cannot disprove an earlier accepted request; successful
resolution clears the pending submission. Tests exercise network errors,
timeouts, 400/401/403/404/409/413/422, 500 and 503.

Both disposable containers and their test network were removed after validation.
No RAG worker/shared-contract code changed, so the unrelated RAG suite was not
rerun. No tests failed in the final regression runs.
