# Internal Architecture Contract

This repo follows a strict boundary contract for search, authorization, and
denormalized payload writes.

## Core Boundary Rules

1. Laravel owns actor resolution, tenancy, application permissions, metadata
   validation, grant projection, and search-scope construction.
2. Python is a retrieval engine only. It receives search inputs and filters. It
   must not resolve tenants, applications, user identities, or permission
   grants.
3. The auth backend is optional infrastructure. When enabled, Laravel services
   may write or check graph relationships. When disabled, auth-shaped inputs
   are accepted and ignored without side effects.
4. Qdrant payload mutation is a write-path concern. Controllers do not write
   Qdrant directly. Denormalized search payload sync belongs to dedicated
   Laravel services.

## Allowed Responsibilities By File

- `app/Http/Controllers/API/HawkiRagProxyController.php`
  Validates requests and delegates to Laravel-owned scope filtering plus the
  bridge client. Must not talk to the auth backend or Qdrant directly.
- `app/Http/Controllers/API/OpenCompat/RetrievalController.php`
  Builds application-visible document scope through Laravel policy services
  before delegating retrieval or compatibility reads.
- `app/Services/Authorization/ApplicationReadPolicy.php`
  The single policy layer for application read permissions across tenant,
  application, heap, group, corpus, and document reads.
- `app/Services/Authorization/ApplicationScopeResolver.php`
  Resolves document-level access scope for Laravel. It may use native grants
  and identity mappings, but not Python.
- `app/Services/Authorization/PermissionSyncService.php`
  Accepts connector-shaped inputs, projects them into native groups and grants,
  and optionally mirrors them into the auth backend.
- `app/Services/SpecV2/DocumentSearchPayloadSyncService.php`
  Owns denormalized document search-payload persistence and Qdrant payload
  refreshes.
- `python_rag/application/workflows/query_execution.py`
  Executes retrieval and ranking only. It must not consume `auth_context`,
  resolve user identity, or call authorization backends.

## Enforcement Notes

- Application permissions mean:
  - `reads`: own application only
  - `reads-all-apps`: any application in the same tenant
  - `reads-federated`: all tenants
  - `reads-protected`: protected resources are readable without grant-based
    narrowing
- Protected heaps and corpora are hidden from ordinary application reads when
  authorization is enabled and the caller lacks `reads-protected`.
- Required denormalized payload fields on documents are:
  - `heap`
  - `document_id`
  - `owner_app`
  - `visibility`
  - `protected`

## Non-Goals

- Controllers are not allowed to become policy engines.
- Python is not allowed to grow application-tenancy or authorization semantics.
- Authorization connector event rows are not the native runtime access model.
  Native runtime access is based on groups, group members, heap grants, and
  document grants.
