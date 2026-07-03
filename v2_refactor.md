# V2 Refactor

## Goal

Refactor the first-pass V2 implementation so it fits the repo's Laravel
conventions more closely while keeping the runtime stable.

This refactor was guided by two constraints:

1. follow the local Laravel skill:
   - thin controllers
   - validation in `FormRequest`
   - business logic in domain services
   - database access in repositories
   - avoid throwing generic PHP exceptions from domain code
   - prefer a single domain injection point when a domain has multiple sub-services
2. preserve the current RAWKI architecture boundary:
   - do not silently replace the current dataset/document runtime
   - do not silently replace the current LMS-neutral authorization path

---

## What Was Refactored

### 1. V2 is now a proper Laravel domain

The V2 code stays under a dedicated domain:

```text
app/Services/SpecV2/
```

It now has explicit sub-structure:

```text
app/Services/SpecV2/
├── Exceptions/
├── Payloads/
├── Repositories/
├── ApplicationService.php
├── CorpusService.php
├── GroupService.php
├── HeapService.php
├── SpecIdentifierFactory.php
├── SpecV2Service.php
└── TenantService.php
```

This aligns with the Laravel skill's "light DDD" structure instead of leaving
the domain as a flat collection of mixed concerns.

### 2. One aggregate service now fronts the whole domain

Added:

- [SpecV2Service.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/SpecV2/SpecV2Service.php)

This is now the single domain injection point for controllers:

- `tenants`
- `applications`
- `heaps`
- `corpora`
- `groups`

That follows the Laravel skill recommendation to expose multiple sub-services
through one aggregate service instead of scattering many domain injections.

### 3. Payload shaping was extracted out of services

The first pass mixed orchestration and response shaping inside the services.
That was cleaned up by adding payload builders:

- [TenantPayloadBuilder.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/SpecV2/Payloads/TenantPayloadBuilder.php)
- [ApplicationPayloadBuilder.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/SpecV2/Payloads/ApplicationPayloadBuilder.php)
- [HeapPayloadBuilder.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/SpecV2/Payloads/HeapPayloadBuilder.php)
- [CorpusPayloadBuilder.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/SpecV2/Payloads/CorpusPayloadBuilder.php)
- [GroupPayloadBuilder.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/SpecV2/Payloads/GroupPayloadBuilder.php)
- [PaginationPayloadBuilder.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/SpecV2/Payloads/PaginationPayloadBuilder.php)

Result:

- services now orchestrate
- repositories persist
- payload builders format output

This matches the repo's existing `DatasetPayloadBuilder` pattern and avoids
forcing a new API Resource style into a codebase that does not currently use it
for these endpoints.

### 4. Domain exceptions replaced generic PHP exceptions

Added:

- [SpecV2ExceptionInterface.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/SpecV2/Exceptions/SpecV2ExceptionInterface.php)
- [TenantNotFoundException.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/SpecV2/Exceptions/TenantNotFoundException.php)
- [ApplicationNotFoundException.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/SpecV2/Exceptions/ApplicationNotFoundException.php)
- [HeapNotFoundException.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/SpecV2/Exceptions/HeapNotFoundException.php)
- [CorpusNotFoundException.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/SpecV2/Exceptions/CorpusNotFoundException.php)
- [GroupNotFoundException.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/SpecV2/Exceptions/GroupNotFoundException.php)
- [InvalidGroupIdentifierException.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/SpecV2/Exceptions/InvalidGroupIdentifierException.php)

Why:

- the Laravel skill explicitly says not to throw built-in PHP exceptions
  directly from domain code
- these exceptions make controller behavior clearer
- error messages are now domain-specific and reusable

### 5. Controllers are thinner now

The V2 controllers now:

- inject only `SpecV2Service`
- call one domain method
- translate domain exceptions into HTTP status codes

Updated controllers:

- [TenantController.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Http/Controllers/SpecV2/TenantController.php)
- [ApplicationController.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Http/Controllers/SpecV2/ApplicationController.php)
- [HeapController.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Http/Controllers/SpecV2/HeapController.php)
- [CorpusController.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Http/Controllers/SpecV2/CorpusController.php)
- [GroupController.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Http/Controllers/SpecV2/GroupController.php)

This is closer to the "HTTP only" controller role defined in the Laravel
skill.

### 6. Validation was moved into FormRequests

The remaining controller-side validation check was removed.

Updated:

- [UpdateGroupMembersRequest.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Http/Requests/SpecV2/UpdateGroupMembersRequest.php)

It now rejects empty `PATCH /api/groups/{group_id}/users` payloads itself, so
the controller no longer owns that rule.

---

## Runtime Model After Refactor

### Tenant

- model: [Tenant.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Models/SpecV2/Tenant.php)
- table: `tenants`
- API: `/api/tenants`

### Application

- model: [Application.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Models/SpecV2/Application.php)
- table: `applications`
- API: `/api/applications`

### Heap

- model: [Heap.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Models/SpecV2/Heap.php)
- backing table: `datasets`
- API: `/api/heaps`

Important design choice:

- `Heap` is still intentionally dataset-backed
- this avoids breaking the current document, ingestion, Qdrant, and Neo4j flows
- the V2 noun is now first-class, but the storage boundary stays compatible

### Corpus

- model: [Corpus.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Models/SpecV2/Corpus.php)
- table: `corpora`
- API: `/api/corpora`

Integration point:

- [PipelineIngestionRepository.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/Pipeline/Repositories/PipelineIngestionRepository.php)

New behavior:

- corpus records exist separately
- `documents.corpus_id` is populated
- `reference_count` is tracked
- pipeline ingestion syncs corpora after document upsert

### Group

- model: [Group.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Models/SpecV2/Group.php)
- member model: [GroupMember.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Models/SpecV2/GroupMember.php)
- tables: `groups`, `group_members`
- API: `/api/groups`

Current supported behavior:

- create/list/show/delete groups
- replace/add/remove group members
- namespaced group IDs

Current intentional limitation:

- groups are not yet the live retrieval enforcement primitive
- they exist as a V2 domain object, not as the replacement for the current
  LMS-neutral authorization flow

### Internal User Identity Bridge

Added:

- [IdentityProvisioningService.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/Authorization/IdentityProvisioningService.php)
- [InternalUser.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Models/SpecV2/InternalUser.php)
- [2026_07_03_150000_add_spec_v2_identity_bridge.php](/Users/ixdlab/Projects/HAWKI/RAWKI/database/migrations/2026_07_03_150000_add_spec_v2_identity_bridge.php)

What this changes:

- every resolved auth identity now gets:
  - `tenant_id`
  - `application_id`
  - `internal_user_id`
- local Sanctum users are auto-provisioned into the same V2 identity model when
  they create heaps, applications, or groups without explicit ownership fields
- OIDC identities now resolve tenant/application defaults from claims or config
- group members are persisted with both:
  - the original external identifier returned by the API
  - the resolved `internal_user_id` stored internally

Why this matters:

- it matches the draft spec's "external identifier at the API edge, internal
  UUID inside the system" model
- it keeps the current repo's user-authenticated request flow intact
- it avoids exposing internal UUIDs while giving the V2 layer a stable identity
  key for future grant and graph work

Current boundary:

- this is still an identity-bridge step, not full spec authentication
- request auth is still `auth:sanctum,oidc`
- application identity is currently a provisioned default context for the
  authenticated user, not a true bearer-app auth flow from the draft spec

---

## Database Refactor Summary

Main migration:

- [2026_07_03_140100_add_hawki_v2_spec_domain_tables.php](/Users/ixdlab/Projects/HAWKI/RAWKI/database/migrations/2026_07_03_140100_add_hawki_v2_spec_domain_tables.php)

It adds:

- `tenants`
- `applications`
- `corpora`
- `groups`
- `group_members`

