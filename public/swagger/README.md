# Local Swagger UI for HAWKI RAG APIs

Open the local docs page in your browser after starting the app:

```bash
http://localhost/swagger
```

By default this page loads:

- `public/swagger/index.html` (UI wrapper)
- `public/swagger/openapi.yaml` (API contract)

If your app is behind a different host/port, replace `localhost` accordingly.

## About auth

Most `/api` routes documented here are protected in code, including the dataset,
document, pipeline, query, and graph endpoints.

You can add `Authorization: Bearer <token>` in Swagger UI once you log in with
your normal app flow.

Legacy compatibility routes may still exist in code, but this published
contract intentionally shows only the unified public API surface.

## Next steps

- Keep `public/swagger/openapi.yaml` aligned with the unified public API surface.
- Keep legacy compatibility routes out of the published contract unless they are meant for external consumers.
- Optionally wire an OpenAPI generator once network/package install is available.
