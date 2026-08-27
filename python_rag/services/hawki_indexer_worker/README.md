# HAWKI RAG indexer worker

This Temporal activity worker owns Markdown chunking, incremental planning,
embedding, Qdrant writes, Neo4j writes, and RAG-Anything graph extraction. It
polls `rag-ingestion-task-queue` by default so existing workflow executions keep
working during the role rename.

The worker calls its indexing application logic directly. It has no FastAPI
application, bridge URL, Laravel database credentials, or PostgreSQL client.
Laravel-owned pipeline metadata is updated only through signed worker events.

Build the CPU or CUDA 13.0 variants with `TORCH_VARIANT=cpu` or
`TORCH_VARIANT=gpu`; both are tags of the same logical indexer role. The GPU
extra resolves packages from the CUDA 13.0 (`cu130`) PyTorch index.

## Tests

From `python_rag`, run `uv run --group test --package hawki-indexer-worker
--extra cpu pytest services/hawki_indexer_worker/tests`.
