# Chunking & Embeddings

A **chunk** is one retrievable passage. Each successfully embedded chunk becomes a
Qdrant point containing the vector, text, and source metadata.

```text
Document → character-based chunks → embedding per chunk → Qdrant points
```

Current file, text, and URL submissions reach the same indexing activity.

| Input | Temporal workflow | Path to indexing |
|---|---|---|
| Website | `IngestSourceWorkflow` | Crawl pages → prepare Markdown through conversion or passthrough → index |
| File or inline text | `IngestSourceWorkflow` | Stage the upload → prepare Markdown through conversion or passthrough → index |
| Historical direct text | `IngestTextWorkflow` | Read the stored Markdown → index |

These workflows call the `ingest_markdown_files` activity, run by
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

The chunker prefers paragraph breaks when it splits a document. Otherwise it
uses the configured chunk size. Each point stores the chunk text, source and
document IDs, position, and metadata. Reprocessing the same chunk writes to
the same **stable point ID**.

See [Batches and partial failures](#batches-and-partial-failures) for more details.

## Embedding Model Compatibility

FastAPI stores the dataset's embedding provider and model. The indexing
workflow and FastAPI's retrieval package both use that contract. Indexing and
queries must use the same embedding model so their vectors can be compared.
Compatibility depends on the model as well as the vector dimension.

:::warning Changing embedding models

Each dataset uses a specific embedding model for both indexing and search.
Changing the model for an existing dataset can make its stored vectors
incompatible with newly generated ones. If you need to change the embedding model, create a new dataset and collection, re-ingest the content, and verify that search works correctly before switching
to the new dataset. Existing data continues to use the embedding model with which it was originally
created.
See [Embedding migration](#embedding-migration) for the migration steps.

:::

Set `RAG_BACKEND_DATASET_EMBEDDING_PROVIDER` and
`RAG_BACKEND_DATASET_EMBEDDING_MODEL` before creating the target dataset.
The backend copies these defaults into the dataset record at creation.
Changing the defaults leaves existing records and vectors in place.
`RAG_BACKEND_QUERY_MODELS` separately registers chat and vision models
by provider. Supported embedding providers include **Ollama** and **LiteLLM**.
Indexing reports embedding failures.

## Batches and partial failures

The indexer uses the same **batch size** for input files and Qdrant writes.
A write can include points from **one or more documents**, and a large
document can span several writes.

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

Ingestion can leave a **partial result** for documents without whole-document
completion checks. For example, if 8 of 10 chunks succeed, search can see
those eight. Inspect any partial result before retrying. The incremental
planner can skip a document it considers unchanged.

Historical direct-text documents and artifacts identified by source and path
require whole-document completion. All embeddings must succeed before the
indexer replaces existing points. If a later Qdrant batch fails, points from
earlier batches may already exist. The indexer checks the expected points and
completion markers to decide whether a retry must reprocess the document.

See [How text ingestion handles retries](./identity_incremental.md#how-text-ingestion-handles-retries).

## System configuration and setup

For managed file and inline-text submissions, FastAPI records `CHUNK_SIZE`,
`CHUNK_OVERLAP_SIZE`, and `INGEST_BATCH_SIZE` in the workflow input. URL
ingestion uses the same indexing activity, but FastAPI leaves these fields
unset for URL tasks. The indexer then uses its defaults of 1200 characters,
250 characters of overlap, and 64 items per batch. Existing workflow inputs
retain their recorded settings.

| Setting or processing rule changed | How to apply it and handle existing data |
|---|---|
| `CHUNK_SIZE`, `CHUNK_OVERLAP_SIZE` | Recreate the backend to apply the settings to new managed workflow inputs. Rebuild affected vectors and consider rebuilding graph facts. |
| Dataset embedding provider/model or vector dimension | Create a compatible target and re-ingest before switching consumers. Deployment defaults apply to new datasets. |
| `QDRANT_DISTANCE` / collection distance metric | Use a collection configured for the intended metric and reindex |
| `INGEST_BATCH_SIZE` | Recreate the backend to apply the setting to new managed workflow inputs. Existing vectors remain valid. |

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

