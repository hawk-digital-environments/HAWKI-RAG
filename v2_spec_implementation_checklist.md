# HAWKI RAG V2 Spec Implementation Checklist

Scope:
- Source spec reviewed: `/Users/ixdlab/.codex/attachments/fb62419c-7b28-48ca-8e89-ab5e9d96d1d9/pasted-text.txt`
- Repo snapshot reviewed: `/Users/ixdlab/Projects/HAWKI/RAWKI` on `2026-07-07`
- This checklist follows the spec section order and only records items that are still missing, inconsistent, need cleanup, or need stronger test coverage.

How to use this file:
- `Implement next`: behavior the repo still does not match.
- `Clean next`: naming, route, or boundary cleanup needed so the code actually reflects V2.
- `Test next`: coverage that should be added before calling the section aligned.

## 0. Spec Clarifications To Resolve First

Status: `Clarification needed before more code churn`

Implement next:
- Decide the official behavior when `AUTHZ_ENABLED=false`.
- Pick one rule and enforce it everywhere:
  - either auth inputs are accepted and ignored with no side effects,
  - or `/api/auth/*` returns `503` and protected resources fail closed.
- Decide whether the public filter language officially supports `NOT` now or only later.
- Decide whether the public API contract is snake_case only. The current repo still returns many camelCase fields.
- Decide whether `provider` is a valid part of external identity uniqueness, or whether the spec truly wants tenant-scoped identifier uniqueness without that extra dimension.

Clean next:
- Update the spec or add a repo-local contract note for the contradictions above so implementation work stops oscillating.

Test next:
- Add one architecture test that asserts the chosen auth-disabled contract.
- Add one contract test that asserts the chosen response casing convention.

## 1. Design Philosophy

Status: `Partial`

Implement next:
- Remove the last public-surface leaks where V2 and compatibility routes overlap. The branch still exposes duplicate group and auth alias routes that blur the product boundary.
- Make the search gateway forward one canonical V2 search contract instead of translating metadata and scope into a doc-id allow list before Python.

Clean next:
- Keep only V2 and truly shared backend code in this branch. Compatibility-only route surfaces should be isolated or removed if they are not part of the V2 product.

Test next:
- Add architecture tests that fail if application-facing APIs are mounted outside the approved V2/app surfaces.
- Add tests that prove Python receives retrieval inputs only, not authorization-specific payloads.

Repo evidence:
- `routes/internal_api/spec_v2.php`
- `app/Services/Authorization/GatewaySearchFilterService.php`
- `docs/internal-architecture-contract.md`

## 2. Terminology

Status: `Partial`

Implement next:
- Finish removing `dataset` as a core domain term. V2 still maps heaps onto `datasets` internally and documents still carry `dataset_id`.
- Keep any remaining legacy names behind explicit adapters only.

Clean next:
- Rename or isolate remaining dataset-shaped repositories and service methods where heap is the real aggregate.
- Review public payload keys and route params so they only use `tenant`, `application`, `heap`, `document`, `corpus`, `group`, `metadata`, and `filter`.

Test next:
- Add a terminology architecture test that blocks new core classes, routes, and resources from exposing legacy dataset names outside compatibility layers.

Repo evidence:
- `app/Models/SpecV2/Heap.php`
- `app/Models/Document.php`
- `app/Services/SpecV2/HeapService.php`

## 3. Architecture Overview

Status: `Partial`

Implement next:
- Align search to the spec contract where Laravel computes scope and forwards a canonical filter expression to Python.
- Stop reducing Laravel-side scope resolution to a Qdrant `document_id` allow list when the spec wants the merged filter to remain the contract.

Clean next:
- Document the exact V2 search request and response shape in one place and make Laravel, Python, and Swagger all use it.

Test next:
- Add an integration test that asserts the Python bridge receives only query, canonical filters, and limit.
- Add regression tests for heap metadata change, protection change, and document move so the denormalized search payload stays stable across write paths.

