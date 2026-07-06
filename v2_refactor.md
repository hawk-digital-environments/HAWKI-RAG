# V2 Refactor

## Scope

This document records the V2-oriented refactor work completed in this RAWKI
repo so far, the architectural boundary that was intentionally preserved, and
the remaining gaps between current repo behavior and the HAWKI RAG V2 target.

The work was driven by two rules:

1. follow the local Laravel architecture skill
2. align toward the HAWKI RAG V2 terminology and API shape without silently
   breaking the current runtime

That means this was not treated as a blind rewrite. Where the repo still has a
live dataset-based runtime, compatibility APIs, or a partially transitional auth
model, those boundaries were made more explicit instead of being hidden.

---

## High-Level Outcome

The repo is materially closer to the V2 model now:

- V2 tenants, applications, heaps, corpora, groups, grants, and user identity
  concepts are first-class in Laravel
- application bearer authentication is live
- Laravel now computes search scope and forwards search inputs to Python instead
  of forwarding an auth context on the main search path
- Python no longer carries a retrieval-time authorization workflow
- application-token auth now covers the app-facing ingest, dataset, document,
  folder, and V2 management API surfaces
- heap metadata and protection state are denormalized onto document search
  payloads and propagated to Qdrant
- corpus handling is split into a real write-path service
- `AUTHZ_ENABLED=false` now forces grant and permission-sync flows into
  side-effect-free no-op behavior
- API resources and centralized exception rendering replaced a large amount of
  raw array shaping and repeated controller error handling

The repo is not fully at the V2 target yet. The biggest remaining gaps are:

- the dataset-backed runtime is still the storage truth under the heap API
- compatibility and legacy naming still leak through non-V2 surfaces
- some app-facing APIs still keep human-auth compatibility for transition
- Python still has unrelated non-auth failing tests outside the V2 boundary

---

## Architectural Guardrails Preserved

The refactor intentionally did **not** pretend the repo had already completed a
full architecture migration.

Preserved boundaries:

- the current storage model still uses `datasets` as the backing table for
  `Heap`
- the existing compatibility and pipeline flows were not broken just to force a
  pure V2 vocabulary internally
- the repo was moved toward gateway-owned filtering, but the refactor was still
  treated as a migration step rather than a claim that every old path has
  disappeared

This matters because the local auth skill explicitly distinguishes:

- `current repo behavior`
- `spec target behavior`
- `alignment gap`
- `migration step`

That framing was kept throughout the work.

---

## Completed Refactors

## 1. Migration Tables Were Organized By Domain

Database migrations are now explicitly loaded from domain buckets in
`app/Providers/AppServiceProvider.php`:

- `database/migrations/auth`
- `database/migrations/documents`
- `database/migrations/framework`
- `database/migrations/pipeline`

This reflects the earlier migration cleanup request:

- document/storage tables under `documents`
- auth and identity tables under `auth`
- pipeline/runtime orchestration tables under `pipeline`
- framework or infrastructure support tables under `framework`

This reduced the previous flat migration sprawl and made the repo structure
match the domain split more closely.

## 2. V2 Became A Real Laravel Domain

The V2 work now lives as a proper domain under:

- `app/Services/SpecV2`

That domain includes:

- aggregate services
- repositories
- value objects
- exceptions
- events
- payload and resource shaping

The important shift here was structural:

- controllers are thinner
- V2 logic is not scattered across controllers and ad hoc helpers
- the domain now has a clearer root and clearer sub-responsibilities

## 3. V2 Terminology Was Promoted To First-Class Models

The repo now has explicit V2-facing models for:

- `Tenant`
- `Application`
- `Heap`
- `Corpus`
- `Group`
- `GroupMember`
- `InternalUser`
- `HeapGrant`
- `DocumentGrant`

This moved the codebase closer to the spec vocabulary:

- tenant
- application
- heap
- document
- corpus
- group
- metadata
- filter

Important limitation:

- `Heap` is still dataset-backed
- `Document` still stores `dataset_id`
- compatibility code and parts of pipeline code still surface `dataset` naming

So the terminology cleanup is substantial, but not absolute yet.

## 4. Applications Now Support Real Bearer Authentication

`App\Models\SpecV2\Application` now acts as an authenticatable principal rather
than just a decorative record with a dead token hash.

Implemented pieces:

