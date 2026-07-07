# HAWKI RAG V2 Architecture And API Summary

This file consolidates the current Markdown documentation in this repository into one operational summary. It is meant to answer three questions:

- What is the current HAWKI RAG V2 architecture?
- What is connected to what at runtime?
- Which APIs and tests should be used to validate the system?

Current source-of-truth docs:

- `docs/internal-architecture-contract.md`
- `docs/hawki-rag-v2-search-contract.md`
- `docs/authorization.md`
- `public/swagger/openapi.yaml`
- `routes/internal_api/*.php`

Historical context docs:

- `v2_refactor.md`
- `v2_spec_implementation_checklist.md`
- `refactored_code.md`
- `refactor_code_python.md`

Some historical checklist entries may describe work that has already been implemented or intentionally superseded. When docs disagree, prefer the internal architecture contract, search contract, Swagger, route files, and executable tests.

## Product Boundary

HAWKI RAG V2 is a backend data and retrieval service. It stores documents, manages heaps, syncs corpus/chunk payloads, applies metadata filters, resolves application read scope, optionally checks authorization, and returns search results.

Business logic, UI behavior, LMS-specific behavior, scraper-specific behavior, and platform-specific adapter code should stay outside the core V2 product boundary unless explicitly retained as backend integration infrastructure.

The canonical public terminology is:

- Tenant
- Application
- Heap
- Document
- Corpus
- Chunk
- Group
- Metadata
- Filter
- Adapter

Legacy names such as `dataset` are allowed only behind explicit storage adapters. Public APIs, resources, Swagger, tests, and V2 services should speak heap/document/corpus terminology.

## Runtime Architecture

```text
Application client
  |
  | Bearer application token
  v
Laravel API gateway
  |
  |-- V2 domain APIs: tenants, applications, heaps, documents, corpora
  |-- V2 auth APIs: groups, heap grants, document grants, access checks
  |-- V2 search APIs: search, chunks, grouped chunks
  |-- App ingestion API: pipeline file upload
  |
  | writes domain state
  v
PostgreSQL
  |
  | schedules ingestion/conversion
  v
Temporal workflows and workers
  |
  | process files and text
  v
Python RAG services
  |
  | write/read vectors and graph data
  v
Qdrant + Neo4j

Operator client
  |
  | human auth: Sanctum/OIDC
  v
Laravel operator APIs
  |
  | pipeline task management, recovery, stats, graph exploration
  v
Temporal + PostgreSQL + Qdrant + Neo4j
```

## Component Responsibilities

### Laravel

Laravel owns the product boundary.

Responsibilities:

- Authenticate application clients with bearer application tokens.
- Keep human/OIDC auth on operator-only surfaces.
- Resolve tenant and application actor context.
- Enforce application permissions and read scope.
- Validate metadata and reserved filter keys.
- Own V2 domain writes for heaps, documents, corpora, groups, and grants.
- Build the canonical search filter that represents application scope, metadata filters, and optional authorization.
- Forward only retrieval inputs to Python.
- Shape public API responses through V2 resources.
- Orchestrate ingestion and pipeline workflows.

Important code areas:

- `routes/internal_api/spec_v2.php`
- `routes/internal_api/app_search.php`
- `routes/internal_api/app_ingestion.php`
- `routes/internal_api/operator.php`
- `app/Http/Controllers/SpecV2`
- `app/Http/Controllers/API/HawkiRagProxyController.php`
- `app/Services/SpecV2`
- `app/Services/Authorization`
- `app/Services/Rag`
- `app/Services/Pipeline`

### Python RAG

Python is the retrieval and processing engine, not the authorization owner.

Responsibilities:

- Execute retrieval against Qdrant.
- Run ingestion, chunking, embedding, reranking, graph extraction, and RAGAnything-related processing.
- Return retrieval results to Laravel.

Search requests from Laravel to Python must contain only:

```json
{
  "query": "string",
  "limit": 10,
  "filters": {}
}
```

Python must not receive:

- `auth_context`
- `user_identifier`
- tenant IDs
- application IDs
- internal user IDs
- permission names
- grant state

Important code areas:

- `python_rag/application/workflows/query_execution.py`
- `python_rag/infrastructure/raganything`
- `python_rag/infrastructure/vectorstores`
- `python_rag/tests`

### PostgreSQL

PostgreSQL stores the Laravel domain state and pipeline state.

Main responsibilities:

- Tenants and applications.
- Application tokens and permissions.
- Heaps and documents.
- Corpus references.
- User identities.
- Groups and grant assignments.
- Pipeline task/job/event state.
- Temporal persistence, when using the local stack.

