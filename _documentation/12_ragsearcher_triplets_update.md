# 12. RagSearcher Triplets Update (Interface and Runtime Specification)

## Scope

This specification describes triplet-aware retrieval behavior across:

- `app/Mcp/Tools/HawkiRagSearchTool.php`
- `app/Services/RagSearch/RagSearcher.php`
- `python_rag/pipeline/query_logic.py`

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
- `top_k` is explicitly cast to `int` before calling strict service method `withTopK(int)`.

This guarantees type consistency at the PHP service boundary.

### RAG request payload (`RagSearcher::execute`)

Current payload defaults:

- `fast_mode = false`
- `smart_lookup = true`
- `structural_hops = null` and then removed by `array_filter` before request dispatch

Operational effect:

- graph traversal is not force-disabled by client defaults,
- backend structural depth is controlled by service defaults/environment unless explicitly provided.

## Output contract

`RagSearcher::filterResponse()` returns:

```json
{
  "results": [],
  "kg": [],
  "rewrite_terms": []
}
```

### `results[]`

Each item can represent a semantic chunk or a graph relation.

Possible fields:

- `metadata.language`
- `metadata.title`
- `metadata.url`
- `metadata.timestamp`
- `metadata.tags`
- `metadata.collection`
- `content`
- `component_type`
- `subject`
- `relation`
- `object`

Interpretation rule:

- `component_type = relation` indicates relation/triplet-oriented hit.

### `kg[]`

Top-level relation facts, each requiring all three fields:

- `subject`
- `relation`
- `object`

Incomplete facts are excluded during normalization.

### `rewrite_terms[]`

Derived from backend query rewrite entity terms.
Normalization procedure:

- accept string values only,
- trim empty values,
- deduplicate,
- reindex array.

## Backend retrieval semantics (`query_logic.py`)

### Structural hops precedence

Current precedence:

1. use `body.structural_hops` if present,
2. otherwise use backend default via `structural_hops()`.

### Relation-hit retention rule

Allowed `component_type` values in post-fusion filtering:

- `None`
- `""`
- `"chunk"`
- `"relation"`

This allows graph-derived relation hits to continue into rerank and final output.

## Contract delta summary

| Dimension | Previous behavior | Current behavior |
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

## Verification protocol

1. Execute a graph-oriented query through MCP tool.
2. Confirm `response.results` exists.
3. Confirm top-level `response.kg` exists.
4. Confirm at least one relation item can appear with `component_type = relation`.
5. Confirm `rewrite_terms` contains unique non-empty strings.

## Implementation constraints

- PHP `array_filter` removes null/empty values; field presence in `results` is sparse by design.
- `kg` includes only complete triples.
- `rewrite_terms` can be empty when rewrite yields no entity terms.
