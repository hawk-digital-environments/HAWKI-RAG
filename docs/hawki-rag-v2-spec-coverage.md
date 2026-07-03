# HAWKI RAG v2 Spec Coverage Audit

This document maps the March 2026 draft architecture/API specification to the
current RAWKI repository.

It is intentionally direct:

- `Exists`: the repo already implements the concept in a recognizably matching way
- `Partial`: the repo implements part of the concept, but the boundary or contract differs
- `Missing`: the concept is not present in the current repo
- `Different`: the repo has a different model instead of the one from the draft
- `Repo-only`: the repo contains product surfaces that go beyond the draft

## Current Repo Profile

The current repo is not a full implementation of the March 2026 draft.

It is currently closer to this product shape:

- Laravel gateway plus Python RAG bridge
- `Dataset` and `Document` storage model, not `Application -> Heap -> Corpus`
- first-class V2 domain layer for `Tenant`, `Application`, `Heap`, `Corpus`, and `Group`
- tenant-scoped `InternalUser` identity bridging for OIDC, local users, and V2 group members
- Sanctum and OIDC protected internal APIs in `routes/internal_api.php`
- OpenCompat compatibility endpoints for ingest, retrieval, folders, documents, models, and system actions
- Pipeline-orchestrated ingestion and recovery surfaces
- Qdrant vector retrieval plus Neo4j graph surfaces
- Optional authorization via OIDC, LMS-neutral connectors, and SpiceDB or OpenFGA
- Retrieval-time authorization filtering still happens in Python

Relevant source files:

- `routes/internal_api.php`
- `app/Models/Dataset.php`
- `app/Models/Document.php`
- `docs/authorization.md`
- `docs/hawki-rag-svelte-ui.md`

## Terminology Mapping

| Draft spec term | Current repo concept | Status | Notes |
| --- | --- | --- | --- |
| Tenant | `App\\Models\\SpecV2\\Tenant` | Exists | First-class table and `/api/tenants` endpoints now exist. |
| Application (App) | `App\\Models\\SpecV2\\Application` | Partial | First-class records and permission flags now exist, but request auth is still Sanctum/OIDC rather than bearer-app resolution. |
| Heap | dataset-backed `App\\Models\\SpecV2\\Heap` | Partial | First-class `/api/heaps` surface exists, but the runtime storage boundary is still the `datasets` table. |
| Document | `Document` | Exists | Present in `app/Models/Document.php`. |
| Corpus | `App\\Models\\SpecV2\\Corpus` | Partial | Corpus records, `reference_count`, and `documents.corpus_id` now exist; full lifecycle replacement of current document storage is not complete yet. |
| Chunk | Qdrant point payloads | Partial | Chunks exist operationally in Python/Qdrant, but not as a Laravel-side data model. |
| Group | `App\\Models\\SpecV2\\Group` | Partial | Group records and member rosters now exist, but they are not yet the active authorization primitive for retrieval enforcement. |
| Metadata | `metadata_json` on documents plus payload fields | Partial | Present on documents, but not separated into heap and document metadata as in the draft. |
| Filter language | flat dict payload filters | Different | Python currently builds Qdrant match filters from plain key/value dicts. |

## Top-Level Coverage

