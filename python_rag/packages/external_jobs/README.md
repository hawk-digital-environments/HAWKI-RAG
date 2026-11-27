# HAWKI external jobs

`ExternalJobClient` submits and polls HTTP jobs for the scraper and converter
adapters. `CustomCrawlerClient` adds contract checks, owned job receipts,
and cancellation for `crawler-api`. The worker handles Temporal and
FastAPI callbacks.

## Tests

From `python_rag`, run `uv run --group test --package hawki-external-jobs pytest
packages/external_jobs/tests`.
