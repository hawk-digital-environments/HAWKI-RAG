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
- `user_identifier`: optional opaque external user identifier used only by Laravel when authorization is enabled

Deprecated limit aliases such as `top_k` and `k` are not accepted on the V2
application-facing API. Compatibility clients must translate those names before
calling V2.

## User Identifier Contract

When present, `user_identifier` follows one strict contract:

- it is treated as an opaque string
- matching is exact against `user_identities.external_user_id`
- Laravel does not fall back through `email` or `username`
- uniqueness is scoped by `tenant + provider + external_user_id`
- federated reads union only unambiguous exact matches

Unsupported ambiguity case:

- if the same `external_user_id` exists under more than one provider inside the
  same tenant, that tenant does not contribute access scope for that identifier
  until the ambiguity is resolved

## Canonical Filter Expression

The canonical filter language is structural and backend-agnostic.

Leaf field match:

```json
["heap", "heap-design"]
```

Leaf arrays mean logical OR across values:

```json
["document_id", ["doc-1", "doc-2"]]
```

Boolean groups:

```json
{ "AND": [["owner_app", "hawki-web"], ["visibility", "discoverable"]] }
```

```json
{ "OR": [["heap", "heap-a"], ["heap", "heap-b"]] }
```

```json
{ "NOT": ["protected", true] }
```

Sibling expressions at the root are treated as an implicit `AND`:

```json
[["heap", "heap-design"], ["course", "architecture"]]
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
      ["owner_app", "hawki-web"],
      ["protected", false]
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

`POST /api/search` returns a Laravel-owned V2 response shape. Python bridge
fields such as `ok`, `hits`, `kg`, `answer`, and `retrieval` are internal and
must not leak through this public route.

```json
{
  "query": "campus policy",
  "count": 1,
  "results": [
    {
      "id": "chunk-1",
      "document_id": "doc-1",
      "score": 0.92,
      "content": "Chunk content",
      "metadata": {
        "document_id": "doc-1"
      }
    }
  ]
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
