# Ingestion

FastAPI admits authorized operations into durable ownership state, then Temporal dispatches Python workers.

| Input | API | Execution |
| --- | --- | --- |
| File or inline text | `/v1/documents` | immutable upload → converter → indexer |
| URL or sitemap | `/v1/pipeline/tasks/start` | compliant crawler → converter → indexer |

All admission requires the service scope and an exact ingest grant on an active dataset. Services can prepare datasets through the dataset creation and grant APIs. Dataset settings supply the storage scope and embedding model.

The indexer writes Qdrant and optional Neo4j contributions. Signed callbacks publish authoritative status after writer and manifest checks. Graph ingestion is explicit for managed and URL inputs. Retry and deletion reuse ownership and generation fences.

See [direct text](../Reference/direct_text_ingestion.md), [graph processing](../Core%20Concepts/Ingestion/graph_enrichment.md), [recovery](ingestion_recovery.md), and [deployment](python_runtime.md).
