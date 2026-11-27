# Temporal operations

FastAPI saves the operation ID and workflow input before dispatch. Workers poll
the workflow, scraper, converter, or indexer queue. Temporal history records
execution. Check the backend receipt and stored artifacts or records to confirm
publication.

Configure Temporal address/namespace and `TEMPORAL_RAG_WORKFLOW_TASK_QUEUE`, `TEMPORAL_RAG_SCRAPER_TASK_QUEUE`, `TEMPORAL_RAG_CONVERTER_TASK_QUEUE` and `TEMPORAL_RAG_INDEXER_TASK_QUEUE` consistently. Local workers use dedicated `rawki-local-*` queues.

Workers report events to FastAPI on the private Docker network. The worker and
backend share a signing secret. The HMAC covers the Unix timestamp, a dot, and
the exact serialized callback body. Keep their clocks synchronized so the
timestamp reaches the backend within 300 seconds of its clock. Readiness checks
that `RAG_WORKER_CALLBACK_MAX_AGE_SECONDS` is between 1 and 3600. The callback
verifier always uses its fixed 300-second limit.

Publication and deletion checks use the recorded generation, workflow and run
IDs, source ID, and writer state.

Inspect execution history and pending activities before retries. Use canonical pipeline/document operations for cancellation, replacement and deletion. A retry must reconcile uncertain dispatch instead of starting duplicate work; cleanup must prove producer quiescence before removing owned contributions.

Keep historical executions, immutable receipts and original artifacts intact. See [recovery](ingestion_recovery.md), [monitoring](monitoring.md) and [deployment](python_runtime.md).

## Dataset deletion in the background

Dataset retirement creates cleanup work for the sources owned by the dataset. Reconcile pending cleanup through the configured backend coordinator and canonical APIs. Do not substitute an unrelated queue task or a broad store deletion for ownership, writer release and quiescence evidence.