Repo evidence:
- `app/Http/Controllers/API/HawkiRagProxyController.php`
- `app/Services/Rag/RagQueryPayloadFactory.php`
- `app/Services/Authorization/GatewaySearchFilterService.php`

## 4. Tenancy, Permissions, And Authentication

Status: `Partial`

Implement next:
- Verify that `reads`, `reads-all-apps`, `reads-federated`, and `reads-protected` drive every read path consistently, not only search and a subset of V2 services.
- Align cross-application access errors with the chosen V2 contract. Current grant lookup paths hide unauthorized reads as not found.

Clean next:
- Remove any remaining human-auth assumptions from app-facing APIs. Operator-only routes should be the only place that still depends on OIDC/human context.

Test next:
- Add matrix tests for each application permission across heap read, heap list, document read, corpus read, grant read, and search.
- Add explicit tests for `403` versus `404` behavior once the contract is fixed.

Repo evidence:
- `app/Services/Authorization/ApplicationReadPolicy.php`
- `app/Services/Authorization/ApplicationScopeResolver.php`
- `app/Services/SpecV2/AuthorizationGrantService.php`

## 5. User Identity Model

Status: `Partial`

Implement next:
- Decide whether the canonical user identifier is fully opaque and exact-match only. Current repository lookup still falls back across `external_user_id`, `email`, and `username`.
- Confirm whether tenant-scoped identity uniqueness should include `provider`.
- Verify federated identity behavior against the spec when the same external identifier exists in multiple tenants.

Clean next:
- Remove event-era and placeholder-style assumptions from identity resolution where they still leak into grant provisioning semantics.

Test next:
- Add tests for duplicate identifier values across tenants.
- Add tests for duplicate identifier values across providers within one tenant.
- Add tests proving `reads-federated` unions access only in the officially supported identity cases.

Repo evidence:
- `app/Models/UserIdentity.php`
- `app/Services/Authorization/Repositories/UserIdentityRepository.php`
- `app/Services/Authorization/IdentityProvisioningService.php`

## 6. Data Model

Status: `Partial`

Implement next:
- Add the missing canonical `GET /api/documents/{document_id}` V2 route and service contract.
- Reconcile the internal heap/document/corpus persistence model so the public V2 aggregate names are not backed by leaking dataset semantics.

Clean next:
- Replace remaining payload-builder style responses for applications and tenants with API resources so the V2 surface is uniform.

Test next:
- Add contract tests for document create, get, update, delete, and list under the V2 routes only.
- Add tests that assert heap delete and document delete return the intended V2 status codes.

Repo evidence:
- `routes/internal_api/spec_v2.php`
- `app/Http/Controllers/SpecV2/DocumentController.php`
- `app/Http/Resources/SpecV2`
- `app/Services/SpecV2/Payloads`

## 7. Metadata And Denormalization

Status: `Partial`

Implement next:
- Decide whether relational document metadata should store only public document metadata plus a tiny internal audit block, or whether the current embedded merged search payload is acceptable.
- If the spec is strict, stop storing the merged heap and system payload snapshot inside `documents.metadata_json`; keep merged payload generation as a write-path concern for Qdrant/search sync.
- Finalize one canonical denormalized search payload schema and enforce it on create, update, move, protection change, and heap metadata change.

Clean next:
- Separate public metadata from internal sync bookkeeping more clearly. Right now both live inside `metadata_json` under `__rawki`.

Test next:
- Add tests that assert reserved keys are blocked on heap and document metadata writes.
- Add tests that assert Qdrant payload fields are rebuilt after heap metadata edits, heap protection changes, and document heap moves.
- Add tests that assert DB-stored public metadata never gets polluted with heap/system keys if that is the chosen contract.

