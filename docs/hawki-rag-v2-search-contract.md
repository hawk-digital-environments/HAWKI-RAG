# HAWKI RAG V2 Search Contract

This file is the canonical search contract for the active V2 branch.

## Application-Facing Request

The application-facing Laravel search endpoints are:

- `POST /api/search`
- `POST /api/search/chunks`
- `POST /api/search/chunks/grouped`

Accepted request fields:

- `query`: required string
- `limit`: optional integer
- `filters`: optional canonical filter expression
- `user_identifier`: optional string used only by Laravel when authorization is enabled

Accepted limit aliases:

- `top_k`
- `k`

Laravel normalizes those aliases into `limit` before any bridge call.

## Canonical Filter Expression

The canonical filter language is structural and backend-agnostic.

Leaf field match:

```json
{ "heap": "heap-design" }
```

Leaf arrays mean logical OR across values:

```json
{ "document_id": ["doc-1", "doc-2"] }
```

Boolean groups:

```json
{ "AND": [{ "owner_app": "hawki-web" }, { "visibility": "discoverable" }] }
```

```json
{ "OR": [{ "heap": "heap-a" }, { "heap": "heap-b" }] }
```

```json
{ "NOT": { "protected": true } }
```

Reserved system fields are:

- `heap`
- `document_id`
- `owner_app`
- `visibility`
- `protected`

## Laravel To Python Bridge Request

Laravel is the only layer that resolves actor scope, application permissions,
and grant-based narrowing.

The Python bridge request contains exactly:

```json
{
  "query": "campus policy",
  "limit": 5,
  "filters": {
    "AND": [
      { "owner_app": "hawki-web" },
      { "protected": false }
    ]
  }
}
```

Python must not receive:

- `user_identifier`
- tenant identifiers
- application identifiers
- internal user ids
- permission or auth context blobs

Python translates the canonical filter expression into Qdrant-native filters
internally.

## Search Response

`POST /api/search` returns the retrieval response from Python:

```json
{
  "ok": true,
  "count": 1,
  "hits": [
    {
      "id": "chunk-1",
      "score": 0.92,
      "payload": {
        "document_id": "doc-1",
        "content": "Chunk content"
      }
    }
  ],
  "kg": [],
  "answer": "",
  "retrieval": {
    "iterative_pass": false
  }
}
```

## Chunk Response

`POST /api/search/chunks` returns:

```json
{
  "count": 1,
  "chunks": [
    {
      "id": "chunk-1",
      "document_id": "doc-1",
      "content": "Chunk content",
      "score": 0.92,
      "metadata": {}
    }
  ]
}
```

## Grouped Chunk Response

`POST /api/search/chunks/grouped` returns:

```json
{
  "count": 1,
  "groups": [
    {
      "document_id": "doc-1",
      "chunks": [
        {
          "id": "chunk-1",
          "document_id": "doc-1",
          "content": "Chunk content",
          "score": 0.92,
          "metadata": {}
        }
      ]
    }
  ]
}
```
