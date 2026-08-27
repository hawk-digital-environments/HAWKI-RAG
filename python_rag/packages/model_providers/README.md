# HAWKI model providers

This package owns concrete Ollama and LiteLLM clients plus their explicit
construction and model-selection helpers. Consuming applications own the model
capability ports they require. Provider configuration accepts model aliases only; request,
authorization, dataset, and workflow interpretation remain service-owned.

New code should import from `hawki_model_providers.factory`, `.ollama`, or
`.litellm`. The package root lazily preserves its former concrete-client and
factory exports without loading both adapters during a plain package import.

## Tests

From `python_rag`, run `uv run --group test --package hawki-model-providers
pytest packages/model_providers/tests`. The `integration/` category requires a
live Ollama or LiteLLM endpoint.
