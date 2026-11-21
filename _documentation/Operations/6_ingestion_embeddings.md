# Ingestion

FastAPI admits authorized operations into durable ownership state, then Temporal dispatches Python workers.

| Input | API | Execution |
| --- | --- | --- |
| Managed file | `/v1/documents` | immutable upload → converter → indexer |
| Direct text | `/v1/text-ingestions` | immutable Markdown → indexer |
| URL or sitemap | `/v1/pipeline/tasks/start` | compliant crawler → converter → indexer |

All admission requires the service scope and exact active dataset ingest grant. Managed browser uploads prepare datasets through canonical creation and grant APIs. Existing datasets without a grant remain denied. Dataset metadata supplies physical storage scope and embedding contract.

The indexer writes Qdrant and optional Neo4j contributions; signed callbacks publish authoritative status after writer/manifest checks. Graph ingestion is explicit for managed/URL inputs; direct text retains its vector-only contract. Retry and deletion reuse ownership and generation fences.

See [direct text](../Reference/direct_text_ingestion.md), [graph processing](../Core%20Concepts/Ingestion/graph_enrichment.md), [recovery](ingestion_recovery.md), and [deployment](python_runtime.md).
