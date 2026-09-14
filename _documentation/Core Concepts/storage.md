# Storage: Vectors, Graphs & Artifacts

Each store answers a different question. They are not interchangeable replicas.

| Store | State | Authority |
|---|---|---|
| PostgreSQL application database | Datasets, grants, tasks, sources, jobs, managed documents, worker-event receipts, monitor artifacts | Laravel's application records and operator projections |
| Temporal persistence | Workflow history and execution state, separate from Laravel tables | Temporal execution truth |
| Qdrant | Chunk text and metadata alongside embeddings; content hashes and direct-text completion markers | What is indexed and the state used for incremental decisions |
| Neo4j | Canonical normalized entities/relations with dataset namespace and document provenance | What graph facts exist |
| Shared volume | Raw uploads/crawl files, Markdown, metadata sidecars, manifests | Artifact handoff and source material for indexing |

## Qdrant

Each deterministic point represents one chunk, not one entire document.
The payload contains the text required for retrieval, so searching does not
read a `chunks.md` artifact or reconstruct chunks from PostgreSQL.

A query uses the collection from trusted dataset scope and a mandatory
`dataset_id` payload filter. The reader does not create a missing collection.
Indexing creates/validates the collection dimension and writes its content.

Source identity, content hash, and direct-text completion information are stored
with Qdrant payloads. Historical/application `ingested_pages` records in
PostgreSQL are not a competing indexer registry.
[Identity & Incremental Ingestion](./Ingestion/identity_incremental.md) explains
the comparison and replacement rules.

## Neo4j

A namespace is a logical graph scope, not a separate Neo4j database.
Canonical facts carry dataset and namespace scope; reads and writes use those
trusted values. Document provenance permits targeted graph replacement.

RAG-Anything/LightRAG extraction can maintain intermediate graph state.
That state is distinct from the canonical facts HAWKI RAG normalizes and writes.
See [Graph Enrichment](./Ingestion/graph_enrichment.md).

Neo4j enrichment is optional behavior, although Compose starts Neo4j and the
default health gate includes graph checks.

## Shared storage

The named Docker volume is `rawki_shared_storage`. Laravel and activity workers
share `/shared`; Laravel also mounts the volume at `/app/shared`.
The application initializes ownership/permissions at startup.

Paths must stay beneath the configured canonical shared root. The artifact
adapter rejects escaping paths and does not implement S3 listing/reading.
Remote object-storage settings do not enable an alternative ingestion handoff.

## Consistency and backups

Qdrant writes precede graph writes; callbacks update Laravel separately.
A snapshot of only one store is not a full recoverable application backup.

Preserve the application database, Temporal persistence, Qdrant, Neo4j, shared
artifacts, the host-mounted Laravel `storage/` directory, and installation
secrets. The repository does not provide a coordinated cross-store backup/restore
command. Quiesce ingestion and coordinate snapshots operationally.

For mismatched state, begin with
[Ingestion Recovery](../Operations/ingestion_recovery.md), not a whole-stack reset.

Sources: [Compose volumes](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/docker-compose.yml),
[artifact store](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/packages/artifact_store/src/hawki_artifact_store/local.py),
[Qdrant state](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/indexing/page_state.py),
[graph store](https://github.com/hawk-digital-environments/HAWKI-RAG/tree/main/python_rag/packages/graph_store/src/hawki_graph_store).
