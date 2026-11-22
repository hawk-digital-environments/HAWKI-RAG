# 10-PDF queue and latency benchmark

Started: 2026-11-22T19:11:04.868849+00:00

One multipart `POST /documents/batch` per mode, containing 10 distinct synthetic PDFs (approximately 3 MB and six searchable pages each). Unique document text avoids identical-content deduplication. Most bytes are an uncompressed illustration; this is not an OCR/dense-document capacity estimate.

All times below are milliseconds. Client completion is observed by polling, so includes up to one poll interval plus polling-request latency. Temporal schedule-to-start is server history time, not an inferred client wait. Activity execution includes any worker executor/model waiting after Temporal starts the activity. Retries can make schedule-to-start include backoff; attempts are recorded. Scheduled-to-running callback spans also include SDK/executor handoff and callback preparation; they compare UTC clocks on this local stack and are not pure Temporal queue time.

Monitoring mode: `list`. List mode uses one task-list request per polling cycle; parallel mode issues one request per pending task.

One sequential batch per mode; hardware, warm caches, model contention and other traffic affect results. Percentiles use nearest rank: for ten documents p95 is the slowest document, not a stable service-level estimate.

| Mode | Result | Accepted | Batch response | First ready | Last ready | Ready p50 | Ready p95 | PDFs/min |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| graph_off | PASS | 10 | 519.628 | 18168.46 | 19500.792 | 18755.86 | 19500.792 | 30.768 |
| graph_on | FAIL | 10 | 732.757 | 553912.016 | incomplete (9/10) | not reported | not reported | incomplete |

## graph_off

| PDF | Status | Ready since submission (ms) | Convert queue (ms) | Index queue (ms) | Index scheduled to running callback (ms) | Index execution (ms) |
|---|---|---:|---:|---:|---:|---:|
| 1 | completed | 18168.46 | 2.059 | 2.31 | 18.203 | 6753.401 |
| 2 | completed | 18316.913 | 8.911 | 3.227 | 46.132 | 6409.192 |
| 3 | completed | 18462.993 | 3.903 | 5.203 | 6394.125 | 8810.233 |
| 4 | completed | 18607.167 | 6.569 | 3.341 | 8099.809 | 10171.572 |
| 5 | completed | 18755.86 | 4.662 | 4.557 | 6061.011 | 8644.796 |
| 6 | completed | 18892.003 | 8.487 | 3.611 | 9190.865 | 11386.741 |
| 7 | completed | 19032.34 | 8.299 | 20.916 | 7325.323 | 9430.407 |
| 8 | completed | 19195.413 | 12.486 | 3.556 | 9400.105 | 11555.449 |
| 9 | completed | 19349.692 | 2.77 | 5.496 | 11025.39 | 13145.117 |
| 10 | completed | 19500.792 | 2.799 | 3.617 | 10283.559 | 12678.129 |

Task-list GET probe latency distribution (ms): `{"min": 70.36, "p50": 82.584, "p95": 144.319, "max": 144.319, "samples": 9}`; HTTP failures: 0.
Temporal activity schedule-to-start distributions: `{"ingest_markdown_files": {"min": 2.31, "p50": 3.611, "p95": 20.916, "max": 20.916, "samples": 10}, "inspect_and_convert_files": {"min": 2.059, "p50": 4.662, "p95": 12.486, "max": 12.486, "samples": 10}, "mark_source_ready": {"min": 1.676, "p50": 2.32, "p95": 4.861, "max": 4.861, "samples": 10}, "scrape_source": {"min": 2.303, "p50": 3.955, "p95": 9.338, "max": 9.338, "samples": 10}}`.
Peak Temporal started-but-not-finished activity counts: `{"ingest_markdown_files": 10, "inspect_and_convert_files": 10, "mark_source_ready": 9, "scrape_source": 2}`. These counts are not CPU-thread or LLM concurrency.
Cleanup: **PASS**. Retry attempts above one: 0.

## graph_on

| PDF | Status | Ready since submission (ms) | Convert queue (ms) | Index queue (ms) | Index scheduled to running callback (ms) | Index execution (ms) |
|---|---|---:|---:|---:|---:|---:|
| 1 | cancelled_stalled_writer_drain_pending | — | 2.723 | — | — | — |
| 2 | completed | 553912.016 | 3.435 | 19.791 | 64.018 | 71548.708 |
| 3 | completed | 554072.402 | 5.598 | 9.723 | 71499.09 | 129922.015 |
| 4 | completed | 554240.577 | 2.95 | 2.614 | 129878.611 | 199568.089 |
| 5 | completed | 554410.031 | 3.147 | 2.619 | 198347.606 | 256250.596 |
| 6 | completed | 554586.507 | 3.014 | 3.26 | 256091.523 | 317342.135 |
| 7 | completed | 554756.808 | 4.71 | 5.265 | 317281.627 | 377690.559 |
| 8 | completed | 554927.811 | 2.636 | 2.954 | 377674.946 | 435323.688 |
| 9 | completed | 555091.016 | 5.357 | 22.601 | 433896.714 | 492308.901 |
| 10 | completed | 555250.671 | 2.742 | 7.165 | 492233.61 | 547124.693 |

