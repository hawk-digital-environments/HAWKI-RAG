# HAWKI workflow worker

This service runs the deterministic `IngestSourceWorkflow`. Network,
filesystem, database, scraping, conversion, and indexing operations remain in
activities hosted by their owning workers.

## Tests

From `python_rag`, run `uv run --group test --package hawki-workflow-worker
pytest services/hawki_workflow_worker/tests`.
