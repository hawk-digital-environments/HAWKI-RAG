# LMS-neutral RAG authorization

RAWKI authorization is intentionally split into independent layers:

1. Keycloak or another OIDC issuer provides a JWT.
2. Laravel validates the JWT through JWKS and resolves a stable internal user.
3. An LMS connector emits normalized membership and document relation events.
4. `PermissionSyncService` writes idempotent relationships to the configured permission graph.
5. Python retrieval gets candidate chunks, checks document access through the permission graph, and builds LLM context only from authorized chunks.

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

## Connector contract

Connectors implement `App\Services\Authorization\Contracts\LmsPermissionConnector`.
The core only consumes `LmsUserIdentity`, `LmsMembership`, and `LmsDocumentRelation` values, so campus-specific systems remain plugins. Current implementations:

- `StaticLmsPermissionConnector`: local development and tests.
- `StudIpLmsPermissionConnector`: scaffold for a site-specific plugin.
- Moodle, ILIAS, Canvas: extension placeholders returning no permissions until implemented.

## Retrieval enforcement

Laravel sends `auth_context` to the Python RAG bridge for authenticated users. When `AUTHZ_ENABLED=true`, Python:

1. Retrieves candidate hits.
2. Collects unique `doc_id`/`document_id` values.
3. Calls the configured permission graph for `viewer document:<document_id>`.
4. Discards unauthorized hits.
5. Repeats high-recall retrieval once if too few authorized hits remain.
6. Builds prompt context only from the filtered hit list.

If authorization is enabled and no auth context or permission graph backend is available, retrieval denies all chunks.

## Alternate graph backend

OpenFGA remains available as an adapter for installations already operating it:

```env
AUTHZ_GRAPH_BACKEND=openfga
OPENFGA_API_URL=http://openfga:8080
OPENFGA_STORE_ID=<store-id>
OPENFGA_AUTHORIZATION_MODEL_ID=<model-id>
```
