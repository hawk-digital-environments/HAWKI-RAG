# HAWKI reranker

Cohere-compatible local HTTP service for relevance reranking with an isolated
model dependency environment.

## Tests

The reranker has an isolated model environment. From `python_rag`, run:

```bash
UV_PROJECT_ENVIRONMENT=.venv-reranker \
  uv run --group test --package hawki-reranker --extra cpu \
  pytest services/hawki_reranker/tests
```
