# Testing

Choose checks for the boundary being changed. Unit/contract suites provide
deterministic evidence; live smoke tests prove deployed services cooperate.
One does not substitute for the other.

| Change | Minimum relevant validation |
|---|---|
| Laravel request/auth | Relevant Feature/Unit tests for validation, identity, and grants |
| Query pipeline | Bridge/query tests, including scope and fallback behavior |
| Ingestion | Indexer tests, including incremental and partial-write behavior |
| Graph extraction | Graph/indexer tests for scope, filtering, and failure handling |
| Temporal workflow | Workflow compatibility tests for old/new history branches |
| Documentation | Existing documentation build, link checks, and `git diff --check` |

## Laravel

From the repository root with Composer dependencies installed:

```bash
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
php artisan test --testsuite=System
```

The System suite replaces external Python HTTP calls; it does not prove live
model/store behavior. `make system-test` runs that suite.
[Laravel test commands](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/tests/README.md) own the PostgreSQL migration-test
environment; `make migration-test` uses the running stack and an isolated
migration schema.

Use [the repository map](../Reference/8_repo_map.md#find-the-change-you-need)
to find the feature/unit suite for your change.

## Python workspace

Use Python **3.13.14** and the checked-in uv lock. From `python_rag`:

```bash
uv lock --check
uv sync --frozen --group test --extra cpu \
  --package hawki-bridge \
  --package hawki-workflow-worker \
  --package hawki-scraper-worker \
  --package hawki-converter-worker \
  --package hawki-indexer-worker

PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 \
  uv run --frozen --no-sync pytest -c pytest.ini -m "not integration"
```

The root [pytest configuration](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/pytest.ini) selects package,
data-plane service, and cross-service tests. The reranker is
intentionally separate:

```bash
UV_PROJECT_ENVIRONMENT=.venv-reranker \
  uv sync --frozen --group test --package hawki-reranker --extra cpu

PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 UV_PROJECT_ENVIRONMENT=.venv-reranker \
  uv run --frozen --no-sync pytest -c pytest.ini services/hawki_reranker/tests
```

After installing the appropriate environment, append a member path to run a
focused suite, for example `services/hawki_bridge/tests`.
Tests live beside member `src/` trees; production images omit them.

[Python CI](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/.github/workflows/python-rag.yml) also checks Ruff formatting,
lint, dependency boundaries, file length, lock CPU/CUDA invariants, and Compose.
Run its exact commands when changing those concerns. From the repository root,
`make python-deps` provisions the separate development environments.

:::note Checking versus resolving the lock

Use `uv lock --check` from `python_rag` to check the committed lock without
resolving a new one. `make python-lock` first runs `uv lock`, which can modify
`uv.lock`, then verifies the lock and CPU/CUDA invariants. Use that target when
intentionally maintaining dependencies, and review any lockfile diff.

:::

## Live integration

The `integration` marker requires live infrastructure. Use a disposable test
dataset: tests can create indexed content and workflow/application records.

- [Direct-text smoke](../Reference/direct_text_ingestion.md) proves Laravel →
  bridge → Temporal → indexer → Qdrant without crawler/converter.
- [Full ingestion smoke](../Getting%20Started/2_setup.md#full-ingestion-smoke-test)
  adds the external tool handoff.
- [Python live test instructions](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/tests/README.md) describe the
  automated direct-text and source-workflow end-to-end tests.

Set `RAWKI_INTEGRATION_REQUIRED=1` when missing infrastructure should fail rather
than skip. Verify model readiness and the dataset's explicit ingest grant first.
Temporal unit tests cover old/new patch paths, but captured production-history
replay remains a separate rollout check.

## Documentation checks

From `_documentation.build`:

```bash
npm ci
npm run build
```

Skip `npm ci` when the locked dependencies are already installed. The existing
build compiles Markdown/MDX and checks site links. Navigation is generated from
documentation directories and page metadata. No dedicated documentation lint
command is configured.

From the repository root:

```bash
git diff --check
```

Source-code links point to GitHub. When changing the repository map, verify the
corresponding paths in your checkout; for example:

```bash
test -f python_rag/services/hawki_bridge/src/hawki_bridge/application/query/execution.py
test -d python_rag/services/hawki_indexer_worker/tests
```

Review build warnings, linked headings, and rendered Mermaid diagrams. A site
build does not execute curl examples or prove live service behavior.
