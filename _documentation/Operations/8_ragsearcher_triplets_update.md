# 8. RagSearcher Triplets Update (Interface and Runtime Specification)

## Scope

This specification describes triplet-aware retrieval behavior across:

- `app/Mcp/Tools/HawkiRagSearchTool.php`
- `app/Services/RagSearch/RagSearcher.php`
- `python_rag/application/workflows/query_logic.py`

## System-level behavior

The query path is:

1. MCP tool validates user input and sends normalized parameters to `RagSearcher`.
2. `RagSearcher` sends `/query` request to Python RAG.
3. Python retrieval performs semantic + structural retrieval, then rerank.
4. Relation hits (`component_type = relation`) are preserved through filtering.
5. Laravel returns a normalized response object with `results`, `kg`, and `rewrite_terms`.

## Interface specification

### MCP input validation (`HawkiRagSearchTool`)

- `query`: required string.
- `top_k`: optional integer in `[1, 50]`.
- Missing `top_k` defaults to `5`.

## Output contract

### Response shape

`RagSearcher::filterResponse()` always returns this top-level structure:

| Key | Type | Purpose |
|---|---|---|
| `results` | array | Ranked result items (semantic chunks and/or relation hits). |
| `kg` | array | Normalized relation triples for graph-aware consumers. |
| `rewrite_terms` | array | Query rewrite/entity terms derived by backend logic. |

```json
{
  "results": [],
  "kg": [],
  "rewrite_terms": []
}
```

### `results[]`

Each item can represent a semantic chunk or a graph relation.

| Semantic-oriented fields | Relation-oriented fields | Shared/typing fields |
|---|---|---|
| `metadata.language` | `subject` | `component_type` |
| `metadata.title` | `relation` | `content` |
| `metadata.url` | `object` | `metadata.collection` |
| `metadata.timestamp` |  | `metadata.tags` |

!!! note "Interpretation rule"
    `component_type = relation` indicates a relation/triplet-oriented hit.

### `kg[]` and `rewrite_terms[]`

Both fields are optional metadata arrays returned with `results[]`.

| Field | Structure | Purpose | Empty state |
|---|---|---|---|
| `kg[]` | triple items: `subject`, `relation`, `object` | Graph relation facts | `kg: []` when no valid relation facts exist |
| `rewrite_terms[]` | string items | Query expansion/debug metadata | `rewrite_terms: []` when no terms are produced |

## Contract delta summary

| Dimension | Only Qdrant | Qdrant + Neo4j |
| --- | --- | --- |
| Retrieval mode default | `fast_mode=true` | `fast_mode=false` |
| Graph lookup default | `smart_lookup=false` | `smart_lookup=true` |
| Structural depth from client | forced to `0` | omitted unless explicitly set |
| Allowed hit types | chunk-only | chunk + relation |
| Top-level response keys | `results` | `results`, `kg`, `rewrite_terms` |
| Relation fields in `results` | not exposed | exposed (`component_type`, `subject`, `relation`, `object`) |

## Reference response example

```json
{
  "results": [
    {
      "metadata": {
        "language": "en",
        "title": "Example Page",
        "url": "https://example.org",
        "timestamp": "2026-03-11T12:00:00Z",
        "tags": "graph,neo4j",
        "collection": "hawki"
      },
      "content": "Some chunk text..."
    },
    {
      "metadata": {
        "collection": "hawki"
      },
      "component_type": "relation",
      "subject": "Entity A",
      "relation": "connected_to",
      "object": "Entity B"
    }
  ],
  "kg": [
    {
      "subject": "Entity A",
      "relation": "connected_to",
      "object": "Entity B"
    }
  ],
  "rewrite_terms": [
    "entity a",
    "entity b"
  ]
}
```
