# Pipeline Exit Codes

Automation should use process exit codes instead of parsing logs.

| Code | Meaning |
|------|---------|
| `0` | Success |
| `1` | Runtime failure |
| `2` | Validation, bad input, or bad configuration |
| `3` | Partial success |

## Command Behavior

| Command | `0` | `1` | `2` | `3` |
|---------|-----|-----|-----|-----|
| `php artisan scraper:scrape` | Crawl completed | Crawler/runtime failure | Missing or invalid input | Not used |
| `php artisan convert:crawled-pdfs` | All discovered documents converted | Unhandled runtime failure | Missing/invalid crawl directory | No source documents, user-cancelled conversion, or one or more documents failed |
| `php artisan rag:publish-converted-folder` | All discovered converted documents published | RabbitMQ/runtime failure | Missing/invalid folder | No publishable documents or some metadata skipped |
| `php artisan rag:rabbit-ingestion-worker --once` | One message completed or was already completed | Message failed and was retried or published to failed queue | Not used | No message received before timeout |
| `python python_rag/ingest/ingest_crawled.py` | Ingest completed | One or more batches failed | Invalid CLI arguments or missing root | No page folders found, all page folders are empty, or one or more empty page folders were skipped |

API-launched ingestion runs the Python script as a detached process. When it
finishes, the ingest log records `INGEST_EXIT_CODE=<code>` and
`/ingest/status` exposes that value as `status.exit_code`.

## Fallback Behavior

| Condition | Stage | Behavior | Reported Status |
|-----------|-------|----------|-----------------|
| Missing root or input folder | Scrape, convert, publish, ingest CLI | Stop before processing because the requested input cannot be read. | Exit `2`; validation message is printed or logged. |
| Partial scrape data or page-level scrape warnings | Scrape pipeline | The initial `scraper:scrape` command starts/executes the scrape request and does not classify partial page results as an exit-code `3`. Partial states are logged during scrape event handling and finalization. | Initial command exits `0` when the scrape request succeeds; partial details are recorded in pipeline logs. |
| No page folders found under an existing ingest root | Ingest CLI | Do not contact the bridge; write an optional summary with `reason=no_pages_found`. | Exit `3`; partial summary when `--summary-file` is provided. |
| Empty page folder | Ingest CLI | Skip the folder, log/print the relative path, and continue with other folders. | Exit `3` if any empty folders were skipped; if all folders are empty, summary uses `reason=no_ingestable_documents`. |
| Invalid ingest document | Python API ingest | Skip the invalid document, record validation errors in the ingest summary, and continue with valid documents. | Request succeeds when at least one valid document remains; request fails with HTTP `400` when no valid content remains. |
| Failed embedding chunk or document | Python API ingest | Log the failed chunk as skipped, remove fully failed documents from processed counts, and continue indexing valid points. | Request succeeds when at least one point remains; request fails with HTTP `500` when every prepared chunk fails embedding. |
| Failed conversion document | Convert command | Catch the document-level failure, record it in `storage/logs/failed_conversion.json`, and continue converting the rest of the batch. | Exit `3` when one or more documents fail; exit `0` when all discovered documents convert cleanly. |
| Failed RabbitMQ ingestion job | RabbitMQ ingestion worker | Mark processing state with the error. Transient failures are published to the retry exchange while attempts remain. Permanent failures are sent directly to the failed exchange. | Worker `--once` exits `1` for retry or failed routing; state is stored in `job_processing_state`. |
| Retry exhausted | RabbitMQ ingestion worker | Publish a `pipeline.failed` event to the failed exchange and mark the job as failed. | Worker `--once` exits `1`; failed event includes retry count, max retries, error type, and original payload. |

## Make Targets

Pipeline Make targets preserve the same convention when they perform their own
preflight checks:

- `make crawl` exits `2` when `URL` is missing.
- `make convert` exits `2` when `OUTPUT_DIR` is still the placeholder path.
- `make ingest` exits `2` when `CRAWLED_ROOT` is still the placeholder path.
- `make publish-converted-folder` and `make convert-ingest-folder` exit `2` when `SCRAPED_FOLDER` is missing.

Health-check targets use `1` for runtime/service failures.
