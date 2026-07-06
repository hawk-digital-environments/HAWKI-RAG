# Local Swagger UI for HAWKI RAG APIs

Open the local docs page in your browser after starting the app:

```bash
http://localhost/swagger
```

By default this page loads:

- `public/swagger/index.html` (UI wrapper)
- `public/swagger/openapi.yaml` (API contract)

If your app is behind a different host/port, replace `localhost` accordingly.

## What is documented

The current `openapi.yaml` is centered on the canonical V2 application API:

- tenants
- applications
- heaps
- documents
- corpora
- groups
- authorization grant endpoints

## About auth

These V2 endpoints use application bearer auth. Create an application first or
use an existing token, then authorize in Swagger UI with:

```text
Bearer <token>
```

## Next steps

- Keep `public/swagger/openapi.yaml` aligned with `routes/internal_api/spec_v2.php`.
- Extend response detail when additional V2 fields become stable.
- Optionally replace the handwritten contract with generator-backed OpenAPI later.
