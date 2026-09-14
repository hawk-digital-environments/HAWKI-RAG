# REST APIs

Laravel is the public HTTP boundary. Its canonical JSON routes use `/api`.
The browser uses these same routes. FastAPI is the internal read-only data-plane
bridge, with additional Temporal control operations.

The local Swagger UI is at `http://localhost:8080/swagger`. It loads the
[checked-in OpenAPI contract](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/public/swagger/openapi.yaml); some UI/internal
routes deliberately opt out. [Routes](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/routes/api.php), request validation,
and the owning service remain the source of truth for runtime behavior.

## Public surfaces

| Surface | Representative routes | Boundary |
|---|---|---|
| Dataset retrieval | `GET /api/query/datasets`, `POST /api/query` | Query principal and dataset authorization |
| Direct text | `POST /api/integrations/text-ingestions`, `DELETE /api/integrations/text-ingestions/{sourceId}` | Explicit token ability and dataset ingestion grant |
| Dataset management | `GET/POST /api/datasets`, `GET /api/datasets/{datasetId}` | Management API |
| Documents | `GET/POST /api/documents`, `POST /api/documents/batch`, `GET/PUT/DELETE /api/documents/{documentId}` | Management API |
| Pipeline | `POST /api/pipeline/tasks/start`, `GET /api/pipeline/tasks/{taskId}`, `POST /api/pipeline/controller/files` | Management API |
| Recovery/control | Task cancel/retry and `/api/pipeline/recovery/...` routes | Management API with operation-specific throttles |
| Graph / statistics | `/api/rag/neo4j/...`, `GET /api/rag/stats` | Management API; semantic graph search adds query-principal middleware |
| Worker events | `POST /api/internal/pipeline/worker-events` | Exact-body HMAC |
| Health / monitoring | `/up`, `/api/pipeline/health`, `/api/rag/health`, `/api/rag/monitor` | See [Monitoring](../Operations/monitoring.md) |

The management group has throttling, not blanket Sanctum authentication.
Direct-text and MCP always need their explicit credentials. See
[Authorization & Dataset Scope](../Core%20Concepts/authorization_dataset_scope.md)
before exposing this deployment.

## Query request

`POST /api/query` accepts the following validated fields:

| Field | Meaning |
|---|---|
| `dataset_id` | Required active authorized dataset; up to 191 characters, restricted identifier syntax |
| `query` | Required string, maximum 4000 characters |
| `top_k` | Optional integer 1–100; default 5 |
| `generate` | Optional boolean; also gated by bridge configuration |
| `fast_mode`, `smart_lookup`, `is_optimized` | Optional booleans; see retrieval behavior |
| `preferred_tags` | Up to 20 strings, maximum 80 characters each |
| `filters` | Up to 20 named metadata predicates with scalar values; reserved scope/model keys rejected |

Collection, namespace, provider, models, and `authorized_scope` cannot be
selected through this request. Laravel derives them from dataset authorization
and Settings.

Example with a query-capable token and an already indexed dataset:

```bash
curl --fail-with-body http://localhost:8080/api/query \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $HAWKI_TOKEN" \
  -d '{"dataset_id":"docs-smoke","query":"What is the cobalt lighthouse code?","top_k":5,"generate":false}'
```

Laravel forwards the backend JSON response: typically `hits`, `count`, `kg`,
`retrieval`, `answer`, and operational metadata. It does not apply the
[MCP normalization](./mcp_query_search_contract.md). `top_k` is not a strict
HTTP result-count guarantee: current context selection can retain at least the
configured context-document count. See [Query & Retrieval](../Core%20Concepts/query_retrieval.md).

Input failures normally return 422 at Laravel. Missing/ineligible explicit
credentials are rejected; ambiguous implicit identity returns 503. Downstream
statuses are passed through. Missing Qdrant collections become a
`dataset_not_ready` 503 instead of creating a collection during a query.
Transport/invalid-response failures use the proxy's error path and may include
backend exception details.

## Direct text and pipeline status

The full direct-text request, idempotency, asynchronous status, and deletion
contract is owned by [Direct Text Ingestion](./direct_text_ingestion.md).

For accepted work use `GET /api/pipeline/tasks/{taskId}` and the returned jobs
and sources. `GET /api/pipeline/status/{jobId}` is a UI-oriented status route
excluded from OpenAPI. There is no `GET /api/integrations/text-ingestions/{id}`.

## Internal bridge surface

These routes are internal service contracts, reached through Laravel:

| Method / route | Purpose |
|---|---|
| `GET /health` | Liveness and optional runtime information |
| `POST /query` | Authorized retrieval and optional generation |
| `POST /graph/related` | Scoped graph reads |
| `POST /temporal/workflows/ingest` | Start source ingestion |
| `POST /temporal/workflows/ingest-text` | Start direct-text ingestion |
| `POST /temporal/schedules/ingest` | Create/replace ingestion schedule |
| `POST /temporal/schedules/delete` | Delete schedule |
| `POST /temporal/workflows/cancel` | Cancel workflow |

The bridge has no ingestion write route to Qdrant or Neo4j. Its Temporal start
operations dispatch durable work to activity workers. Its internal trusted
scope is not a client credential; direct public exposure bypasses Laravel's
authorization boundary.

Implementation: [Laravel query validation](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/app/Http/Requests/Rag/QueryDatasetRequest.php),
[proxy service](https://github.com/hawk-digital-environments/HAWKI-RAG/tree/main/app/Services/Rag),
[bridge schemas](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/services/hawki_bridge/src/hawki_bridge/http/schemas.py),
[bridge routers](https://github.com/hawk-digital-environments/HAWKI-RAG/tree/main/python_rag/services/hawki_bridge/src/hawki_bridge/http/routers).
