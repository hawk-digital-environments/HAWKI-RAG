# 6. Ingestion & Embeddings

<div className="hero">

This page explains the data contracts and failure boundaries inside ingestion:
how a document keeps its identity, when work is skipped, when vectors are
replaced, and how to recover when graph enrichment is incomplete.

[See the complete source flow](../Getting%20Started/3_introduction_architecture.md)
· [Configure models and storage](./5_environment_db_queue.md)

</div>

:::info Where this page begins

The architecture guide already follows a website or upload through Laravel,
Temporal, the crawler, the converter, and shared storage. This page begins when
normalized Markdown reaches the FastAPI ingestion endpoint. Startup, monitoring,
and maintenance commands remain in
[Run HAWKI RAG](../Getting%20Started/2_setup.md).

:::

## The handoff into ingestion

Temporal carries source references, storage paths, and configuration—not the
Markdown body, chunks, or vectors—in workflow input. The ingestion activity
recursively discovers sorted `.md` and `.markdown` files under the source's
shared-storage path, skips blank files, and sends the remaining documents to
FastAPI in batches. A source with no usable Markdown fails before indexing.

:::warning Shared storage is the implemented read path

The current Temporal storage adapter reads local/shared Docker paths. It rejects
`s3://` listing and reading, so object-storage ingestion must not be enabled
until an object-storage adapter is implemented.

:::

## The ingestion commit pipeline

An ingestion request is not one indivisible database transaction. Preparation,
vector indexing, graph enrichment, and progress projection have distinct commit
points.

```mermaid
flowchart TD
    Request["Normalized documents and trusted dataset scope"]
    Prepare["Validate metadata<br/>clean text<br/>assign stable identity"]
    Hash["Calculate content hash<br/>split into overlapping chunks"]
    Compare{"Existing identity<br/>and content hash?"}
    Unchanged["Unchanged<br/>mark as seen"]
    Embed["Create one embedding<br/>per chunk"]
    VectorCheck{"Any embeddings<br/>succeeded?"}
    Failed["Fail the ingestion request"]
    Qdrant["Replace or insert<br/>Qdrant points"]
    GraphSwitch{"Graph enrichment<br/>enabled?"}
    Extract["Extract and filter<br/>document facts"]
    Neo4j["Replace or insert<br/>Neo4j facts"]
    Complete["Update ingestion registry<br/>write summary and status"]

    Request --> Prepare --> Hash --> Compare
    Compare -- "same hash" --> Unchanged --> Complete
    Compare -- "new or changed" --> Embed --> VectorCheck
    VectorCheck -- "none" --> Failed
    VectorCheck -- "some or all" --> Qdrant --> GraphSwitch
    GraphSwitch -- "no" --> Complete
    GraphSwitch -- "yes" --> Extract --> Neo4j --> Complete
```

The request carries four contracts:

| Contract | Required information | Why it matters |
|---|---|---|
| **Document** | Stable ID, text, and metadata payload | Connects every chunk and later refresh to the same source document. |
| **Vector** | Qdrant collection, embedding provider/model, distance metric, and chunk settings | Keeps indexed vectors compatible with future query vectors. |
| **Graph** | Dataset ID and Neo4j namespace when graph writes are enabled | Prevents extracted facts from crossing dataset boundaries. |
| **Operation** | Request/job identity and an idempotency key | Correlates retries, writes, logs, and artifacts. The HTTP `Idempotency-Key` header takes precedence over a key in the request body. |

The idempotency key is propagated to storage adapters, but the endpoint does
not keep a cached-response ledger. Retry safety also depends on stable
identities and deterministic upserts.

## Stable identity and incremental ingestion

Two values answer different questions:

- **Source identity** answers “is this the same page or uploaded document?”
- **Content hash** answers “does its normalized text still have the same
  contents?”

For web content, URL identity is normalized without discarding the query string;
fragments are removed and scheme/host casing is normalized. When a crawler URL
represents several files, the relative path participates in the identity. An
upload keeps the source-scoped stable document ID created by the Temporal
activity. The content hash is SHA-256 over the cleaned document text.

The pipeline first checks the PostgreSQL `ingested_pages` registry and falls
back to Qdrant metadata when necessary.

| Detected state | Vector action | Graph action | Registry action |
|---|---|---|---|
| **New identity** | Embed and insert its chunks. | Extract and insert facts when enabled. | Record the completed identity and hash. |
| **Same identity, same hash** | Skip embedding and Qdrant writes. | Skip graph extraction during ordinary ingestion. | Update the last-seen state. |
| **Same identity, different hash** | Delete prior document points, then insert the new chunks. | Extract first, then replace facts for that document. | Store the new hash after the commit phases finish. |
| **No longer submitted by the source** | No automatic action. | No automatic action. | The existing record remains until an explicit reconciliation or deletion. |
| **Graph-only request** | Skip vector indexing and incremental filtering. | Re-extract and upsert facts for submitted documents. | Do not update the page registry. |

