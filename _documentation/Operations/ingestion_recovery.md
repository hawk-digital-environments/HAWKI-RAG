# Ingestion Recovery

Find the failed stage before retrying. Record the document, task and source IDs,
the Temporal workflow and run IDs, the generation, and the idempotency key.
Keep the signed receipts.

1. Read canonical document or pipeline state and its stage-event logs.
2. Inspect Temporal history for pending, failed or uncertain activities.
3. Compare Qdrant, graph and artifact evidence using the authorized dataset scope.
4. Use the owning document/pipeline retry, replacement, cancellation or deletion API. Never adopt a foreign or unknown historical job.
5. Verify publication/ready status or completed cleanup using actual store evidence.

A timeout can leave a dispatch outcome uncertain. Reconcile the same operation identity; do not generate an unrelated duplicate. Graph failure can leave vector evidence current while graph enrichment is incomplete. Use supported scoped recovery rather than broad store clearing.

Deletion stays pending until the backend can verify the expected generation
and close writer admission with the applicable fences. For a submitted crawler
job, it must verify a sealed receipt for the exact job and generation that
proves the external producer has drained. Independently, the backend checks
that the owned sink writers have released their grants or that its records
prove no writer was admitted. A terminal, closed, or cancelled Temporal
workflow alone is insufficient evidence for cleanup.

Historical archives, reserved IDs, and frozen public tables remain protected.
See [deployment](python_runtime.md) and [Temporal operations](temporal_operations.md).
