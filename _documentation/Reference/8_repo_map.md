# Repository Map

## Python service ownership

| Directory | Responsibility |
| --- | --- |
| `python_backend/src/hawki_backend` | FastAPI, service authentication, grants, datasets, query/ops and ingestion ownership |
| `python_backend/tests` | unit/integration tests, frozen fixtures and inert compatibility models |
| `python_rag/services` | workflow and activity workers, indexer/converter/scraper, reranker |
| `python_rag/packages` | shared typed contracts, stores, artifacts, runtime adapters |
| `docker`, Compose files | deployment images, networking, local crawler and service issuer |
| `scripts` | explicit provisioning, disposable smoke checks and read-only audits |

## Actual workspace dependencies

`python_rag/pyproject.toml` and `uv.lock` declare workspace members and pinned resolution. The backend has its own project/lockfile. Inspect these files before changing package boundaries. The reranker uses its isolated environment.

Use the [deployment guide](../Operations/python_runtime.md), [testing guide](../Developer/testing.md) and member READMEs for operational commands.

## Follow one query

The browser gateway injects its service JWT. FastAPI validates the principal and exact dataset grant, resolves canonical storage/model scope, and calls the query adapters for vector/graph retrieval and optional generation.
