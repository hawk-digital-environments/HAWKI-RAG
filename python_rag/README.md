# HAWKI RAG Python workspace

This uv workspace contains the ingestion workers, reranker, and shared Python
packages. Members declare **Python 3.13.14**. The workspace uses
[pyproject.toml](pyproject.toml) and one [lockfile](uv.lock).
It owns retrieval and ingestion. FastAPI owns current service authorization and
operational records; historical public-schema records remain preserved evidence.

See
[runtime architecture](../_documentation/Getting%20Started/3_introduction_architecture.md)
and [workspace dependencies](../_documentation/Reference/8_repo_map.md#actual-workspace-dependencies).

| Concern | Documentation owner |
|---|---|
| Service/member layout | [Services](services/README.md), [Repository Map](../_documentation/Reference/8_repo_map.md) |
| Shared package contracts | [Packages](packages/README.md) |
| Install/run/rebuild | [Run HAWKI RAG](../_documentation/Getting%20Started/2_setup.md) |
| Model/store/endpoint configuration | [Configuration](../_documentation/Operations/5_environment_db_queue.md) |
| Workflow names, queues, callbacks, compatibility | [Temporal Operations](../_documentation/Operations/temporal_operations.md) |
| Deterministic suites and isolated reranker environment | [Testing](../_documentation/Developer/testing.md) |
| Live integration tests | [Test README](tests/README.md) |
| Ownership rules | [Architecture Rules](../_documentation/Developer/architecture_rules.md) |

The [Python runtime guide](../_documentation/Operations/python_runtime.md)
lists deployment requirements. FastAPI serves query and graph APIs
directly through the shared retrieval and workflow-control packages.

## Local development

From the repository root, `make python-deps` provisions the data-plane and
separate reranker environments. From this directory, `uv lock --check` checks
the committed lock. Follow [Testing](../_documentation/Developer/testing.md#python-workspace)
for the exact sync and focused test commands.

There is no single workspace server entrypoint. Each member declares its command
in `services/<member>/pyproject.toml`; implementations live under that member's
`src/`, with tests beside it in `tests/`. Cross-service tests live in
[tests/](tests/README.md).

For container development and Python image rebuilds, use
[Run HAWKI RAG](../_documentation/Getting%20Started/2_setup.md#choose-a-startup-mode).
