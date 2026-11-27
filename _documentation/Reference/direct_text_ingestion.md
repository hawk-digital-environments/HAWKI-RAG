# Inline text and file ingestion

HAWKI-RAG accepts inline text and file uploads at `POST /v1/documents`. Both use the managed document workflow. The API stages the upload. The converter worker produces Markdown, and the
indexer writes the chunks to the dataset.

## Submit a source

Use a registered service JWT with the `rag:ingest` scope and an exact ingest grant on the active dataset. Reuse a stable `Idempotency-Key` when retrying the same request. Send files as `multipart/form-data` with `dataset_id` and `file`. Send inline text as `application/json` or as form fields with `text` in place of `file`.

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

`dataset_id` and `text` are required for inline text. `content_format` defaults to `plain_text` and also accepts `markdown`. The optional `external_document_id` supplies the Markdown filename stem. `display_name` and `source_url` describe the source. Omitting `metadata` supplies an empty object. In a form, send `metadata` as a JSON string. Text must be nonblank and at most 1,048,576 characters. The JSON request body is limited to 4,300,000 bytes.

## Submit through local MCP

FastAPI starts MCP at `/mcp` on the same private Docker listener as the REST API.
Both `make up-core` and `make up-core-local` start that listener. The
[local stack](../Operations/python_runtime.md#local-development) supplies a
development token issuer.

```sh
make up-core-local
```

Connect your MCP client from the backend's Docker network using **Streamable HTTP**
at `http://rag_backend/mcp`. Set its HTTP `Authorization` header to
`Bearer <service JWT>`. Use a JWT with `rag:ingest` and an exact ingest grant
for the dataset. Set credentials on the client connection so the tool arguments
can contain the document itself.

Call `submit_document` with the arguments below. Replace `design` with an active
dataset that grants ingest access to the service in your token.

```json
{
  "dataset_id": "design",
  "text": "# Local test\n\nThis Markdown arrived through MCP.",
  "content_format": "markdown",
  "idempotency_key": "local-mcp-text-1"
}
```

In `markdown_file`, set `filename` to a plain `.md` filename and
`content_base64` to the base64-encoded UTF-8 contents. The decoded file limit
is 4 MiB.

```json
{
  "dataset_id": "design",
  "markdown_file": {"filename": "notes.md", "content_base64": "IyBUZXN0Cg=="},
  "idempotency_key": "local-mcp-file-1"
}
```

The tool calls the same Python document service as `POST /v1/documents` and
returns the same receipt, including `pipeline.task_id` when accepted.

## Public metadata

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

The API stages inline text as an immutable UTF-8 Markdown upload. The converter worker publishes that Markdown through the same activity and artifact contract used for files. The indexer reads it and writes validated source metadata to Qdrant alongside the chunks. The managed workflow supports optional graph enrichment through `graph` when the dataset has a graph scope.

A successful submission returns HTTP 202 with `document.document_id` and `pipeline.task_id`, `pipeline.job_id`, and `pipeline.source_id`. Reuse the same idempotency key and content to retrieve the same admission receipt. Changing the content or metadata under the same key returns HTTP 409. Replace a document with `PUT /v1/documents/{document_id}` and a new key. Supply `source_updated_at` or set `force` to `true` to replace it explicitly. Replacement keeps the managed document ID and creates a new source revision.

Poll `GET /v1/pipeline/tasks/{task_id}` for completion. Delete new text and file documents through `DELETE /v1/documents/{document_id}`. The older `DELETE /v1/text-ingestions/{source_id}` route remains available for sources created before the unified endpoint.

## Query results

A service with `rag:query` and the dataset query grant can call `POST /v1/query`. Each hit includes its existing ID, score and projected evidence payload, plus a separate `metadata` object. For the example above, `hits[0].payload.content` contains the retrieved chunk and `hits[0].metadata.year` is the integer `2025`. Hits without validated public source metadata return `{}`.

Use the optional `metadata_filters` expression to narrow retrieval before reranking. The REST reference lists the operators, operand types, and limits. [Metadata-aware queries](rest_apis.md#query-request)
