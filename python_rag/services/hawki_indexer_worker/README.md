# HAWKI RAG indexer worker

The indexer turns Markdown into Qdrant chunk points. It detects unchanged
content, computes embeddings, and can extract graph facts for Neo4j through
RAG-Anything. It uses deployment-selected queues. The current local deployment polls only
`rawki-local-indexer`; historical queue defaults remain wire compatibility.

The worker runs the indexing code in a Temporal activity and sends signed
events to FastAPI to record progress. The worker has no application PostgreSQL
client. Under the root Compose model, the container loads the shared project
environment file, including any database credentials present in that file.

Build the CPU or CUDA 13.0 variants with `TORCH_VARIANT=cpu` or
`TORCH_VARIANT=gpu`; both are tags of the same logical indexer role. The GPU
extra resolves packages from the CUDA 13.0 (`cu130`) PyTorch index.

See [Ingestion](../../../_documentation/Operations/6_ingestion_embeddings.md)
for incremental behavior and separate vector/graph commit boundaries.

## Tests

From `python_rag`, run `uv run --group test --package hawki-indexer-worker
--extra cpu pytest services/hawki_indexer_worker/tests`.
