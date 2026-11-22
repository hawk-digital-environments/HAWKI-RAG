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

## Local ingestion timing benchmark

Run `python_backend/.venv/bin/python scripts/benchmark_local_ingestion.py` from
repository root with the local management UI boundary available. It generates a
six-page searchable PDF (approximately 3 MB, mostly an uncompressed illustration)
and measures PDF indexing with graph off, PDF indexing with graph on, and direct
text from the same source paragraphs. Each case uses its own disposable dataset,
checks ready indexing/retrieval and applicable graph/download behavior, then
removes its content and retires dataset storage. It never reads a browser token.

Reports default to `/tmp/rawki-ingestion-benchmark.json` and
`/tmp/rawki-ingestion-benchmark.md`; the PDF is
`/tmp/rawki-synthetic-3mb.pdf`. Use `--report`, `--markdown`, and `--timeout` to
change report paths or the per-case ingestion deadline (default 1800 seconds).
Only one sequential sample per case is measured. Model warmup, caches, page count,
text density, images, OCR, worker queues, and hardware affect results; this
synthetic fixture does not establish a general latency for a normal 3 MB PDF.

See the [measured local ingestion report](../Operations/ingestion_benchmark.md)
for the recorded PDF graph-off/on and direct-text timings and verification.

## Ten-PDF queue benchmark

Run `python_backend/.venv/bin/python scripts/benchmark_local_pdf_queue.py` from
repository root. It sends one approximately 30 MB multipart batch containing ten
unique six-page synthetic PDFs, first with graph disabled and then enabled.
Each mode creates a separate disposable dataset, verifies indexed documents and
byte-identical downloads, checks retrieval/graph behavior, then removes its
content and retires dataset storage. Real model inference can take minutes.

The default `--polling list` uses one task-list request per observation cycle.
`--polling parallel` deliberately stresses task-status reads with one concurrent
request per pending task. This can expose database pool saturation independently
of ingestion throughput; failures are recorded and cleanup is attempted.

Results are `/tmp/rawki-pdf-queue-benchmark.json` and
`/tmp/rawki-pdf-queue-benchmark.md`. Temporal histories are read inside the backend
container for only this run's workflow IDs. Scheduled-to-start and execution
spans use Temporal server timestamps; they distinguish task-queue wait from
worker/model execution, but SDK executor waiting can fall inside execution.
Callback spans additionally expose scheduled-to-worker-running delays. No worker
limits, model settings, authentication rules, or backend behavior are changed.
Use `--timeout` (default 3600 seconds per mode), `--poll` (default 2 seconds),
`--count` (1..20), `--report`, and `--markdown` to control a repeat run.

The [measured ten-PDF report](../Operations/pdf_queue_benchmark.md) records the
vector batch timings, the parallel-status-read HTTP 500, and the graph batch's
stalled tenth PDF. The latter left one safeguarded deletion pending writer drain;
it is not reported as a successful batch or successful cleanup.
