# Temporal operations

FastAPI persists operation identity and immutable workflow input before dispatch. Python workers consume the configured workflow, scraper, converter and indexer queues. Temporal execution state, backend ownership receipts and actual store state answer different questions; do not infer successful publication from workflow closure alone.

Configure Temporal address/namespace and `TEMPORAL_RAG_WORKFLOW_TASK_QUEUE`, `TEMPORAL_RAG_SCRAPER_TASK_QUEUE`, `TEMPORAL_RAG_CONVERTER_TASK_QUEUE` and `TEMPORAL_RAG_INDEXER_TASK_QUEUE` consistently. Local workers use dedicated `rawki-local-*` queues.

Workers call the Python internal boundary with timestamped HMAC signatures. Secret and replay-window configuration must agree across backend and workers. The backend receives internal callbacks on the private Docker network. Generation, workflow/run/source identity and writer release remain required for publication/deletion.

Inspect execution history and pending activities before retries. Use canonical pipeline/document operations for cancellation, replacement and deletion. A retry must reconcile uncertain dispatch instead of starting duplicate work; cleanup must prove producer quiescence before removing owned contributions.

Keep historical executions, immutable receipts and original artifacts intact. See [recovery](ingestion_recovery.md), [monitoring](monitoring.md) and [deployment](python_runtime.md).

## Dataset deletion in the background

Dataset retirement drives owned source cleanup through the existing lifecycle. Reconcile pending cleanup through the configured backend coordinator and canonical APIs. Do not substitute an unrelated queue task or a broad store deletion for ownership, writer release and quiescence evidence.
