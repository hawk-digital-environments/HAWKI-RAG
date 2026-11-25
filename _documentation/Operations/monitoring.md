# Monitoring & Observability

Use `/v1/ops/health` and `/v1/ops/stats` with a service JWT that has the required scopes. Health reports required runtime probes; stats report actual store counts. A healthy dependency does not prove an individual job completed.

The pipeline API lists authorized task runs, stage state/counters and preview/download logs. Managed previews expose allowlisted signed stage events, scoped to an authorized task. Earlier receipts without stage details have an intentional empty state. Shared worker stdout is not exposed as task-owned browser logs.

Compare backend ownership, Temporal workflow/activity history, writer receipts, Qdrant/Neo4j evidence and exact source artifacts. Preserve IDs and timestamps during diagnosis. Do not print provider credentials, service JWTs, callback secrets or database passwords.

Run the 26-check backend readiness and read-only Python deployment audit described in [deployment](python_runtime.md). Use [recovery](ingestion_recovery.md) for scoped retries and cleanup.
