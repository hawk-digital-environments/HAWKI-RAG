# Monitoring & Observability

Use `/v1/ops/health` and `/v1/ops/stats` with a service JWT that has the required scopes. `health` reports runtime probes. `stats` reports store counts. Use task and
workflow records to check a specific job.

The pipeline API lists authorized task runs, stage state/counters and preview/download logs. Managed previews expose allowlisted signed stage events, scoped to an authorized task. Older receipts without stage details produce empty task logs. The API uses
task-scoped events for previews and downloads.

Compare backend ownership, Temporal workflow/activity history, writer receipts, Qdrant/Neo4j evidence and exact source artifacts. Preserve IDs and timestamps during diagnosis. Do not print provider credentials, service JWTs, callback secrets or database passwords.

Run backend readiness and the read-only Python deployment audit described in [deployment](python_runtime.md). Use [recovery](ingestion_recovery.md) for scoped retries and cleanup.
