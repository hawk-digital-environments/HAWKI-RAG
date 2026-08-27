# HAWKI model providers

This package defines model capability ports and concrete Ollama/LiteLLM
adapters. Provider configuration accepts explicit model aliases only; request,
authorization, dataset, and workflow interpretation remain service-owned.

## Tests

From `python_rag`, run `uv run --group test --package hawki-model-providers
pytest packages/model_providers/tests`. The `integration/` category requires a
live Ollama or LiteLLM endpoint.
