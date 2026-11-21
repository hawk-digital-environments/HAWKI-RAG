# Python and frontend testing

The repository test/build toolchain requires Python and Node, with no PHP or
Composer installation. See the [runtime guide](../Operations/python_runtime.md).

## Backend

```sh
cd python_backend
uv sync --frozen --all-groups
uv run --frozen pytest
uv run --frozen ruff check .
```

PostgreSQL integration tests require their explicit isolated test DSN/flags.
Never run them against a historical database. Alembic owns `hawki_backend`; tests
and schema checks must not mutate historical public tables. The original PHP
oracles have been captured as inert fixtures, with provenance under
`python_backend/tests/fixtures/README.md`. Service JWT tests generate ephemeral
keys in memory and preserve issuer/audience/client/scope/lifetime/signature checks.

On this macOS host, 48 direct-text artifact tests retain the documented setgid
assertion failure (`0o2775`); their behavior has not been relaxed. Report these
separately from new failures. Linux CI keeps the full assertions.

## Python workspace

```sh
make python-deps
cd python_rag
uv run --frozen pytest -m "not integration"
uv run --frozen ruff check packages services tests
```

The reranker has an isolated environment because its Transformers resolution
differs from the indexer. Optional integration/model tests require their live
stores/providers and are skipped unless explicitly enabled. The unchanged
638-line graph-store module currently triggers the pre-existing 600-line
boundary test; report that baseline separately rather than weakening the limit.

Historical compatibility tests may contain old wire labels and mocked deployment
inventories. They cannot start the removed PHP runtime. Current workers and live
acceptance use the signed Python callback boundary.

## Frontend and crawler

```sh
npm ci
npm test
npm run build
```

The Node suite verifies same-origin Python API routing, browser credential
isolation, graph scope/stale-response handling and the existing frontend helpers.
The removed opt-in PHP browser fixture is replaced operationally by the Python
managed/text/graph/URL smoke and cleanup audits. See `docker/crawler/README.md`
for the upgraded crawler contract/lifecycle test command.

## Live acceptance

Use only the explicitly provisioned local overlay and disposable datasets.
`scripts/smoke_local_graph.py` exercises the real converter, indexer, Ollama,
Qdrant, Neo4j, graph UI operations, query/download and API deletion.
`scripts/audit_local_graph.py` verifies cleanup read-only. The URL smoke uses
the separate isolated fixture overlay and its own read-only receipt/store audit.
Remove disposable fixture networking afterward. Preserve all historical archives,
SQL rows and original files throughout. Never bypass `crawler_contract`.
