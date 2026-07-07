# Internal Architecture Contract

This repo follows a strict boundary contract for search, authorization, and
denormalized payload writes.

## Core Boundary Rules

1. Laravel owns actor resolution, tenancy, application permissions, metadata
   validation, grant projection, and search-scope construction.
2. App-facing internal APIs use application bearer tokens only. Human
   `sanctum` or OIDC authentication is reserved for operator-only surfaces.
3. App-facing V2 reads return `403` when a resource exists but falls outside
   the caller's permitted application scope. `404` is reserved for truly
   missing resources.
4. Python is a retrieval engine only. It receives only `query`, `limit`, and a
   canonical `filters` expression from Laravel. It must not resolve tenants,
   applications, user identities, or permission grants.
5. The auth backend is optional infrastructure. When enabled, Laravel services
   may write or check graph relationships. When disabled, auth-shaped inputs
   are accepted and ignored without side effects.
6. Qdrant payload mutation is a write-path concern. Controllers do not write
   Qdrant directly. Relational `documents.metadata_json` holds caller metadata
   plus internal audit (`__rawki.audit`) only. Denormalized payload composition
   and Qdrant sync belongs to dedicated Laravel services.
7. No legacy compatibility API routes are registered on this branch. Shared
   legacy-named services may remain only as internal adapters behind active
   app-search, app-ingestion, or V2 flows.
8. `user_identifier` is an opaque Laravel-only lookup value. Scope resolution
   matches it exactly against `user_identities.external_user_id`, never through
   `email` or `username`, and excludes ambiguous multi-provider matches within
   one tenant from federated unioning.

## Allowed Responsibilities By File

- `app/Http/Controllers/API/HawkiRagProxyController.php`
  Validates requests and delegates to Laravel-owned scope filtering plus the
  bridge client. Must not talk to the auth backend or Qdrant directly.
- `app/Http/Requests/Search/SearchQueryRequest.php`
  Owns the canonical Laravel request contract for application-facing search
  endpoints and normalizes `top_k` or `k` into `limit`.
- `app/Http/Controllers/API/OpenCompat/RetrievalController.php`
  Builds application-visible search scope through Laravel policy services
  before delegating shared chunk retrieval for `/api/search/chunks` and
  `/api/search/chunks/grouped`.
- `app/Services/OpenCompat/OpenCompatService.php`
  Owns shared chunk response shaping for active app-search flows. It must not
  absorb document browsing, ingestion orchestration, or retired compatibility
  route concerns.
- `app/Http/Controllers/SpecV2/DocumentController.php`
  Handles canonical V2 document HTTP validation and delegates heap-scoped
  document create, update, list, and delete flows into the dedicated V2
  document service.
- `app/Services/SpecV2/DocumentService.php`
  Owns canonical V2 document lifecycle orchestration, corpus synchronization,
  and bridge-backed text-ingest alignment. It must not absorb authorization
  policy resolution or compatibility response shaping.
- `app/Services/OpenCompat/OpenCompatIngestService.php`
  Owns shared ingest-to-pipeline and text-ingest bridge handoff for active
  app-ingestion and V2 document flows. It must not absorb document browsing,
  folder lifecycle, or retired compatibility route concerns.
- `routes/internal_api/app_search.php`
  Application retrieval and search endpoints. Must stay on
  `auth:application-token`.
- `routes/internal_api/app_ingestion.php`
  Application ingestion entrypoints such as task start and file upload. Must
  stay on `auth:application-token`.
- `routes/internal_api/operator.php`
  Operator-only monitoring, recovery, graph, usage, and admin endpoints. Must
  stay on `auth:sanctum,oidc`.
- `app/Services/Authorization/ApplicationReadPolicy.php`
  The single policy layer for application read permissions across tenant,
  application, heap, group, corpus, and document reads.
- `app/Services/Authorization/ApplicationScopeResolver.php`
  Resolves document-level access scope for Laravel. It may use native grants
  and identity mappings, but not Python.
- `app/Services/SpecV2/AuthorizationGrantService.php`
  Owns canonical auth grant CRUD, direct user-grant persistence, and auth
  utility lookups. It must not delegate authorization grant writes back into
  connector event rows.
- `app/Services/Authorization/PermissionSyncService.php`
  Accepts connector-shaped inputs, projects them into native groups and grants,
  and optionally mirrors them into the auth backend.
- `app/Services/SpecV2/DocumentSearchPayloadSyncService.php`
  Owns denormalized document search-payload persistence and Qdrant payload
  refreshes.
- `python_rag/application/workflows/query_execution.py`
  Executes retrieval and ranking only. It must not consume `auth_context`,
  resolve user identity, or call authorization backends.
- `docs/hawki-rag-v2-search-contract.md`
  The single documentation source for the public Laravel search request,
  Laravel-to-Python bridge payload, canonical filter grammar, and search
  response shapes.

## Enforcement Notes

- Application permissions mean:
  - `reads`: own application only
  - `reads-all-apps`: any application in the same tenant
  - `reads-federated`: all tenants
  - `reads-protected`: protected resources are readable without grant-based
    narrowing
- Federated identity union means:
  - same exact `external_user_id` may union across tenants
  - `tenant + provider + external_user_id` is the supported uniqueness key
  - one tenant must not contribute scope when that exact external id exists
    under multiple providers in that same tenant
- Protected heaps and corpora are hidden from ordinary application reads when
  authorization is enabled and the caller lacks `reads-protected`.
- When authorization is disabled:
  - `document_api_enforced` is treated as off even if configured on
  - heap `protected` write inputs are ignored
  - heap `protected` list filters are ignored
  - `user_identifier` may be accepted on request surfaces but must not narrow
    access scope
- Required denormalized payload fields on documents are:
  - `heap`
  - `document_id`
  - `owner_app`
  - `visibility`
  - `protected`
- Stored relational document metadata means:
  - caller metadata keys remain top-level in `documents.metadata_json`
  - the only internal relational block is `__rawki.audit`
  - clients may not submit `__rawki`, `heap`, `document_id`, `owner_app`,
    `visibility`, or `protected` as metadata
  - merged heap metadata and system payloads are generated for Qdrant/search
    sync only

## Non-Goals

- Controllers are not allowed to become policy engines.
- Python is not allowed to grow application-tenancy or authorization semantics.
- Authorization connector event rows are not the native runtime access model.
  Native runtime access is based on groups, group members, heap grants, and
  document grants.
