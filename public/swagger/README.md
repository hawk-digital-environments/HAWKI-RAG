# Local Swagger UI for HAWKI RAG APIs

Open the local docs page in your browser after starting the app:

```bash
http://localhost/swagger
```

By default this page loads:

- `public/swagger/index.html` (UI wrapper)
- `public/swagger/openapi.yaml` (API contract)

If your app is behind a different host/port, replace `localhost` accordingly.
For the default reverse-proxy deployment path, use:

```text
https://your-host.example/hawki-rag/swagger
```

The UI derives both `openapi.yaml` and the API server URL from its deployed
path, so the same files work at `/swagger` and `/hawki-rag/swagger`.

## About auth

HAWKI-RAG's control-plane and dataset-scoped query endpoints support a trusted
single-user deployment without a bearer token. A credential-free query uses
the only active local user; zero or multiple active users fail with HTTP `503`
instead of selecting one arbitrarily.

External API clients may optionally add `Authorization: Bearer <query-token>`
in Swagger UI to select an explicit active user. An invalid token is rejected
with HTTP `401` and never falls back to the implicit user.

Because the whole published API is reachable without a HAWKI-RAG credential in
single-user mode, keep this UI and the endpoints on loopback or a trusted
network, or protect them at the reverse proxy.

Legacy compatibility routes may still exist in code, but this published
contract intentionally shows only the unified public API surface.

## Next steps

- Keep `public/swagger/openapi.yaml` aligned with the unified public API surface.
- Keep legacy compatibility routes out of the published contract unless they are meant for external consumers.
- Optionally wire an OpenAPI generator once network/package install is available.
