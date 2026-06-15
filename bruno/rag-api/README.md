# RAWKI RAG API Bruno Collection

This OpenCollection YAML scaffold contains Bruno request files for the Laravel RAG API endpoints.

## Use in Bruno

1. Open `bruno/rag-api` as an OpenCollection collection.
2. Select the `local` environment.
3. Set the secret `token` value in Bruno before running Sanctum-protected `/api/rag/*` and `/api/query` requests.

The local environment defaults to `http://localhost:8080` for `baseUrl` and includes placeholder IDs, search text, graph limits, and dataset values.

## CLI

Use read-only requests first:

```bash
cd bruno/rag-api
bru run requests/health/001-health-ping.yml --env local
```

Authenticated RAG requests need a token override:

```bash
bru run requests/rag/026-rag-health.yml --env local --env-var token="$RAWKI_API_TOKEN"
```

Avoid running mutation, retry, or destructive requests against shared data unless you intend to change application state.
