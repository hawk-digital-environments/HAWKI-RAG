# HAWKI bridge

> Epoch 8: `/temporal/*` control endpoints are retired and return 410. Laravel
> calls the authenticated Python backend instead; there is no bridge fallback.
> Any Temporal endpoint examples below describe the historical surface. Follow
> [Python workflow control](../../../_documentation/Operations/python_workflow_control.md)
> for deployment and rollback. Query and graph reads remain on this bridge.

`hawki_bridge` provides Laravel-authorized query/graph-read APIs and health. It does not provide canonical Qdrant/Neo4j ingestion
writes or resolve the caller's dataset grants.

The handbook owns the full [architecture](../../../_documentation/Getting%20Started/3_introduction_architecture.md),
[retrieval flow](../../../_documentation/Core%20Concepts/query_retrieval.md),
[authorization boundary](../../../_documentation/Core%20Concepts/authorization_dataset_scope.md),
and [HTTP contracts](../../../_documentation/Reference/rest_apis.md).

## Entrypoint

[src/hawki_bridge/main.py](src/hawki_bridge/main.py) exports the ASGI application
`hawki_bridge.main:app`. The `hawki-bridge` console command declared in
[pyproject.toml](pyproject.toml) starts it on port 8000 for local execution.
Use the handbook's [workspace setup](../../../_documentation/Developer/testing.md#python-workspace)
before running local commands; container startup belongs to
[Run HAWKI RAG](../../../_documentation/Getting%20Started/2_setup.md).

## Tests

Tests live in [tests/](tests/). From `python_rag`, run:

```bash
uv run --group test --package hawki-bridge pytest services/hawki_bridge/tests
```

The `integration/` category requires live Qdrant. See
[Testing](../../../_documentation/Developer/testing.md) for locked environment
commands and infrastructure requirements.
