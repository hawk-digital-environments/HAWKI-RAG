# Local Swagger UI for HAWKI RAG

After starting the UI stack, open `http://localhost:8080/swagger`.
The [UI wrapper](index.html) loads [openapi.yaml](openapi.yaml) relative to its
deployed path. A reverse proxy may publish a different host/path.

The maintained [REST API guide](../../_documentation/Reference/rest_apis.md)
explains public versus internal surfaces. Routes marked `openapi=false` are
intentionally omitted from this checked-in contract.

Authentication varies by boundary. Credential-free queries can use the sole
active local user, management routes have no blanket application authentication,
and direct-text ingestion requires an explicit token and dataset ingest grant.
MCP and worker callbacks have their own policies. See
[Authorization & Dataset Scope](../../_documentation/Core%20Concepts/authorization_dataset_scope.md).

Keep the contract aligned with [routes](../../routes/api.php), request validation,
and [API contract tests](../../tests/Feature/ApiContract). Swagger presence alone
does not establish runtime authorization behavior.
