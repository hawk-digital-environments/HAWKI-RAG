# HAWKI workflow worker

This service runs deterministic `IngestSourceWorkflow` and `IngestTextWorkflow`
executions. Network,
filesystem, database, scraping, conversion, and indexing operations remain in
activities hosted by their owning workers.

See [Temporal Operations](../../../_documentation/Operations/temporal_operations.md)
for queue ownership, retries, callbacks, and replay compatibility.

## Tests

From `python_rag`, run `uv run --group test --package hawki-workflow-worker
pytest services/hawki_workflow_worker/tests`.
