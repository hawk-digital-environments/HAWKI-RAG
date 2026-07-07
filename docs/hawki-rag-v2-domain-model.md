# HAWKI RAG V2 Domain Layer

This repo treats the V2 terminology as the canonical product language.

## Canonical domain terms

- `Tenant`: organization boundary for applications and users.
- `Application`: bearer-authenticated API consumer inside a tenant.
- `Heap`: primary container for documents and the default authorization boundary.
- `Document`: logical record inside a heap.
- `Corpus`: deduplicated content unit shared by documents.
- `Chunk`: internal vector segment of a corpus.
- `Group`: named set of users for access assignment.
- `Metadata`: key-value attributes attached to heaps and documents.
- `Filter`: structured search constraint evaluated before retrieval.

## Current implementation shape

- `Tenant`, `Application`, `Heap`, `Corpus`, and `Group` all have first-class API surfaces.
- `Document` payloads expose `heapId` and `corpusId`.
- Search requests use gateway-built `filters` and forward search inputs to Python.
- Authorization identity resolution is tenant-aware and application-aware.

## Boundary that still matters

This is a terminology and surface-alignment step, not a full storage rewrite.

- Laravel remains the gateway for request authentication and search scoping.
- Python remains the search executor.
- Authorization data still flows through the existing permission-graph path.
- Internal storage and pipeline code may still contain compatibility-era symbols, but product-facing surfaces should speak only in V2 terms.

## Canonical API surfaces

- `GET|POST /api/tenants`
- `GET /api/tenants/{tenant_id}`
- `GET|POST /api/applications`
- `GET /api/applications/{application_id}`
- `GET|POST /api/heaps`
- `GET|PATCH|DELETE /api/heaps/{heap_id}`
- `GET /api/corpora`
- `GET /api/corpora/{corpus_id}`
- `GET|POST /api/auth/groups`
- `GET|DELETE /api/auth/groups/{group_id}`
- `GET|PUT|PATCH /api/auth/groups/{group_id}/users`
- `POST /api/search`
- `POST /api/search/chunks`
- `POST /api/search/chunks/grouped`