It also extends existing tables:

- `datasets.tenant_id`
- `datasets.owner_application_id`
- `datasets.visibility`
- `datasets.protected`
- `datasets.metadata_json`
- `datasets.updated_at`
- `documents.corpus_id`

Bootstrap/backfill behavior:

- creates default tenant: `default`
- creates default application: `rawki-default`
- backfills existing datasets into that tenant/application scope
- backfills corpora from existing document checksums
- links documents to `corpus_id = checksum_sha256`

---

## Compatibility Changes

Existing dataset and document payloads now also expose V2 naming:

### Dataset payload

Added in:

- [DatasetPayloadBuilder.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/Dataset/DatasetPayloadBuilder.php)

New fields:

- `heapId`
- `tenantId`
- `ownerApp`
- `visibility`
- `protected`
- `metadata`

### Document payload

Added in:

- [DocumentPayloadBuilder.php](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/Document/DocumentPayloadBuilder.php)

New fields:

- `heapId`
- `corpusId`

This keeps old endpoints usable while exposing the new V2 terminology to
clients.

---

## Why This Refactor Does Not Go Further

This refactor intentionally does **not** do the following:

### 1. It does not move authorization fully into Laravel

Current repo behavior remains:

- Laravel still builds and passes `auth_context`
- Python still performs retrieval-time authorization filtering

Reason:

- that is an explicit architecture migration, not a cleanup refactor
- the `rawki-authz` skill says not to silently migrate the repo toward the spec
  in incidental changes

### 2. It does not replace `Dataset` storage with a new heap table

Reason:

- the current repo already has dataset-linked ingestion, pipeline, vector, and
  graph behavior
- replacing that in one step would be much higher risk than exposing a
  dataset-backed `Heap` model

### 3. It does not make `Group` the active permission graph input

Reason:

- the live auth flow is still LMS-neutral course/document sync
- a real migration would need graph schema/model updates, grant APIs, and
  Laravel/Python enforcement changes together

---

## Files To Look At First

If you want to continue the V2 work, start here:

- [routes/internal_api.php](/Users/ixdlab/Projects/HAWKI/RAWKI/routes/internal_api.php)
- [app/Services/SpecV2](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Services/SpecV2)
- [app/Models/SpecV2](/Users/ixdlab/Projects/HAWKI/RAWKI/app/Models/SpecV2)
- [database/migrations/2026_07_03_140100_add_hawki_v2_spec_domain_tables.php](/Users/ixdlab/Projects/HAWKI/RAWKI/database/migrations/2026_07_03_140100_add_hawki_v2_spec_domain_tables.php)
- [docs/hawki-rag-v2-domain-model.md](/Users/ixdlab/Projects/HAWKI/RAWKI/docs/hawki-rag-v2-domain-model.md)
- [docs/hawki-rag-v2-spec-coverage.md](/Users/ixdlab/Projects/HAWKI/RAWKI/docs/hawki-rag-v2-spec-coverage.md)

---

## Validation Run

Refactor verification was run with:

```bash
/bin/zsh -lc 'DB_HOST=127.0.0.1 DB_PORT=3306 php artisan test tests/Feature/AuthorizationLmsNeutralTest.php tests/Feature/SpecV2DomainApiTest.php tests/Unit/Authorization/AuthorizationServiceTest.php'
```

That covers:

- OIDC-to-V2 identity mapping
- internal user reuse for group member identities
- local user auto-provisioning into the V2 tenant/application context
- V2 endpoint behavior and member persistence
- unchanged Laravel authorization retrieval-context behavior

---

## Recommended Next Step

If you want the repo to move from "V2 nouns exist" to "V2 behavior is fully
live", the next real architecture step is:

1. add heap/document grant APIs under `/api/auth/*`
2. map V2 groups and direct grants into the permission graph
3. build authorization filters in Laravel
4. stop sending V2 auth semantics into Python

That should be treated as a separate migration, not as part of this refactor.