:::note Why an unchanged retry may not repair the graph

Ordinary incremental ingestion returns early when the content hash is unchanged.
If Qdrant is correct but Neo4j is incomplete, use the explicit graph repair or
graph-only path with graph enrichment enabled; resubmitting the same ordinary
ingestion request is expected to skip it.

:::

## Chunk and vector contracts

HAWKI RAG currently uses **character-based chunks**, not token-based chunks.
With the defaults, a chunk targets 1,200 characters and overlaps the next chunk
by 250 characters. The splitter prefers a paragraph boundary when a suitable
one occurs after 60% of the target window.

Every Qdrant point contains:

- the chunk text and zero-based chunk index;
- the stable document ID and source metadata;
- the content hash, component type, and derived tags; and
- a deterministic UUID generated from document ID plus chunk index.

This gives retries the same point identities as long as document identity and
chunk boundaries remain unchanged.

:::warning Embedding compatibility is a dataset invariant

Query vectors must use the same embedding space as indexed vectors. Changing
the embedding provider, model, or vector dimension requires intentional
re-ingestion into a compatible collection. A model with the same marketing
name is not automatically compatible if its output dimension or behavior
differs.

:::

### What “batch size” actually controls

`INGEST_BATCH_SIZE` defaults to 64. The Temporal activity uses it to group
Markdown files sent to FastAPI, and the bridge uses it for Qdrant upsert
batches. The current embedding loop still calls the provider once per chunk;
raising this value does not turn embedding into a provider-side batch request.

Embedding failures are isolated per chunk:

- if some chunks fail, successful chunks are still written and the summary
  reports a partial embedding result;
- a document for which every chunk fails is counted as skipped; and
- if every prepared chunk in the request fails, the endpoint fails without
  performing the Qdrant upsert.

## Vector and graph commit boundaries

Qdrant is committed before optional graph processing. The two stores can
therefore diverge; Neo4j enrichment is not part of the same atomic transaction
as the vector write.

| Boundary | Current behavior | Operational consequence |
|---|---|---|
| **Changed Qdrant document** | Old points are deleted before new points are upserted. | A failed upsert can temporarily leave that document absent. Retry or re-ingest it. |
| **Graph scope validation** | Conflicting document scope is rejected before vector writing. | Treat dataset ID and Neo4j namespace as trusted request fields, not document-controlled metadata. |
| **Changed Neo4j document** | New facts are extracted first; old facts are then deleted before the new upsert. | Extraction failure preserves the old version, but a later write failure can leave a graph gap. |
| **Per-document graph failure** | The failure is recorded and other documents continue. | Vectors can be current while graph facts are incomplete; repair the graph explicitly. |
| **Status projection** | Registry and Laravel-facing metadata updates are separate PostgreSQL writes. | A status panel can lag behind successfully written data; correlate using the operation/job ID before replaying work. |

Graph extraction groups chunks back into documents before calling the graph
engine. By default, it considers at most the first 6 chunks and 6,000
characters per document. Images referenced by supported converter metadata can
also be supplied to the multimodal extractor.

The graph adapter filters extracted triplets against the source text, normalizes
them, and writes them under the trusted dataset scope. Both dataset ID and
Neo4j namespace are required for canonical writes. A conflicting payload scope
causes a client error before vectors are committed; a missing trusted scope
disables the canonical graph write.

:::warning A ready result can still be partial

Successful completion means the request reached its finalization path. It does
not prove that every chunk embedded or that every document produced graph
facts. Check the partial-failure counts and graph-failure evidence before
treating the two stores as synchronized.

:::

