# Chunking & Embeddings

A chunk is one retrievable passage. Each successfully embedded chunk becomes a
Qdrant point containing the vector, text, and source metadata.

```text
Document → character-based chunks → embedding per chunk → Qdrant points
```

This happens during the **indexing stage** of the ingestion workflows:

| Input | Temporal workflow | Path to indexing |
|---|---|---|
| Website | `IngestSourceWorkflow` | Crawl pages → prepare Markdown through conversion or passthrough → index |
| Uploaded file | `IngestSourceWorkflow` | Stage the uploaded file → prepare Markdown through conversion or passthrough → index |
| Direct text | `IngestTextWorkflow` | Use the submitted text's stored Markdown → index |

Both workflows call the `ingest_markdown_files` activity, run by
`hawki_indexer_worker`. This activity groups input files, splits documents into
chunks, generates embeddings, and writes chunk points to Qdrant. The batching
described below takes place inside this activity. See
[Ingestion & Embeddings](../../Operations/6_ingestion_embeddings.md) for the
end-to-end ingestion process.

## Chunk boundaries

Chunking uses **character counts** to set passage boundaries.

| Setting | Template default | Behavior |
|---|---|---|
| `CHUNK_SIZE` | 1200 | Target characters per chunk |
| `CHUNK_OVERLAP_SIZE` | 250 | Start the next window before the previous end |
| `INGEST_BATCH_SIZE` | 64 | Maximum input files per ingestion group and chunk points per Qdrant write |

A **batch** is a group of document chunks written to Qdrant together. A batch can contain chunks from **one or multiple documents**, and large documents may span several batches. Documents are divided into smaller pieces called **chunks**. Whenever possible, the system splits the text at a paragraph break to keep related content together. Otherwise, it splits at the configured chunk size. Each chunk is stored in Qdrant with its text, document ID, position, source information, and metadata. Each chunk also has a **stable unique ID**, so processing the same chunk again updates the existing point instead of creating a duplicate.

See [Batches and partial failures](#batches-and-partial-failures) for more details.

## Embedding Model Compatibility

Laravel stores the dataset's embedding provider and model and sends them to the
indexer and query bridge. Indexing and queries must use the same embedding model
so their vectors share a common meaning. Compatibility depends on the model as
well as the vector dimension.

:::warning Changing embedding models

Each dataset uses a specific embedding model for both indexing and search.
Changing the model for an existing dataset can make its stored vectors
incompatible with newly generated ones. If you need to change the embedding model, create a new dataset and collection, re-ingest the content, and verify that search works correctly before switching
to the new dataset. Existing data continues to use the embedding model with which it was originally
created.
See [Embedding migration](#embedding-migration) for the migration steps.

:::

The system keeps track of the embedding model used for each dataset. Existing vectors continue to use their original model until the content is re-ingested. If the embedding model is changed, the content should be re-ingested so that all vectors are created with the same model. Embedding providers such as **Ollama** and **LiteLLM** can be selected explicitly. If the selected provider cannot generate an embedding, the operation fails and reports an error.
## Batches and partial failures

The system applies `INGEST_BATCH_SIZE` at two stages:

| Stage | What one batch contains |
|---|---|
| Preparing documents | Up to the configured number of input Markdown files, whose documents are passed together to the indexer |
| Writing vectors to Qdrant | Up to the configured number of chunk points from those documents, sent in one write request |

For example, two documents processed together with 40 successfully embedded
chunks each produce 80 points. With a batch size of 64, the first Qdrant write
contains 40 points from the first document and 24 from the second. The next write
contains the remaining 16 points. Each point keeps its source document ID.

The embedding provider is called once for each chunk. The successfully created
points are then grouped into batches and written to Qdrant.

### What happens when indexing fails

For website and uploaded-file ingestion, successfully processed chunks can still
be stored even if some chunks fail. This can leave a document **partially
indexed**. For example, if 8 of 10 chunks are successfully indexed, those 8 chunks are
available for search while the remaining 2 are missing.

A retry does not always fill these missing chunks automatically. If the system
recognizes the document as unchanged, it may skip processing it again. When a
partial indexing result occurs, the affected document should therefore be checked
and rebuilt if necessary. Direct-text ingestion handles this more strictly. All chunk embeddings must
succeed before the existing document is replaced. If an embedding fails, the
operation stops and the document can be retried safely.

Qdrant writes are performed in batches. If one of the later writes fails, earlier
successful batches may already be stored. Retrying the ingestion allows the
system to process the incomplete document again. For direct-text ingestion, the document is considered complete only after all expected points have been written successfully.

See [How text ingestion handles retries](./identity_incremental.md#how-retries-check-whether-direct-text-indexing-is-complete).

## System configuration and setup

These settings control chunking, embedding, and batch writes. The table describes
how changes affect future ingestion and existing indexed content.

| Setting or processing rule changed | How to apply it and handle existing data |
|---|---|
| `CHUNK_SIZE`, `CHUNK_OVERLAP_SIZE` | Recreate affected services and rebuild affected vectors; consider graph rebuild |
| Dataset embedding provider/model or vector dimension | Create a compatible target and re-ingest before switching consumers; Settings defaults apply to new datasets |
| `QDRANT_DISTANCE` / collection distance metric | Use a collection configured for the intended metric and reindex |
| `INGEST_BATCH_SIZE` | Recreate affected services; new workflow inputs use the new batch size, and existing vectors remain valid |

See [Runtime options and system configuration](../../Operations/5_environment_db_queue.md#runtime-options-and-system-configuration)
for when settings take effect, and
[graph extraction settings](./graph_enrichment.md#system-configuration-and-setup)
for changes to graph processing.

For websites and uploaded files, the incremental planner can skip documents with
unchanged content after a setting change. See
[Identity & Incremental Ingestion](./identity_incremental.md#system-configuration-and-setup).

## Embedding migration

1. Configure and create a new dataset/collection with the intended model contract.
2. Ingest the source content into that target.
3. Check dimensions, document/chunk coverage, and representative retrieval.
4. Switch consumers to the verified dataset.
5. Retire the old target after cutover and retention requirements are satisfied.

Operators carry out these migration steps using dataset configuration and
ingestion workflows.

