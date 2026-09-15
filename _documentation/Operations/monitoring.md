# Monitoring & Observability

Start with [Ingestion Recovery's authority table](./ingestion_recovery.md#choose-the-authoritative-system).
Execution, projected status, indexed content, and graph facts can disagree after
a partial failure; no single green indicator proves all four are current.

## Choose the evidence

| Surface | What it tells you | What it does not prove |
|---|---|---|
| `make health` | Container and selected endpoint checks | Successful ingestion or model inference; optional workers can be missing |
| `GET /up` | Laravel liveness | Store, model, or worker readiness |
| `GET /api/rag/health` | Bridge health report | End-to-end retrieval success |
| `GET /api/health/system-gate` | Configured combined UI gate | Every source has finished |
| `GET /api/pipeline/health` | Database, shared storage, Temporal and configured worker checks | All task queues have live pollers |
| `GET /api/pipeline/tasks/{taskId}` | Task, jobs, sources, and projected stage state | Storage and callback state are synchronized |
| `GET /api/pipeline/tasks/{taskId}/events` | Recorded pipeline events | Complete Temporal execution history |
| Temporal UI | Workflow history, pending activities, attempts, task queues | Laravel received the terminal callback |
| `GET /api/rag/monitor` | Bridge/runtime/config report, latest summary, graph preview, document graph and recent failures | A complete per-dataset audit |

`pipeline:workers` prints configured ownership. Workflow/indexer entries in
`pipeline:health` include configuration-only results. Use Temporal poller/history
evidence and a [smoke test](../Getting%20Started/2_setup.md#direct-text-smoke-test) to prove work executes.

## Follow one ingestion

1. Capture `task_id`, `job_id`, and `source_id`. Direct-text acceptance also
   returns `workflow_id`; Laravel job records use `temporal_workflow_id` and
   `temporal_run_id` for execution correlation.
2. Inspect `GET /api/pipeline/tasks/{taskId}` for projected task/job/source state,
   including `index_status` and `ready_at`. Record the last reported stage.
3. Open that workflow and run in Temporal. Inspect its result, pending activity,
   task queue, retry attempt, and terminal callback history.
4. Read logs from the worker that owns the failing activity. Match workflow/run
   and source identifiers; retain the callback `event_id` when diagnosing delivery.
5. Check the dataset's Qdrant collection and source/document payloads. Verify
   expected chunk coverage; for direct text, inspect completion state as well.
6. When graph ingestion was enabled or required, check canonical Neo4j facts
   using the dataset ID, namespace, and document provenance. A monitor preview
   alone is insufficient.
7. Use [Ingestion Recovery](./ingestion_recovery.md#diagnose-by-completed-boundary)
   to choose a retry or targeted repair at the last completed boundary.

Read bounded logs before restarting:

```bash
docker logs --tail=200 hawki_rag_bridge
docker logs --tail=200 hawki_rag_indexer_worker
docker logs --tail=200 hawki_rag_temporal_workflow_worker
```

These are container names from Compose. The general log stream is documented in
[Run HAWKI RAG](../Getting%20Started/2_setup.md); queue/history diagnostics and
callback signatures are in [Temporal Operations](./temporal_operations.md).

## Monitor data and retention

Laravel stores ingestion summaries and graph previews as JSONB in
`rag_ingestion_artifacts`; graph failures live in `rag_graph_failures`.
The monitor's `postgresql://rag_ingestion_artifacts/...` path is a compatibility
identifier, not a file to open. The latest summary and latest nonempty graph
preview can come from different records.

Terminal callbacks persist this evidence. Retention pruning is opportunistic
during callback handling, with a default of 30 days; a value below one disables
pruning. It is not a dedicated scheduled cleanup service. See
[configuration](./5_environment_db_queue.md#postgresql-and-laravel-state).

Do not treat a preview or a summary count as canonical graph/vector state.
For batch ingestion, counters and the carried batch summary are also different
levels of aggregation; inspect source results when diagnosing an individual
document.

## Logging boundaries

The supplied repository provides health/status endpoints, Temporal history,
container logs, request IDs, and bounded event-logging helpers. It does **not**
configure an application Prometheus metrics endpoint/scraper, OpenTelemetry
tracing/exporter, or centralized log collector. Infrastructure services may
have their own telemetry capabilities; those are not an integrated observability
pipeline in this Compose stack. Correlate identifiers across the surfaces above.

Python's observability utilities redact common secret keys and bound log values.
The bridge carries request IDs and returns categorized HTTP failures. This does
not make every log content-free: exception paths and Laravel MCP logging can
include query text and backend details. Limit log access and avoid putting
credentials into queries, metadata, or source URLs.

<details>
<summary>Implementation references</summary>

Implementation: [monitor service](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/app/Services/Rag/RagMonitorService.php),
[artifact reader](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/app/Services/Rag/RagMonitorArtifactReader.php),
[worker events](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/app/Services/Pipeline/PipelineWorkerEventService.php),
[observability package](https://github.com/hawk-digital-environments/HAWKI-RAG/tree/main/python_rag/packages/observability).

</details>
