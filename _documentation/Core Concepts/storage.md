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

Qdrant stores the document passages used for search together with their
embeddings. A **collection** is a named container for these records, called
**points**. A dataset's configuration identifies the collection to use, and one
collection can hold points from many documents.

Each point represents one **chunk**, a passage split from a document, and contains:

| Component | Purpose |
|---|---|
| Point ID | Derived from the document ID and chunk position, so retrying the same chunk writes to the same point |
| Vector | The chunk's embedding: a list of numbers used to compare its meaning with a query |
| Payload | The chunk text and metadata, including its document ID, dataset ID, and source information |

For example, fully indexing a document with ten chunks stores ten points in the
selected collection. Retrieval reads the matching passages directly from their
point payloads.

During ingestion, the indexer prepares the chunks and their embeddings, then
checks whether the target collection exists. If needed, it creates the collection
with the embedding's **dimension** (the number of values in each vector) and the
configured **distance metric** (the method used to compare vectors, such as
cosine similarity). The new collection starts empty. The indexer then writes the
prepared points in batches; a failure before the first successful write can leave
it empty.

For an existing collection, the indexer confirms its existence and proceeds with
its stored dimension and distance settings. A dimension mismatch can cause a
point write to fail. Before changing the embedding model or collection settings,
follow the [embedding compatibility and migration guidance](./Ingestion/chunking_embeddings.md#embedding-compatibility-is-a-dataset-invariant).

During a query, Laravel supplies the collection selected for the authorized
dataset. The query bridge searches that collection and applies a mandatory
`dataset_id` payload filter to restrict results to that dataset. If the collection
is missing, the query returns `dataset_not_ready` (HTTP 503); collection creation
takes place during ingestion.

The indexer also reads source identity, content hashes, and direct-text completion
markers from Qdrant payloads to decide whether to skip, retry, or replace a
document. Laravel's PostgreSQL `ingested_pages` records serve application and
history needs. [Identity & Incremental Ingestion](./Ingestion/identity_incremental.md)
explains how the indexer uses the stored points to make those decisions.

For a full explanation of Qdrant's concepts, please refer to the official
[Collections](https://qdrant.tech/documentation/manage-data/collections/) and
[Points](https://qdrant.tech/documentation/manage-data/points/) documentation.

## Neo4j

Neo4j represents entities as **nodes** and connections between them as
**relationships**. Both can carry **properties**, which store additional data as
key-value pairs.

In HAWKI RAG, a namespace identifies a logical graph scope within a Neo4j database.
Canonical facts carry dataset and namespace scope; reads and writes use those
trusted values. Document provenance permits targeted graph replacement.

RAG-Anything/LightRAG extraction can maintain intermediate graph state.
That state is distinct from the canonical facts HAWKI RAG normalizes and writes.
See [Ingestion with Graph Processing Enabled](./Ingestion/graph_enrichment.md).

Neo4j enrichment is optional behavior, although Compose starts Neo4j and the
default health gate includes graph checks.

For a full explanation of Neo4j's concepts, please refer to the official
[Graph database concepts](https://neo4j.com/docs/getting-started/appendix/graphdb-concepts/)
documentation.

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

<details>
<summary>Implementation references</summary>

Sources: [Compose volumes](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/docker-compose.yml),
[artifact store](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/packages/artifact_store/src/hawki_artifact_store/local.py),
[Qdrant state](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/indexing/page_state.py),
[graph store](https://github.com/hawk-digital-environments/HAWKI-RAG/tree/main/python_rag/packages/graph_store/src/hawki_graph_store).

</details>