- `ApplicationTokenService`
- `application-token` guard in `config/auth.php`
- request guard registration in `AppServiceProvider`
- bearer-token issuance through the V2 application service and test helpers

Search and retrieval routes now accept:

- `auth:application-token,sanctum,oidc`

That gives the repo a real application actor model and makes application auth a
live runtime concern instead of a placeholder schema field.

## 5. Application Permissions Became Executable

The permission constants on `Application` are now live policy inputs:

- `reads`
- `reads-all-apps`
- `reads-federated`
- `reads-protected`

`ApplicationScopeResolver` now turns those permissions into actual document
scope:

- same-application reads
- same-tenant reads
- federated reads
- protected-document bypass for privileged apps

This is one of the most important architecture changes in the refactor because
permissions now drive behavior instead of existing only as metadata.

## 6. Search Authorization Was Moved Out Of The Main Python Bridge Path

The main API search bridge now resolves the actor in Laravel and builds the
effective filter before forwarding the request.

Key pieces:

- `App\Services\Authorization\ApiActorResolver`
- `App\Services\Authorization\ApplicationScopeResolver`
- `App\Services\Authorization\GatewaySearchFilterService`
- `App\Http\Controllers\API\HawkiRagProxyController`

Current search request behavior:

- validate `query`, `filters`, `top_k`, flags, and optional `user_identifier`
- resolve the caller as either an application actor or a human actor
- compute the effective allowed document scope in Laravel
- intersect that scope with client filters
- forward only the normalized search payload to Python

Most importantly, `RagQueryPayload` now contains:

- `query`
- `top_k`
- `filters`
- `is_optimized`
- `generate`
- `fast_mode`
- `smart_lookup`
- `preferred_tags`

It does **not** forward `auth_context` on this path anymore.

This is the clearest move toward the V2 gateway-driven prefilter design.

## 7. A Real Filter Language Parser Was Added

The search layer now supports a structured filter language in Laravel through
`App\Services\Rag\FilterLanguageParser`.

Supported behavior:

- boolean operators `AND`, `OR`, `NOT`
- leaf conditions on reserved system fields
- leaf conditions on metadata fields
- parsing of legacy Qdrant-style `must`, `should`, `must_not` bodies
- normalization of `metadata.*` input
- normalization of `doc_id` to `document_id`

This parser is now consumed by gateway search filtering rather than leaving the
entire filter contract implicit.

## 8. Reserved Metadata Keys Are Enforced

Heap metadata validation now rejects reserved search/system keys through
`DisallowReservedMetadataKeys`, wired into:

- `CreateHeapRequest`
- `UpdateHeapRequest`

Reserved/system filtering is now explicitly separated from arbitrary metadata.

That blocks collisions with keys such as:

- `heap`
- `document_id`
- `owner_app`
- `visibility`
- `protected`

This closes an important spec-alignment gap because those keys can no longer be
quietly injected through ordinary metadata writes.

## 9. Authorization Grant CRUD Is First-Class In Laravel

The repo now exposes explicit authorization endpoints under `/api/auth/*`:

- heap grant read and replace/update
- document grant read and replace/update

The Laravel side now includes:

- `SpecV2\AuthorizationController`
- `SpecV2\AuthorizationGrantService`
- `HeapGrantRepository`
- `DocumentGrantRepository`
- `HeapGrant` and `DocumentGrant` models

Grant validation includes:

- heap existence
- document existence
- group existence
- tenant consistency between the resource and granted groups

This makes heap protection and direct document grants a first-class application
surface instead of only an LMS-sync side effect.

## 10. Group Membership Was Connected To Internal Identity

The earlier placeholder-identity behavior was moved toward a real V2 identity
bridge.

Important identity work now in the repo:

- `IdentityProvisioningService`
- `InternalUser`
- tenant/application/internal-user assignment for resolved actors
- group members storing both external user identifiers and internal UUIDs
- migration support for user-identity normalization

This gives Laravel a stable internal identity key while keeping the external
identifier at the API edge.

Important nuance:

- the repo now has stronger internal identity modeling
- this is still a migration step, not a finished end-state for every auth path

## 11. Heap Metadata And Protection Are Denormalized Onto Documents

The repo now has an explicit write-time denormalization path for search payload
data:

- `DocumentSearchPayloadFactory`
- `DocumentSearchPayloadSyncService`

This service:

- merges heap-derived search metadata into stored document metadata
- writes the denormalized view back onto documents
- builds the heap-level Qdrant payload fields

