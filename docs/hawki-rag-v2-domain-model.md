# HAWKI RAG v2 Domain Layer

This repo now includes a first-class V2 terminology layer without replacing the
current RAWKI runtime shape.

## What Exists Now

- `Tenant`: relational model and `/api/tenants` endpoints
- `Application`: relational model and `/api/applications` endpoints
- `Heap`: first-class spec-facing model backed by the existing `datasets` table,
  available under `/api/heaps`
- `Corpus`: relational dedup model and `/api/corpora` endpoints
- `Group`: relational model plus member roster endpoints under `/api/groups`
- `InternalUser` identity bridge: tenant-scoped internal UUIDs linked to OIDC
  identities, local users, and V2 group members

## Important Boundary

This is intentionally a **domain-model alignment step**, not a full
authorization migration.

Current repo behavior that remains unchanged:

- Laravel and Python still use the existing RAWKI auth bridge
- resolved auth identities now also map into V2 `tenant`, `application`, and
  `internal_user` records
- retrieval-time authorization still exists in Python
- Sanctum and OIDC remain the active request-auth mechanisms
- LMS-neutral permission sync remains the active graph-writing path

## Storage Mapping

### Heap

`Heap` is implemented as a dataset-backed model:

- table: `datasets`
- spec-facing model: `App\Models\SpecV2\Heap`
- API: `/api/heaps`

Added dataset fields:

- `tenant_id`
- `owner_application_id`
- `visibility`
- `protected`
- `metadata_json`
- `updated_at`

### Corpus

`Corpus` is stored in its own table:

- table: `corpora`
- key: `id` = checksum/content hash
- document link: `documents.corpus_id`
- `reference_count` is backfilled from existing documents

Pipeline ingestion now syncs corpora for newly ingested documents.

### Group

`Group` is stored separately and currently supports:

- group catalog records
- tenant + owner application linkage
- member roster persistence via `group_members`
- internal user mapping via `group_members.internal_user_id`

What is not yet implemented:

- heap-to-group authorization grants
- document direct grants through the V2 group layer
- gateway-side filter construction from V2 group membership

## Why This Shape

The repo already had a strong `Dataset` + `Document` runtime. Replacing that in
one step would create a high-risk partial migration. The safer approach is:

1. make the V2 nouns real in the relational model and API surface
2. map `Heap` cleanly onto the current dataset boundary
3. introduce `Corpus` as the new dedup/content concept
4. keep current auth enforcement stable until the gateway-side migration is done

## API Surfaces Added

- `GET|POST /api/tenants`
- `GET /api/tenants/{tenant_id}`
- `GET|POST /api/applications`
- `GET /api/applications/{application_id}`
- `GET|POST /api/heaps`
- `GET|PATCH|DELETE /api/heaps/{heap_id}`
- `GET /api/corpora`
- `GET /api/corpora/{corpus_id}`
- `GET|POST /api/groups`
- `GET|DELETE /api/groups/{group_id}`
- `GET|PUT|PATCH /api/groups/{group_id}/users`

## Compatibility

Existing dataset/document payloads now also expose the new names:

- dataset payloads include `heapId`, `tenantId`, `ownerApp`, `visibility`,
  `protected`, and `metadata`
- document payloads include `heapId` and `corpusId`
