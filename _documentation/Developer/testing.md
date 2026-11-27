# Python testing

The service tests run with Python and uv. The documentation site builds
separately with Node.js. See the [runtime guide](../Operations/python_runtime.md).

## Backend

```sh
cd python_backend
uv sync --frozen --all-groups
uv run --frozen pytest
uv run --frozen ruff check .
```

Set the integration-test flags and use a disposable PostgreSQL database with
an isolated test DSN. Keep historical databases out of the integration suite.
Alembic owns `hawki_backend`. Tests and schema checks must not mutate
historical public tables. The original PHP
oracles have been captured as inert fixtures, with provenance under
`python_backend/tests/fixtures/README.md`. Service JWT tests generate ephemeral
keys in memory and preserve issuer/audience/client/scope/lifetime/signature checks.

The recorded local macOS run failed the setgid assertion in 48 historical
text artifact cases, which expect directory mode `0o2775`. Report those
baseline failures separately from new failures. Linux CI checks the same
setgid assertion.

## Python workspace

```sh
make python-deps
cd python_rag
uv run --frozen pytest -m "not integration"
uv run --frozen ruff check packages services tests
```

The reranker has an isolated environment because its Transformers resolution
differs from the indexer. Optional integration/model tests require their live
stores and providers and are skipped unless explicitly enabled. The 638-line
`hawki_graph_store/graph.py` exceeds the workspace's 600-line test limit.
Report this known failure separately and keep the limit in place.

Compatibility tests use historical wire labels, fixtures, and mocked service
inventories. Live acceptance checks the signed Python callback interface.

## Private API and crawler

The backend suite checks that Compose keeps the API and data stores private.

URL ingestion tests verify the crawler contract, the scraper worker boundary,
and receipt checks that gate cleanup. See the [external crawler guide](../Operations/crawler_v2_local_upgrade.md).
