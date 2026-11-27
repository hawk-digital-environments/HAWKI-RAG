# HAWKI converter worker

The converter worker prepares Markdown for the indexer. It reads crawled
files or stages a managed upload from shared storage, then converts the
input or passes through existing Markdown.

## Tests

From `python_rag`, run `uv run --group test --package hawki-converter-worker
pytest services/hawki_converter_worker/tests`.
