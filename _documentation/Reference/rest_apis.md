# REST APIs

Open interactive Swagger at `/ui-api/v1/docs` (locally,
`http://localhost:8080/ui-api/v1/docs`). Its schema is
`/ui-api/v1/openapi.json`; interactive requests use the UI gateway's server-side
service authentication and browser origin checks. Direct backend clients can
read `/v1/openapi.json`; API operations still require service JWT authorization.
Internal callback routes and UI administration `/v1/ops/*` routes are excluded
from the published schema. The UI administration routes remain callable with
their existing authentication and authorization.

Public service calls use `/v1/*` on FastAPI. Browser calls use `/ui-api/v1/*` through the server-side gateway, which supplies its own service JWT. `/api/*` returns 410; internal callback routes are not published by nginx.

| Family | Current routes / authorization |
| --- | --- |
| Retrieval | `/v1/query`, `/v1/query/datasets`; query scope and exact dataset query grant |
| Datasets | `/v1/datasets`, creation/deletion and self-grants; management/query/ingest scopes according to action |
| Managed documents | `/v1/documents`, batch/create/read/update/delete and upload download; exact ingest/query grants |
| Direct text | `/v1/text-ingestions`; ingest scope and dataset grant |
| Pipeline | `/v1/pipeline/tasks`, start/read/cancel/retire, recovery and stage logs; ingest scope and ownership |
| Graph/ops | `/v1/ops/*`; query or management scopes, dataset scope where applicable |

Mutations use stable idempotency keys and existing generation/writer barriers. Metadata cannot override tenant or physical storage targets. Read readiness and error bodies without logging credentials. The registered routers under `python_backend/src/hawki_backend` are authoritative for request schemas and optional compatibility routes.

See [deployment](../Operations/python_runtime.md), [direct text](direct_text_ingestion.md) and [authorization](../Core%20Concepts/authorization_dataset_scope.md).

## Query request

Submit the authorized `dataset_id` and `query` to `/v1/query`, or use the browser gateway prefix. Query options such as `top_k`, `generate` and `fast_mode` follow the typed FastAPI schema. Storage scope is resolved server-side.