Repo evidence:
- `app/Services/SpecV2/DocumentSearchPayloadFactory.php`
- `app/Observers/DocumentObserver.php`
- `app/Services/SpecV2/DocumentSearchPayloadSyncService.php`
- `app/Http/Requests/SpecV2/CreateHeapRequest.php`
- `app/Http/Requests/SpecV2/UpdateHeapRequest.php`

## 8. Authorization Model

Status: `Partial`

Implement next:
- Stop treating heap protection as a client-settable field on general heap create and update APIs if the V2 contract says protection is grant-derived state.
- Add or confirm the canonical unprotect flow if the spec requires an explicit endpoint distinct from deleting grants.
- Align heap and document grant payloads to the exact V2 schema, including resource key names and whether document grants may contain groups.
- Return `201` on first-time grant creation and `200` on replacement if the spec keeps that distinction.

Clean next:
- Remove deprecated `/auth/heaps/{heapId}/grants` and `/auth/documents/{documentId}/grants` aliases from the live route table if the spec-only surface is the target.
- Collapse duplicate group management under `/api/auth/groups` if `/api/groups` is no longer part of the intended public contract.

Test next:
- Add grant lifecycle tests for first create versus replace.
- Add tests for group detail payload including assigned heaps if that remains part of the spec.
- Add tests for delete and unprotect behavior in both auth-enabled and auth-disabled modes once the contract is fixed.

Repo evidence:
- `routes/internal_api/spec_v2.php`
- `app/Services/SpecV2/AuthorizationGrantService.php`
- `app/Http/Controllers/SpecV2/AuthorizationController.php`

## 9. Filter Language

Status: `Missing spec contract`

Implement next:
- Replace the current object-style parser with the spec grammar, or add a strict V2 parser alongside the existing compatibility parser.
- Support the canonical expression style from the spec:
  - leaf: `["field", "value"]`
  - boolean: `{"AND": [...]}`, `{"OR": [...]}`
  - implicit root AND for sibling expressions if the spec keeps that rule
- Rename the public search limit field from `top_k` to `limit`.

Clean next:
- Decide whether legacy Qdrant-shaped filter bodies should remain accepted. If yes, isolate them behind compatibility-only endpoints.

Test next:
- Add parser contract tests straight from the spec examples.
- Add request validation tests for invalid filter arrays, reserved fields, empty values, and `NOT` behavior.
- Add search endpoint tests for `limit`, not `top_k`.

Repo evidence:
- `app/Services/Rag/FilterLanguageParser.php`
- `app/Http/Controllers/API/HawkiRagProxyController.php`

## 10. API Reference: Core

Status: `Partial`

Implement next:
- Align search request and response shapes with the spec. Current bridge behavior still reflects a proxy payload, not a stable V2 API response contract.
- Add `GET /api/documents/{document_id}`.
- Confirm the official heap list visibility behavior. The spec says hidden heaps stay excluded unless `visibility=all`, while current code still uses `discoverable` and `hidden`.
- Align destructive responses to the intended V2 status codes, especially heap delete.

Clean next:
- Remove public route duplication where compatibility and V2 both expose overlapping capabilities.
- Ensure Swagger documents the core search route and its real V2 schema.

Test next:
- Add OpenAPI contract tests for heaps, documents, corpora, and search.
- Add response-shape tests that assert snake_case if that becomes the official contract.

Repo evidence:
- `routes/internal_api/spec_v2.php`
- `app/Http/Requests/SpecV2/ListHeapsRequest.php`
- `app/Http/Controllers/SpecV2/HeapController.php`
- `public/swagger/openapi.yaml`

## 11. API Reference: Authorization

Status: `Partial`

Implement next:
- Verify every auth utility route from the spec is present and returns the intended contract.
- Align `/api/auth/check` and `/api/auth/users/by-identifier/heaps` response shapes to the final spec wording.
- Decide whether auth endpoints should no-op or fail with `503` when the backend is disabled or absent.