Task-list GET probe latency distribution (ms): `{"min": 63.51, "p50": 87.624, "p95": 118.611, "max": 233.572, "samples": 401}`; HTTP failures: 0.
Temporal activity schedule-to-start distributions: `{"ingest_markdown_files": {"min": 2.614, "p50": 5.265, "p95": 22.601, "max": 22.601, "samples": 9}, "inspect_and_convert_files": {"min": 2.636, "p50": 3.014, "p95": 5.598, "max": 5.598, "samples": 10}, "mark_source_ready": {"min": 2.028, "p50": 2.581, "p95": 305012.698, "max": 305012.698, "samples": 9}, "scrape_source": {"min": 2.452, "p50": 3.355, "p95": 5.009, "max": 5.009, "samples": 10}}`.
Peak Temporal started-but-not-finished activity counts: `{"ingest_markdown_files": 9, "inspect_and_convert_files": 10, "mark_source_ready": 9, "scrape_source": 1}`. These counts are not CPU-thread or LLM concurrency.
Cleanup: **PENDING_WRITER_DRAIN**. Retry attempts above one: 3.

Failure: Stopped after 9/10 completions: PDF 1 had stale indexing heartbeat for more than 10 minutes; its workflow was cancelled through safeguarded deletion. No completion time is assigned to the unfinished PDF.

Runtime model configuration: `{"engine": "raganything", "query_provider": "ollama", "query_model": "llama3.1:8b", "embedding_model": "bge-m3"}`.

Measured worker settings: `{"indexer": {"activity_worker_threads": 2, "queue": "rawki-local-indexer"}, "converter": {"activity_worker_threads": 4, "queue": "rawki-local-converter"}, "scraper": {"activity_worker_threads": 4, "queue": "rawki-local-scraper"}}`.

The batch handler admits files sequentially and launches independent Temporal workflows. Conversion and indexing use different queues. An accepted upload is not a completed ingestion, and completion order is not guaranteed. Graph extraction can saturate model capacity while API requests remain responsive.

Reproduce: `python_backend/.venv/bin/python scripts/benchmark_local_pdf_queue.py`.
Detailed measurements: `/tmp/rawki-pdf-queue-benchmark.json`.

## Failures and interpretation

The first vector-only attempt used ten simultaneous task-status GETs after its
batch upload. All ten uploads were accepted in **720.026 ms**, but task reads
returned **HTTP 500**. Backend logs identified SQLAlchemy QueuePool saturation:
**size 5, overflow 0, timeout 3,000 ms**. This is a real API concurrency defect,
not an ingestion success. That first attempt's dataset cleanup passed. Its raw
report remains `/tmp/rawki-pdf-queue-benchmark-first-attempt.json`.

The measured rerun used one task-list request per cycle. It did not increase the
database pool, worker limits, or model parallelism. The vector batch passed. The
graph batch did **not** pass: nine PDFs became ready around 554–555 seconds; PDF 1
remained in attempt 1 with a stale heartbeat for over ten minutes. Its workflow
was cancelled through normal document deletion, then the benchmark observer was
stopped. The observation window includes diagnosis and cancellation, so it is
not a completed batch latency. No full-batch p95 or throughput is claimed.

Recent indexer logs contained twelve `no running event loop` and twelve
`bound to a different event loop`/lock RuntimeErrors in an eight-minute sample.
These errors are a separate finding; their causal link to PDF 1 is unproven.

The per-file table uses observed readiness timestamps, including polling and
sequential receipt collection overhead. Several documents were first seen ready
in the same task-list snapshot; small differences between those timestamps do
not establish actual finish order. Graph activity distributions include only
finished activities; the cancelled indexing activity has no completion span.
The peak counts similarly cover recorded finished intervals and are lower
bounds when an unfinished activity is omitted.

### Outstanding cleanup

Dataset: `queue-bench-d4bc8fb3a1a24550`.

Document: `adoc_e13fb6ba1109451c8cd684255c19d99e`.

Workflow: `ingest-source-source_5e56b2dd532f4704a91754be3669d9f8` (cancelled).

Nine completed graph documents were deleted through the API. The cancelled
PDF's deletion and dataset retirement return **202**, awaiting writer drain.
Their persisted fencing/ownership records remain intact. No historical or
unrelated dataset was deleted. This test exposed a recovery blocker; cleanup
must not be marked successful or forced by editing the writer ledger.

### How to interpret the queue

The running configuration has scraper/converter/indexer executor sizes of
**4 / 4 / 2 threads**. Temporal can hand off more tasks than those executors can
run. This explains why millisecond Temporal schedule-to-start measurements can
coexist with seconds or minutes of client delay. In the vector run, PDF 10 took
**10,283.559 ms** from index scheduling to the worker-running callback, while its
Temporal schedule-to-start was only **3.617 ms**. Scheduled-to-callback includes
worker handoff and callback preparation; it is not pure compute time.

```mermaid
flowchart LR
    A["10 PDFs, about 30 MB"] --> B["Batch API: sequential admission"]
    B --> C["10 Temporal workflows"]
    C --> D["Scraper executor: 4 threads"]
    D --> E["Converter executor: 4 threads"]
    E --> F["Indexer executor: 2 threads"]
    F --> G["Qdrant; optional Neo4j"]
    G --> H["Ready projection shares indexer executor"]
```

The pipeline task list reported all ten workflows as running even when some had
not yet emitted an ingest-running callback. Workflow running status is not an
active worker-thread count. These are single local samples using small amounts
of text inside image-heavy PDFs; they do not establish general 3 MB PDF capacity.

To reproduce the monitoring stress explicitly:
`python_backend/.venv/bin/python scripts/benchmark_local_pdf_queue.py --polling parallel`.
Run only against disposable local test data; the existing defects can cause
pending cleanup as documented above.
