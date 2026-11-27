# Ingestion with Graph Processing Enabled

When graph processing is enabled for a URL or managed document, the indexer
extracts entities and relationships from the document content and stores them
as facts in the dataset's Neo4j graph. This adds a graph representation of the
documents alongside their searchable chunk vectors in Qdrant.

Graph processing runs inside the `ingest_markdown_files` activity of
`IngestSourceWorkflow`. During normal ingestion, the indexer writes the chunk
vectors to Qdrant, then extracts and stores graph facts with references to their
source documents. Graph retrieval reads these stored facts from Neo4j.

## Ingestion with and without graph processing

Both normal ingestion modes create searchable chunk vectors. Enabling graph
processing adds a second step that builds relationships between entities found
in the documents.

| Ingestion mode | What the indexer does | Stored result |
|---|---|---|
| Graph processing disabled (`graph=false`) | Split documents into chunks → generate embeddings → write points to Qdrant | Chunk text, vectors, and source metadata for passage retrieval |
| Graph processing enabled (`graph=true`) | Perform the same vector indexing → extract entities and relationships → write facts to Neo4j | Qdrant chunk points plus a document-linked graph for relationship retrieval |

For example, a document might say, "Course A requires Course B." Both modes store
that passage with its embedding in Qdrant. With graph processing enabled, the
extractor can also store the relationship `Course A → requires → Course B` in
Neo4j, linked to the source document.

