# HAWKI workflow worker

The workflow worker runs `IngestSourceWorkflow` for current ingestion and
`IngestTextWorkflow` for historical text operations. Workflows determine the
activity order. Scraping, conversion, indexing, and other I/O run in the
activity workers.

## Failed-stage recovery

The historical Laravel dispatcher started a new workflow with a `resume`
payload derived from the failed stage and verified the saved output before
dispatch. The workflow still accepts an explicit, typed `resume` payload.

- `scrape`: start at the beginning of the ingestion path. Managed uploads
  using protocol 2 still start with conversion.
- `convert`: reuse the completed scrape's `raw_dir`, then convert and ingest.
- `ingest`: reuse the completed conversion's `markdown_dir`, then ingest.

The workflow validates the stage and its required output paths before
scheduling activities. Resuming at `ingest` reprocesses unchanged content so
that an incomplete vector batch or a failed graph write can be retried.

The current Python pipeline retry API uses the owning service's recovery path:

- A replay of the same mutation reconciles its recorded operation and workflow
  identity. An uncertain URL dispatch resumes the recorded generation.
- A URL generation recorded as completed with a failed or skipped result is
  recrawled as a successor generation with a new workflow identity.
- A failed managed revision is replaced using its saved upload. The replacement
  creates a new source revision, generation, and workflow identity.

These paths use the normal activity sequence for their workflow input.
Keep the backend, workflow worker, and indexer on matching input contracts.

## URL dispatch acknowledgement

URL starts with the `hawki_url_dispatch_protocol=1` memo retain their final
outcome in an open workflow until PostgreSQL confirms it. They expose
`url_terminal_outcome` and accept `acknowledge_url_terminal` only for the exact
run and control operation. No ingestion activity runs again while awaiting this
acknowledgement. The dispatcher omits execution/run deadlines for these URL
workflows; activity timeouts and retries are unchanged. This allows backend
outages longer than Temporal's closed-history retention.

Deploy these workflow workers and remove older workflow worker processes before
deploying the corresponding backend dispatcher. Workflows without the memo keep
their original behavior. PostgreSQL records the terminal outcome before sending
the acknowledgement and retries that handoff after crashes.

## Tests

From `python_rag`, run `uv run --group test --package hawki-workflow-worker
pytest services/hawki_workflow_worker/tests`.