The public V2 term is heap. Any internal `dataset` storage is a legacy adapter detail and must not leak into public payloads or route names.

### Qdrant

Qdrant stores chunk vectors and denormalized search payload fields.

Laravel write paths are responsible for making sure Qdrant payload fields are rebuilt when these change:

- document create
- document update
- document move
- heap metadata update
- heap protection/grant state change
- corpus reassignment

Search-time joins should be avoided. Heap metadata and system scope fields are denormalized into the Qdrant payload at write/sync time.

### Neo4j

Neo4j stores graph exploration data produced by retained graph/RAGAnything backend capabilities.

Operator graph APIs expose graph overview, search, semantic search, node lookup, expansion, snapshots, and clear operations. These are operator surfaces, not general app-facing V2 document APIs.

### Temporal And Workers

Temporal coordinates ingestion/conversion pipelines.

Core queues from the docs:

- `rag-workflow-task-queue`
- `rag-converter-task-queue`
- `rag-ingestion-task-queue`

Typical flow:

```text
POST /api/pipeline/files
  -> Laravel validates app actor and upload
  -> file enters shared storage
  -> Laravel records pipeline task state
  -> Temporal workflow starts
  -> converter worker extracts/normalizes content
  -> ingestion worker chunks/embeds content
  -> Python writes vectors/graph output
  -> Laravel/PostgreSQL task state is updated
```

### Optional Authorization Backend

The V2 runtime source of truth is the Laravel-native authorization model: applications, user identities, groups, heap grants, and document grants.

External graph backends such as SpiceDB/OpenFGA are optional infrastructure for adapter/event projection. They are not the place where Python should resolve access scope.

When authorization is disabled with `AUTHZ_ENABLED=false`, the current repo contract is no-op mode:

- Auth fields are accepted.
- Grant endpoints are accepted.
- Permission sync inputs are accepted.
- No authorization side effects are required.
- Search and read behavior stays application-scope based.

## Authentication Boundaries

Application-facing APIs use:

```text
auth:application-token
```

Operator-only APIs use:

```text
auth:sanctum,oidc
```

Application auth and human auth must stay separate. Application clients act as registered API consumers inside tenants. Human/OIDC auth is only for operator surfaces such as pipeline task dashboards, recovery operations, graph exploration, and infrastructure stats.

## Application Permission Model

Application read behavior is driven by a single policy layer.

Permissions:

- `reads`: application can read its own visible data.
- `reads-all-apps`: application can read data owned by other applications in the same tenant.
- `reads-federated`: application can union supported federated identity access where identity resolution is unambiguous.
- `reads-protected`: application can read protected resources when grants allow it.

Expected error behavior:

- Return `403` when a resource exists but the current actor is outside its allowed scope.
- Return `404` when the resource does not exist.

Important services:

- `app/Services/Authorization/ApplicationReadPolicy.php`
- `app/Services/Authorization/ApplicationScopeResolver.php`
- `app/Services/SpecV2/AuthorizationGrantService.php`

## Identity And Grants

The canonical user identifier is opaque and exact-match based.

Current contract:

- `user_identifier` maps to `user_identities.external_user_id`.
- Lookup is tenant-scoped.
- Provider is part of uniqueness.
- No fallback to email or username should be used in V2 grant semantics.
- Federated reads must only union access in explicitly supported, unambiguous cases.

Heap grants:

- May grant direct users.
- May grant groups.
- Drive heap protection state.

Document grants:

- Grant direct users.
- Do not grant groups unless the architecture contract is changed.

Groups:

- Are managed under `/api/auth/groups`.
- Are named sets of users for efficient heap access.

## Search Contract

Canonical app-facing search endpoints:

- `POST /api/search`
- `POST /api/search/chunks`
- `POST /api/search/chunks/grouped`

Canonical search request:

```json
{
  "query": "privacy policy",
  "limit": 10,
  "filters": ["AND", [["department", "law"], ["year", 2026]]],
  "user_identifier": "opaque-external-user-id"
}
```

Public request rules:

- Use `limit`, not `top_k` or `k`.
- Use `filters` for metadata filtering.
- Use `user_identifier` only when protected access should be resolved for a user.
- Use snake_case public fields.

Filter grammar:

```json
["field", "value"]
```

```json
["field", ["value1", "value2"]]
```

```json
["AND", [["field", "value"], ["other", "value"]]]
```

```json
["OR", [["field", "value"], ["other", "value"]]]
```

```json
["NOT", ["field", "value"]]
```

Reserved filter/metadata fields:

- `heap`
- `document_id`
- `owner_app`
- `visibility`
- `protected`

