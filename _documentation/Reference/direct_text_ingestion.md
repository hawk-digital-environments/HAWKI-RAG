# Direct Text Ingestion

Use `POST /api/integrations/text-ingestions` to index supplied text or Markdown
without a crawler or file converter. Laravel stores immutable artifacts and
starts `IngestTextWorkflow`; the indexer writes Qdrant and reports readiness.
This path always sets graph ingestion to false.

See [Authorization & Dataset Scope](../Core%20Concepts/authorization_dataset_scope.md)
for the token, dataset-grant, and trusted storage boundaries.

## Prepare a dataset and token

Complete [Installation](../Getting%20Started/4_installation_zero_to_up.md) first.
You need Laravel, the bridge, Temporal, workflow/indexer workers, shared storage,
Qdrant, and the dataset's embedding runtime.

For a local smoke test, create dataset metadata through the management API:

:::note Dataset creation is a management operation

The following command uses the locally protected management surface. Dataset
creation has its own [security boundary](../Core%20Concepts/authorization_dataset_scope.md#access-boundaries);
the ingestion token does not establish a management authorization policy.

:::

```bash
curl --fail-with-body http://localhost:8080/api/datasets \
  -H 'Content-Type: application/json' \
  -d '{"dataset_id":"docs-smoke","name":"Documentation smoke test"}'
```

Dataset creation selects its embedding provider/model from current Settings.
It does not prove its collection contains indexed content. Compatible creation
can be replayed; conflicting existing metadata returns 409.

Use the active user ID printed during installation. Grant that user ingest
access to this dataset, and create a token carrying the literal
`rag:text-ingest` ability. Include `query` for the retrieval smoke test:

```bash
docker exec -it hawki_rag_app php artisan dataset:grant-ingest docs-smoke YOUR_USER_ID
docker exec -it hawki_rag_app php artisan user:token --abilities=rag:text-ingest,query
```

Replace `YOUR_USER_ID` before executing. Select that same user at the token
command's prompt. A browser session, a wildcard-only token, or a query-only
token cannot authorize direct text ingestion. The query-all-datasets setting
does not grant ingestion. If query-all is disabled, also use
`dataset:grant-query docs-smoke YOUR_USER_ID`.

Read the token into a shell variable without putting its value in command history:

```bash
read -r -s HAWKI_TOKEN
export HAWKI_TOKEN
```

Paste it at the silent prompt and press Enter. Keep it in this terminal for the
following commands.

## Submit text

```bash
curl --fail-with-body http://localhost:8080/api/integrations/text-ingestions \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer $HAWKI_TOKEN" \
  -H 'Idempotency-Key: docs-smoke-v1' \
  -d '{
    "external_document_id": "cobalt-lighthouse",
    "dataset_id": "docs-smoke",
    "text": "# Cobalt lighthouse\n\nThe cobalt lighthouse code is CL-731.",
    "content_format": "markdown",
    "display_name": "Documentation smoke test",
    "metadata": {"purpose": "docs-smoke"}
  }'
```

An initial successful response is HTTP **202 Accepted**:

```json
{
  "task_id": "task_text_<hash>",
  "job_id": "ingest_<hash>",
  "source_id": "source_<hash>",
  "workflow_id": "ingest-text-<hash>",
  "status": "running",
  "replayed": false
}
```

`202 Accepted` means the request has been accepted for asynchronous processing.
It does not mean the source is ready for retrieval or guarantee a worker has
already started indexing. Keep all four identifiers.

## Request contract

Unknown top-level fields are rejected.

| Field | Required | Constraint |
|---|---|---|
| `external_document_id` | Yes | Up to 191 characters; alphanumeric first, then letters/digits/`._:-` |
| `dataset_id` | Yes | Up to 160 characters with the same identifier syntax; active and explicitly writable by this user |
| `text` | Yes | Nonblank string, maximum 1,048,576 characters |
| `content_format` | Yes | `plain_text` or `markdown` |
| `display_name` | No | Maximum 255 characters |
| `source_url` | No | HTTP(S) URL, maximum 2048 characters; descriptive, not the source identity |
| `metadata` | No | JSON object, maximum 65,536 serialized bytes; validator also accepts null or an empty array; caller data cannot override trusted storage/model scope |

The complete request body limit is 4,300,000 bytes. `Idempotency-Key` is required,
maximum 191 characters, using the same restricted identifier syntax.
Do not supply graph, collection, namespace, provider, or model controls.

## Wait for readiness and query

Substitute the returned task ID:

```bash
curl --fail-with-body http://localhost:8080/api/pipeline/tasks/TASK_ID \
  -H 'Accept: application/json'
```

Poll at a modest interval until `task.status` becomes `completed` or `failed`.
A successful direct-text run has completed job state and
`index_status: "ready"` on its job/source, with a populated `ready_at`.
These are Laravel projections from worker callbacks. If they lag storage, use
[Recovery](../Operations/ingestion_recovery.md), not repeated new submissions.

After readiness, run the [REST query example](./rest_apis.md#query-request).
The returned evidence should include the cobalt lighthouse sentence.
`generate:false` tests retrieval without requiring answer generation.

## Identity, replay, and retries

The logical source is keyed by dataset plus `external_document_id`. An
idempotency key identifies a submission within the dataset. Keep the same key
and identical body when retrying a transport failure or an uncertain response.

| Situation | Behavior |
|---|---|
| First accepted submission | 202; asynchronous workflow identifiers |
| Same key, identical request | 200 with `replayed:true` and current status |
| Same key, different request | 409 idempotency conflict |
| Another active submission for the same logical source | 409 source busy |
| Changed content after the earlier operation finishes | Submit a new idempotency key with the same external document ID |
| Workflow start is uncertain | 502; retry the identical submission to reconcile its stable workflow ID |
| Completed/ready submission replay | Return recorded state; do not start another completed execution |

Active or failed executions use stable workflow identity during reconciliation;
completed workflow IDs are not restarted. Artifact/metadata reconciliation may
leave a marker after a failed database transaction so that a retry can recover
safely without deleting artifacts referenced by concurrent work.

Direct-text incremental checks verify a completion marker, expected point set,
and fingerprint. They can repair incomplete vector state; metadata-only changes
can refresh payloads without embedding again. See
[Identity & Incremental Ingestion](../Core%20Concepts/Ingestion/identity_incremental.md)
and [Chunking & Embeddings](../Core%20Concepts/Ingestion/chunking_embeddings.md).

## Delete a source

Deletion requires the same token ability and an explicit ingestion grant for
the source's dataset. Replace `SOURCE_ID` with the returned `source_...` value:

```bash
curl --fail-with-body -X DELETE \
  http://localhost:8080/api/integrations/text-ingestions/SOURCE_ID \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer $HAWKI_TOKEN"
```

The service stops/waits for in-flight work, removes that source's Qdrant points
and artifacts, and retains its audit record as `deleted`. It returns 200 with
`source_id`, `dataset_id`, `status:"deleted"`, `deleted:true`, and
`replayed`. Repeated deletion is supported. A workflow whose start cannot yet
be confirmed may produce a 409 busy result; deletion failures use 502.
A deleted source can be ingested again with a new idempotency key.
Replaying the old ingestion key returns recorded state and does not resurrect
the deleted source.

## Error guide

| Status | Typical cause |
|---|---|
| 401 / 403 | Missing/ineligible token, ability, user, or dataset ingest grant |
| 404 | Dataset/source not found, where applicable |
| 409 | Idempotency conflict, active source, or deletion/start reconciliation conflict |
| 413 | Complete request body exceeds the middleware limit |
| 422 | Invalid fields, metadata, format, or key |
| 500 | Unhandled artifact storage/database/recovery failure |
| 502 | Workflow start cannot be confirmed, or deletion fails |
| 429 | Operation throttle |

<details>
<summary>Implementation references</summary>

Implementation: [request validation](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/app/Http/Requests/Integration/IngestTextRequest.php),
[service](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/app/Services/TextIngestion/TextIngestionService.php),
[deletion](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/app/Services/TextIngestion/TextIngestionDeletionService.php),
[workflow](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/services/hawki_workflow_worker/src/hawki_workflow_worker/workflows/ingest_text.py),
[API tests](https://github.com/hawk-digital-environments/HAWKI-RAG/tree/main/tests/Feature/Integration),
[live test](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/tests/end_to_end/integration/test_direct_text_ingestion.py).

</details>
