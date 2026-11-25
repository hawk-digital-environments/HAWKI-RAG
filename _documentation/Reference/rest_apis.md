# REST APIs

The service API is available at `http://rag_backend/v1` from the private Docker network.
Read the machine-readable contract at `/v1/openapi.json`. Send the caller's
issuer-provided service JWT as an `Authorization: Bearer` header on
protected routes. Workers authenticate internal callbacks with separate signatures.
Service-authenticated operational routes use their documented scopes.

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

The optional `metadata_filters` field selects chunks by public source metadata. The existing `filters` field remains a flat equality map for top-level payload attributes such as `source_format`. You can supply both. Each adds a required condition. Protected names and storage paths are rejected.

```json
{
  "dataset_id": "design",
  "query": "interaction design",
  "generate": false,
  "metadata_filters": {
    "and": [
      {"field": "course", "op": "eq", "value": "design"},
      {"field": "semester", "op": "eq", "value": "ws2025"},
      {"field": "year", "op": "gte", "value": 2025}
    ]
  }
}
```

A matching response includes ranked evidence and its validated source metadata. This excerpt shows one hit and selected diagnostics.

```json
{
  "ok": true,
  "count": 1,
  "hits": [{
    "id": "7146e239-cebb-4088-8b2d-dfbb98dd8dad",
    "score": 0.81,
    "payload": {"content": "Interaction design focuses on how people interact with digital products."},
    "metadata": {"course": "design", "semester": "ws2025", "language": "en", "year": 2025}
  }],
  "kg": [],
  "answer": "",
  "retrieval": {"dataset_id": "design", "graph_enabled": false, "graph_disabled_reason": "metadata_filters"}
}
```

### Predicates and groups

| Operator | Comparison value | Match |
| --- | --- | --- |
| `eq` | One scalar | Exact, case-sensitive equality. On a stored string list, any element may equal the supplied string. |
| `neq` | One scalar | The key exists and none of its values equal the scalar. |
| `in`, `not_in` | A nonempty array of up to 32 scalars | At least one stored value equals one supplied value, or for `not_in`, the key exists and no values match. Each operand retains its own type. |
| `gt`, `gte`, `lt`, `lte` | One number | Numeric comparison. Only numeric fields can match. |
| `exists` | Optional boolean, default `true` | Selects a present key with `true` and an absent key with `false`. An empty list and an empty string are present. |

Scalars are strings, signed 64-bit integers, finite binary64 numbers and booleans. Integral numbers in the signed 64-bit range normalize to integers at ingestion and query time. The string `"2025"` and the integer `2025` select different values. Boolean `true` is distinct from the number `1`. Whitespace and case in strings are significant. String lists retain their stored order in a hit. Filtering compares individual elements. Whole-list equality, nested values, null operands and coercion are unsupported.

A missing key fails every comparison, including `neq` and `not_in`. An empty list matches `exists`, `neq` and `not_in`, since it is present and has no matching elements. `and` requires every child, `or` requires at least one child and `not` complements its single child within the authorized dataset. Thus `not` of an equality also matches a missing key.

This query includes sources tagged `ux` or `design`, as well as sources that omit the key.

```json
{
  "dataset_id": "design",
  "query": "course material",
  "metadata_filters": {
    "or": [
      {"field": "tags", "op": "in", "value": ["ux", "design"]},
      {"not": {"field": "tags", "op": "exists"}}
    ]
  }
}
```

### Limits, scope and retrieval

Field names use the ingestion key policy. They are case-sensitive and must match `[A-Za-z][A-Za-z0-9_]{0,63}`. Tenant, dataset, credentials, storage routing, writer, workflow and ownership names are reserved. Use the plain public key in each predicate. Strings have a 4,096-character limit and must contain valid, NUL-free Unicode. The filter allows six levels including the root, 32 total nodes including groups, 32 operands per membership predicate, and 65,536 bytes of normalized compact ASCII-escaped JSON. Invalid expressions receive HTTP 422.

The service authenticates the JWT and checks `rag:query` and the exact grant for the active dataset in the JWT tenant. A denied or unknown dataset returns the same HTTP 404. The server resolves the physical collection and combines its mandatory dataset predicate with caller filters. Semantic search, lexical search, scroll and high-recall retries all use that intersection in Qdrant. Only matching candidates reach ranking and answer generation. An allowed query with no matches returns HTTP 200 with `count: 0`, empty `hits` and `kg`, and an empty answer.

A metadata-filtered query skips both structural graph candidates and related graph facts, including in deep mode. Current Neo4j reads cannot verify each fact against the public source metadata. Qdrant searches select chunk evidence, including older chunks with an unset component type. Direct text supplies the typed metadata on indexed chunks. Managed upload `metadata_json` belongs to the document record and URL task `metadata` belongs to the crawler request. Those adapters have an empty public metadata view for retrieval, so they can match an absence predicate.

The shared contract is `hawki_rag_contracts.retrieval.metadata_filter.MetadataFilter`, accepted by `hawki_rag_contracts.retrieval.query.QueryRequest`. A Python MCP tool can forward the same logical body and service JWT to this endpoint. An in-process adapter must perform the same scope and exact-grant checks, resolve trusted storage and models, then pass the validated request to `hawki_rag_retrieval.execute_authorized_query` and project its result with `sanitize_query_response`. The REST endpoint is the deployed Python query interface.