The architectural roles of RAG-Anything, LightRAG, and the HAWKI RAG adapter are
explained once in
[Introduction & Architecture](../Getting%20Started/3_introduction_architecture.md#why-both-rag-anything-and-lightrag-exist).

## Failure diagnosis and recovery

Start with the last completed boundary rather than restarting the whole stack.

| Symptom | Likely completed boundary | Recommended action |
|---|---|---|
| No valid Markdown or every document fails validation | Nothing indexed | Correct the converted content or required document fields, then rerun ingestion. |
| Every embedding call fails | Preparation completed; Qdrant was not written | Check provider reachability, model availability, and the embedding contract. Retry after correction. |
| Only some embedding chunks fail | Partial Qdrant write may exist | Inspect failed document/chunk identifiers and re-ingest the affected source. |
| Qdrant fails while replacing a changed document | Old document points may already be deleted | Retry the same source with the same dataset scope. |
| Graph failure is reported after vectors succeed | Qdrant is current; Neo4j may be partial | Repair with graph enrichment enabled; use graph-only mode when vectors must remain untouched. |
| UI status appears stale but storage contains the new data | Data commit may be complete; metadata projection failed or lagged | Correlate the operation ID across the ingestion summary, worker logs, PostgreSQL, Qdrant, and Neo4j before replaying. |

Temporal retries protect the stages before this endpoint as well. In
particular, the scraper activity heartbeats the external crawler job ID so an
activity retry can resume polling that job instead of submitting a duplicate
crawl.

<details>
<summary>How the retry layers differ</summary>

Temporal currently retries each workflow activity up to five times with
exponential backoff. The ingestion activity has a separate HTTP retry loop for
calls to FastAPI. A manual retry after cancellation is different: Laravel
starts a new workflow execution with a new workflow ID; it does not resume the
cancelled execution.

Because a vector commit can precede a hard graph failure, the new attempt may
see an unchanged content hash and skip ordinary graph ingestion. Use the
targeted graph path when Qdrant is current but Neo4j needs repair.

</details>

## Change-impact matrix

Use this table before changing ingestion settings on a populated dataset.

| Change | Affects existing vectors? | Affects existing graph facts? | Required follow-up |
|---|---:|---:|---|
| `CHUNK_SIZE` or `CHUNK_OVERLAP_SIZE` | Yes | Yes, because document evidence boundaries change. | Re-ingest the dataset; rebuild graph facts if graph mode is used. |
| Embedding provider or embedding model | Yes | Not directly. | Re-ingest into a compatible vector collection. |
| `QDRANT_DISTANCE` | Yes | No. | Use a collection configured for the new metric and re-index vectors. |
| `INGEST_BATCH_SIZE` | No | No. | Recreate affected services; no data rebuild is required. |
| `GRAPH_DOC_MAX_CHUNKS` or `GRAPH_DOC_MAX_CHARS` | No | Yes. | Rebuild graph facts to apply the new extraction window. |
| Chat or vision model used for graph extraction | No | Potentially. | Rebuild graph facts only when existing facts must reflect the new model. |
| Tag derivation or metadata normalization logic | Payload and lexical behavior | Potentially. | Re-ingest documents whose stored payload must change. |

### Safe embedding migration

Editing an environment default is not a migration for an existing dataset.
Treat the dataset's provider, embedding model, vector dimension, and collection
as one persisted contract:

1. create a new target dataset or collection with the intended contract;
2. re-ingest the source content into that target;
3. validate document/chunk counts, vector dimension, and representative queries;
4. switch consumers to the validated target; and
5. retire the old target only after the cutover is confirmed.

This avoids mixing incompatible vectors or leaving a populated collection in a
half-reindexed state.

## Advanced request modes

<details>
<summary>Preview ingestion without writing canonical data</summary>

Set `dry_run` to validate documents, normalize metadata, calculate chunks, and
return planned point/batch statistics without writing Qdrant or canonical
Neo4j data.

When graph mode and `dry_include_graph` are both enabled, the preview also calls
the configured model to extract graph facts and writes preview/failure
artifacts. It is therefore write-safe for canonical stores, but it is not a
zero-cost or provider-free check.

</details>

<details>
<summary>Use graph-only mode for a targeted graph refresh</summary>

Set both `graph_only` and `graph` to true. This bypasses vector embedding,
Qdrant writes, incremental filtering, and page-registry updates while running
the graph path over the submitted documents.

Keep the same trusted dataset ID and Neo4j namespace used by the dataset.
This mode upserts newly extracted facts but, because it bypasses the incremental
replacement plan, it does not by itself guarantee removal of stale facts.
Use it to restore missing facts; use an explicit graph cleanup and rebuild when
exact replacement is required. It is not a substitute for initial vector
ingestion.

</details>

## Evidence left by an ingestion run

Use the operation ID, job ID, document ID, and chunk index as the correlation
chain. The service emits structured stage events for preparation, incremental
decisions, embedding, vector indexing, graph extraction, and completion.

| Evidence | Default location or owner | What it answers |
|---|---|---|
| **Ingestion summary** | `/shared/public/ingest_summary.json` | How many documents, chunks, points, skips, replacements, and partial failures were observed? |
| **Graph preview** | `/shared/public/ingest_graph_preview.json` | Which graph records were produced during the latest graph-enabled run? |
| **Graph failure log** | `/shared/storage/logs/ingest_graph_failures.jsonl` | Which document-level graph extractions failed, and why? |
| **Page registry** | PostgreSQL `ingested_pages` | Which source identity and content hash are considered completed? |
| **Vector evidence** | Dataset Qdrant collection | Which deterministic chunk points are currently searchable? |
| **Graph evidence** | Dataset-scoped Neo4j namespace | Which normalized entities and relations are currently available? |

The authoritative system depends on the question:

| Question | Authority |
|---|---|
| Did the workflow run, retry, fail, or get cancelled? | **Temporal workflow history** |
| What status is displayed to the operator? | **Laravel's PostgreSQL projection** |
| What text is actually retrievable? | **Qdrant** |
| What graph facts actually exist? | **Neo4j** |

The public artifact paths can be overridden by Compose settings, so use the
configured paths when the deployment differs from the defaults.
