# Ingestion Recovery

Identify the failed ownership boundary before retrying. Preserve document/task/source IDs, workflow/run identity, generation, idempotency key and signed receipts.

1. Read canonical document or pipeline state and its stage-event logs.
2. Inspect Temporal history for pending, failed or uncertain activities.
3. Compare Qdrant, graph and artifact evidence using the authorized dataset scope.
4. Use the owning document/pipeline retry, replacement, cancellation or deletion API. Never adopt a foreign or unknown historical job.
5. Verify publication/ready status or completed cleanup using actual store evidence.

A timeout can leave a dispatch outcome uncertain. Reconcile the same operation identity; do not generate an unrelated duplicate. Graph failure can leave vector evidence current while graph enrichment is incomplete. Use supported scoped recovery rather than broad store clearing.

Deletion requires sealed/quiescent producer evidence and writer release. Terminal workflow status alone does not authorize cleanup. Historical archives, reserved IDs and frozen public tables remain protected. See [deployment](python_runtime.md) and [Temporal operations](temporal_operations.md).
