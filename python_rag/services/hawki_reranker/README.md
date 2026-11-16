# HAWKI reranker

Cohere-compatible local HTTP service for relevance reranking with an isolated
model dependency environment.

## Production role and configuration

This service remains the legacy HTTP backend for bridge requests using
`RERANKER_MODE=external` and `RERANKER_PROVIDER=cohere`. That provider name means
Cohere-compatible HTTP shape, not hosted Cohere. The bridge calls
`RERANKER_API_URL`, normally `http://hawki_rag_rerank/v1/rerank`.

The service reads `HAWKI_RERANKER_MODEL` (default
`mixedbread-ai/mxbai-rerank-base-v1`) and `HAWKI_RERANKER_PRELOAD` (default true).
It normally preloads at startup and retains lazy loading if preload fails.
`HAWKI_RERANKER_HOST`/`HAWKI_RERANKER_PORT` control the console entrypoint
(default `0.0.0.0:8000`); the shipped ASGI/nginx image exposes port 80 instead.
Production Compose injects `.env`; root development Compose explicitly forwards
preload but needs an override for a custom standalone model.

The reusable [hawki_reranking clients](../../packages/reranking/README.md) support
bridge-local and SDK inference without depending on this HTTP service. They do
not remove it. See [bridge backend configuration](../hawki_bridge/README.md#production-reranking-backends)
for all backend choices and the narrowly scoped JLU fallback. The bridge-local
backend also reads `HAWKI_RERANKER_MODEL`; a shared production env-file can change
both components' model selection.

## Tests

The reranker has an isolated model environment. From `python_rag`, run:

```bash
UV_PROJECT_ENVIRONMENT=.venv-reranker \
  uv run --group test --package hawki-reranker --extra cpu \
  pytest services/hawki_reranker/tests
```
