# Storage: Vectors, Graphs & Artifacts

HAWKI-RAG stores operation records, workflow history, indexed evidence, and
source files separately.

| Store | State | Authority |
|---|---|---|
| PostgreSQL application database | Datasets, grants, tasks, sources, jobs, managed documents, worker-event receipts, monitor artifacts | FastAPI operational records |
| Temporal persistence | Workflow history and execution state, separate from backend operational tables | Recorded workflow execution |
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
follow the [embedding compatibility and migration guidance](./Ingestion/chunking_embeddings.md#embedding-model-compatibility).

During a query, FastAPI supplies the collection selected for the authorized
dataset. The retrieval package searches that collection and applies a mandatory
`dataset_id` payload filter to restrict results to that dataset. If the collection
is missing, the query returns `dataset_not_ready` (HTTP 503); collection creation
takes place during ingestion.

The indexer also reads source identity, content hashes, and direct-text completion
markers from Qdrant payloads to decide whether to skip, retry, or replace a
document. FastAPI stores operational ownership in its dedicated PostgreSQL schema. [Identity & Incremental Ingestion](./Ingestion/identity_incremental.md)
explains how the indexer uses the stored points to make those decisions.

See the Qdrant documentation for
[Collections](https://qdrant.tech/documentation/manage-data/collections/) and
[Points](https://qdrant.tech/documentation/manage-data/points/).

## Neo4j

Neo4j represents entities as **nodes** and connections between them as
**relationships**. Both can carry **properties**, which store additional data as
key-value pairs.

In HAWKI-RAG, a namespace identifies a logical graph scope within a Neo4j database.
Canonical facts carry dataset and namespace scope; reads and writes use those
trusted values. Document provenance permits targeted graph replacement.

RAG-Anything/LightRAG extraction can maintain intermediate graph state.
That state is distinct from the canonical facts HAWKI-RAG normalizes and writes.
See [Ingestion with Graph Processing Enabled](./Ingestion/graph_enrichment.md).

Neo4j enrichment is optional behavior, although Compose starts Neo4j and the
default health gate includes graph checks.

See
[Graph database concepts](https://neo4j.com/docs/getting-started/appendix/graphdb-concepts/)
in the Neo4j documentation.

## Shared storage

The named Docker volume is `rawki_shared_storage`. FastAPI and activity workers
share `/shared`.
The application initializes ownership/permissions at startup.

Paths must stay beneath the configured canonical shared root. The artifact
adapter rejects escaping paths and does not implement S3 listing/reading.
Remote object-storage settings do not enable an alternative ingestion handoff.

## Consistency and backups

The indexer writes to Qdrant before Neo4j. Signed callbacks update FastAPI
operation records separately. Back up the stores together to retain all state.

Preserve the application database, Temporal persistence, Qdrant, Neo4j, shared
artifacts, protected archive evidence, and installation secrets. The repository does not provide a coordinated cross-store backup/restore
command. Quiesce ingestion and coordinate snapshots operationally.

For mismatched state, begin with
[Ingestion Recovery](../Operations/ingestion_recovery.md), not a whole-stack reset.

