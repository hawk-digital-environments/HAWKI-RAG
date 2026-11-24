# Normalized text ingestion

RAWKI accepts normalized text and source metadata, indexes the text, retrieves and reranks chunks, and manages the indexed source through deletion. An upstream integration can acquire a file, page or database record and extract its text before submission. Managed file and URL ingestion remain available as compatibility adapters.

## Submit a source

Use `POST /v1/text-ingestions` with a registered service JWT, the `rag:ingest` scope, an exact ingest grant on the active dataset, and a stable `Idempotency-Key` header. The browser gateway uses `/ui-api/v1/text-ingestions`.

```json
{
  "dataset_id": "design",
  "external_document_id": "interaction-design",
  "text": "Interaction design studies how people interact with digital products.",
  "content_format": "plain_text",
  "metadata": {
    "course": "design",
    "semester": "ws2025",
    "language": "en",
    "year": 2025
  }
}
```

The first four fields are required. `content_format` accepts `plain_text` or `markdown`. The optional `display_name` and `source_url` fields describe the source. Omitting `metadata` supplies an empty object. Text must be nonblank and at most 1,048,576 characters. The entire request is limited to 4,300,000 bytes.

### Public metadata

| Property | Contract |
| --- | --- |
| Keys | Up to 32 case-sensitive keys matching `[A-Za-z][A-Za-z0-9_]{0,63}` |
| Scalars | Unicode string, signed 64-bit integer, finite JSON number, or boolean |
| Numeric storage | Integral numbers within the signed 64-bit range use integers. Other accepted numbers use binary64. A quoted number stays a string. |
| Lists | Up to 32 strings, in order |
| Strings | Up to 4,096 Unicode characters per string, including list elements. NUL and isolated surrogates are invalid. |
| Total | Up to 65,536 bytes of compact, ASCII-escaped JSON after numeric normalization |
| Invalid shapes | Null, nested objects, lists of other types, and dotted storage paths receive HTTP 422 |

Tenant, dataset, physical storage, graph scope, ownership, workflow, writer, authorization and credential names are reserved. The shared validator compares reserved names without case or underscores (for example, `datasetId`, `qdrant_collection`, `source_id`, `operation_id`, `generation`, `auth_context`, and `apiKey`). Only the authenticated principal, active dataset, grants and server-selected physical targets participate in access decisions. Submit only descriptive values intended for authorized dataset readers.

## Indexing, identity and deletion

PostgreSQL retains source metadata with the ingest operation. The immutable revision contains UTF-8 Markdown and its metadata sidecar. The frozen Temporal input identifies the revision and carries a SHA-256 digest of its canonical user metadata. The indexer verifies the sidecar and supplies normalized text to the existing `IngestDocument` and chunk pipeline. Every Qdrant point stores a typed `payload.metadata` object alongside its own chunk content, index, and internal provenance. A metadata-only revision can refresh chunk payloads without re-embedding unchanged text.

A new submission returns HTTP 202 and the source, task, job and workflow IDs. Object key order is immaterial. Text, format, dataset, external identity, display fields and metadata participate in the request fingerprint. An identical retry returns HTTP 200. Changing metadata under the same tenant and idempotency key returns HTTP 409. Use a new key for a new revision and retain the dataset and external document ID to keep the same source.

Poll `GET /v1/pipeline/tasks/{task_id}` for completion. Delete through `DELETE /v1/text-ingestions/{source_id}` with an idempotency key and the dataset ingest grant. The service fences and drains writers, verifies removal of the source's Qdrant contributions, and removes its artifacts. It retains durable deletion evidence.

Direct text writes vectors even on graph-capable datasets. Its writer grant covers the source's vector document and its deletion snapshot owns the vector collection and artifact revision. Managed file and URL adapters support optional graph enrichment with their own Neo4j provenance and cleanup.

## Query results

A service with `rag:query` and the dataset query grant can call `POST /v1/query`. Each hit includes its existing ID, score and projected evidence payload, plus a separate `metadata` object. For the example above, `hits[0].payload.content` contains the retrieved chunk and `hits[0].metadata.year` is the integer `2025`. Hit metadata comes only from validated direct-text source metadata. A hit without public source metadata, including a historical point whose metadata fails validation, returns `{}`. Source metadata remains recoverable from the authoritative operation.

Use the optional `metadata_filters` expression to narrow retrieval before reranking. The REST reference defines its operators, types, limits and shared Python contract. [Metadata-aware queries](rest_apis.md#query-request)
