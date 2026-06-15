# RAWKI RAG API Bruno Collection

This OpenCollection YAML collection contains Bruno request files for the Laravel RAG API endpoints.

## Use in Bruno

1. Open `bruno/rag-api` as an OpenCollection collection.
2. Select the `local` environment.
3. Set the secret `token` value in Bruno before running API requests. Laravel protects all `/api/*` routes with Sanctum.

The local environment defaults to `http://localhost:8080` for `baseUrl` and includes placeholder IDs, search text, graph limits, and dataset values.

## CLI

Run deterministic smoke checks first. These assert controller-backed response contracts and should not depend on seeded IDs or live RAG generation:

```bash
cd bruno/rag-api
bru run requests -r --env local --tags smoke --env-var token="$RAWKI_API_TOKEN"
```

Run the Make target used by local development:

```bash
make test-bruno-smoke
```

## Tags

- `smoke`: deterministic local contract checks, including Sanctum rejection and basic list/health routes.
- `integration`: requires live downstream services such as the RAG bridge, model runtime, Qdrant, or Neo4j.
- `requires-data`: requires a real dataset, document, task, job, node, or snapshot ID.
- `mutation`: creates, retries, cancels, uploads, or changes application state.
- `destructive`: deletes or clears state; run only with explicit local intent.

Avoid running `mutation`, `requires-data`, `integration`, or `destructive` requests against shared data unless you intend to change application state and have supplied real local IDs.
