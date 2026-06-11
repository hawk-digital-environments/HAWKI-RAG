# Python RAG Architecture Map

## Naming in this branch

- `api/` (new)
  - HTTP entrypoint and transport adapters.
  - `api/main.py`, `api/factory.py`, `api/settings.py`, `api/runtime.py`, `api/logging_config.py`
  - Request/response and dependency wiring in `api/http/`.
- `api/http/routers/` (HTTP contract boundaries).
- `application/`
  - Use-case orchestration for ingest/query workflows:
    - `application/ingest.py`, `application/query.py`, `application/documents.py`, `application/config_response.py`, `application/service.py`
- `domain/`
  - Domain contracts and boundaries:
    - `domain/ports.py`, `domain/settings.py`, `domain/__init__.py`
- `infrastructure/`
  - Concrete adapters, grouped by external system:
    - `infrastructure/graph/`, `infrastructure/vectorstore/`, `infrastructure/raganything/`, `infrastructure/rerank/`, `infrastructure/messaging/`

## Compatibility layer

The legacy `app/*` namespace is kept as a thin compatibility shim and re-exports the new `api/*` and `application/*` modules so existing imports continue to work while migration progresses.

## Next migration step

Move adapter imports in code from top-level modules (`core`, `graph`, `vectorstore`, etc.) to their corresponding `infrastructure/*` and `application/*` modules one module at a time.