The denormalized payload includes V2-oriented fields such as:

- `heap`
- `owner_app`
- `visibility`
- `protected`
- merged metadata keys

This is a direct implementation of the design principle that search-time joins
should be traded for write-time propagation.

## 12. Heap Changes Now Trigger Async Propagation

Heap search payload changes no longer stop at a local DB update.

Added pieces:

- `HeapSearchPayloadChanged` event
- queued listener `PropagateHeapSearchPayload`
- listener registration in `AppServiceProvider`

Behavior:

- heap updates capture previous heap metadata keys
- a domain event is emitted
- the queued listener reloads the heap
- document metadata and Qdrant payloads are propagated asynchronously

This is significantly better than the previous silent DB-only update model.

## 13. Corpus Was Split Into A Real Write-Path Domain Service

Corpus handling is no longer buried as mixed IO and orchestration inside a
repository.

The repo now has:

- `CorpusService` for read-facing corpus domain operations
- `CorpusSyncService` for write-path synchronization from documents
- `CorpusContentReader` for content access concerns
- a cleaner `CorpusRepository` boundary

Pipeline ingestion now coordinates corpus syncing explicitly:

- corpus records are created or reused by checksum
- corpus content is populated when needed
- `reference_count` is maintained
- `documents.corpus_id` is synchronized

This is closer to the Laravel skill’s separation of:

- service orchestration
- repository persistence
- file or content IO

## 14. API Response Shaping Was Moved Toward Resources

Earlier payload builders still exist in parts of the codebase, but V2 endpoints
now also use Laravel API resources for response shaping, including:

- `HeapResource`
- `GroupResource`
- `CorpusResource`
- related V2 resource classes

This reduces manual array construction in controllers and moves the JSON shape
closer to the HTTP layer where it belongs.

The result is cleaner separation between:

- service return values
- HTTP serialization
- controller orchestration

## 15. Exception Mapping Was Centralized

Repeated controller `try/catch` handling was reduced in favor of centralized
exception rendering in `bootstrap/app.php`.

Mapped exceptions include:

- `ApplicationNotFoundException`
- `InvalidGroupIdentifierException`
- `HeapNotFoundException`
- `GroupNotFoundException`
- `CorpusNotFoundException`
- `AuthorizationGrantException`

This produced more consistent API behavior and kept controllers thinner.

## 16. Route Security And Operator Access Were Tightened

The repo now has stronger route-level security coverage and clearer operator
behavior.

Relevant outcomes:

- operator-gated UI routes stay idle when operator access is absent
- sensitive responses are explicitly non-cacheable
- internal API CORS requires explicit allowed origins
- identifier route constraints reject suspicious path values
- rate limiting distinguishes application actors, users, and IP-based callers

This work is covered by `RouteSecurityTest`.

## 17. Open-Compat Search Paths Consume Application Scope

The compatibility search surface was also brought under the application-scope
model.

That means the app-auth and scope work was not limited to one controller only.
Search and retrieval compatibility endpoints now align more closely with the V2
idea that applications read only within their permitted scope.

## 18. The Last Test Failures Were Fixed Cleanly

Two late test failures were fixed without weakening the architecture:

### Graph source document lookup

`GraphSourceDocumentResolver` now catches `QueryException` when document storage
is unavailable and logs a warning instead of failing the whole normalization
path.

That keeps graph normalization resilient in tests and partial-runtime states.

### Operator bypass isolation in page tests

`Neo4jGraphExplorerPageTest` now explicitly disables operator bypass in the test
setup so the page contract is verified against the intended access mode instead
of whatever environment default is present.

## 19. Python Is Now A Pure Retrieval Engine

The remaining Python-side authorization workflow code was removed.

Removed:

- `python_rag/application/workflows/authorization_filter.py`
- `python_rag/tests/test_authorization_filter.py`

Effect:

- Python no longer owns document authorization checks
- Laravel is now the only layer computing access scope for the main retrieval
  path
- the Python side only receives search inputs and filter constraints

This turns the earlier Laravel-to-Python bridge cleanup into a cleaner
architectural fact instead of leaving dead auth workflow code behind.

## 20. Application-Token Auth Was Expanded Across App-Facing APIs

Application-token middleware is no longer limited to search and retrieval.

App-facing route groups now accept application-token auth for:

