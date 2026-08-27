# Python RAG test layout

`python_rag` is a uv workspace. Tests belong to the workspace member whose
production behavior they verify and live beside that member's `src/` directory:

```text
packages/<member>/
├── src/<import_package>/
└── tests/
    ├── unit/
    ├── integration/
    ├── contract/
    └── characterization/

services/<member>/
├── src/<import_package>/
└── tests/
    ├── unit/
    ├── integration/
    ├── contract/
    └── characterization/
```

Only categories used by a member are created. Unit test paths mirror production
capabilities below the import-package root. For example,
`src/hawki_bridge/application/query/execution.py` is covered under
`tests/unit/application/query/` or, for behavior frozen during refactoring,
`tests/characterization/application/query/`.

The workspace-level `tests/` directory is reserved for end-to-end flows spanning
multiple deployable services. Cross-member architecture contracts live with the
member closest to the behavior they protect; for example, shared-storage
deployment checks belong to `artifact_store`, and graph/vector separation checks
belong to `vector_store`.

## Categories

- `unit/` verifies one production module or capability with local collaborators.
- `integration/` talks to an already-running external dependency and is marked
  `integration`; unavailable dependencies skip unless strict mode is enabled.
- `contract/` verifies public wire, package, service, deployment, or ownership
  boundaries.
- `characterization/` freezes current behavior so later refactoring can be
  reviewed safely.
- root `tests/end_to_end/` exercises behavior spanning multiple services.

Fixtures live in the nearest `conftest.py`. Tests import installed workspace
members through uv; no test mutates `sys.path`, and the suite does not require a
repository `PYTHONPATH`.

## Prepare the test environments

From `python_rag`:

```bash
uv sync --frozen --group test --extra cpu \
  --package hawki-bridge \
  --package hawki-workflow-worker \
  --package hawki-scraper-worker \
  --package hawki-converter-worker \
  --package hawki-indexer-worker

UV_PROJECT_ENVIRONMENT=.venv-reranker \
  uv sync --frozen --group test --package hawki-reranker --extra cpu

uv sync --frozen --only-group lint --inexact
```

Use `--extra gpu` instead of `--extra cpu` when validating the CUDA dependency
variant.

## Python quality checks

Run the same pinned Ruff checks used by CI:

```bash
uv run --frozen --no-sync ruff format --check packages services tests
uv run --frozen --no-sync ruff check packages services tests
```

To apply formatting locally before re-running the check:

```bash
uv run --frozen --no-sync ruff format packages services tests
```

## Deterministic tests

The default pytest paths intentionally omit the reranker because its model
dependencies conflict with the indexer. Run the main and reranker environments
separately:

```bash
PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 \
  uv run --frozen --no-sync pytest -c pytest.ini -m "not integration"

PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 \
UV_PROJECT_ENVIRONMENT=.venv-reranker \
  uv run --frozen --no-sync pytest -c pytest.ini \
  services/hawki_reranker/tests
```

## Run one owner or category

```bash
uv run --frozen --group test --package hawki-bridge \
  pytest services/hawki_bridge/tests
uv run --frozen --group test --package hawki-bridge \
  pytest services/hawki_bridge/tests/unit
uv run --frozen --group test --package hawki-graph-store \
  pytest packages/graph_store/tests/contract
```

Live tests only probe already-running dependencies; they do not start
containers or download models:

```bash
PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 \
  uv run --frozen --no-sync pytest -c pytest.ini \
  -m "integration and not model" \
  packages/graph_store/tests/integration \
  services/hawki_bridge/tests/integration \
  tests/end_to_end/integration

PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 \
  uv run --frozen --no-sync pytest -c pytest.ini \
  -m "integration and model" \
  packages/model_providers/tests/integration

PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 \
  uv run --frozen --no-sync pytest -c pytest.ini \
  services/hawki_indexer_worker/tests/integration
```

The final command is the focused MinerU pipeline integration test. To collect
all main-environment tests and let unavailable integrations skip, run
`uv run --frozen --no-sync pytest -c pytest.ini`.

Set `RAWKI_INTEGRATION_REQUIRED=1` to make an unavailable selected dependency a
failure. Endpoint overrides include `RAWKI_INTEGRATION_QDRANT_URL`,
`RAWKI_INTEGRATION_NEO4J_URI`, `RAWKI_INTEGRATION_TEMPORAL_ADDRESS`,
`RAWKI_INTEGRATION_OLLAMA_API_URL`, and `RAWKI_INTEGRATION_LITELLM_API_URL`.

## Coverage and locked CI

```bash
uv run --frozen --group test pytest services/hawki_bridge/tests \
  --cov=hawki_bridge --cov-report=term-missing

uv lock --check
uv sync --locked --group test --extra cpu \
  --package hawki-bridge \
  --package hawki-workflow-worker \
  --package hawki-scraper-worker \
  --package hawki-converter-worker \
  --package hawki-indexer-worker
PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 \
  uv run --locked --no-sync pytest -c pytest.ini -m "not integration"

UV_PROJECT_ENVIRONMENT=.venv-reranker \
  uv sync --locked --group test --package hawki-reranker --extra cpu
PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 \
UV_PROJECT_ENVIRONMENT=.venv-reranker \
  uv run --locked --no-sync pytest -c pytest.ini \
  services/hawki_reranker/tests
```

CI uses the lockfile and runs the reranker in its isolated uv environment.
Production images copy member `src/` trees rather than member `tests/` trees, so
the co-located tests are not installed into runtime images.
