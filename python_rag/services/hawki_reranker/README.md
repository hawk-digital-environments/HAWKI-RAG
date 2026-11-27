# HAWKI reranker

The reranker scores candidate passages against a query through a
Cohere-compatible HTTP endpoint. Its model dependencies use a separate
environment.

## Tests

The reranker has an isolated model environment. From `python_rag`, run:

```bash
UV_PROJECT_ENVIRONMENT=.venv-reranker \
  uv run --group test --package hawki-reranker --extra cpu \
  pytest services/hawki_reranker/tests
```