- ingest
- datasets
- documents
- folders
- model discovery
- V2 tenants, applications, heaps, corpora, groups, and grants

Operator-only surfaces remain on human auth:

- pipeline control and recovery
- RAG stats and Neo4j graph operations
- provider API key management
- operational logs and storage usage endpoints

To make this safe, app-facing dataset and document services now resolve the
current API actor and apply scope-aware defaults and read filters.

## 21. Disabled Authorization Is A True No-Op For Grant And Sync Flows

When `AUTHZ_ENABLED=false`, auth-related input is now accepted without creating
side effects.

Implemented no-op behavior:

- `AuthorizationGrantService` accepts heap/document grant requests but does not
  persist grants
- `PermissionSyncService` accepts membership and document-relation inputs but
  does not record permission events or write to the graph backend
- search-side user identifiers are already ignored when gateway authorization is
  disabled

This brings the repo much closer to the design requirement that authorization is
an optional layer rather than a partially active hidden dependency.

---

## Tests And Validation

Validation completed during this refactor pass included:

- targeted regression rerun after the three architecture changes:
  - `37 passed`
  - `210 assertions`
- targeted regression rerun after the final fixes:
  - `AuthorizationGrantApiTest`
  - `RouteSecurityTest`
  - `Neo4jGraphExplorerPageTest`
  - `GraphResultNormalizerTest`
- targeted rerun result:
  - `18 passed`
  - `106 assertions`
- full Laravel suite:
  - `187 passed`
  - `1084 assertions`
- full Python suite:
  - `131 passed`
  - `7 failed`
  - failures were outside the auth removal path and currently sit in:
    - `python_rag/tests/test_phase1_characterization.py`
    - `python_rag/tests/test_temporal_converter_passthrough.py`
  - observed failure areas:
    - graph fallback triplet counting
    - API factory logging paths under read-only `/shared`
    - passthrough path normalization using `/private/var` vs `/var`

This gives reasonable confidence that the refactor is internally coherent across:

- application auth
- Python auth boundary removal
- V2 resources and routes
- filter parsing
- grant CRUD
- graph normalization
- security middleware behavior

---

## Current Repo State After Refactor

The current state is best described as:

- V2-aware
- materially refactored
- partially aligned to the target architecture
- still intentionally transitional in a few core areas

What is now true:

- Laravel owns the main application actor and search-scope decision path
- Python no longer contains retrieval-time authorization workflow logic
- application permissions are executable
- V2 auth and grant surfaces exist
- disabled auth grant and sync flows are side-effect-free
- heap/document search payload denormalization exists
- corpus handling has a dedicated service boundary
- V2 routes, resources, requests, and exception handling are much cleaner

What is still true:

- `Heap` still maps onto `datasets`
- `Document` still carries `dataset_id`
- pipeline and compatibility code still expose legacy dataset naming
- some app-facing routes still retain human-auth compatibility during transition
- the Python suite still has unrelated failing tests outside this refactor scope
- some current-state versus target-state architecture tension remains by design

---

## Remaining Alignment Gaps

These are the main items still left if the repo must fully follow the design
philosophy and V2 target:

1. finish promoting application-token auth across all app-facing write and
   management routes that still intentionally preserve human-actor
   compatibility
2. remove remaining legacy dataset leakage or isolate it behind explicit
   adapters
3. finish the remaining optional-auth cleanup outside grant and permission-sync
   flows
4. define one canonical denormalized document-search payload contract and verify
   all write paths honor it
5. reduce route and service mixing between core RAG storage, pipeline
   operations, compatibility APIs, and operator UI
6. continue replacing legacy or transitional terminology outside explicit
   compatibility layers
7. clean up the unrelated Python test failures so the non-Laravel suite is
   fully green again

---

## Short Summary

This refactor did not just rename things. It made V2 concepts operational:

- application bearer auth is live
- application permissions now drive search scope
- Laravel now constructs effective search filters
- Python no longer carries auth workflow logic
- explicit auth grant CRUD exists
- disabled auth grant and sync flows are now no-op
- reserved metadata keys are enforced
- heap metadata is denormalized and propagated to Qdrant
- corpus handling has a proper service boundary
- API resources and centralized exception mapping cleaned up the Laravel side

The repo is substantially closer to the HAWKI RAG V2 design, but it is still a
transitional architecture rather than a finished end-state. That is now much
clearer in both the code and the boundary between current behavior and target
behavior.