Graph processing adds model calls and Neo4j writes to the ingestion work. Vector
indexing finishes first, followed by graph extraction and storage. The
[Scope and commits](#scope-and-update-order) section explains how failures in that
second step affect the stored data and ingestion status.

## When graph processing runs

For a new managed document or URL task, the request's graph option defaults to
`false`. To enable graph enrichment on a managed document, send a top-level
`graph` field in the JSON body for inline text or a `graph` form field with
the file upload. The following examples show only the graph fields.

```json
{"graph": true}
```

For a URL task, put the option inside `metadata`.

```json
{"metadata": {"graph": true}}
```

Omitting `graph` on a managed replacement keeps the document's previous
setting. An explicit `true` requires a graph scope on the dataset. FastAPI
records the selection in the workflow input. The Python API derives these
defaults from the request and document record independently of the legacy
`RAG_INGEST_GRAPH` entry in the root environment template.

A converted document marked `converter_fallback=raganything_passthrough` can
also enable graph processing for its batch. The historical `IngestTextWorkflow`
uses vector indexing.

For websites and uploaded files, the incremental planner skips graph extraction
for documents whose content hash is unchanged. Enabling graph processing for
previously indexed content therefore requires a planned graph rebuild so those
documents are processed again. Existing graph facts remain stored when later
ingestion runs with graph processing disabled. See
[Identity & Incremental Ingestion](./identity_incremental.md).

The internal `graph_only=true, graph=true` option is a maintenance mode: it
extracts graph facts from the supplied documents while preserving existing
vectors. It processes documents independently of the unchanged-content check.
Its cleanup behavior and invocation requirements are described under
[Graph repair and preview](#scope-and-update-order).

## Sources, documents, chunks, and facts

| Term | Meaning in this workflow |
|---|---|
| Source | The website or uploaded file submitted for ingestion; a website crawl can produce many documents |
| Document | One indexed Markdown artifact, usually representing a web page or converted file, identified by `doc_id` |
| Chunk | A passage split from a document; several chunks can share the same `doc_id` |
| Graph fact | An extracted subject, relationship, and object, linked to the document that supplied it |

For graph extraction, the indexer groups chunks by `doc_id`, applies the configured
chunk and character limits, and submits the selected text in one extraction call
for that document. The extraction libraries can split the text internally; the
indexer records the result or failure for the document as a whole.

## Extraction layers

```mermaid
flowchart TB
    Docs["Group chunks by document<br/>bounded text and optional images"] --> Outer["RAG-Anything<br/>document / multimodal integration"]
    Outer --> Inner["LightRAG<br/>entity and relationship extraction"]
    Inner --> Adapter["HAWKI adapter<br/>export, normalize, source-filter, deduplicate"]
    Adapter --> Canonical["Scoped Neo4j facts"]
    Outer -. "no usable triplets" .-> Fallback["Direct model-provider extraction fallback"]
    Fallback --> Adapter
```

| Layer | Responsibility |
|---|---|
| RAG-Anything | Coordinates text, supported converter image metadata, and model callbacks |
| LightRAG | Embedded extraction and intermediate graph representation |
| HAWKI-RAG adapter | Exports edges, normalizes subject/relation/object tuples, filters against source text, removes duplicates |
| Neo4j writer | Persists canonical facts with document provenance and trusted dataset/namespace |

## System configuration and setup

FastAPI records the effective `graph` option for each ingestion. Recreate the
indexer after changing the worker environment settings below.

The indexer reads the amount of document text to use for graph extraction from
its worker environment:

| Setting | Value in `.env.example` | Behavior |
|---|---|---|
| `GRAPH_DOC_MAX_CHUNKS` | `6` | Select the first N chunks of each document; `0` selects all available chunks |
| `GRAPH_DOC_MAX_CHARS` | `6000` | Limit the total characters across the selected chunks of each document; `0` keeps all selected text |

The indexer applies the chunk limit first, then the character limit. With the
template values, it passes up to 6,000 characters from the first six chunks of
each document to graph extraction. That character budget applies to the combined
selected text for one document. When a setting is unset, the indexer uses `0`.

New limits and model selections apply to future extraction. Rebuild affected graph
data when existing facts should reflect those changes. See
[Runtime options and system configuration](../../Operations/5_environment_db_queue.md#runtime-options-and-system-configuration)
for when settings take effect, and
[graph model configuration](../../Operations/5_environment_db_queue.md#graph-extraction)
for model selection.

<details>
<summary>Intermediate storage, cache, and model fallback</summary>

The RAG-Anything adapter configures LightRAG and its model callbacks.
Intermediate extraction can use Neo4j when credentials are available or
fallback storage otherwise. Intermediate nodes/cache files are not the canonical
dataset graph.

The adapter can clear extraction cache per document and run direct model-provider
fallback extraction if the library path yields no usable triplets. Filtering can
leave zero facts. Some adapter errors also produce an empty result, as described
under [Empty results and extraction failures](#when-graph-extraction-returns-no-facts).
Cache lifecycle and temporary graph cleanup are internal details.

</details>

## Scope and update order

Graph-enabled indexing requires a trusted dataset ID and Neo4j namespace.
The indexer checks existing chunks against that scope before writing vectors.
Neo4j writes use the same dataset ID and namespace. A namespace identifies a
logical scope within the configured Neo4j database.

The indexer writes to Qdrant **before** extracting facts and updating Neo4j.
For changed documents, it keeps the existing graph data while extracting the
new facts. If extraction fails or times out, the indexer records the failure
and preserves the old graph data. A graph failure on a source/path artifact
also prevents whole-document completion.

If extraction finishes successfully, the system replaces the old graph data with the new result. This also happens when extraction succeeds but returns no graph facts. If the later Neo4j delete or write step fails, the graph update can be left incomplete and the indexing operation fails.

## When graph extraction returns no facts

Graph extraction can produce zero facts for two different reasons:

- **No facts were found:** extraction completed successfully, so any previous
  graph facts for the changed document are removed.
- **Extraction failed:** the failure is recorded and the previous graph facts
  are normally preserved.

In some cases, an adapter may handle an extraction error internally and return
an empty result. The indexer then treats it as a successful extraction with zero
facts, which can remove the document's previous graph data.

:::warning Check graph results

A source can still reach `ready` even when some documents have no graph facts or
experienced extraction problems. Check `graph_failures`, the logs for the
document's `doc_id`, and the stored Neo4j data when investigating these cases.

:::
