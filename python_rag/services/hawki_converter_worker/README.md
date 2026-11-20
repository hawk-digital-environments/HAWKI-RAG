# HAWKI converter worker

Temporal worker that inspects raw artifacts and produces canonical Markdown
artifacts for the indexer.

## Tests

From `python_rag`, run `uv run --group test --package hawki-converter-worker
pytest services/hawki_converter_worker/tests`.
