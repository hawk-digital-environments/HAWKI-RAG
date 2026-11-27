# Local ingestion timing report

Measured: 2026-11-22T17:44:35.080561+00:00

Synthetic six-page searchable PDF with a 3 MB uncompressed RGB illustration. This is image-heavy test input, not a prediction for ordinary PDFs, scanned PDFs, or OCR. Same PDF used for graph off/on; direct text uses its source paragraphs. One sequential sample per mode; model/cache warmup and polling affect comparisons.

PDF: 3,006,483 bytes; source text: 3,772 characters.

Runtime: raganything; model: llama3.1:8b; embeddings: bge-m3.

Input SHA-256: `8396a6d7977cf975a5f30f2563f34c268b2e6b41a2fe5a81dc583fa2f4b11b27`.

Times are seconds. Admission-to-ready includes upload, queueing, conversion/indexing and up to one polling interval. Stage spans use worker callback timestamps; missing spans are not estimated. Verification and cleanup are separate.

| Mode | Result | Admission | Admission to ready | Convert span | Index span | Verification | Cleanup |
|---|---|---:|---:|---:|---:|---:|---:|
| pdf_vectors | PASS | 0.081 | 3.218 | 1.12 | 1.043 | 0.786 | 0.047 |
| pdf_graph | PASS | 0.057 | 142.651 | 1.024 | 141.099 | 0.809 | 0.101 |
| direct_text_vectors | PASS | 0.019 | 2.116 | — | — | 0.685 | 0.031 |

This run used the former text workflow, which skipped conversion and graph enrichment. The current benchmark submits inline text and files to the same managed workflow. Each case creates its own dataset, redeems its exact ingest grant, waits for ready indexing, redeems query access, and verifies retrieval. PDF cases also verify indexed document status and byte-identical download. Graph on requires nodes and relationships. Graph off and text require an empty graph. Cleanup removes the run's documents and dataset storage.

Direct-text worker stage spans were not exposed by this run's event projection; its total admission-to-ready time was measured with the same monotonic clock as the PDF cases.

| Mode | Retrieval hits | Graph nodes | Graph relationships | Cleanup |
|---|---:|---:|---:|---|
| pdf_vectors | 6 | 0 | 0 | PASS |
| pdf_graph | 6 | 7 | 8 | PASS |
| direct_text_vectors | 5 | 0 | 0 | PASS |

Run `python_backend/.venv/bin/python scripts/benchmark_local_ingestion.py` from
the repository root. Set `RAWKI_API_BASE_URL` to the reachable API URL ending
in `/v1` and supply a scoped service JWT via `RAWKI_API_TOKEN_FILE`.
The generated PDF is `/tmp/rawki-synthetic-3mb.pdf`.

Detailed receipts and checks: `/tmp/rawki-ingestion-benchmark.json`

For multiple uploads, see the [ten-PDF queue benchmark](pdf_queue_benchmark.md).
That load test exposed API pool saturation during parallel polling and an
unfinished graph PDF; the single-PDF success above is not a concurrency guarantee.
