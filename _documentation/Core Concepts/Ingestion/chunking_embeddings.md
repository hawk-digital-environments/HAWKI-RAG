# Chunking & Embeddings

A chunk is one retrievable passage. Each successfully embedded chunk becomes a
Qdrant point containing the vector, text, and source metadata.

```text
Document → character-based chunks → embedding per chunk → Qdrant points
```

## Chunk boundaries

Current chunking is **character-based**, not token-based.

| Setting | Template default | Behavior |
|---|---|---|
| `CHUNK_SIZE` | 1200 | Target characters per chunk |
| `CHUNK_OVERLAP_SIZE` | 250 | Start the next window before the previous end |
| `INGEST_BATCH_SIZE` | 64 | Artifact grouping and Qdrant upsert batch size |

The splitter strips outer whitespace, seeks the last paragraph break in the
window, and uses it if it lies beyond 60% of the target size. Otherwise it cuts
at the character window. There is no tokenizer or sentence-boundary guarantee.
The request validates positive size and overlap smaller than size.

A point stores chunk text/index, stable document ID, source metadata, content
hash, component type, and derived tags. Its ID is a UUIDv5 derived from
`doc_id:chunk_index`. The same document and chunk index overwrite the same point
on retry.

## Embedding compatibility is a dataset invariant

Laravel persists the dataset's embedding provider/model and sends it to the
indexer and query bridge. Queries must use that embedding space. Equal vector
dimensions alone do not make two models compatible.

:::warning Existing collections are not fully preflighted

Indexing infers dimension from returned embeddings when creating a collection.
For an existing collection, `ensure_collection()` returns after an existence
check: it does not compare dimension or distance. Incompatibility can therefore
surface at upsert, after changed-document deletion. Verify the target before a
model migration; this is a current preflight-validation gap.

:::

Graph-only work has no vector response from which to infer dimension, so its
model adapter needs a known model dimension or the trusted alias-to-dimension map.

Changing a provider/model default affects new dataset selection; it does not
convert existing vectors. Ollama and LiteLLM selection is explicit: provider
failure does not silently switch the embedding space.

## Batches and partial failures

The embedding loop calls the provider once per chunk. Increasing the batch size
does not introduce provider-side embedding batching. Artifacts are grouped into
in-process calls, then Qdrant upserts are batched separately.

| Failure | Ordinary website/upload | Direct text |
|---|---|---|
| Some chunk embeddings fail | Successful points can commit; failures appear in summary | Any failed chunk fails the document before Qdrant replacement/upsert |
| Every chunk of one document fails | Document counted as skipped; other documents may proceed | Whole document must be retried |
| Every prepared chunk fails | No upsert; embedding error | Same |
| Later Qdrant upsert batch fails | Earlier batches may already exist | Earlier batches may exist, but completion is not proven |
| Completion state cannot be recorded | Ordinary page-state update can warn | Direct-text activity fails |

Partial ordinary vectors can carry the new content hash, so an unchanged retry
may skip missing chunks. Confirm coverage and use a controlled rebuild of the
affected source/target when needed; do not assume an ordinary retry repairs
every partial result.

Changed-document replacement deletes old points before upserting replacements.
A subsequent write failure can leave a gap. Direct text computes all embeddings
before that replacement, but the multi-batch database write is still not atomic.

## Change impact

| Change | Data follow-up |
|---|---|
| Chunk size / overlap | Rebuild affected vectors; consider graph rebuild |
| Provider / embedding model / dimension | Re-ingest into a compatible target |
| Collection distance metric | Use a correctly configured target and reindex |
| Batch size | Recreate affected services; no semantic data migration |
| Graph extraction model/window | Rebuild graph if historical facts should change |
| Metadata/tag derivation | Refresh/re-ingest affected payloads through a supported path |

Ordinary unchanged content can bypass work after a setting change. See
[Identity & Incremental Ingestion](./identity_incremental.md#change-settings-deliberately).

## Embedding migration

1. Configure and create a new dataset/collection with the intended model contract.
2. Ingest the source content into that target.
3. Check dimensions, document/chunk coverage, and representative retrieval.
4. Switch consumers to the verified dataset.
5. Retire the old target after cutover and retention requirements are satisfied.

No automated embedding migration command is documented by this repository.

<details>
<summary>Implementation references</summary>

Sources: [splitter](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/packages/text_processing/src/hawki_rag_text/chunking.py),
[vector preparation](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/indexing/vector_prepare.py),
[vector commit](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/indexing/vector_commit.py),
[request validation](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/services/hawki_indexer_worker/src/hawki_indexer_worker/indexing/request.py),
[collection client](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/packages/vector_store/src/hawki_vector_store/client.py).

</details>
