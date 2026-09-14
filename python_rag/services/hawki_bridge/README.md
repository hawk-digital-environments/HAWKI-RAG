# HAWKI bridge

Read-only data-plane bridge for Laravel-authorized query and graph reads,
with health and Temporal-control endpoints. Temporal controls start/schedule/cancel
work; there is no Qdrant/Neo4j ingestion write route.

See [Query & Retrieval](../../../_documentation/Core%20Concepts/query_retrieval.md)
and [REST APIs](../../../_documentation/Reference/rest_apis.md).

## Tests

From `python_rag`, run `uv run --group test --package hawki-bridge pytest
services/hawki_bridge/tests`. The `integration/` category requires live Qdrant.
