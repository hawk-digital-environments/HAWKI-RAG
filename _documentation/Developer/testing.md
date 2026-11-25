# Python testing

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

## Private API and crawler

The backend suite checks that Compose keeps the API and data stores private.

See the [crawler guide](../Operations/crawler_v2_local_upgrade.md) for
consumer contracts and an isolated browser runtime test.