Clean next:
- Keep only canonical auth routes in Swagger and in the route table.
- Remove any remaining alias descriptions or compatibility naming from the generated docs.

Test next:
- Add OpenAPI coverage for every auth endpoint, including disabled-mode behavior.
- Add end-to-end grant tests that cover heap grants with users and groups and document grants with direct users.

Repo evidence:
- `routes/internal_api/spec_v2.php`
- `app/Services/SpecV2/AuthorizationGrantService.php`
- `public/swagger/openapi.yaml`

## 12. Workflows

Status: `Partial`

Implement next:
- Verify all spec workflows exist on the canonical V2 routes, not only through compatibility endpoints or internal service shortcuts.
- Ensure heap metadata changes, protection changes, corpus reassignment, and document moves all trigger the same payload rebuild and sync pipeline.

Clean next:
- Remove workflow duplication where one operation is implemented differently in V2 and compatibility layers.

Test next:
- Add full workflow tests for:
  - create heap -> add documents -> search
  - protect heap -> grant users/groups -> search as authorized and unauthorized users
  - move document between heaps -> verify metadata and search payload refresh
  - delete grants/unprotect -> verify visibility changes in search

Repo evidence:
- `app/Services/SpecV2/HeapService.php`
- `app/Services/SpecV2/DocumentService.php`
- `app/Services/SpecV2/CorpusService.php`

## 13. Integration Patterns

Status: `Partial`

Implement next:
- Keep the repo as backend-only V2 infrastructure if that is the branch goal. Remove any legacy UI or non-V2 surfaces that are not shared backend responsibilities.
- Verify graph exploration and RAGAnything backend capabilities are mounted through the same product boundary rules as the rest of V2.

Clean next:
- Split route and service namespaces so core RAG storage, pipeline operations, compatibility APIs, and operator surfaces are unmistakably separate.

Test next:
- Add route-map tests that fail if operator endpoints leak into app-facing middleware groups.
- Add smoke tests for the retained graph and pipeline entrypoints that are supposed to stay in this branch.

Repo evidence:
- `routes/internal_api.php`
- `routes/internal_api/app_search.php`
- `routes/internal_api/app_ingestion.php`
- `routes/internal_api/operator.php`

## 14. Design Decisions

Status: `Needs explicit repo contract`

Implement next:
- Write down the final chosen answers for:
  - auth-disabled semantics
  - canonical response casing
  - filter grammar
  - permission error semantics
  - whether document grants may include groups
  - whether protection is grant-derived only

Clean next:
- Reflect those decisions in `docs/internal-architecture-contract.md` and remove older contradictory comments or route aliases that imply a different model.

Test next:
- Add one architecture test suite that encodes the decisions above so refactors cannot silently regress them.

Repo evidence:
- `docs/internal-architecture-contract.md`
- `tests/Feature/SwaggerDocumentationTest.php`

## 15. Prioritized Execution Order

### P0: Resolve contract contradictions
- Decide auth-disabled behavior.
- Decide filter grammar and public request/response casing.
- Decide exact permission error semantics.

### P1: Fix public contract gaps
- Add `GET /api/documents/{document_id}`.
- Replace `top_k` with `limit`.
- Implement the spec filter grammar.
- Document the canonical search request and response in Swagger.

### P2: Remove legacy leakage
- Eliminate dataset terminology from core runtime paths.
- Remove auth alias routes and duplicate group surfaces if they are not part of the final V2 API.
- Keep compatibility parsing and routes isolated behind explicit compatibility layers only.

### P3: Tighten auth and denormalization behavior
- Make protection derived from grants if that is the final model.
- Finish canonical denormalized payload enforcement across every write path.
- Lock down auth-disabled behavior and permission matrices with tests.

### P4: Lock the architecture
- Add route-map tests.
- Add search contract tests.
- Add architecture tests for Laravel-owned scope resolution, optional auth, tenancy boundaries, and required payload fields.
