---
name: rawki-authz
description: "Use when implementing, reviewing, debugging, or designing RAWKI authorization features: OIDC login, auth identity resolution, LMS permission connectors, permission graph integration with SpiceDB or OpenFGA, Laravel document/API enforcement, and Python retrieval-time authorization filtering. Especially relevant when aligning repo behavior with the HAWKI RAG architecture/auth specification."
---

# RAWKI Authz

Use this skill for authorization work in this repo. It is intentionally opinionated about the current codebase so changes stay aligned across Laravel, Python RAG, tests, and `.env` configuration.

## What This Repo Does Today

RAWKI already has an LMS-neutral authorization core:

- Laravel holds the main authorization orchestration.
- OIDC JWT validation happens in Laravel against JWKS.
- A connector resolves LMS identities, memberships, and document relations.
- `PermissionSyncService` writes normalized relationships into the configured permission graph.
- Laravel passes `auth_context` to Python RAG.
- Python RAG filters candidate hits by permission graph checks before building prompt context.

Important current behavior:

- If `AUTHZ_ENABLED=true` and Python receives no valid `auth_context`, retrieval denies all hits.
- `AUTHZ_DOCUMENT_API_ENFORCED=true` also gates direct Laravel document access.
- `static` is the only fully implemented LMS connector.
- `studip` is a scaffold, not a finished integration.
- `moodle`, `ilias`, and `canvas` resolve to unsupported placeholders returning no permissions.

Read [references/file-map.md](references/file-map.md) before editing code.

If the task involves architecture or product-level changes, also read [references/spec-alignment.md](references/spec-alignment.md).

## Default Workflow

1. Classify the task:
   - `config/runtime`: env flags, backend selection, fail-open or fail-closed behavior
   - `identity/oidc`: JWT validation, identity resolution, user mapping
   - `connector`: LMS permission ingestion or normalization
   - `graph`: SpiceDB or OpenFGA adapters and relationship shape
   - `enforcement`: Laravel document checks or Python retrieval filtering
   - `architecture`: aligning the repo with the HAWKI RAG auth spec
2. Read only the relevant files from `references/file-map.md`.
3. Preserve the existing split of responsibilities unless the user explicitly asks for an architectural refactor.
4. When changing auth semantics, update both Laravel and Python if the change crosses the boundary.
5. Add or update tests in the matching PHP and Python suites.

## Repo-Specific Rules

- Prefer SpiceDB unless the user explicitly operates OpenFGA already.
- Do not recommend placeholder LMS connectors as production-ready.
- Keep authorization fail-closed unless the user explicitly asks for weaker behavior.
- Preserve normalized connector outputs: `LmsUserIdentity`, `LmsMembership`, `LmsDocumentRelation`.
- Keep permission graph logic behind `PermissionGraphClient`.
- Keep controller logic thin; put business logic in authorization services.
- Do not move current files around just to satisfy a cleaner architecture.

## Task Playbooks

## OIDC Or Identity Changes

Read:

- `config/authz.php`
- `app/Services/Authorization/Oidc/OidcJwtValidator.php`
- `app/Services/Authorization/Oidc/OidcUserResolver.php`
- `app/Services/Authorization/Repositories/AuthorizationIdentityRepository.php`
- `tests/Feature/AuthorizationLmsNeutralTest.php`

Expectations:

- Issuer, audience, token timing, and RS256 signature checks must stay explicit.
- Provider identity must stay stable across retrieval and graph writes.
- If you change claim mapping, verify both stored identity and downstream `auth_context`.

## LMS Connector Work

Read:

- `app/Services/Authorization/Contracts/LmsPermissionConnector.php`
- `app/Services/Authorization/LmsPermissionConnectorRegistry.php`
- `app/Services/Authorization/Connectors/StaticLmsPermissionConnector.php`
- `app/Services/Authorization/Connectors/StudIpLmsPermissionConnector.php`
- `app/Services/Authorization/PermissionSyncService.php`
- `tests/Unit/Authorization/LmsConnectorRegistryTest.php`
- `tests/Feature/AuthorizationPermissionSyncTest.php`