Laravel computes the merged search scope and forwards only this canonical retrieval payload to Python:

```json
{
  "query": "privacy policy",
  "limit": 10,
  "filters": {
    "must": []
  }
}
```

Canonical `/api/search` response shape:

```json
{
  "results": [
    {
      "id": "chunk-id",
      "document_id": "document-id",
      "heap_id": "heap-id",
      "corpus_id": "corpus-id",
      "chunk_content": "matched content",
      "score": 0.91,
      "metadata": {}
    }
  ],
  "total": 1
}
```

## Canonical V2 API Surface

Swagger path source:

- `public/swagger/openapi.yaml`
- local UI: `http://localhost:8080/swagger/index.html`

All paths below are mounted under `/api` in the Laravel app.

### Tenants

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/tenants` | List tenants |
| POST | `/tenants` | Create tenant |
| GET | `/tenants/{tenant_id}` | Get tenant |

### Applications

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/applications` | List applications |
| POST | `/applications` | Create application and issue bearer token |
| GET | `/applications/{application_id}` | Get application |

### Heaps

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/heaps` | List heaps |
| POST | `/heaps` | Create heap |
| GET | `/heaps/{heap_id}` | Get heap |
| PATCH | `/heaps/{heap_id}` | Update heap metadata; protection is grant-derived |
| DELETE | `/heaps/{heap_id}` | Delete heap and storage backends |

### Documents

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/heaps/{heap_id}/documents` | List documents in a heap |
| POST | `/heaps/{heap_id}/documents` | Create document in a heap |
| GET | `/documents/{document_id}` | Get document |
| PUT | `/documents/{document_id}` | Update document |
| DELETE | `/documents/{document_id}` | Delete document |

### Corpora

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/corpora` | List corpora |
| GET | `/corpora/{corpus_id}` | Get corpus |

### Search

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/search` | Search documents |
| POST | `/search/chunks` | Search and return chunk-shaped results |
| POST | `/search/chunks/grouped` | Search and group chunks by document |

### Authorization Utilities

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/auth/check` | Check heap or document access for a user identifier |
| GET | `/auth/users/by-identifier/heaps` | List heap IDs accessible to a user identifier |

### Groups

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/auth/groups` | List groups |
| POST | `/auth/groups` | Create group |
| GET | `/auth/groups/{group_id}` | Get group |
| DELETE | `/auth/groups/{group_id}` | Delete group |
| GET | `/auth/groups/{group_id}/users` | List group members |
| PUT | `/auth/groups/{group_id}/users` | Replace group members |
| PATCH | `/auth/groups/{group_id}/users` | Add and remove group members |

### Heap Grants

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/auth/heaps/{heap_id}` | Get heap grants |
| PUT | `/auth/heaps/{heap_id}` | Replace heap grants |
| PATCH | `/auth/heaps/{heap_id}` | Update heap grants |
| DELETE | `/auth/heaps/{heap_id}` | Delete heap grants and unprotect heap |

### Document Grants

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/auth/documents/{document_id}` | Get document grants |
| PUT | `/auth/documents/{document_id}` | Replace document grants |
| PATCH | `/auth/documents/{document_id}` | Update document grants |
| DELETE | `/auth/documents/{document_id}` | Delete document grants |

### App Ingestion

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/pipeline/files` | Upload files into the ingestion pipeline |

### Operator APIs

These routes are not application-facing V2 product APIs. They require human/OIDC auth.

Pipeline task routes:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/pipeline/tasks` | List pipeline tasks |
| GET | `/pipeline/tasks/{taskId}` | Show task |
| GET | `/pipeline/tasks/{taskId}/jobs` | List task jobs |
| GET | `/pipeline/tasks/{taskId}/failed-jobs` | List failed jobs |
| GET | `/pipeline/tasks/{taskId}/events` | List task events |
| GET | `/pipeline/tasks/{taskId}/stages/{stage}/logs` | Read stage logs |
| GET | `/pipeline/tasks/{taskId}/stages/{stage}/logs/download` | Download stage logs |
| POST | `/pipeline/tasks/{taskId}/jobs` | Upsert task job |
| POST | `/pipeline/tasks/{taskId}/retry` | Retry task |
| POST | `/pipeline/tasks/{taskId}/retry-failed-jobs` | Retry failed task jobs |
| POST | `/pipeline/tasks/{taskId}/cancel` | Cancel task |
| DELETE | `/pipeline/tasks/{taskId}` | Delete task |