| Draft section | Status | Repo reality |
| --- | --- | --- |
| 1. Design Philosophy | Partial | Optional auth and Laravel/Python split exist, but app-level multi-tenancy and gateway-only auth filtering do not. |
| 2. Terminology | Partial | `Document` and chunked retrieval exist; `Tenant`, `Application`, `Heap`, `Corpus`, and `Group` do not exist as specified. |
| 3. Architecture Overview | Partial | Laravel gateway, Python backend, relational DB, Qdrant, and optional SpiceDB exist. Python still knows auth context and performs retrieval-time auth checks. |
| 4. Tenancy, Permissions & Authentication | Partial | Sanctum and OIDC auth still drive requests, but tenant/application records and auth identity mapping now exist in Laravel. |
| 5. User Identity Model | Partial | Tenant-scoped internal user UUIDs now exist, but the live permission graph path still uses provider plus external user ID semantics. |
| 6. Data Model | Different | Repo uses `Dataset` + `Document` + pipeline records, not the spec's `Tenant` + `Application` + `Heap` + `Corpus` + `Chunk` model. |
| 7. Metadata & Denormalization | Partial | Document metadata and Qdrant payload filtering exist, but not the full heap/document merge, system fields, and reserved-keyword contract. |
| 8. Authorization Model | Partial | Optional auth exists, but it is LMS-neutral course/document authorization rather than heap/group/direct-grant authorization. |
| 9. Filter Language | Missing / Different | No nested `AND`/`OR` filter grammar. Current Python filter path is flat key/value matching. |
| 10. API Reference - Core | Different | Repo exposes datasets, documents, OpenCompat ingest/retrieve, and `/api/query`, not the draft heap/search API. |
| 11. API Reference - Authorization | Missing | No `/api/auth/*` CRUD endpoints are implemented in routes. |
| 12. Workflows | Partial | Search, ingestion, bridge, and optional auth exist, but the specific heap/protection/corpus workflows do not. |
| 13. Integration Patterns | Partial | OpenCompat, scrapers, uploads, and LMS-neutral connectors exist, but not the exact adapter contract from the draft. |
| 14. Design Decisions | Partial | Some motivations match the codebase, but several draft decisions describe a future architecture rather than current code. |
| 15. Future Considerations | Mixed | A few are already present in other forms; many remain unimplemented. |

## Section-by-Section Notes

## 1. Design Philosophy

Status: `Partial`

What exists:

- Laravel and Python are separated: `routes/internal_api.php`, `app/Services/Rag/RagProxyService.php`, `python_rag/api/http/routers/query.py`
- Authorization is optional: `docs/authorization.md`
- Qdrant is used as the search backend and Neo4j is used for graph surfaces

What differs:

- The draft says business logic and filter construction stay in consuming applications or the gateway. Current repo still passes `auth_context` into Python and filters there.
- The draft says application-level multi-tenancy is core. Current repo does not implement tenant/application scoping primitives.

## 2. Terminology

Status: `Partial`

What exists:

- `Document`: `app/Models/Document.php`
- chunked vector retrieval: `python_rag/infrastructure/vectorstore/*`

What is different or missing:

- `Heap` now exists as a dataset-backed spec model: `app/Models/SpecV2/Heap.php`
- `Corpus` and `reference_count` now exist, but the current runtime still transitions through legacy document flows
- `Group` now exists as a first-class catalog + membership concept, but not yet as the live retrieval authorization primitive

## 3. Architecture Overview

Status: `Partial`

What exists:

- Laravel internal API gateway: `routes/internal_api.php`
- Sanctum and OIDC middleware: `auth:sanctum,oidc` in `routes/internal_api.php`
- Python query backend: `python_rag/api/http/routers/query.py`
- Relational DB plus Qdrant: database migrations and Python vector store code
- Optional SpiceDB/OpenFGA adapters: `docs/authorization.md`, `app/Services/Authorization/PermissionGraph/*`

What differs:

- The draft expects the gateway to forward `{ query, filters, limit }` only.
- Current repo still sends `auth_context` to Python, and Python still applies authorization filtering: `python_rag/application/workflows/authorization_filter.py`

## 4. Tenancy, Permissions & Authentication

Status: `Missing / Different`

What exists:

- Request authentication by Sanctum and OIDC
- User-facing auth identity resolution: `app/Services/Authorization/Oidc/*`

What is missing:

- current request auth still resolves users rather than bearer applications
- application permission flags exist in the V2 model, but are not yet the live request-scoping mechanism
- no gateway-side scope computation based on application permissions

## 5. User Identity Model

Status: `Partial`

What exists:

- identity persistence: `app/Models/AuthorizationIdentity.php`
- identity repository: `app/Services/Authorization/Repositories/AuthorizationIdentityRepository.php`
- OIDC resolution and upsert: `app/Services/Authorization/Oidc/OidcUserResolver.php`

What differs from the draft:

- tenant-scoped `internal_users` and auth identity mappings now exist in Laravel
- current graph checks still use `provider + external_user_id` semantics, as documented in `docs/authorization.md`
- the internal UUID bridge is present, but it is not yet the live graph subject for retrieval checks

## 6. Data Model

Status: `Different`

What exists:

- `Dataset`: grouping plus Qdrant/Neo4j namespaces
- `Document`: file/source metadata, checksum, status, and dataset link
- `AuthorizationIdentity`: auth identity mapping
- `AuthorizationPermissionEvent`: permission sync event persistence
- pipeline task/job/stage models for ingestion orchestration

Evidence:

- `app/Models/Dataset.php`
- `app/Models/Document.php`
- `database/migrations/2026_06_05_000000_create_datasets_table.php`
- `database/migrations/2026_04_22_140000_create_documents_table.php`

What is missing:

- V2 `Tenant`, `Application`, `Heap`, and `Corpus` now exist as first-class Laravel models
- explicit `Chunk` relational model

Important difference:

- the repo does have a dedup-like constraint on `documents(collection, checksum_sha256)`, but that is not the same as the draft's global corpus model with shared vectors and reference counting

## 7. Metadata & Denormalization

Status: `Partial`

What exists:

- document metadata JSON: `app/Models/Document.php`
- Qdrant payload filters and payload-based search: `python_rag/infrastructure/vectorstore/payloads.py`

What is missing or different:

- heap metadata now exists on the dataset-backed heap model via `datasets.metadata_json`
- no explicit heap metadata plus document metadata merge contract
- no implemented reserved-keyword blocking for the draft metadata vocabulary
- no current `visibility` and `protected` payload contract matching the spec
- no documented async propagation job for dataset metadata updates comparable to the draft heap propagation model

## 8. Authorization Model

Status: `Partial`

What exists:

- optional authorization architecture: `docs/authorization.md`
- permission graph abstraction: `app/Services/Authorization/Contracts/PermissionGraphClient.php`
- permission sync event recording: `app/Services/Authorization/PermissionSyncService.php`
- fail-closed retrieval behavior when auth is enabled and context is missing: `python_rag/application/workflows/authorization_filter.py`
- direct document gating in Laravel: `app/Services/Authorization/AuthorizationService.php`

What differs:

- the draft is heap and group based
- current repo still enforces authorization through the LMS-neutral course/document relationship path
- group and heap domain objects now exist, but heap grants and direct document grant CRUD are still alignment work

## 9. Filter Language

Status: `Missing / Different`

The draft defines nested filter expressions with `AND` and `OR`.

Current repo behavior:

- Python search filters are plain dicts
- Qdrant request construction turns each key/value pair into a `must` match clause

Evidence:

- `python_rag/api/http/schemas.py`
- `python_rag/infrastructure/vectorstore/payloads.py`

This is one of the clearest contract mismatches with the draft.

## 10. API Reference - Core

Status: `Different`

What exists:

- `/api/query`
- `/api/ingest/*`
- `/api/retrieve/*`
- `/api/datasets/*`
- `/api/documents/*`
- `/api/folders/*` as an OpenCompat mapping layer

Evidence:

- `routes/internal_api.php`
- `app/Http/Controllers/API/OpenCompat/*`
- `app/Http/Controllers/DatasetController.php`
- `app/Http/Controllers/DocumentBrowserController.php`

What is missing relative to the draft:

- `/api/heaps`
- `/api/heaps/{heap_id}/documents`
- `/api/search`
- draft-style heap visibility/protection lifecycle

## 11. API Reference - Authorization

Status: `Missing`

No `/api/auth/*` route group matching the draft was found in the repo.

Missing surfaces include:

- heap grant CRUD
- document direct-grant CRUD
- group CRUD
- group membership CRUD
- authorization utility endpoints like `/api/auth/check`

The repo has authorization services and tests, but not the draft authorization API surface.

## 12. Workflows

Status: `Partial`

What exists:

- query flow through Laravel to Python bridge
- file and text ingestion through OpenCompat plus pipeline services
- optional authorization checks during retrieval and document access

What differs:

- no heap-first protection lifecycle
- no corpus reference-count flow
- no gateway-only merged authorization filter workflow

## 13. Integration Patterns

Status: `Partial`

What exists:

- OpenCompat compatibility API
- scraper-oriented and upload-oriented ingest flows
- LMS-neutral connector interfaces
- static and scaffold connector implementations

Evidence:

- `app/Services/OpenCompat/*`
- `app/Services/Authorization/Contracts/LmsPermissionConnector.php`
- `app/Services/Authorization/Connectors/*`

What differs:

- the draft frames adapters around tenant/app/heap auth flows
- current repo frames integrations around datasets, documents, pipeline uploads, and optional course/document permission sync

## 14. Design Decisions

Status: `Partial`

These draft decisions already match the repo reasonably well:

- optional authorization module
- separate permission graph adapter contract
- fail-closed authorization bias
- Qdrant as denormalized retrieval storage

These do not match current repo reality yet:

- all filtering logic in gateway
- no user identity in search layer
- heap-level authorization as default
- additive application permission flags
- corpus deduplication via shared corpus records

## 15. Future Considerations

Status: `Mixed`

Already present in some form:

- multi-modal / graph-heavy retrieval surfaces via RAGAnything and Neo4j tooling
- admin or operator UI surfaces: `docs/hawki-rag-svelte-ui.md`

Still missing:

- tenant/application registration model
- `/api/auth/*` administration surface
- nested groups
- draft filter-language `NOT`
- heap collaborator model

## Repo-Only Surfaces Beyond The Draft

These are important because they define what the repo already is beyond the draft architecture.

### 1. Dataset-Centered Product Surface

The repo is organized around datasets, not heaps:

- dataset CRUD and cleanup: `app/Http/Controllers/DatasetController.php`
- dataset namespaces for Qdrant and Neo4j: `app/Models/Dataset.php`

### 2. Pipeline-Orchestrated Ingestion And Recovery

The repo contains substantial pipeline ownership that the draft does not define:

- pipeline tasks, jobs, stages, retry, cancel, and recovery
- upload and converter flows
- health and repair commands and dashboards

Evidence:

- `routes/internal_api.php`
- `app/Services/Pipeline/*`
- `docs/hawki-rag-svelte-ui.md`

### 3. Neo4j Graph Retrieval And Operator UI

The draft focuses on Qdrant search and optional SpiceDB. The repo also includes:

- Neo4j graph search and expansion endpoints
- graph explorer UI
- dataset and document graph stats

Evidence:

- `routes/internal_api.php`
- `docs/hawki-rag-svelte-ui.md`

### 4. OpenCompat Compatibility Layer

The repo exposes a compatibility surface beyond the draft's clean heap API.

Examples:

- ingest compatibility endpoints
- retrieve compatibility endpoints
- folder compatibility endpoints
- model and API key compatibility endpoints

Important limitation:

- folders are only mapped to datasets for a limited subset of behavior
- hierarchy, move, summaries, and document membership mutation are explicitly unsupported

Evidence:

- `app/Http/Controllers/API/OpenCompat/FolderController.php`
- `app/Services/OpenCompat/OpenCompatService.php`

### 5. Current Authorization Model Is LMS-Neutral, Not Heap/Group-Based

The repo's auth model already goes beyond the draft in one direction and falls short in another:

- beyond the draft: connector abstraction for LMS membership and document relation sync
- short of the draft: no heap/group/public authorization API surface

Evidence:

- `docs/authorization.md`
- `app/Services/Authorization/PermissionSyncService.php`

## If You Want Strict Alignment With The Draft

These are the largest missing architectural steps:

1. Introduce first-class `Tenant` and `Application` models plus app permission flags.
2. Replace `Dataset`-centered ownership semantics with draft-style `Heap` semantics, or explicitly redefine `Dataset` as the official heap equivalent.
3. Add a real `Corpus` lifecycle with shared content records and reference counting.
4. Move authorization filtering fully into Laravel so Python receives only query plus merged filters.
5. Implement the draft filter language instead of the current flat dict filter contract.
6. Add the `/api/auth/*` API surface if the draft authorization model is the real target.
7. Decide whether OpenCompat stays a compatibility shim or becomes part of the official product contract.

## Bottom Line

The draft is not fully present in the repo today.

The repo already implements:

- Laravel plus Python split
- Sanctum and OIDC protected APIs
- dataset and document storage
- Qdrant retrieval
- Neo4j graph surfaces
- optional SpiceDB or OpenFGA-backed authorization
- pipeline orchestration

The repo does not yet implement:

- full tenant/application permission scoping in request auth
- full heap/corpus/group authorization enforcement
- `/api/auth/*` authorization CRUD API
- draft filter grammar
- gateway-only authorization filtering

That difference should be treated as an explicit architecture gap, not as a documentation detail.
