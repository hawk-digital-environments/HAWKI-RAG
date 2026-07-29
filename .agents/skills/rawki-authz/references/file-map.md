# RAWKI Authz File Map

Read only the sections relevant to the task.

## Core Docs And Config

- `docs/authorization.md`
  - repo-level overview of the current LMS-neutral authorization flow
  - sample env values for SpiceDB, OpenFGA, and static connector usage
- `config/authz.php`
  - source of truth for Laravel authz config structure and defaults
- `docs/spicedb-rag-schema.zed`
  - SpiceDB schema used by the current implementation
- `docs/openfga-rag-model.fga`
  - OpenFGA model used by the alternate backend

## Identity And OIDC

- `app/Services/Authorization/Oidc/OidcJwtValidator.php`
  - JWT parsing, issuer and audience checks, JWKS fetch, signature validation, time claim enforcement
- `app/Services/Authorization/Oidc/OidcUserResolver.php`
  - user resolution path from validated OIDC identity into local user models
- `app/Services/Authorization/Repositories/AuthorizationIdentityRepository.php`
  - persistence and lookup of stable auth identity mappings
- `app/Models/AuthorizationIdentity.php`
  - Eloquent model backing identity storage

## Connector Layer

- `app/Services/Authorization/Contracts/LmsPermissionConnector.php`
  - normalized connector contract
- `app/Services/Authorization/LmsPermissionConnectorRegistry.php`
  - default connector selection and provider mapping
- `app/Services/Authorization/Connectors/StaticLmsPermissionConnector.php`
  - only complete connector today
- `app/Services/Authorization/Connectors/StudIpLmsPermissionConnector.php`
  - scaffold for site-specific connector implementation
- `app/Services/Authorization/Connectors/UnsupportedLmsPermissionConnector.php`
  - placeholder behavior for unsupported LMS providers
- `app/Services/Authorization/Values/LmsUserIdentity.php`
- `app/Services/Authorization/Values/LmsMembership.php`
- `app/Services/Authorization/Values/LmsDocumentRelation.php`

## Graph Integration

- `app/Services/Authorization/Contracts/PermissionGraphClient.php`
  - backend-neutral graph contract
- `app/Services/Authorization/PermissionGraph/SpiceDbPermissionGraphClient.php`
  - primary backend adapter
- `app/Services/Authorization/PermissionGraph/OpenFgaPermissionGraphClient.php`
  - alternate backend adapter
- `app/Services/Authorization/PermissionGraph/PermissionGraphRelationshipFactory.php`
  - backend-friendly relationship object shaping
- `app/Services/Authorization/Values/PermissionGraphRelationship.php`
  - normalized relationship value
- `app/Services/Authorization/PermissionSyncService.php`
  - records permission events and writes graph relationships
- `app/Services/Authorization/Repositories/PermissionEventRepository.php`
  - local event persistence used for replay or reconciliation

## Laravel Enforcement

- `app/Services/Authorization/AuthorizationService.php`
  - direct document access checks and Python retrieval context generation
- `app/Http/Controllers/API/HawkiRagProxyController.php`
  - adds `auth_context` to the Python query bridge
- `app/Http/Controllers/DocumentBrowserController.php`
  - document details access control
- `app/Http/Controllers/UploadedSourceDocumentController.php`
  - protected uploaded source download control
- `app/Providers/AppServiceProvider.php`
  - graph client binding and OIDC request auth wiring

## Python Enforcement

- `python_rag/application/workflows/authorization_filter.py`
  - retrieval-time permission graph filtering and backend selection
- `python_rag/application/workflows/query_execution.py`
  - applies auth filtering before prompt context generation
- `python_rag/tests/test_authorization_filter.py`
  - primary Python auth coverage

## Tests

### PHP Feature

- `tests/Feature/AuthorizationLmsNeutralTest.php`
  - OIDC validation, static connector parsing, document enforcement path
- `tests/Feature/AuthorizationPermissionSyncTest.php`
  - permission sync writes and event recording

### PHP Unit

- `tests/Unit/Authorization/AuthorizationServiceTest.php`
- `tests/Unit/Authorization/LmsConnectorRegistryTest.php`
- `tests/Unit/Authorization/PermissionGraphClientTest.php`

## High-Risk Couplings

- Laravel `retrievalContextFor()` and Python `AuthorizationContext.from_payload()` must agree.
- Connector `providerId()` values affect graph object IDs and retrieval checks.
- Relationship naming must stay compatible with `docs/spicedb-rag-schema.zed` and `docs/openfga-rag-model.fga`.
- Enabling `AUTHZ_ENABLED=true` without valid auth context or graph settings causes Python to deny all hits.
- Production advice must not treat `studip`, `moodle`, `ilias`, or `canvas` as fully implemented unless the repo changes.
