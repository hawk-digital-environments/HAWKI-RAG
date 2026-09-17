# HAWKI workflow worker

This service runs the deterministic `IngestSourceWorkflow`. Network,
filesystem, database, scraping, conversion, and indexing operations remain in
activities hosted by their owning workers.

## Failed-stage recovery

Laravel's Retry action creates a new workflow with current service settings and
a typed `resume` payload selected from the recorded stage states:

- `scrape`: run scraping, conversion, and ingestion.
- `convert`: reuse the completed scrape's `raw_dir`, then convert and ingest.
- `ingest`: reuse the completed conversion's `markdown_dir`, then ingest.

Laravel verifies the required saved outputs before starting recovery. Missing
outputs produce a recovery error; they do not silently restart earlier stages.
Ingestion recovery reprocesses unchanged content so a completed Qdrant write
cannot hide an incomplete vector batch or failed Neo4j write. Recovery restarts
the failed stage, not an individual file or database statement within it.

Workflows without `resume` retain their original activity sequence. Deploy the
updated Laravel app, bridge (shared input contract), workflow worker, and indexer
worker together before using stage recovery.

## Tests

From `python_rag`, run `uv run --group test --package hawki-workflow-worker
pytest services/hawki_workflow_worker/tests`.
