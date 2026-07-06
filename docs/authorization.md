# HAWKI RAG authorization

HAWKI RAG authorization is intentionally split into independent layers:

1. Keycloak or another OIDC issuer provides a JWT.
2. Laravel validates either a human JWT/session or an application bearer token and resolves an explicit actor context.
3. An LMS adapter emits normalized membership and document relation events.
4. `PermissionSyncService` writes idempotent relationships to the configured permission graph.
5. Laravel computes the merged retrieval filter from application scope, protected-heap access, and client filters before forwarding the search to Python.

The authorization core depends on `PermissionGraphClient`, not on a specific graph product. SpiceDB is the default backend.

## SpiceDB schema

```zed
definition user {}

definition course {
  relation member: user
  relation instructor: user
  permission viewer = member + instructor
}

definition document {
  relation owner: user
  relation course: course
  permission viewer = owner + course->viewer
}
```

Relationship shape:

- `course:<provider>__<course_id>#member@user:<provider>__<user_id>`
- `course:<provider>__<course_id>#instructor@user:<provider>__<instructor_id>`
- `document:<document_id>#course@course:<provider>__<course_id>`

## Local setup

Start SpiceDB only when testing authorization:

```bash
docker compose --profile authz up -d spicedb
```

Load `docs/spicedb-rag-schema.zed` with `zed schema write` or the SpiceDB schema API, then set:

```env
AUTHZ_ENABLED=true
AUTHZ_DOCUMENT_API_ENFORCED=true
AUTHZ_GRAPH_BACKEND=spicedb
SPICEDB_API_URL=http://spicedb:8443
SPICEDB_PRESHARED_KEY=dev-spicedb-key
```

For static local permissions:

```env
LMS_PERMISSION_CONNECTOR=static
STATIC_LMS_PROVIDER=local
STATIC_LMS_MEMBERSHIPS="1:course-a:member"
STATIC_LMS_DOCUMENTS="course-a:<document-uuid>"
```

## Adapter contract

Adapters implement `App\Services\Authorization\Contracts\LmsPermissionConnector`.
The core only consumes `LmsUserIdentity`, `LmsMembership`, and `LmsDocumentRelation` values, so campus-specific systems remain isolated adapters. Current implementations:

- `StaticLmsPermissionConnector`: local development and tests.
- `StudIpLmsPermissionConnector`: scaffold for a site-specific plugin.
- Moodle, ILIAS, Canvas: extension placeholders returning no permissions until implemented.

## Search enforcement

Laravel now owns search scoping. For `/api/search` and `/api/search/*`, it:

1. Resolves the application actor from the bearer token or falls back to the authenticated human actor.
2. Computes application scope from `reads`, `reads-all-apps`, `reads-federated`, and `reads-protected`.
3. Resolves protected-document access from the synced permission event store when authorization is enabled.
4. Merges the resulting allow filter with any client metadata filters.
5. Forwards only `query`, `filters`, and search options to the Python bridge.

Python executes the filter against Qdrant and never receives application identity, tenant context, permissions, or user identifiers.

The document API still performs direct point checks through `AuthorizationService` when `AUTHZ_DOCUMENT_API_ENFORCED=true`.

## Alternate graph backend

OpenFGA remains available as an adapter for installations already operating it:

```env
AUTHZ_GRAPH_BACKEND=openfga
OPENFGA_API_URL=http://openfga:8080
OPENFGA_STORE_ID=<store-id>
OPENFGA_AUTHORIZATION_MODEL_ID=<model-id>
```
