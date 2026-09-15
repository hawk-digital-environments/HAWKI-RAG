# Identity & Incremental Ingestion

| Concept | Question it answers | Direct-text example |
|---|---|---|
| Source identity | Which logical document is this? | Keep the dataset and `external_document_id` across revisions |
| Content hash | Did normalized content change? | Changed text produces a different hash |
| Idempotency key | Is this the same submitted operation/replay? | Retry an uncertain submission with the same key and identical body |

A new revision keeps source identity but uses a new idempotency key. The key
belongs to the submission, not to the document's identity.

## Identity rules

| Source | Logical identity |
|---|---|
| Web page | Normalized HTTP URL; scheme/host casing normalized, fragment removed, query string preserved |
| Several files sharing a crawler URL | Relative artifact path participates when needed |
| Upload without HTTP identity | Source-scoped document ID from source ID and relative artifact path |
| Direct text | Dataset plus `external_document_id` determines the source; its stable `document.md` artifact determines the document ID |

A direct-text `source_url` is descriptive metadata and does not replace its
logical identity. Artifact preparation verifies the supplied hash against the
actual bytes and normalized text. A document with no supplied content hash gets
a SHA-256 text hash during chunk preparation.

## Which state is authoritative?

The incremental planner reads **Qdrant payload state** in the selected
collection. Its page-state adapter also uses Qdrant. The PostgreSQL
`ingested_pages` table belongs to Laravel's application/history data;
Python does not consult it for skip/replace decisions.

| Detected state | Vector action | Graph action |
|---|---|---|
| New identity | Embed and insert chunks | Extract/write when enabled |
| Same identity and hash, ordinary source | Skip embedding/vector replacement; mark seen | Skip ordinary extraction |
| Same identity, different hash | Embed; delete previous document points; upsert replacement chunks | Extract first; replace that document's facts after successful extraction |
| Source no longer submits a document | No automatic removal | No automatic removal |
| Internal `graph_only=true, graph=true` | Bypass vector writes and incremental filtering | Re-extract/upsert; stale facts are not automatically removed |

Read failures are not proof that a document is new. Indexing can fail rather
than safely infer state from an unavailable store.

## Direct text requires completion proof

Direct text does not trust one point with a matching hash. Qdrant completion
state records the expected deterministic point set, chunk count, content-based
completion fingerprint, and metadata fingerprint. An unchanged skip requires
that complete expected point set.

If a previous attempt wrote only some points or failed before completion was
published, retry runs the document again. Stable IDs allow missing/incomplete
points to be upserted. Completion publication failure fails direct-text work
rather than reporting a ready source.

When content and completion proof are unchanged but integration metadata changed,
the planner refreshes payload metadata and completion metadata without
re-embedding. This differs from an ordinary unchanged source skip.

## Change settings deliberately

Ordinary source hashes do not include every configuration setting. Changing
chunk size, tag logic, or graph extraction settings and rerunning unchanged
content can still skip that content. A planned rebuild needs a fresh target or
controlled cleanup; merely editing `.env` is not a reindex operation.

Direct-text completion fingerprints include the deterministic point set, but
are not a complete model/chunk-configuration version. They do not eliminate
the need for a deliberate rebuild.

See [Chunking & Embeddings](./chunking_embeddings.md#embedding-migration) for a
safe embedding migration and [Ingestion Recovery](../../Operations/ingestion_recovery.md)
when graph state lags vectors.

<details>
<summary>Implementation references</summary>

Sources: [incremental planner](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/indexing/incremental.py),
[page state](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/indexing/page_state.py),
[point identity](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/indexing/point_identity.py),
[text artifact storage](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/app/Services/TextIngestion/TextIngestionArtifactStorage.php).

</details>