Expectations:

- New connectors should normalize into connector values first, not write graph tuples directly.
- Registry wiring, config keys, and tests should land in the same change.
- If a connector is scaffold-only, say so explicitly in code comments or docs.
- Production connectors need a real source of memberships and document-course relations; static env rows are not enough for live LMS sync.

## Permission Graph Work

Read:

- `app/Services/Authorization/Contracts/PermissionGraphClient.php`
- `app/Services/Authorization/PermissionGraph/SpiceDbPermissionGraphClient.php`
- `app/Services/Authorization/PermissionGraph/OpenFgaPermissionGraphClient.php`
- `app/Services/Authorization/PermissionGraph/PermissionGraphRelationshipFactory.php`
- `docs/spicedb-rag-schema.zed`
- `docs/openfga-rag-model.fga`
- `tests/Unit/Authorization/PermissionGraphClientTest.php`

Expectations:

- Keep relationship serialization backend-neutral at the service layer.
- Preserve idempotent write behavior and deterministic object ID shaping.
- If you add relation types or permissions, update schema or model files and tests together.
- If you change object naming, verify Laravel and Python still agree on document and user object identities.

## Laravel Enforcement Work

Read:

- `app/Services/Authorization/AuthorizationService.php`
- `app/Http/Controllers/API/HawkiRagProxyController.php`
- `app/Http/Controllers/DocumentBrowserController.php`
- `app/Http/Controllers/UploadedSourceDocumentController.php`
- `tests/Feature/AuthorizationLmsNeutralTest.php`
- `tests/Unit/Authorization/AuthorizationServiceTest.php`

Expectations:

- `retrievalContextFor()` is the bridge from Laravel auth identity to Python auth filtering.
- Direct document endpoints and upload-download flows must not bypass auth when enforcement is enabled.
- Audit logging should keep clear allow or deny reasons.

## Python Retrieval Enforcement Work

Read:

- `python_rag/application/workflows/authorization_filter.py`
- `python_rag/application/workflows/query_execution.py`
- `python_rag/tests/test_authorization_filter.py`

Expectations:

- Auth filtering happens before prompt context generation.
- Missing graph config or missing auth context currently denies access.
- Backend selection must stay consistent with Laravel env names.
- If filtering rules change, update retry or high-recall behavior tests as needed.

## Architecture Alignment Work

When the user references the HAWKI RAG architecture or API spec, compare current code against [references/spec-alignment.md](references/spec-alignment.md) before editing.

Important distinction:

- The attached architecture spec describes a gateway-driven prefilter model where Python is a pure search executor.
- The current repo still performs retrieval-time authorization checks in Python using `auth_context`.

Do not silently “correct” the repo toward the spec in small incidental changes. If the user wants alignment, treat it as an explicit architecture task and identify which layer boundaries are changing.

## Validation

Choose the smallest test set that covers the change:

- PHP feature auth flow:
  - `php artisan test tests/Feature/AuthorizationLmsNeutralTest.php`
  - `php artisan test tests/Feature/AuthorizationPermissionSyncTest.php`
- PHP unit auth contracts:
  - `php artisan test tests/Unit/Authorization`
- Python auth filtering:
  - `uv run pytest python_rag/tests/test_authorization_filter.py`

For config-only reviews, verify:

- `docs/authorization.md`
- `config/authz.php`
- matching `.env` flags in repo examples or deployment notes

## Done Criteria

A change is not done until:

- config, services, and tests agree on the same auth behavior
- Laravel and Python agree on provider and user ID semantics
- fail-closed behavior is preserved or intentionally changed
- connector maturity is stated honestly
- production recommendations do not rely on scaffold or placeholder integrations
