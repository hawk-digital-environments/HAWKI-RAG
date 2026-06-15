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

The current route map marks the following API groups as protected in code:

- `POST /api/query`
- `GET /api/rag/*`

You can add `Authorization: Bearer <token>` in Swagger UI once you log in with your normal app flow.

## Next steps

- Extend `openapi.yaml` when request/response fields are known.
- Add any missing endpoints from route files.
- Optionally wire an OpenAPI generator once network/package install is available.
