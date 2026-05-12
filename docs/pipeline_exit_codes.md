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
| `python python_rag/ingest/ingest_crawled.py` | Ingest completed | One or more batches failed | Invalid CLI arguments or missing root | Not used |

## Make Targets

Pipeline Make targets preserve the same convention when they perform their own
preflight checks:

- `make crawl` exits `2` when `URL` is missing.
- `make convert` exits `2` when `OUTPUT_DIR` is still the placeholder path.
- `make ingest` exits `2` when `CRAWLED_ROOT` is still the placeholder path.
- `make publish-converted-folder` and `make convert-ingest-folder` exit `2` when `SCRAPED_FOLDER` is missing.

Health-check targets use `1` for runtime/service failures.
