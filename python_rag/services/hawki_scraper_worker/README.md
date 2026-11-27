# HAWKI scraper worker

The `scrape_source` activity submits and polls the external crawler. The
crawler writes to shared storage. The worker reports status and receipts to
FastAPI through signed callbacks. The worker also runs `CrawlerControlWorkflow`
for readiness, catalogs, job status, and cancellation. Historical upload
workflows can still stage files through `scrape_source`. Current uploads
start with the converter.

## Tests

From `python_rag`, run `uv run --group test --package hawki-scraper-worker pytest
services/hawki_scraper_worker/tests`.
