# HAWKI scraper worker

This service owns the `scrape_source` Temporal activity. It either stages an
uploaded source into shared artifact storage or starts/resumes the configured
external crawler job. Pipeline state is reported through signed, typed Laravel
callbacks; this worker has no PostgreSQL or bridge dependency.

## Tests

From `python_rag`, run `uv run --group test --package hawki-scraper-worker pytest
services/hawki_scraper_worker/tests`.
