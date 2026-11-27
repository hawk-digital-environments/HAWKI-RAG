# Ingestion

FastAPI records each admitted ingestion operation before starting its Temporal
workflow. The workers prepare Markdown and index it in the selected dataset.

| Input | API | Execution |
| --- | --- | --- |
| File or inline text | `/v1/documents` | immutable upload → converter → indexer |
| URL or sitemap | `/v1/pipeline/tasks/start` | Temporal scraper worker → converter → indexer |

The caller needs `rag:ingest` and an ingest grant on the active dataset. Use the
dataset creation and grant APIs to prepare a dataset. Its stored settings select
the embedding model and storage targets.

The indexer writes chunk points to Qdrant and can write graph facts to Neo4j
within the authorized graph scope. Signed worker events record stage progress.
Before publishing ready status, FastAPI verifies the applicable writer-release
and manifest evidence. Retry and deletion enforce ownership and generation checks.

See [direct text](../Reference/direct_text_ingestion.md), [graph processing](../Core%20Concepts/Ingestion/graph_enrichment.md), [recovery](ingestion_recovery.md), and [deployment](python_runtime.md).