Pipeline recovery routes:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/pipeline/recovery/failed-jobs` | List recoverable failed jobs |
| POST | `/pipeline/recovery/jobs/retry-selected` | Retry selected jobs |
| POST | `/pipeline/recovery/jobs/{jobId}/retry` | Retry one job |
| POST | `/pipeline/recovery/retry-all` | Retry all recoverable jobs |
| POST | `/pipeline/recovery/tasks/{taskId}/retry-failed` | Retry failed jobs for one task |
| POST | `/pipeline/recovery/heaps/{heapId}/retry-failed` | Retry failed jobs for one heap |

Graph and infrastructure routes:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/rag/stats` | Show RAG backend stats |
| DELETE | `/rag/qdrant/collections/{collection}` | Delete Qdrant collection |
| GET | `/rag/neo4j/graph/overview` | Graph overview |
| GET | `/rag/neo4j/graph/search` | Graph search |
| GET | `/rag/neo4j/graph/semantic-search` | Graph semantic search |
| GET | `/rag/neo4j/graph/node` | Graph node detail |
| POST | `/rag/neo4j/graph/expand` | Expand graph |
| POST | `/rag/neo4j/graph/clear-view` | Clear graph view |
| GET | `/rag/neo4j/graph/snapshots` | List graph snapshots |
| POST | `/rag/neo4j/graph/snapshots` | Save graph snapshot |
| GET | `/rag/neo4j/graph/snapshots/{id}` | Load graph snapshot |
| DELETE | `/rag/neo4j/graph/snapshots/{id}` | Delete graph snapshot |
| POST | `/rag/neo4j/clear` | Clear Neo4j |

## Testing API Flow

Use Swagger for manual request testing:

```text
http://localhost:8080/swagger/index.html
```

Minimal manual flow:

1. Create or list a tenant with `/api/tenants`.
2. Create an application with `/api/applications`.
3. Copy the returned bearer token.
4. Authorize Swagger with `Bearer <token>`.
5. Create a heap with `/api/heaps`.
6. Create a document with `/api/heaps/{heap_id}/documents`.
7. Search with `/api/search`.
8. Create a group with `/api/auth/groups` if testing protected access.
9. Replace heap grants with `/api/auth/heaps/{heap_id}`.
10. Use `/api/auth/check` and `/api/auth/users/by-identifier/heaps` to verify access.
11. Search again with `user_identifier` to validate protected access behavior.
12. Delete grants with `/api/auth/heaps/{heap_id}` and verify visibility/search behavior.

Example V2 search body:

```json
{
  "query": "course policy",
  "limit": 5,
  "filters": ["AND", [["department", "law"]]],
  "user_identifier": "student-123"
}
```

Example heap grant replacement body:

```json
{
  "users": ["student-123"],
  "groups": ["course-admins"]
}
```

Example document grant replacement body:

```json
{
  "users": ["student-123"]
}
```

## Automated Tests

Run the full Laravel suite:

```bash
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test
```

Run the full Python suite:

```bash
uv run pytest python_rag/tests
```

Alternative Python command documented by the Python README:

```bash
make python-test
```

Swagger/API contract tests:

```bash
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/SwaggerDocumentationTest.php
```

Architecture boundary tests:

```bash
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/ArchitectureContractTest.php
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/RouteSecurityTest.php
```

V2 domain workflow tests:

```bash
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/SpecV2DomainApiTest.php
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/SpecV2WorkflowTest.php
```

Authorization tests:

```bash
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/AuthorizationGrantApiTest.php
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/AuthorizationLmsNeutralTest.php
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/AuthorizationPermissionSyncTest.php
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Unit/Authorization
```

Search and filter tests:

```bash
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/HawkiRagProxyControllerTest.php
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Unit/Rag/FilterLanguageParserTest.php
```

Stored metadata and denormalization tests:

```bash
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Unit/SpecV2/DocumentStoredMetadataTest.php
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/DocumentPersistenceTest.php
```

Pipeline tests:

```bash
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/PipelineUploadRepositoryTest.php
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/PipelineRepositoryReadTest.php
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/PipelineOperationsCommandTest.php
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/PipelineRecoveryRepositoryTest.php
DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/PipelineControllerDashboardTest.php
```

Python boundary and ingestion tests:

```bash
uv run pytest python_rag/tests/test_refactor_boundaries.py
uv run pytest python_rag/tests/test_incremental_ingest.py
uv run pytest python_rag/tests/test_reliability_contracts.py
uv run pytest python_rag/tests/test_raganything_vision.py
uv run pytest python_rag/tests/test_temporal_converter_passthrough.py
uv run pytest python_rag/tests/test_temporal_metadata.py
uv run pytest python_rag/tests/test_phase1_characterization.py
```

## Local Operations

Start the core stack:

```bash
make up-core
```

Generate the Laravel app key if needed:

```bash
docker compose exec hawki_rag_app php artisan key:generate
```

Check service health:

```bash
make health
```

Check pipeline health:

```bash
docker compose exec hawki_rag_app php artisan pipeline:health
```

Open key local services:

```text
Laravel API: http://localhost:8080
Swagger UI: http://localhost:8080/swagger/index.html
Temporal UI: http://localhost:8081
```

Useful database and operations notes live in:

- `docs/db_cookbook.md`
- `docs/pipeline_exit_codes.md`

## Pipeline Exit Codes

Pipeline commands use these documented exit codes:

| Code | Meaning |
| --- | --- |
| 0 | Success |
| 1 | Runtime failure |
| 2 | Validation or configuration failure |
| 3 | Partial success |

Common retry and recovery behavior is documented in `docs/pipeline_exit_codes.md`.

## Repository Test Map

Current Laravel tests:

- `tests/Feature/ArchitectureContractTest.php`
- `tests/Feature/AuthorizationGrantApiTest.php`
- `tests/Feature/AuthorizationLmsNeutralTest.php`
- `tests/Feature/AuthorizationPermissionSyncTest.php`
- `tests/Feature/DocumentPersistenceTest.php`
- `tests/Feature/HawkiRagProxyControllerTest.php`
- `tests/Feature/PipelineArchitectureCommandTest.php`
- `tests/Feature/PipelineControllerDashboardTest.php`
- `tests/Feature/PipelineOperationsCommandTest.php`
- `tests/Feature/PipelineRecoveryRepositoryTest.php`
- `tests/Feature/PipelineRepositoryReadTest.php`
- `tests/Feature/PipelineStateRepositoryTest.php`
- `tests/Feature/PipelineUploadRepositoryTest.php`
- `tests/Feature/RouteSecurityTest.php`
- `tests/Feature/SpecV2DomainApiTest.php`
- `tests/Feature/SpecV2WorkflowTest.php`
- `tests/Feature/SwaggerDocumentationTest.php`
- `tests/Unit/Authorization`
- `tests/Unit/Graph/GraphResultNormalizerTest.php`
- `tests/Unit/Health/HawkiRagSystemGateServiceTest.php`
- `tests/Unit/Pipeline`
- `tests/Unit/Rag/FilterLanguageParserTest.php`
- `tests/Unit/SpecV2/DocumentStoredMetadataTest.php`

Current Python tests:

- `python_rag/tests/test_incremental_ingest.py`
- `python_rag/tests/test_phase1_characterization.py`
- `python_rag/tests/test_raganything_vision.py`
- `python_rag/tests/test_refactor_boundaries.py`
- `python_rag/tests/test_reliability_contracts.py`
- `python_rag/tests/test_temporal_converter_passthrough.py`
- `python_rag/tests/test_temporal_metadata.py`

## Current Design Decisions To Preserve

- Laravel owns access scope resolution.
- Python receives retrieval inputs only.
- App-facing APIs use application bearer auth.
- Operator APIs use human/OIDC auth.
- Public API fields use snake_case.
- Search uses `limit`, not `top_k`.
- Filter grammar is array based.
- Auth-disabled mode is no-op.
- Native Laravel grants are the V2 runtime source of truth.
- Heap protection is grant-derived.
- Document grants are direct-user grants.
- Dataset terminology is an internal adapter concern only.
- Compatibility-only routes should not reappear on the app-facing V2 surface.

## Documentation Index

| File | Role |
| --- | --- |
| `README.md` | Local Docker stack, quick start, health, Swagger, production notes |
| `docs/internal-architecture-contract.md` | Current architecture decisions and boundaries |
| `docs/hawki-rag-v2-search-contract.md` | Search request, filter grammar, bridge payload, response shape |
| `docs/authorization.md` | Authorization architecture, adapters, identity, graph backend notes |
| `docs/hawki-rag-v2-domain-model.md` | V2 terminology and domain model overview |
| `docs/hawki-rag-v2-spec-coverage.md` | Spec coverage snapshot |
| `docs/db_cookbook.md` | Database, Temporal, shared storage, and operational queries |
| `docs/pipeline_exit_codes.md` | Pipeline command exit-code contract |
| `public/swagger/README.md` | Swagger UI usage |
| `python_rag/README.md` | Python RAG service and Python test commands |
| `v2_refactor.md` | Long-running V2 refactor changelog |
| `v2_spec_implementation_checklist.md` | Spec checklist and remaining-item history |
| `refactored_code.md` | Laravel refactor history |
| `refactor_code_python.md` | Python refactor history |
