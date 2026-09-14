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

Keep the returned `task_id`, `job_id`, `source_id`, and `workflow_id`.
Match the workflow to its Temporal run/activity/attempt and signed callback event.
Then compare the source's projected `index_status` with Qdrant content and,
when relevant, Neo4j facts.

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

Python's observability utilities redact common secret keys and bound log values.
The bridge carries request IDs and returns categorized HTTP failures. This does
not make every log content-free: exception paths and Laravel MCP logging can
include query text and backend details. Limit log access and avoid putting
credentials into queries, metadata, or source URLs.

Implementation: [monitor service](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/app/Services/Rag/RagMonitorService.php),
[artifact reader](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/app/Services/Rag/RagMonitorArtifactReader.php),
[worker events](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/app/Services/Pipeline/PipelineWorkerEventService.php),
[observability package](https://github.com/hawk-digital-environments/HAWKI-RAG/tree/main/python_rag/packages/observability).
