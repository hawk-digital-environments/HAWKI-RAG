# HAWKI pipeline callbacks

This package owns the signed, retry-safe HTTP protocol used by Python workers
to publish immutable pipeline events to Laravel. It does not own Temporal,
worker logging, service settings, or external conversion/scraping jobs.

## Tests

From `python_rag`, run `uv run --group test --package hawki-pipeline-callbacks
pytest packages/pipeline_callbacks/tests`.
