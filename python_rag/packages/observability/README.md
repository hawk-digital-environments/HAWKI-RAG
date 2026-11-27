# HAWKI observability

Workers and adapters use this package to select correlation IDs and format
events, including redacted request previews. Network clients and retry
policies belong to the consuming packages.

## Tests

From `python_rag`, run `uv run --group test --package hawki-observability pytest
packages/observability/tests`.
